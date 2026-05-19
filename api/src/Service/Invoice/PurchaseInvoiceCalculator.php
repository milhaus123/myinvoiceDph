<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přepočítá sumy přijaté faktury (purchase_invoices) a jejích položek.
 *
 * Zrcadlí InvoiceCalculator, ale operuje nad tabulkami:
 *   purchase_invoices      (hlavička)
 *   purchase_invoice_items (položky)
 *
 * Volat po každém replaceItems():
 *   CreatePurchaseInvoiceAction
 *   UpdatePurchaseInvoiceAction
 *   SetPurchaseInvoiceItemsAction
 *
 * Pravidla výpočtu jsou identická s InvoiceMath::compute().
 */
final class PurchaseInvoiceCalculator
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Přepočítá per-item totals i celkové sumy přijaté faktury.
     *
     * @return array{totals: array, vat_breakdown: array}
     */
    public function recompute(int $invoiceId): array
    {
        $pdo = $this->db->pdo();

        // Načti hlavičku (pro reverse_charge)
        $stmt = $pdo->prepare('SELECT reverse_charge FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            throw new \RuntimeException("Purchase invoice {$invoiceId} not found");
        }
        $reverseCharge = (bool) $header['reverse_charge'];

        // Načti položky
        $stmt = $pdo->prepare(
            'SELECT id, quantity, unit_price_without_vat, vat_rate_snapshot
               FROM purchase_invoice_items
              WHERE purchase_invoice_id = ?
              ORDER BY order_index, id'
        );
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Výpočet (pure funkce, stejná logika jako pro vydané faktury)
        $computed = InvoiceMath::compute($items, $reverseCharge);

        // Persist per-item totals
        $updateItem = $pdo->prepare(
            'UPDATE purchase_invoice_items
                SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
              WHERE id = ?'
        );
        foreach ($items as $i => $item) {
            $r = $computed['items'][$i];
            $updateItem->execute([$r['base'], $r['vat'], $r['with'], (int) $item['id']]);
        }

        // Persist invoice-level totals
        $pdo->prepare(
            'UPDATE purchase_invoices
                SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
              WHERE id = ?'
        )->execute([
            $computed['totals']['without_vat'],
            $computed['totals']['vat'],
            $computed['totals']['with_vat'],
            $invoiceId,
        ]);

        return [
            'totals'        => $computed['totals'],
            'vat_breakdown' => $computed['vat_breakdown'],
        ];
    }
}
