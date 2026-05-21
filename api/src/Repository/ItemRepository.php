<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Repository pro skladové položky a pohyby na skladě.
 *
 * Tabulky:
 *   items           — skladové položky (sku, název, stav, minimum)
 *   stock_movements — všechny pohyby (stock_in / stock_out / adjustment)
 */
final class ItemRepository
{
    public function __construct(private readonly Connection $db) {}

    // -------------------------------------------------------------------------
    // Items
    // -------------------------------------------------------------------------

    /**
     * Najde jednu položku podle ID.
     */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, sku, name, description, unit,
                    stock_quantity, min_stock_alert,
                    created_at, updated_at
               FROM items
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $this->cast($row);
    }

    /**
     * Najde položku podle SKU.
     */
    public function findBySku(string $sku, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, sku, name, description, unit,
                    stock_quantity, min_stock_alert,
                    created_at, updated_at
               FROM items
              WHERE sku = ? AND supplier_id = ?'
        );
        $stmt->execute([$sku, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return $this->cast($row);
    }

    /**
     * Seznam všech položek s úrovní skladových zásob.
     * Řazeno podle $sort: name | sku | stock_quantity | updated_at
     */
    public function list(int $supplierId, string $sort = 'name', string $direction = 'asc'): array
    {
        $allowedSorts = ['name' => 'i.name', 'sku' => 'i.sku', 'stock_quantity' => 'i.stock_quantity', 'updated_at' => 'i.updated_at'];
        $orderCol = $allowedSorts[$sort] ?? 'i.name';
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id, sku, name, description, unit,
                    stock_quantity, min_stock_alert,
                    created_at, updated_at
               FROM items i
              WHERE i.supplier_id = ?
              ORDER BY {$orderCol} {$dir}"
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Položky pod minimální úrovní skladu.
     */
    public function listLowStock(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, sku, name, description, unit,
                    stock_quantity, min_stock_alert,
                    created_at, updated_at
               FROM items
              WHERE supplier_id = ?
                AND stock_quantity < min_stock_alert
              ORDER BY stock_quantity ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r) => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Vytvoří novou položku.
     * Vrací ID nové položky.
     *
     * @throws \InvalidArgumentException když SKU již existuje.
     */
    public function create(array $data, int $supplierId): int
    {
        $pdo = $this->db->pdo();

        // Unikátnost SKU per supplier
        $check = $pdo->prepare('SELECT 1 FROM items WHERE sku = ? AND supplier_id = ?');
        $check->execute([trim((string) $data['sku']), $supplierId]);
        if ($check->fetch() !== false) {
            throw new \InvalidArgumentException("SKU '{$data['sku']}' již existuje.");
        }

        $stmt = $pdo->prepare(
            'INSERT INTO items (supplier_id, sku, name, description, unit, stock_quantity, min_stock_alert)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            trim((string) $data['sku']),
            trim((string) $data['name']),
            $data['description'] ?? null,
            trim((string) ($data['unit'] ?? 'ks')),
            (float) ($data['stock_quantity'] ?? 0),
            (float) ($data['min_stock_alert'] ?? 0),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Aktualizuje existující položku (kromě stock_quantity — na to jsou stock pohyby).
     *
     * @throws \InvalidArgumentException když SKU mění na již existující.
     */
    public function update(int $id, array $data, int $supplierId): void
    {
        $pdo = $this->db->pdo();

        // Ověř vlastnictví
        $existing = $this->find($id, $supplierId);
        if ($existing === null) {
            throw new \InvalidArgumentException("Položka #$id nenalezena.");
        }

        // Pokud se mění SKU, ověř unikátnost
        $newSku = trim((string) ($data['sku'] ?? $existing['sku']));
        if ($newSku !== $existing['sku']) {
            $check = $pdo->prepare('SELECT 1 FROM items WHERE sku = ? AND supplier_id = ? AND id != ?');
            $check->execute([$newSku, $supplierId, $id]);
            if ($check->fetch() !== false) {
                throw new \InvalidArgumentException("SKU '$newSku' již existuje.");
            }
        }

        $stmt = $pdo->prepare(
            'UPDATE items SET
                sku = ?, name = ?, description = ?, unit = ?, min_stock_alert = ?
             WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $newSku,
            trim((string) $data['name']),
            $data['description'] ?? null,
            trim((string) ($data['unit'] ?? $existing['unit'])),
            (float) ($data['min_stock_alert'] ?? $existing['min_stock_alert']),
            $id,
            $supplierId,
        ]);
    }

    /**
     * Smaže položku (CASCADE smaže i stock_movements).
     */
    public function delete(int $id, int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM items WHERE id = ? AND supplier_id = ?'
        )->execute([$id, $supplierId]);
    }

    // -------------------------------------------------------------------------
    // Stock movements
    // -------------------------------------------------------------------------

    /**
     * Přidá množství na sklad (stock_in).
     */
    public function stockIn(
        int     $itemId,
        float   $quantity,
        int     $supplierId,
        string  $note,
        ?string $referenceType = null,
        ?int    $referenceId   = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('quantity must be positive for stock_in');
        }
        return $this->recordMovement(
            $itemId, $supplierId, 'stock_in', $quantity, $note, $referenceType, $referenceId
        );
    }

    /**
     * Odebere množství ze skladu (stock_out).
     * Neodesílá do záporných hodnot — háže výjimku.
     */
    public function stockOut(
        int     $itemId,
        float   $quantity,
        int     $supplierId,
        string  $note,
        ?string $referenceType = null,
        ?int    $referenceId   = null,
    ): array {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('quantity must be positive for stock_out');
        }

        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('SELECT stock_quantity FROM items WHERE id = ? AND supplier_id = ? FOR UPDATE');
        $stmt->execute([$itemId, $supplierId]);
        $current = (float) $stmt->fetchColumn();
        if ($current === false) {
            throw new \InvalidArgumentException("Položka #$itemId nenalezena.");
        }
        if ($current < $quantity) {
            throw new \InvalidArgumentException(
                "Nedostatečné množství na skladě. Skladem: $current, požadováno: $quantity."
            );
        }

        return $this->recordMovement(
            $itemId, $supplierId, 'stock_out', $quantity, $note, $referenceType, $referenceId
        );
    }

    /**
     * Přímá oprava skladu (adjustment) — nastaví nové množství.
     */
    public function adjust(
        int     $itemId,
        float   $newQuantity,
        int     $supplierId,
        string  $note,
        ?string $referenceType = null,
        ?int    $referenceId   = null,
    ): array {
        if ($newQuantity < 0) {
            throw new \InvalidArgumentException('new quantity cannot be negative');
        }

        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('SELECT stock_quantity FROM items WHERE id = ? AND supplier_id = ? FOR UPDATE');
        $stmt->execute([$itemId, $supplierId]);
        $current = (float) $stmt->fetchColumn();
        if ($current === false) {
            throw new \InvalidArgumentException("Položka #$itemId nenalezena.");
        }

        $delta = $newQuantity - $current;
        if ($delta === 0.0) {
            return ['stock_before' => $current, 'stock_after' => $newQuantity, 'delta' => 0.0];
        }

        return $this->recordMovement(
            $itemId, $supplierId, 'adjustment', abs($delta), $note, $referenceType, $referenceId
        );
    }

    /**
     * Historie pohybů pro jednu položku.
     */
    public function stockHistory(int $itemId, int $supplierId, int $limit = 100, int $offset = 0): array
    {
        $item = $this->find($itemId, $supplierId);
        if ($item === null) {
            throw new \InvalidArgumentException("Položka #$itemId nenalezena.");
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT sm.id, sm.item_id, sm.movement_type, sm.quantity,
                    sm.stock_before, sm.stock_after,
                    sm.reference_type, sm.reference_id, sm.note, sm.created_at
               FROM stock_movements sm
              WHERE sm.item_id = ? AND sm.supplier_id = ?
              ORDER BY sm.created_at DESC
              LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $itemId, PDO::PARAM_INT);
        $stmt->bindValue(2, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $r) => $this->castMovement($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function recordMovement(
        int     $itemId,
        int     $supplierId,
        string  $movementType,
        float   $quantity,
        string  $note,
        ?string $referenceType,
        ?int    $referenceId,
    ): array {
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT stock_quantity FROM items WHERE id = ? AND supplier_id = ? FOR UPDATE');
            $stmt->execute([$itemId, $supplierId]);
            $stockBefore = (float) $stmt->fetchColumn();
            if ($stmt->errorCode() !== '00000' && $stockBefore === 0.0) {
                // fetchColumn vrátí "0" i když řádek neexistuje — zkontrolujeme přes rowCount
                // Raději ověříme, že dotaz ne-selhal
            }
            // Ověř že položka existuje
            if ($stmt->rowCount() === 0) {
                throw new \InvalidArgumentException("Položka #$itemId nenalezna.");
            }

            $stockAfter = match ($movementType) {
                'stock_in'   => $stockBefore + $quantity,
                'stock_out'  => $stockBefore - $quantity,
                'adjustment' => $stockBefore + $quantity,
                default => throw new \LogicException("Unknown movement_type: $movementType"),
            };

            $upd = $pdo->prepare('UPDATE items SET stock_quantity = ? WHERE id = ?');
            $upd->execute([$stockAfter, $itemId]);

            $ins = $pdo->prepare(
                'INSERT INTO stock_movements
                    (item_id, supplier_id, movement_type, quantity, stock_before, stock_after,
                     reference_type, reference_id, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $itemId, $supplierId, $movementType, $quantity,
                $stockBefore, $stockAfter,
                $referenceType, $referenceId, $note ?: null,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'item_id'       => $itemId,
            'movement_type' => $movementType,
            'quantity'      => $quantity,
            'stock_before'  => $stockBefore,
            'stock_after'   => $stockAfter,
            'reference_type'=> $referenceType,
            'reference_id'  => $referenceId,
            'note'          => $note,
        ];
    }

    private function cast(array $row): array
    {
        $row['id']              = (int) $row['id'];
        $row['supplier_id']     = (int) $row['supplier_id'];
        $row['stock_quantity']  = (float) $row['stock_quantity'];
        $row['min_stock_alert'] = (float) $row['min_stock_alert'];
        return $row;
    }

    private function castMovement(array $row): array
    {
        $row['id']           = (int) $row['id'];
        $row['item_id']      = (int) $row['item_id'];
        $row['quantity']     = (float) $row['quantity'];
        $row['stock_before'] = (float) $row['stock_before'];
        $row['stock_after']  = (float) $row['stock_after'];
        $row['reference_id'] = $row['reference_id'] !== null ? (int) $row['reference_id'] : null;
        return $row;
    }
}
