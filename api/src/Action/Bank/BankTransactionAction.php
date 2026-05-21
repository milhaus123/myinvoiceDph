<?php

declare(strict_types=1);

namespace MyInvoice\Action\Bank;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\GpcParser;
use MyInvoice\Service\Bank\StatementImporter;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Bank transaction endpoints (Issue #9: Bank Transaction Import + Payment Matching).
 *
 *   POST   /api/bank-transactions/import          import GPC/CSV file
 *   GET    /api/bank-transactions                 list with filters
 *   GET    /api/bank-transactions/unmatched       transactions awaiting manual pairing
 *   POST   /api/bank-transactions/pair/{id}      manually pair transaction to invoice/purchase
 *   POST   /api/bank-transactions/auto-match      run automatic matching on all unmatched
 */
final class BankTransactionAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly GpcParser $parser,
        private readonly StatementImporter $importer,
        private readonly StatementMatcher $matcher,
        private readonly InvoiceRepository $invoices,
        private readonly PurchaseInvoiceRepository $purchaseInvoices,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /**
     * POST /api/bank-transactions/import
     * Import bank transactions from uploaded GPC/CSV file.
     */
    public function import(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Soubor chybí.', 400);
        }

        $content = (string) $file->getStream()->getContents();
        $name = (string) $file->getClientFilename();

        try {
            $r = $this->importer->import($content, $name, (int) ($user['id'] ?? 0));
        } catch (\Throwable $e) {
            return Json::error($response, 'import_failed', 'Nelze naimportovat: ' . $e->getMessage(), 400);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.transaction_imported', $user['id'] ?? null, 'bank_statement', $r['statement_id'], $r, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $r);
    }

    /**
     * GET /api/bank-transactions
     * List bank transactions with optional filters.
     * Query params: statement_id, date_from, date_to, status, account, limit, offset
     */
    public function list(Request $request, Response $response): Response
    {
        $sid = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        $query = $request->getQueryParams();
        $statementId = isset($query['statement_id']) ? (int) $query['statement_id'] : null;
        $dateFrom = trim((string) ($query['date_from'] ?? ''));
        $dateTo = trim((string) ($query['date_to'] ?? ''));
        $status = trim((string) ($query['status'] ?? ''));
        $account = trim((string) ($query['account'] ?? ''));
        $limit = min(200, max(1, (int) ($query['limit'] ?? 100)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        // Base query — scope to supplier via bank_statements → currencies
        $sql = <<<SQL
            SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, bt.currency,
                   bt.variable_symbol, bt.constant_symbol, bt.specific_symbol,
                   bt.counterparty_account, bt.counterparty_bank, bt.counterparty_name,
                   bt.description, bt.match_status,
                   bt.matched_invoice_id, bt.matched_purchase_invoice_id,
                   bt.matched_at,
                   bs.account_number, bs.statement_date,
                   i.varsymbol AS matched_invoice_varsymbol, i.amount_to_pay AS matched_invoice_amount,
                   c.company_name AS matched_client_name,
                   pi.varsymbol AS matched_pi_varsymbol, pi.amount_to_pay AS matched_pi_amount,
                   pi.invoice_number AS matched_pi_number
            FROM bank_transactions bt
            JOIN bank_statements bs ON bs.id = bt.statement_id
            LEFT JOIN invoices i ON i.id = bt.matched_invoice_id
            LEFT JOIN clients c ON c.id = i.client_id
            LEFT JOIN purchase_invoices pi ON pi.id = bt.matched_purchase_invoice_id
            WHERE EXISTS (
                SELECT 1 FROM currencies cur
                 WHERE cur.supplier_id = ?
                   AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                     = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
            )
        SQL;
        $params = [$sid];

        if ($statementId !== null) {
            $sql .= ' AND bt.statement_id = ?';
            $params[] = $statementId;
        }
        if ($dateFrom !== '') {
            $sql .= ' AND bt.posted_at >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= ' AND bt.posted_at <= ?';
            $params[] = $dateTo;
        }
        if ($status !== '' && in_array($status, ['unmatched', 'auto_exact', 'auto_partial', 'manual', 'ignored'], true)) {
            $sql .= ' AND bt.match_status = ?';
            $params[] = $status;
        }
        if ($account !== '') {
            $sql .= ' AND (bt.counterparty_account LIKE ? OR bt.counterparty_name LIKE ?)';
            $params[] = "%$account%";
            $params[] = "%$account%";
        }

        $sql .= ' ORDER BY bt.posted_at DESC, bt.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['statement_id'] = (int) $r['statement_id'];
            $r['amount'] = (float) $r['amount'];
            $r['matched_invoice_id'] = $r['matched_invoice_id'] !== null ? (int) $r['matched_invoice_id'] : null;
            $r['matched_purchase_invoice_id'] = $r['matched_purchase_invoice_id'] !== null ? (int) $r['matched_purchase_invoice_id'] : null;
            $r['matched_invoice_amount'] = $r['matched_invoice_amount'] !== null ? (float) $r['matched_invoice_amount'] : null;
            $r['matched_pi_amount'] = $r['matched_pi_amount'] !== null ? (float) $r['matched_pi_amount'] : null;
        }

        return Json::ok($response, $rows);
    }

    /**
     * GET /api/bank-transactions/unmatched
     * List unmatched transactions for manual pairing UI.
     */
    public function unmatched(Request $request, Response $response): Response
    {
        $sid = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        $query = $request->getQueryParams();
        $limit = min(200, max(1, (int) ($query['limit'] ?? 100)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $sql = <<<SQL
            SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, bt.currency,
                   bt.variable_symbol, bt.constant_symbol, bt.specific_symbol,
                   bt.counterparty_account, bt.counterparty_bank, bt.counterparty_name,
                   bt.description,
                   bs.account_number, bs.statement_date,
                   -- Najdi faktury s odpovídajícím VS pro návrh párování
                   i.id AS candidate_invoice_id, i.varsymbol AS candidate_invoice_vs,
                   i.amount_to_pay AS candidate_invoice_amount, i.status AS candidate_invoice_status,
                   pi.id AS candidate_pi_id, pi.varsymbol AS candidate_pi_varsymbol,
                   pi.amount_to_pay AS candidate_pi_amount, pi.status AS candidate_pi_status
            FROM bank_transactions bt
            JOIN bank_statements bs ON bs.id = bt.statement_id
            LEFT JOIN invoices i ON i.supplier_id = ? AND i.varsymbol = bt.variable_symbol
                                 AND i.status IN ('issued', 'sent', 'reminded')
            LEFT JOIN purchase_invoices pi ON pi.supplier_id = ? AND pi.varsymbol = bt.variable_symbol
                                          AND pi.status IN ('received', 'booked')
            WHERE EXISTS (
                SELECT 1 FROM currencies cur
                 WHERE cur.supplier_id = ?
                   AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                     = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
            )
              AND bt.match_status = 'unmatched'
            ORDER BY bt.posted_at DESC, bt.id DESC
            LIMIT ? OFFSET ?
        SQL;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$sid, $sid, $sid, $limit, $offset]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['statement_id'] = (int) $r['statement_id'];
            $r['amount'] = (float) $r['amount'];
            $r['candidate_invoice_id'] = $r['candidate_invoice_id'] !== null ? (int) $r['candidate_invoice_id'] : null;
            $r['candidate_pi_id'] = $r['candidate_pi_id'] !== null ? (int) $r['candidate_pi_id'] : null;
            $r['candidate_invoice_amount'] = $r['candidate_invoice_amount'] !== null ? (float) $r['candidate_invoice_amount'] : null;
            $r['candidate_pi_amount'] = $r['candidate_pi_amount'] !== null ? (float) $r['candidate_pi_amount'] : null;
        }

        return Json::ok($response, $rows);
    }

    /**
     * POST /api/bank-transactions/pair/{id}
     * Manually pair a bank transaction to an invoice or purchase_invoice.
     * Body: { invoice_id?: number, purchase_invoice_id?: number }
     */
    public function pair(Request $request, Response $response, array $args): Response
    {
        $txId = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $invoiceId = (int) ($body['invoice_id'] ?? 0);
        $purchaseInvoiceId = (int) ($body['purchase_invoice_id'] ?? 0);

        if ($invoiceId <= 0 && $purchaseInvoiceId <= 0) {
            return Json::error($response, 'validation_failed', 'Chybí invoice_id nebo purchase_invoice_id.', 400);
        }
        if ($invoiceId > 0 && $purchaseInvoiceId > 0) {
            return Json::error($response, 'validation_failed', 'Nelze párovat současně s fakturou i purchase fakturou.', 400);
        }

        $pdo = $this->db->pdo();
        $userId = (int) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['id'] ?? 0);

        // Ověř transakci a její scope
        $tx = $pdo->prepare(
            'SELECT bt.posted_at, bt.statement_id FROM bank_transactions bt
              JOIN bank_statements bs ON bs.id = bt.statement_id
             WHERE bt.id = ?'
        );
        $tx->execute([$txId]);
        $txRow = $tx->fetch(\PDO::FETCH_ASSOC);
        if (!$txRow) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }
        $postedAt = (string) ($txRow['posted_at'] ?? date('Y-m-d'));
        $statementId = (int) ($txRow['statement_id'] ?? 0);

        // Ověř supplier scope
        $sid = SupplierGuard::currentId($request);
        $own = $pdo->prepare(
            "SELECT 1 FROM bank_statements bs
              WHERE bs.id = ?
                AND EXISTS (
                    SELECT 1 FROM currencies cur
                     WHERE cur.supplier_id = ?
                       AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                         = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                )"
        );
        $own->execute([$statementId, $sid]);
        if (!$own->fetchColumn()) {
            return Json::error($response, 'not_found', 'Transakce nenalezena.', 404);
        }

        $pdo->beginTransaction();
        try {
            if ($invoiceId > 0) {
                // Ověř fakturu
                $invoice = $this->invoices->find($invoiceId);
                if (!SupplierGuard::owns($request, $invoice)) {
                    $pdo->rollBack();
                    return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
                }

                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET matched_invoice_id = ?, matched_purchase_invoice_id = NULL,
                            match_status = 'manual', matched_at = NOW(), matched_by = ?
                      WHERE id = ?"
                )->execute([$invoiceId, $userId ?: null, $txId]);

                // Označ fakturu jako paid pokud ještě není
                if (in_array($invoice['status'], ['issued', 'sent', 'reminded'], true)) {
                    $pdo->prepare(
                        "UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                    )->execute([$postedAt, $invoiceId]);
                }

                // Audit log
                $this->recordPairAudit($pdo, $txId, $invoiceId, 'invoice', $userId);
            } else {
                // Ověř purchase_invoice
                $pi = $this->purchaseInvoices->find($purchaseInvoiceId);
                if (!$pi || (int) ($pi['supplier_id'] ?? 0) !== $sid) {
                    $pdo->rollBack();
                    return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
                }

                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET matched_purchase_invoice_id = ?, matched_invoice_id = NULL,
                            match_status = 'manual', matched_at = NOW(), matched_by = ?
                      WHERE id = ?"
                )->execute([$purchaseInvoiceId, $userId ?: null, $txId]);

                // Označ purchase_invoice jako paid pokud ještě není
                if (in_array($pi['status'], ['received', 'booked'], true)) {
                    $pdo->prepare(
                        "UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                    )->execute([$postedAt, $purchaseInvoiceId]);
                }

                // Audit log
                $this->recordPairAudit($pdo, $txId, $purchaseInvoiceId, 'purchase_invoice', $userId);
            }

            // Recompute matched_count na výpisu
            if ($statementId > 0) {
                $pdo->prepare(
                    "UPDATE bank_statements
                        SET matched_count = (
                            SELECT COUNT(*) FROM bank_transactions
                             WHERE statement_id = ?
                               AND match_status IN ('auto_exact', 'auto_partial', 'manual')
                        )
                      WHERE id = ?"
                )->execute([$statementId, $statementId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return Json::error($response, 'pair_failed', 'Párování selhalo: ' . $e->getMessage(), 500);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.tx_paired', $userId ?: null, 'bank_transaction', $txId, [
            'invoice_id' => $invoiceId ?: null,
            'purchase_invoice_id' => $purchaseInvoiceId ?: null,
            'paid_at' => $postedAt,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['paired' => true, 'paid_at' => $postedAt]);
    }

    /**
     * POST /api/bank-transactions/auto-match
     * Run automatic matching on all unmatched transactions for current supplier.
     */
    public function autoMatch(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $sid = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();

        // Najdi všechny unmatched transakce pro tohoto suppliera
        $stmt = $pdo->prepare(
            "SELECT bt.id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE EXISTS (
                  SELECT 1 FROM currencies cur
                   WHERE cur.supplier_id = ?
                     AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                       = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
              )
                AND bt.match_status = 'unmatched'
                AND bt.variable_symbol IS NOT NULL
                AND bt.variable_symbol != ''
                AND bt.amount > 0
              ORDER BY bt.posted_at DESC"
        );
        $stmt->execute([$sid]);
        $txIds = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id');

        $matched = 0;
        $unmatched = 0;
        $errors = [];

        foreach ($txIds as $txId) {
            try {
                $result = $this->matcher->match((int) $txId);
                if (in_array($result['status'], ['auto_exact', 'auto_partial'], true)) {
                    $matched++;
                } else {
                    $unmatched++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['tx_id' => $txId, 'error' => $e->getMessage()];
                $unmatched++;
            }
        }

        $summary = [
            'total' => count($txIds),
            'matched' => $matched,
            'unmatched' => $unmatched,
            'errors' => $errors,
        ];

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('bank.auto_match_run', $user['id'] ?? null, null, null, $summary, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $summary);
    }

    private function recordPairAudit(\PDO $pdo, int $txId, int $matchedId, string $matchedType, int $userId): void
    {
        // Deaktivuj předchozí active záznamy
        $pdo->prepare(
            "UPDATE bank_transaction_matches SET is_active = 0 WHERE bank_transaction_id = ? AND is_active = 1"
        )->execute([$txId]);

        $invoiceId = $matchedType === 'invoice' ? $matchedId : null;
        $purchaseInvoiceId = $matchedType === 'purchase_invoice' ? $matchedId : null;

        // Získej amount pro audit
        $tx = $pdo->prepare('SELECT amount FROM bank_transactions WHERE id = ?');
        $tx->execute([$txId]);
        $amount = (float) ($tx->fetchColumn() ?? 0);

        $pdo->prepare(
            "INSERT INTO bank_transaction_matches
                (bank_transaction_id, invoice_id, purchase_invoice_id, match_type, match_amount, is_active, matched_by)
             VALUES (?, ?, ?, 'manual', ?, 1, ?)"
        )->execute([
            $txId,
            $invoiceId,
            $purchaseInvoiceId,
            $amount,
            $userId ?: null,
        ]);
    }
}
