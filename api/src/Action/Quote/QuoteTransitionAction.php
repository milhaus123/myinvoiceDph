<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\QuoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Status transitions + convert to invoice for quotes.
 *
 * POST /api/quotes/{id}/transition  — change quote_status
 * POST /api/quotes/{id}/to-invoice — convert approved quote to invoice
 */
final class QuoteTransitionAction
{
    public function __construct(
        private readonly QuoteRepository $quoteRepo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    // POST /api/quotes/{id}/transition
    public function transition(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $quote = $this->quoteRepo->find($id);
        if (!$quote) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }
        if (!SupplierGuard::owns($request, $quote)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $newStatus = $body['status'] ?? null;

        $validTransitions = [
            'draft'    => ['sent'],
            'sent'     => ['approved', 'rejected', 'draft'],
            'approved' => ['rejected'],
            'rejected' => ['draft'],
            // 'converted' is terminal
        ];

        $allowed = $validTransitions[$quote['quote_status']] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            return Json::error($response, 'invalid_transition',
                "Přechod z '{$quote['quote_status']}' na '$newStatus' není povolen.", 409);
        }

        // Rejection reason if rejecting
        if ($newStatus === 'rejected' && !empty($body['reason'])) {
            $pdo = $this->quoteRepo->find($id);
            // Store rejection reason
            $pdo2 = \MyInvoice\Infrastructure\Database\Connection::get()->pdo();
            $stmt = $pdo2->prepare('UPDATE invoices SET quote_rejection_reason = :reason WHERE id = :id');
            $stmt->execute(['reason' => $body['reason'], 'id' => $id]);
        }

        $this->quoteRepo->updateStatus($id, $newStatus);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.status_changed', $user['id'] ?? null, 'invoice', $id, [
            'from' => $quote['quote_status'],
            'to'   => $newStatus,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->quoteRepo->find($id));
    }

    // POST /api/quotes/{id}/to-invoice
    public function toInvoice(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $quote = $this->quoteRepo->find($id);
        if (!$quote) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }
        if (!SupplierGuard::owns($request, $quote)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }
        if ($quote['quote_status'] !== 'approved') {
            return Json::error($response, 'must_be_approved', 'Nabídku lze převést na fakturu jen když je schválená.', 409);
        }
        if ($quote['quote_converted_to_invoice_id']) {
            return Json::error($response, 'already_converted', 'Nabídka již byla převedena na fakturu.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        try {
            $invoiceId = $this->quoteRepo->convertToInvoice($id, $body, $userId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'conversion_failed', $e->getMessage(), 400);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.converted_to_invoice', $userId, 'invoice', $id, [
            'invoice_id' => $invoiceId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        // Fetch the new invoice
        $invoiceRepo = new \MyInvoice\Repository\InvoiceRepository(
            \MyInvoice\Infrastructure\Database\Connection::get()
        );
        $invoice = $invoiceRepo->find($invoiceId);

        return Json::ok($response, [
            'invoice_id' => $invoiceId,
            'invoice'    => $invoice,
        ], 201);
    }
}
