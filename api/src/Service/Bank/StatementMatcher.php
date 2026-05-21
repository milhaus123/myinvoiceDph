<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;

/**
 * Matchne bankovní transakci na fakturu nebo purchase_invoice podle VS + amount.
 *
 * Strategie:
 *   1. Příchozí (amount > 0) — hledá unpaid invoice SE SHODNÝM varsymbol
 *      a) amount == amount_to_pay → 'auto_exact', faktura → paid
 *      b) |amount - amount_to_pay| <= 1 Kč → 'auto_partial' (jen log, faktura zůstane)
 *      Nejprve hledá v invoices, pak v purchase_invoices
 *   2. Odchozí (amount < 0) — neshodujeme automaticky (může být refund / náš výdaj)
 *
 * Multi-supplier: VS je unique per (supplier_id, varsymbol). Matcher určuje
 * supplier_id z bank_statement.account_number → currencies.account_number → supplier_id.
 * Pokud žádná currency neodpovídá účtu (bank statement nepatří žádnému supplierovi),
 * vrátí 'unmatched/unknown_supplier'.
 *
 * Issue #9: Přidána podpora pro purchase_invoices a audit table bank_transaction_matches.
 */
final class StatementMatcher
{
    public function __construct(
        private readonly Connection $db,
        private readonly FinalFromProformaCreator $finalCreator,
    ) {}

    public function match(int $transactionId): array
    {
        $pdo = $this->db->pdo();
        $tx = $pdo->prepare(
            'SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $tx->execute([$transactionId]);
        $row = $tx->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'unmatched', 'reason' => 'transaction_not_found'];
        }
        $vs = $row['variable_symbol'];
        $amount = (float) $row['amount'];
        if ($amount <= 0 || !$vs) {
            return ['status' => 'unmatched', 'reason' => 'no_vs_or_outgoing'];
        }

        // Určení supplier_id z bank účtu (currencies.account_number + bank_code).
        // Normalizace přes AccountNumberNormalizer (řeší zero-padding a prefix).
        $supplierId = 0;
        if (!empty($row['recipient_account'])) {
            $sql = 'SELECT supplier_id, account_number FROM currencies WHERE account_number IS NOT NULL';
            $params = [];
            if (!empty($row['recipient_bank'])) {
                $sql .= ' AND bank_code = ?';
                $params[] = $row['recipient_bank'];
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
                if (AccountNumberNormalizer::equals((string) $candidate['account_number'], (string) $row['recipient_account'])) {
                    $supplierId = (int) $candidate['supplier_id'];
                    break;
                }
            }
        }
        if ($supplierId === 0) {
            return ['status' => 'unmatched', 'reason' => 'unknown_supplier_for_account'];
        }

        // --- Issue #9: Nejprve zkusíme najít matching na purchase_invoices (odchozí platby = naše závazky) ---
        $piResult = $this->matchPurchaseInvoice($pdo, $supplierId, $vs, $amount, $transactionId, $row);
        if ($piResult !== null) {
            return $piResult;
        }

        // --- Pak hledáme match na issued invoices (příchozí platby = naše pohledávky) ---
        $invResult = $this->matchInvoice($pdo, $supplierId, $vs, $amount, $transactionId, $row);
        if ($invResult !== null) {
            return $invResult;
        }

        return ['status' => 'unmatched', 'reason' => 'no_unpaid_invoice_with_vs'];
    }

    /**
     * Najdi a spáruj purchase_invoice (odchozí platba = naše závazky dodavatelům).
     */
    private function matchPurchaseInvoice(\PDO $pdo, int $supplierId, string $vs, float $amount, int $transactionId, array $txRow): ?array
    {
        // Najdi purchase_invoice s VS = transakce.VS, supplier scope, status = 'received' or 'booked', amount_to_pay sedí.
        // Pro purchase_invoices používáme 'received' a 'booked' jako unpaid statusy.
        $stmt = $pdo->prepare(
            "SELECT pi.id, pi.varsymbol, pi.amount_to_pay, pi.status, pi.invoice_number,
                    cur.code AS currency
               FROM purchase_invoices pi
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.varsymbol = ?
                AND pi.status IN ('received', 'booked')
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $vs]);
        $pi = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$pi) {
            return null; // No purchase invoice match found
        }

        $diff = abs($amount - (float) $pi['amount_to_pay']);
        if ($diff < 0.01) {
            // Exact match — označit purchase_invoice jako paid
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                )->execute([$txRow['posted_at'], $pi['id']]);
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET matched_purchase_invoice_id = ?, match_status = 'auto_exact', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$pi['id'], $transactionId]);

                // Zapiš do audit logu
                $this->recordMatchAudit($pdo, $transactionId, (int) $pi['id'], 'purchase_invoice', 'auto_exact', $amount, (float) $pi['amount_to_pay'], null);

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            return [
                'status' => 'auto_exact',
                'matched_type' => 'purchase_invoice',
                'purchase_invoice_id' => (int) $pi['id'],
                'invoice_number' => $pi['invoice_number'],
                'varsymbol' => $vs,
            ];
        }
        if ($diff <= 1.0) {
            // Partial match — flag, ale nepaint paid (uživatel rozhodne)
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_purchase_invoice_id = ?, match_status = 'auto_partial', matched_at = NOW()
                  WHERE id = ?"
            )->execute([$pi['id'], $transactionId]);

            $this->recordMatchAudit($pdo, $transactionId, (int) $pi['id'], 'purchase_invoice', 'auto_partial', $amount, (float) $pi['amount_to_pay'], $diff);

            return [
                'status' => 'auto_partial',
                'matched_type' => 'purchase_invoice',
                'purchase_invoice_id' => (int) $pi['id'],
                'diff' => $diff,
            ];
        }

        return ['status' => 'unmatched', 'reason' => 'purchase_invoice_amount_mismatch', 'expected' => $pi['amount_to_pay'], 'got' => $amount];
    }

    /**
     * Najdi a spáruj invoice (příchozí platba = naše pohledávky).
     * Proformu povolujeme — zaplacená proforma se označí paid a navíc vytvoří DRAFT finální faktury.
     */
    private function matchInvoice(\PDO $pdo, int $supplierId, string $vs, float $amount, int $transactionId, array $txRow): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT i.id, i.varsymbol, i.amount_to_pay, i.status, i.invoice_type, cur.code AS currency
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.varsymbol = ?
                AND i.status IN ('issued', 'sent', 'reminded')
                AND i.invoice_type IN ('invoice', 'proforma')
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $vs]);
        $inv = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$inv) {
            return null; // No invoice match found
        }

        $diff = abs($amount - (float) $inv['amount_to_pay']);
        if ($diff < 0.01) {
            // Exact match — automaticky označit jako paid (transakce zajišťuje konzistenci s případným final draftem)
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                )->execute([$txRow['posted_at'], $inv['id']]);
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET matched_invoice_id = ?, match_status = 'auto_exact', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$inv['id'], $transactionId]);

                $finalDraftId = null;
                if ($inv['invoice_type'] === 'proforma') {
                    $finalDraftId = $this->finalCreator->create((int) $inv['id'], 0);
                }

                // Zapiš do audit logu
                $this->recordMatchAudit($pdo, $transactionId, (int) $inv['id'], 'invoice', 'auto_exact', $amount, (float) $inv['amount_to_pay'], null);

                $pdo->commit();

                $result = ['status' => 'auto_exact', 'matched_type' => 'invoice', 'invoice_id' => (int) $inv['id'], 'varsymbol' => $vs];
                if ($finalDraftId !== null) {
                    $result['final_draft_id'] = $finalDraftId;
                }
                return $result;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        if ($diff <= 1.0) {
            // Partial match — flag, ale nepaint paid (uživatel rozhodne)
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'auto_partial', matched_at = NOW()
                  WHERE id = ?"
            )->execute([$inv['id'], $transactionId]);

            $this->recordMatchAudit($pdo, $transactionId, (int) $inv['id'], 'invoice', 'auto_partial', $amount, (float) $inv['amount_to_pay'], $diff);

            return ['status' => 'auto_partial', 'matched_type' => 'invoice', 'invoice_id' => (int) $inv['id'], 'diff' => $diff];
        }

        return ['status' => 'unmatched', 'reason' => 'amount_mismatch', 'expected' => $inv['amount_to_pay'], 'got' => $amount];
    }

    /**
     * Zapiš match do audit tabulky bank_transaction_matches.
     * Deaktivuje předchozí active záznam pro tuto transakci.
     */
    private function recordMatchAudit(\PDO $pdo, int $transactionId, int $matchedId, string $matchedType, string $matchType, float $matchAmount, float $expectedAmount, ?float $diff): void
    {
        // Deaktivuj všechny předchozí active záznamy pro tuto transakci
        $pdo->prepare(
            "UPDATE bank_transaction_matches SET is_active = 0 WHERE bank_transaction_id = ? AND is_active = 1"
        )->execute([$transactionId]);

        // Vlož nový záznam
        $invoiceId = $matchedType === 'invoice' ? $matchedId : null;
        $purchaseInvoiceId = $matchedType === 'purchase_invoice' ? $matchedId : null;

        $pdo->prepare(
            "INSERT INTO bank_transaction_matches
                (bank_transaction_id, invoice_id, purchase_invoice_id, match_type, match_amount, expected_amount, amount_diff, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
        )->execute([
            $transactionId,
            $invoiceId,
            $purchaseInvoiceId,
            $matchType,
            $matchAmount,
            $expectedAmount,
            $diff,
        ]);
    }
}
