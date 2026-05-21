<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Repository\EetSessionRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/eet/check/{uuid}
 *
 * Check the status of an EET session by UUID.
 */
final class CheckEetAction
{
    public function __construct(
        private readonly EetSessionRepository $eetRepo,
        private readonly InvoiceRepository $invoiceRepo,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $uuid = trim((string) ($args['uuid'] ?? ''));
        if ($uuid === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return Json::error($response, 'invalid_uuid', 'Invalid UUID format.', 400);
        }

        $session = $this->eetRepo->findByUuid($uuid);
        if ($session === null) {
            return Json::error($response, 'not_found', 'EET session not found.', 404);
        }

        // Verify supplier ownership via the linked invoice
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $invoice = $this->invoiceRepo->find((int) $session['invoice_id']);
        if ($invoice === null || (int) ($invoice['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'EET session not found.', 404);
        }

        return Json::ok($response, $session);
    }
}
