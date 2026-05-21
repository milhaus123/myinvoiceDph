<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\EetSessionRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\EetService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/eet/submit
 *
 * Submit a receipt to EET (Elektronická evidence tržeb).
 *
 * Body params:
 *   invoice_id   (required) - ID of the invoice to submit
 *   sale_date    (optional) - Date of sale (defaults to paid_at or tax_date)
 *   payment_mode (optional) - cash|card|transfer|other (defaults to invoice.payment_type)
 */
final class SubmitEetAction
{
    public function __construct(
        private readonly EetService $eetService,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly EetSessionRepository $eetRepo,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $invoiceId = (int) ($body['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            return Json::error($response, 'invalid_invoice', 'invoice_id is required.', 400);
        }

        $invoice = $this->invoiceRepo->find($invoiceId);
        if ($invoice === null) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Supplier ownership check
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ((int) ($invoice['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Check if invoice is eligible for EET (must be issued and paid)
        if (!in_array($invoice['status'], ['issued', 'sent', 'reminded', 'paid'], true)) {
            return Json::error($response, 'invalid_state', 'EET lze odeslat jen pro vydanou nebo zaplacenou fakturu.', 409);
        }

        // Get supplier data
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.id, s.company_name, s.ic, s.dic, s.street, s.city, s.zip,
                    s.is_vat_payer
               FROM supplier s
              WHERE s.id = ?'
        );
        $stmt->execute([$sid]);
        $supplier = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$supplier) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }

        // Validate DIC
        if (empty($supplier['dic'])) {
            return Json::error($response, 'missing_dic', 'Pro EET je nutné mít vyplněné DIČ (DIC) v nastavení firmy.', 400);
        }

        // Prepare options
        $opts = [];
        if (!empty($body['sale_date'])) {
            $opts['sale_date'] = (string) $body['sale_date'];
        }
        if (!empty($body['payment_mode'])) {
            $opts['payment_mode'] = (string) $body['payment_mode'];
        }

        try {
            $session = $this->eetService->submitReceipt($invoice, $supplier, $opts);
            return Json::ok($response, $session, 201);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return Json::error($response, 'eet_error', 'Chyba při odesílání do EET: ' . $e->getMessage(), 500);
        }
    }
}
