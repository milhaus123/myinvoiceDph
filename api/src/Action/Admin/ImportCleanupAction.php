<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * DELETE /api/admin/import-cleanup?source=fakturoid|idoklad
 *
 * Hromadné smazání dat importovaných z daného zdroje v rámci aktuálního supplier.
 * Smaže faktury (invoices + invoice_items), přijaté faktury (purchase_invoices)
 * a klienty (pokud nemají vazby na ručně vytvořené záznamy).
 *
 * source=fakturoid → záznamy s fakturoid_id IS NOT NULL
 * source=idoklad   → záznamy s idoklad_id IS NOT NULL
 *
 * Vrací počty smazaných záznamů.
 */
final class ImportCleanupAction
{
    public function __construct(private readonly Connection $db) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $q          = $request->getQueryParams();
        $source     = strtolower(trim((string) ($q['source'] ?? '')));

        if (!in_array($source, ['fakturoid', 'idoklad'], true)) {
            return Json::error($response, 'invalid_source',
                'Parametr source musí být "fakturoid" nebo "idoklad".', 400);
        }

        $col = $source === 'fakturoid' ? 'fakturoid_id' : 'idoklad_id';
        $pdo = $this->db->pdo();

        // Smazat invoice_items přes join — nejdřív položky, pak faktury
        $pdo->prepare(
            "DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE supplier_id = ? AND {$col} IS NOT NULL)"
        )->execute([$supplierId]);

        $stmtInv = $pdo->prepare(
            "DELETE FROM invoices WHERE supplier_id = ? AND {$col} IS NOT NULL"
        );
        $stmtInv->execute([$supplierId]);
        $deletedInvoices = $stmtInv->rowCount();

        // purchase_invoices.supplier_id = ID dodavatele v tabulce clients.
        // Vlastnictví faktury je přes clients.supplier_id = naše firma (viz PurchaseInvoiceRepository).
        // Proto musíme mazat přes JOIN, ne přímým WHERE supplier_id = $supplierId.
        $pdo->prepare(
            "DELETE pii FROM purchase_invoice_items pii
              JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              JOIN clients c ON c.id = pi.supplier_id
             WHERE c.supplier_id = ? AND pi.{$col} IS NOT NULL"
        )->execute([$supplierId]);

        $stmtPi = $pdo->prepare(
            "DELETE pi FROM purchase_invoices pi
              JOIN clients c ON c.id = pi.supplier_id
             WHERE c.supplier_id = ? AND pi.{$col} IS NOT NULL"
        );
        $stmtPi->execute([$supplierId]);
        $deletedPurchase = $stmtPi->rowCount();

        // Klienty mažeme jen ty, kteří nemají žádné zbývající vazby.
        // Musíme zkontrolovat OBOJÍ:
        //   - invoices.client_id       (odběratelé — fakturoid import)
        //   - purchase_invoices.supplier_id (dodavatelé — idoklad import)
        // FK fk_pi_supplier je RESTRICT, takže bez druhé podmínky DELETE selže
        // při smazání dodavatelů, na které stále ukazují ručně zadané purchase_invoices.
        $stmtCl = $pdo->prepare(
            "DELETE c FROM clients c
              WHERE c.supplier_id = ?
                AND c.{$col} IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.client_id = c.id)
                AND NOT EXISTS (SELECT 1 FROM purchase_invoices pi WHERE pi.supplier_id = c.id)"
        );
        $stmtCl->execute([$supplierId]);
        $deletedClients = $stmtCl->rowCount();

        return Json::ok($response, [
            'source'           => $source,
            'deleted_invoices'       => $deletedInvoices,
            'deleted_purchase_invoices' => $deletedPurchase,
            'deleted_clients'  => $deletedClients,
        ]);
    }
}
