<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class CashRegisterRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT crm.*, cur.code AS currency_code, cur.symbol AS currency_symbol,
                    c.company_name AS client_name,
                    p.name AS project_name, p.project_number
               FROM cash_register_movements crm
               JOIN currencies cur ON cur.id = crm.currency_id
               LEFT JOIN clients  c  ON c.id  = crm.client_id
               LEFT JOIN projects p  ON p.id  = crm.project_id
              WHERE crm.id = ? AND crm.supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->cast($row) : null;
    }

    public function list(
        int $supplierId,
        array $filters = [],
        int $page = 1,
        int $perPage = 50,
    ): array {
        $where = ['crm.supplier_id = ?'];
        $params = [$supplierId];

        if (!empty($filters['movement_type']) && in_array($filters['movement_type'], ['income', 'expense'], true)) {
            $where[] = 'crm.movement_type = ?';
            $params[] = $filters['movement_type'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'crm.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'crm.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'crm.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'crm.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['q'])) {
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(crm.description LIKE ? OR crm.category LIKE ?)';
            $params[] = "%$q%";
            $params[] = "%$q%";
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM cash_register_movements crm WHERE $whereSql");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $sql = "SELECT crm.*, cur.code AS currency_code, cur.symbol AS currency_symbol,
                       c.company_name AS client_name,
                       p.name AS project_name, p.project_number
                  FROM cash_register_movements crm
                  JOIN currencies cur ON cur.id = crm.currency_id
                  LEFT JOIN clients c ON c.id = crm.client_id
                  LEFT JOIN projects p ON p.id = crm.project_id
                 WHERE $whereSql
                 ORDER BY crm.created_at DESC, crm.id DESC
                 LIMIT ? OFFSET ?";
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => array_map(fn (array $r) => $this->cast($r), $rows),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => $total > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ];
    }

    public function create(int $supplierId, array $data): int
    {
        $currencyId = (int) ($data['currency_id'] ?? $this->defaultCurrencyId($supplierId));

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO cash_register_movements
                (supplier_id, movement_type, amount, currency_id, description, category, client_id, project_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $data['movement_type'],
            (float) $data['amount'],
            $currencyId,
            trim((string) ($data['description'] ?? '')),
            trim((string) ($data['category'] ?? '')),
            !empty($data['client_id']) ? (int) $data['client_id'] : null,
            !empty($data['project_id']) ? (int) $data['project_id'] : null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function update(int $id, int $supplierId, array $data): void
    {
        $fields = [];
        $params = [];

        if (isset($data['movement_type'])) {
            $fields[] = 'movement_type = ?';
            $params[] = $data['movement_type'];
        }
        if (isset($data['amount'])) {
            $fields[] = 'amount = ?';
            $params[] = (float) $data['amount'];
        }
        if (array_key_exists('currency_id', $data)) {
            $fields[] = 'currency_id = ?';
            $params[] = (int) $data['currency_id'];
        }
        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[] = trim((string) $data['description']);
        }
        if (array_key_exists('category', $data)) {
            $fields[] = 'category = ?';
            $params[] = trim((string) $data['category']);
        }
        if (array_key_exists('client_id', $data)) {
            $fields[] = 'client_id = ?';
            $params[] = !empty($data['client_id']) ? (int) $data['client_id'] : null;
        }
        if (array_key_exists('project_id', $data)) {
            $fields[] = 'project_id = ?';
            $params[] = !empty($data['project_id']) ? (int) $data['project_id'] : null;
        }

        if (empty($fields)) {
            return;
        }

        $params[] = $id;
        $params[] = $supplierId;
        $sql = 'UPDATE cash_register_movements SET ' . implode(', ', $fields) . ' WHERE id = ? AND supplier_id = ?';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM cash_register_movements WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Přehled pro dashboard pokladny.
     * denní zůstatek, měsíční suma příjmů/výdajů.
     */
    public function summary(int $supplierId, int $currencyId): array
    {
        $pdo = $this->db->pdo();

        // Celkový zůstatek (suma všech příjmů - výdajů)
        $stmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN movement_type = \'income\' THEN amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN movement_type = \'expense\' THEN amount ELSE 0 END), 0) AS total_expense,
                COALESCE(SUM(CASE WHEN movement_type = \'income\' THEN amount ELSE -amount END), 0) AS balance
             FROM cash_register_movements
             WHERE supplier_id = ? AND currency_id = ?'
        );
        $stmt->execute([$supplierId, $currencyId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        // Denní zůstatek dnes
        $stmt2 = $pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN movement_type = \'income\' THEN amount ELSE 0 END), 0) AS daily_income,
                COALESCE(SUM(CASE WHEN movement_type = \'expense\' THEN amount ELSE 0 END), 0) AS daily_expense,
                COALESCE(SUM(CASE WHEN movement_type = \'income\' THEN amount ELSE -amount END), 0) AS daily_balance
             FROM cash_register_movements
             WHERE supplier_id = ? AND currency_id = ? AND DATE(created_at) = CURDATE()'
        );
        $stmt2->execute([$supplierId, $currencyId]);
        $daily = $stmt2->fetch(PDO::FETCH_ASSOC);

        // Měsíční suma příjmů a výdajů (aktuální měsíc)
        $stmt3 = $pdo->prepare(
            'SELECT
                COALESCE(SUM(CASE WHEN movement_type = \'income\' THEN amount ELSE 0 END), 0) AS month_income,
                COALESCE(SUM(CASE WHEN movement_type = \'expense\' THEN amount ELSE 0 END), 0) AS month_expense,
                COALESCE(SUM(CASE WHEN movement_type = \'income\' THEN amount ELSE -amount END), 0) AS month_balance
             FROM cash_register_movements
             WHERE supplier_id = ? AND currency_id = ?
               AND YEAR(created_at) = YEAR(CURDATE())
               AND MONTH(created_at) = MONTH(CURDATE())'
        );
        $stmt3->execute([$supplierId, $currencyId]);
        $monthly = $stmt3->fetch(PDO::FETCH_ASSOC);

        return [
            'total_income'   => (float) $totals['total_income'],
            'total_expense'  => (float) $totals['total_expense'],
            'balance'        => (float) $totals['balance'],
            'daily_income'   => (float) $daily['daily_income'],
            'daily_expense'  => (float) $daily['daily_expense'],
            'daily_balance'  => (float) $daily['daily_balance'],
            'month_income'   => (float) $monthly['month_income'],
            'month_expense'  => (float) $monthly['month_expense'],
            'month_balance'  => (float) $monthly['month_balance'],
        ];
    }

    public function categories(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM cash_register_categories
              WHERE supplier_id = ? AND is_active = 1
              ORDER BY name'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createCategory(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO cash_register_categories (supplier_id, name) VALUES (?, ?)'
        );
        $stmt->execute([$supplierId, trim($name)]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function defaultCurrencyId(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        return (int) ($stmt->fetchColumn() ?: 1);
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['amount'] = (float) $row['amount'];
        $row['currency_id'] = (int) $row['currency_id'];
        $row['client_id'] = $row['client_id'] !== null ? (int) $row['client_id'] : null;
        $row['project_id'] = $row['project_id'] !== null ? (int) $row['project_id'] : null;
        return $row;
    }
}
