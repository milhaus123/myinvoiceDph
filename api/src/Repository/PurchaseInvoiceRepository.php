<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\CzkRecap;
use PDO;

/**
 * CRUD for purchase invoices (přijaté faktury) + položky + listing s grupováním po měsících (DUZP).
 *
 * Konvence řazení/grupování:
 *   "month bucket" = COALESCE(tax_date, issue_date) → "YYYY-MM"
 *   pro draft (tax_date NULL) padá na issue_date
 *
 * Status lifecycle: draft → received → booked → paid (cancelled at any point)
 */
final class PurchaseInvoiceRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Fetch single purchase invoice with items, supplier info, currency, VAT breakdown.
     */
    public function find(int $id): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT pi.*,
                    c.company_name AS supplier_company_name, c.main_email AS supplier_main_email,
                    c.ic AS supplier_ic, c.dic AS supplier_dic,
                    c.language AS supplier_language,
                    c.reverse_charge AS supplier_reverse_charge,
                    cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                    cur.label AS currency_label,
                    cur.account_number AS bank_account_number, cur.bank_code AS bank_code,
                    cur.bank_name AS bank_name, cur.iban AS bank_iban, cur.bic AS bank_bic
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.supplier_id
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->castInvoice($row);
        $row['items'] = $this->itemsFor($id);

        // VAT breakdown
        $row['vat_breakdown'] = $this->buildVatBreakdown($row['items']);
        $row['totals'] = [
            'without_vat'         => $row['total_without_vat'],
            'vat'                 => $row['total_vat'],
            'with_vat'            => $row['total_with_vat'],
            'rounding'            => $row['rounding'],
            'advance_paid_amount' => $row['advance_paid_amount'],
            'amount_to_pay'       => $row['amount_to_pay'],
        ];

        // CZK přepočet — jen pokud měna != CZK a faktura má zafixovaný kurz.
        if (
            !empty($row['exchange_rate'])
            && (string) ($row['currency'] ?? '') !== 'CZK'
        ) {
            $rateDate = (string) ($row['exchange_rate_date'] ?? $row['issue_date']);
            $fallback = $rateDate !== (string) $row['issue_date'];
            $row['czk_recap'] = CzkRecap::build(
                $row['vat_breakdown'],
                (float) $row['exchange_rate'],
                $rateDate,
                $fallback,
            );
        } else {
            $row['czk_recap'] = null;
        }

        return $row;
    }

    /**
     * Set fixed exchange rate (CZK / 1 foreign currency unit + date).
     */
    public function setExchangeRate(int $id, ?float $rate, ?string $rateDate = null): void
    {
        $this->db->pdo()->prepare(
            'UPDATE purchase_invoices SET exchange_rate = ?, exchange_rate_date = ? WHERE id = ?'
        )->execute([$rate, $rateDate, $id]);
    }

    /**
     * Fetch line items for a purchase invoice.
     */
    public function itemsFor(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.purchase_invoice_id, pii.description, pii.quantity, pii.unit,
                    pii.unit_price_without_vat, pii.vat_rate_id, pii.vat_rate_snapshot,
                    pii.total_without_vat, pii.total_vat, pii.total_with_vat,
                    pii.order_index,
                    vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en
               FROM purchase_invoice_items pii
               JOIN vat_rates vr ON vr.id = pii.vat_rate_id
              WHERE pii.purchase_invoice_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castItem($r), $rows);
    }

    /**
     * List purchase invoices grouped by month per COALESCE(tax_date, issue_date).
     *
     * Output: ['data' => [{month: '2026-04', total_*, count, invoices: [...]} ...], 'meta' => ...]
     */
    public function listGroupedByMonth(array $filters = [], int $page = 1, int $perPage = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'pi.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $place = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "pi.status IN ($place)";
            foreach ($statuses as $s) $params[] = $s;
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(COALESCE(pi.tax_date, pi.issue_date)) = ?';
            $params[] = (int) $filters['year'];
        }
        if (!empty($filters['month'])) {
            $where[] = 'MONTH(COALESCE(pi.tax_date, pi.issue_date)) = ?';
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['currency'])) {
            $where[] = 'cur.code = ?';
            $params[] = strtoupper((string) $filters['currency']);
        }
        if (!empty($filters['unpaid_only'])) {
            $where[] = "pi.status IN ('received','booked')";
        }
        if (!empty($filters['overdue'])) {
            $where[] = "pi.status IN ('received','booked') AND pi.due_date <= CURDATE()";
        }
        if (!empty($filters['q'])) {
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(pi.varsymbol LIKE ? OR pi.invoice_number LIKE ? OR c.company_name LIKE ?)';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = null;
        if ($perPage > 0) {
            $cntStmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM purchase_invoices pi
                   JOIN clients c ON c.id = pi.supplier_id
                   JOIN currencies cur ON cur.id = pi.currency_id
                  WHERE $whereSql"
            );
            $cntStmt->execute($params);
            $total = (int) $cntStmt->fetchColumn();
        }

        $sql = "SELECT pi.id, pi.varsymbol, pi.supplier_id, pi.invoice_number,
                       pi.issue_date, pi.tax_date, pi.due_date, pi.received_at,
                       pi.currency_id, cur.code AS currency, cur.symbol AS currency_symbol,
                       cur.decimals AS currency_decimals,
                       pi.total_without_vat, pi.total_vat, pi.total_with_vat,
                       pi.advance_paid_amount, pi.amount_to_pay,
                       pi.status, pi.booked_at, pi.paid_at, pi.cancelled_at,
                       c.company_name AS supplier_company_name,
                       DATE_FORMAT(COALESCE(pi.tax_date, pi.issue_date), '%Y-%m') AS month_bucket
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.supplier_id
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE $whereSql
                 ORDER BY COALESCE(pi.tax_date, pi.issue_date) DESC, pi.id DESC";

        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $sql .= " LIMIT ? OFFSET ?";
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        if ($perPage > 0) {
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by month
        $grouped = [];
        foreach ($rows as $row) {
            $row = $this->castInvoice($row);
            $month = (string) $row['month_bucket'];
            if (!isset($grouped[$month])) {
                $grouped[$month] = [
                    'month' => $month,
                    'count' => 0,
                    'totals_per_currency' => [],
                    'invoices' => [],
                ];
            }
            $grouped[$month]['invoices'][] = $row;
            $grouped[$month]['count']++;

            $cur = $row['currency'];
            if (!isset($grouped[$month]['totals_per_currency'][$cur])) {
                $grouped[$month]['totals_per_currency'][$cur] = [
                    'currency'    => $cur,
                    'without_vat' => 0.0,
                    'vat'         => 0.0,
                    'with_vat'    => 0.0,
                ];
            }
            // Count all non-draft, non-cancelled into totals
            if (in_array($row['status'], ['received', 'booked', 'paid'], true)) {
                $grouped[$month]['totals_per_currency'][$cur]['without_vat'] += $row['total_without_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['vat']         += $row['total_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['with_vat']    += $row['total_with_vat'];
            }
        }

        // Round totals
        foreach ($grouped as &$m) {
            foreach ($m['totals_per_currency'] as &$t) {
                $t['without_vat'] = round($t['without_vat'], 2);
                $t['vat']         = round($t['vat'], 2);
                $t['with_vat']    = round($t['with_vat'], 2);
            }
            $m['totals_per_currency'] = array_values($m['totals_per_currency']);
        }
        unset($m, $t);

        $meta = ['total' => $total ?? count($rows)];
        if ($perPage > 0) {
            $meta['page']     = $page;
            $meta['per_page'] = $perPage;
            $meta['pages']    = (int) ceil(($total ?? 0) / max(1, $perPage));
        }

        return [
            'data' => array_values($grouped),
            'meta' => $meta,
        ];
    }

    /**
     * Create a new purchase invoice draft.
     *
     * @throws \InvalidArgumentException
     */
    public function createDraft(array $data, int $userId): int
    {
        $pdo = $this->db->pdo();

        // Validate supplier exists
        $supplierId = (int) $data['supplier_id'];
        $stmt = $pdo->prepare('SELECT id FROM clients WHERE id = ?');
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException("Supplier (client) #$supplierId not found.");
        }

        $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
        if ($manualVarsymbol === '') {
            $manualVarsymbol = null;
        } elseif (strlen($manualVarsymbol) > 20) {
            throw new \InvalidArgumentException('varsymbol has max 20 characters');
        }

        $sql = 'INSERT INTO purchase_invoices
            (supplier_id, varsymbol, invoice_number,
             issue_date, tax_date, due_date, received_at, currency_id,
             reverse_charge, language,
             note_above_items, note_below_items, advance_paid_amount,
             status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $supplierId,
            $manualVarsymbol,
            (string) ($data['invoice_number'] ?? ''),
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (string) ($data['received_at'] ?? $data['issue_date']),
            (int) $data['currency_id'],
            !empty($data['reverse_charge']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Update an existing purchase invoice draft.
     *
     * @throws \InvalidArgumentException
     */
    public function updateDraft(int $id, array $data): void
    {
        $hasVarsymbol = array_key_exists('varsymbol', $data);
        $manualVarsymbol = null;
        if ($hasVarsymbol) {
            $manualVarsymbol = trim((string) ($data['varsymbol'] ?? ''));
            if ($manualVarsymbol === '') {
                $manualVarsymbol = null;
            } elseif (strlen($manualVarsymbol) > 20) {
                throw new \InvalidArgumentException('varsymbol has max 20 characters');
            }
        }

        $sql = 'UPDATE purchase_invoices SET
                supplier_id = ?, invoice_number = ?,
                issue_date = ?, tax_date = ?, due_date = ?, received_at = ?,
                currency_id = ?, reverse_charge = ?, language = ?,
                note_above_items = ?, note_below_items = ?,
                advance_paid_amount = ?'
              . ($hasVarsymbol ? ', varsymbol = ?' : '')
              . ' WHERE id = ?';

        $params = [
            (int) $data['supplier_id'],
            (string) ($data['invoice_number'] ?? ''),
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (string) ($data['received_at'] ?? $data['issue_date']),
            (int) $data['currency_id'],
            !empty($data['reverse_charge']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
        ];
        if ($hasVarsymbol) $params[] = $manualVarsymbol;
        $params[] = $id;

        $this->db->pdo()->prepare($sql)->execute($params);
    }

    /**
     * Hard-delete a purchase invoice. Only allowed for draft status.
     *
     * @throws \InvalidArgumentException
     */
    public function delete(int $id): void
    {
        // ON DELETE CASCADE handles purchase_invoice_items
        $this->db->pdo()->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
    }

    /**
     * Replace all line items (delete old + insert new).
     */
    public function replaceItems(int $invoiceId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')
            ->execute([$invoiceId]);

        $stmt = $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)'
        );

        $vatRates = $this->loadVatRates();

        foreach (array_values($items) as $i => $item) {
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $rate = $vatRates[$vatRateId] ?? 0.0;
            $stmt->execute([
                $invoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                (int) ($item['order_index'] ?? $i),
            ]);
        }
    }

    /**
     * Transition invoice status. Allowed transitions:
     *   draft → received
     *   received → booked
     *   booked → paid
     *   draft/received/booked → cancelled
     *
     * @throws \InvalidArgumentException
     */
    public function markStatus(int $id, string $newStatus): void
    {
        $allowed = [
            'draft'     => ['received', 'cancelled'],
            'received'  => ['booked', 'cancelled'],
            'booked'    => ['paid', 'cancelled'],
            'paid'      => [],
            'cancelled' => [],
        ];

        $stmt = $this->db->pdo()->prepare('SELECT status FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new \InvalidArgumentException("Purchase invoice #$id not found.");
        }

        $allowedNext = $allowed[$current] ?? [];
        if (!in_array($newStatus, $allowedNext, true)) {
            throw new \InvalidArgumentException(
                "Invalid status transition: {$current} → {$newStatus}. Allowed: " . implode(', ', $allowedNext)
            );
        }

        $now = match ($newStatus) {
            'booked'    => 'NOW()',
            'cancelled' => 'NOW()',
            default     => 'NULL',
        };

        $paidAt = $newStatus === 'paid' ? 'CURDATE()' : 'NULL';

        if ($newStatus === 'paid') {
            $this->db->pdo()->prepare(
                "UPDATE purchase_invoices
                    SET status = ?, paid_at = CURDATE()
                  WHERE id = ?"
            )->execute([$newStatus, $id]);
        } elseif ($newStatus === 'cancelled') {
            $this->db->pdo()->prepare(
                "UPDATE purchase_invoices
                    SET status = ?, cancelled_at = NOW()
                  WHERE id = ?"
            )->execute([$newStatus, $id]);
        } elseif ($newStatus === 'booked') {
            $this->db->pdo()->prepare(
                "UPDATE purchase_invoices
                    SET status = ?, booked_at = NOW()
                  WHERE id = ?"
            )->execute([$newStatus, $id]);
        } else {
            $this->db->pdo()->prepare(
                'UPDATE purchase_invoices SET status = ? WHERE id = ?'
            )->execute([$newStatus, $id]);
        }
    }

    private function loadVatRates(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['rate_percent'];
        return $out;
    }

    private function castInvoice(array $row): array
    {
        $row['id']              = (int) $row['id'];
        $row['supplier_id']     = (int) $row['supplier_id'];
        if (isset($row['currency_id'])) $row['currency_id'] = (int) $row['currency_id'];
        $row['reverse_charge']  = isset($row['reverse_charge']) ? (bool) $row['reverse_charge'] : false;
        foreach (['total_without_vat', 'total_vat', 'total_with_vat', 'rounding',
                       'advance_paid_amount', 'amount_to_pay'] as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) {
                $row[$f] = (float) $row[$f];
            }
        }
        if (array_key_exists('exchange_rate', $row)) {
            $row['exchange_rate'] = $row['exchange_rate'] !== null ? (float) $row['exchange_rate'] : null;
        }
        return $row;
    }

    private function castItem(array $row): array
    {
        $row['id']                      = (int) $row['id'];
        $row['purchase_invoice_id']     = (int) $row['purchase_invoice_id'];
        $row['vat_rate_id']             = (int) $row['vat_rate_id'];
        $row['order_index']             = (int) $row['order_index'];
        $row['quantity']                = (float) $row['quantity'];
        $row['unit_price_without_vat']  = (float) $row['unit_price_without_vat'];
        $row['vat_rate_snapshot']       = (float) $row['vat_rate_snapshot'];
        foreach (['total_without_vat', 'total_vat', 'total_with_vat'] as $f) {
            $row[$f] = (float) $row[$f];
        }
        return $row;
    }

    private function buildVatBreakdown(array $items): array
    {
        $bd = [];
        foreach ($items as $item) {
            $rate = (float) $item['vat_rate_snapshot'];
            $key = number_format($rate, 2, '.', '');
            if (!isset($bd[$key])) {
                $bd[$key] = ['rate' => $rate, 'base' => 0.0, 'vat' => 0.0];
            }
            $bd[$key]['base'] += (float) $item['total_without_vat'];
            $bd[$key]['vat']  += (float) $item['total_vat'];
        }
        $out = [];
        foreach ($bd as $b) {
            $out[] = [
                'rate' => $b['rate'],
                'base' => round($b['base'], 2),
                'vat'  => round($b['vat'], 2),
            ];
        }
        usort($out, fn ($a, $b) => $b['rate'] <=> $a['rate']);
        return $out;
    }
}
