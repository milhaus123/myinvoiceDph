<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetPurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);

        // purchase_invoices.supplier_id = client.vendor_id, not tenant supplier_id
        // Owner check: verify the vendor's client record belongs to current supplier
        $clientSupplierId = (int) ($invoice['client_supplier_id'] ?? 0);
        $currentId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($invoice === null || $clientSupplierId !== $currentId) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        return Json::ok($response, $invoice);
    }
}
