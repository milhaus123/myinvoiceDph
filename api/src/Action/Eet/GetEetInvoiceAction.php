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
 * GET /api/eet/invoice/{invoiceId}
 *
 * Get all EET sessions for a specific invoice.
 */
final class GetEetInvoiceAction
{
    public function __construct(
        private readonly EetSessionRepository $eetRepo,
        private readonly InvoiceRepository $invoiceRepo,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $invoiceId = (int) ($args['invoiceId'] ?? 0);
        if ($invoiceId <= 0) {
            return Json::error($response, 'invalid_invoice', 'Invalid invoice ID.', 400);
        }

        $invoice = $this->invoiceRepo->find($invoiceId);
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Verify supplier ownership
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ((int) ($invoice['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $sessions = $this->eetRepo->findByInvoiceId($invoiceId);

        // Return enriched data
        return Json::ok($response, [
            'invoice_id' => $invoiceId,
            'eet_required' => in_array($invoice['payment_type'] ?? null, ['cash', 'card'], true),
            'payment_type' => $invoice['payment_type'] ?? null,
            'sessions' => $sessions,
            'latest_session' => $sessions[0] ?? null,
        ]);
    }
}
