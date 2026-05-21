<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository for EET sessions (Elektronická evidence tržeb).
 * Stores EET receipt submissions linked to invoices.
 */
final class EetSessionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Find an EET session by UUID.
     */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM eet_sessions WHERE uuid = ?'
        );
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Find all EET sessions for an invoice.
     */
    public function findByInvoiceId(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM eet_sessions WHERE invoice_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the latest EET session for an invoice.
     */
    public function findLatestByInvoiceId(int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM eet_sessions WHERE invoice_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a new EET session (before sending to EET server).
     */
    public function create(array $data): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO eet_sessions
             (invoice_id, uuid, sale_date, total, payment_mode, eet_mode, dic, evidence_mode, status, supplier_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['invoice_id'],
            $data['uuid'],
            $data['sale_date'],
            $data['total'],
            $data['payment_mode'],
            $data['eet_mode'] ?? 0,
            $data['dic'],
            $data['evidence_mode'] ?? 1,
            $data['status'] ?? 'pending',
            $data['supplier_id'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Update EET session with server response.
     */
    public function updateResponse(int $id, array $data): void
    {
        $sets = [];
        $values = [];

        if (array_key_exists('fik', $data)) {
            $sets[] = 'fik = ?';
            $values[] = $data['fik'];
        }
        if (array_key_exists('pkp', $data)) {
            $sets[] = 'pkp = ?';
            $values[] = $data['pkp'];
        }
        if (array_key_exists('bkp', $data)) {
            $sets[] = 'bkp = ?';
            $values[] = $data['bkp'];
        }
        if (array_key_exists('status', $data)) {
            $sets[] = 'status = ?';
            $values[] = $data['status'];
        }
        if (array_key_exists('error_code', $data)) {
            $sets[] = 'error_code = ?';
            $values[] = $data['error_code'];
        }
        if (array_key_exists('error_message', $data)) {
            $sets[] = 'error_message = ?';
            $values[] = $data['error_message'];
        }
        if (array_key_exists('confirmed_at', $data)) {
            $sets[] = 'confirmed_at = ?';
            $values[] = $data['confirmed_at'];
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $id;
        $sql = 'UPDATE eet_sessions SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * List EET sessions for a supplier with optional filtering.
     */
    public function listForSupplier(int $supplierId, ?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $pdo = $this->db->pdo();
        $where = 'WHERE supplier_id = ?';
        $params = [$supplierId];

        if ($status !== null) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        $sql = "SELECT * FROM eet_sessions {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
