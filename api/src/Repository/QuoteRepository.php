<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * CRUD pro cenové nabídky (quotes).
 *
 * Quote je uložen v tabulce invoices s invoice_type = 'quote'.
 * Nemá DUZP (tax_date), splatnost (due_date) je volitelná (quote_valid_until).
 */
final class QuoteRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Vytvoří novou nabídku jako draft.
     * Vrací ID nové nabídky.
     */
    public function createDraft(array $body, int $userId): int
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO invoices (
                supplier_id, client_id, project_id, invoice_type,
                issue_date, currency_id, reverse_charge, language,
                note_above_items, note_below_items,
                total_without_vat, total_vat, total_with_vat, rounding,
                status, quote_status, quote_valid_until,
                created_by
            ) VALUES (
                :supplier_id, :client_id, :project_id, :invoice_type,
                :issue_date, :currency_id, :reverse_charge, :language,
                :note_above_items, :note_below_items,
                0, 0, 0, 0,
                :status, :quote_status, :quote_valid_until,
                :created_by
            )'
        );

        $stmt->execute([
            'supplier_id'       => $body['supplier_id'] ?? 1,
            'client_id'         => $body['client_id'],
            'project_id'        => $body['project_id'] ?? null,
            'invoice_type'      => 'quote',
            'issue_date'        => $body['issue_date'] ?? date('Y-m-d'),
            'currency_id'      => $body['currency_id'],
            'reverse_charge'    => $body['reverse_charge'] ?? 0,
            'language'          => $body['language'] ?? 'cs',
            'note_above_items'  => $body['note_above_items'] ?? null,
            'note_below_items'  => $body['note_below_items'] ?? null,
            'status'            => 'draft',
            'quote_status'      => 'draft',
            'quote_valid_until' => $body['quote_valid_until'] ?? null,
            'created_by'        => $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Aktualizuje existující nabídku.
     */
    public function update(int $id, array $body): void
    {
        $pdo = $this->db->pdo();

        $fields = [
            'client_id'         => 'client_id',
            'project_id'       => 'project_id',
            'issue_date'       => 'issue_date',
            'currency_id'      => 'currency_id',
            'reverse_charge'   => 'reverse_charge',
            'language'         => 'language',
            'note_above_items' => 'note_above_items',
            'note_below_items' => 'note_below_items',
            'quote_valid_until'=> 'quote_valid_until',
        ];

        $sets = [];
        $params = ['id' => $id];
        foreach ($fields as $key => $col) {
            if (array_key_exists($key, $body)) {
                $sets[] = "$col = :$key";
                $params[$key] = $body[$key];
            }
        }

        if (empty($sets)) return;

        $sql = 'UPDATE invoices SET ' . implode(', ', $sets) . ' WHERE id = :id AND invoice_type = \'quote\'';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Najde nabídku podle ID včetně položek.
     */
    public function find(int $id): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT i.*,
                    c.company_name AS client_company_name, c.main_email AS client_main_email,
                    c.ic AS client_ic, c.dic AS client_dic,
                    c.language AS client_language,
                    c.reverse_charge AS client_reverse_charge,
                    p.name AS project_name, p.hourly_rate AS project_hourly_rate,
                    p.project_number AS project_number, p.contract_number AS contract_number,
                    cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                    cur.label AS currency_label,
                    cur.account_number AS bank_account_number, cur.bank_code AS bank_code,
                    cur.bank_name AS bank_name, cur.iban AS bank_iban, cur.bic AS bank_bic
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN projects p ON p.id = i.project_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.invoice_type = \'quote\''
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row['items'] = $this->itemsFor($id);
        $row['vat_breakdown'] = $this->buildVatBreakdown($row['items']);
        $row['totals'] = [
            'without_vat'  => $row['total_without_vat'],
            'vat'          => $row['total_vat'],
            'with_vat'     => $row['total_with_vat'],
            'rounding'     => $row['rounding'],
        ];

        return $row;
    }

    /**
     * Seznam nabídek s filtry.
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $pdo = $this->db->pdo();

        $where = ["i.invoice_type = 'quote'"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'i.quote_status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'i.client_id = :client_id';
            $params['client_id'] = $filters['client_id'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(i.issue_date) = :year';
            $params['year'] = $filters['year'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(c.company_name LIKE :search OR i.varsymbol LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'i.supplier_id = :supplier_id';
            $params['supplier_id'] = $filters['supplier_id'];
        }

        $whereSql = implode(' AND ', $where);

        // Total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i JOIN clients c ON c.id = i.client_id WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Data
        $offset = ($page - 1) * $perPage;
        $sql = "
            SELECT i.id, i.varsymbol, i.issue_date, i.due_date,
                   i.total_without_vat, i.total_vat, i.total_with_vat,
                   i.quote_status, i.quote_valid_until,
                   i.quote_sent_at, i.quote_approved_at, i.quote_rejected_at,
                   i.status,
                   c.company_name AS client_company_name,
                   cur.code AS currency, cur.symbol AS currency_symbol
              FROM invoices i
              JOIN clients c ON c.id = i.client_id
              JOIN currencies cur ON cur.id = i.currency_id
             WHERE $whereSql
          ORDER BY i.issue_date DESC, i.id DESC
             LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Převede schválenou nabídku na fakturu.
     * Vrací ID nové faktury.
     */
    public function convertToInvoice(int $quoteId, array $invoiceData, int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Ověř, že nabídka existuje a je schválená
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND invoice_type = 'quote'");
            $stmt->execute([$quoteId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$quote) {
                throw new \InvalidArgumentException('Quote not found');
            }
            if ($quote['quote_status'] !== 'approved') {
                throw new \InvalidArgumentException('Quote must be approved to convert to invoice');
            }

            // Zkopíruj fakturu
            $stmt = $pdo->prepare(
                'INSERT INTO invoices (
                    supplier_id, varsymbol, invoice_type, client_id, project_id,
                    issue_date, tax_date, due_date, currency_id, reverse_charge,
                    language, note_above_items, note_below_items,
                    total_without_vat, total_vat, total_with_vat, rounding,
                    status, created_by
                ) VALUES (
                    :supplier_id, :varsymbol, :invoice_type, :client_id, :project_id,
                    :issue_date, :tax_date, :due_date, :currency_id, :reverse_charge,
                    :language, :note_above_items, :note_below_items,
                    :total_without_vat, :total_vat, :total_with_vat, :rounding,
                    :status, :created_by
                )'
            );

            $stmt->execute([
                'supplier_id'       => $quote['supplier_id'],
                'varsymbol'         => $invoiceData['varsymbol'] ?? null,
                'invoice_type'      => 'invoice',
                'client_id'         => $quote['client_id'],
                'project_id'        => $quote['project_id'],
                'issue_date'        => $invoiceData['issue_date'] ?? date('Y-m-d'),
                'tax_date'          => $invoiceData['tax_date'] ?? ($invoiceData['issue_date'] ?? date('Y-m-d')),
                'due_date'          => $invoiceData['due_date'] ?? date('Y-m-d', strtotime('+' . ($invoiceData['payment_due_days'] ?? 14) . ' days')),
                'currency_id'       => $quote['currency_id'],
                'reverse_charge'    => $quote['reverse_charge'],
                'language'          => $quote['language'],
                'note_above_items'  => $quote['note_above_items'],
                'note_below_items'  => $quote['note_below_items'],
                'total_without_vat' => $quote['total_without_vat'],
                'total_vat'         => $quote['total_vat'],
                'total_with_vat'     => $quote['total_with_vat'],
                'rounding'          => $quote['rounding'],
                'status'            => 'draft',
                'created_by'        => $userId,
            ]);

            $invoiceId = (int) $pdo->lastInsertId();

            // Zkopíruj položky
            $itemsStmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
            $itemsStmt->execute([$quoteId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $insItem = $pdo->prepare(
                'INSERT INTO invoice_items (
                    invoice_id, description, quantity, unit, unit_price_without_vat,
                    vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat,
                    total_with_vat, order_index, linked_work_report_id
                ) VALUES (
                    :invoice_id, :description, :quantity, :unit, :unit_price_without_vat,
                    :vat_rate_id, :vat_rate_snapshot, :total_without_vat, :total_vat,
                    :total_with_vat, :order_index, :linked_work_report_id
                )'
            );

            foreach ($items as $item) {
                $insItem->execute([
                    'invoice_id'            => $invoiceId,
                    'description'           => $item['description'],
                    'quantity'              => $item['quantity'],
                    'unit'                  => $item['unit'],
                    'unit_price_without_vat'=> $item['unit_price_without_vat'],
                    'vat_rate_id'          => $item['vat_rate_id'],
                    'vat_rate_snapshot'    => $item['vat_rate_snapshot'],
                    'total_without_vat'    => $item['total_without_vat'],
                    'total_vat'            => $item['total_vat'],
                    'total_with_vat'       => $item['total_with_vat'],
                    'order_index'           => $item['order_index'],
                    'linked_work_report_id'=> $item['linked_work_report_id'],
                ]);
            }

            // Označ nabídku jako převedenou
            $upd = $pdo->prepare(
                "UPDATE invoices SET quote_status = 'converted', quote_converted_to_invoice_id = ? WHERE id = ?"
            );
            $upd->execute([$invoiceId, $quoteId]);

            $pdo->commit();
            return $invoiceId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Aktualizuje status nabídky.
     */
    public function updateStatus(int $id, string $status): void
    {
        $pdo = $this->db->pdo();
        $now = date('Y-m-d H:i:s');

        $sql = "UPDATE invoices SET quote_status = :status";
        $params = ['status' => $status, 'id' => $id];

        if ($status === 'sent') {
            $sql .= ', quote_sent_at = :now';
            $params['now'] = $now;
        } elseif ($status === 'approved') {
            $sql .= ', quote_approved_at = :now';
            $params['now'] = $now;
        } elseif ($status === 'rejected') {
            $sql .= ', quote_rejected_at = :now';
            $params['now'] = $now;
        }

        $sql .= ' WHERE id = :id AND invoice_type = \'quote\'';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Odstraní nabídku (jen draft).
     */
    public function delete(int $id): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ? AND invoice_type = 'quote' AND quote_status = 'draft'");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Nahradí všechny položky nabídky.
     */
    public function replaceItems(int $invoiceId, array $items): void
    {
        $pdo = $this->db->pdo();

        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_items (
                invoice_id, description, quantity, unit, unit_price_without_vat,
                vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat,
                total_with_vat, order_index
            ) VALUES (
                :invoice_id, :description, :quantity, :unit, :unit_price_without_vat,
                :vat_rate_id, :vat_rate_snapshot, :total_without_vat, :total_vat,
                :total_with_vat, :order_index
            )'
        );

        foreach ($items as $i => $item) {
            $vatRate = $this->fetchVatRate((int) ($item['vat_rate_id'] ?? 0));
            $qty = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['unit_price_without_vat'] ?? 0);
            $rate = (float) ($vatRate['rate'] ?? 0);
            $totalWithoutVat = $qty * $price;
            $totalVat = $totalWithoutVat * $rate / 100;
            $totalWithVat = $totalWithoutVat + $totalVat;

            $stmt->execute([
                'invoice_id'            => $invoiceId,
                'description'           => $item['description'] ?? '',
                'quantity'              => $qty,
                'unit'                  => $item['unit'] ?? 'ks',
                'unit_price_without_vat'=> $price,
                'vat_rate_id'          => $item['vat_rate_id'],
                'vat_rate_snapshot'    => $rate,
                'total_without_vat'    => $totalWithoutVat,
                'total_vat'            => $totalVat,
                'total_with_vat'       => $totalWithVat,
                'order_index'           => $i,
            ]);
        }
    }

    private function fetchVatRate(int $id): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM vat_rates WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['rate' => 0];
    }

    // -------------------------------------------------------------------------

    private function itemsFor(int $invoiceId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT ii.*, vr.rate AS vat_rate, vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en
               FROM invoice_items ii
               JOIN vat_rates vr ON vr.id = ii.vat_rate_id
              WHERE ii.invoice_id = ?
           ORDER BY ii.order_index'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildVatBreakdown(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $rate = (float) ($item['vat_rate_snapshot'] ?? $item['vat_rate'] ?? 0);
            $key = (string) $rate;
            if (!isset($groups[$key])) {
                $groups[$key] = ['rate' => $rate, 'base' => 0, 'vat' => 0];
            }
            $groups[$key]['base'] += (float) ($item['total_without_vat'] ?? 0);
            $groups[$key]['vat']  += (float) ($item['total_vat'] ?? 0);
        }
        return array_values($groups);
    }
}
