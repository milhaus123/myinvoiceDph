<?php

declare(strict_types=1);

namespace MyInvoice\Action\Quote;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\QuoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\InvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class QuoteAction
{
    public function __construct(
        private readonly QuoteRepository $quoteRepo,
        private readonly ClientRepository $clients,
        private readonly InvoiceDefaults $defaults,
        private readonly InvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    // GET /api/quotes — list
    public function list(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $filters = [
            'status'     => $q['status'] ?? null,
            'client_id'  => isset($q['client_id']) ? (int) $q['client_id'] : null,
            'year'       => isset($q['year']) ? (int) $q['year'] : null,
            'search'     => $q['search'] ?? null,
            'supplier_id'=> (int) $request->getAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
        ];
        $page = max(1, (int) ($q['page'] ?? 1));

        return Json::ok($response, $this->quoteRepo->list($filters, $page));
    }

    // POST /api/quotes — create
    public function create(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $body = $this->defaults->resolve($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $errors = InvoiceValidation::invoice($body, forQuote: true);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        if (!SupplierGuard::owns($request, $this->clients->find((int) $body['client_id']))) {
            return Json::error($response, 'client_not_found', 'Klient neexistuje.', 400);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        $id = $this->quoteRepo->createDraft($body, $userId);
        $this->quoteRepo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.created', $userId, 'invoice', $id, [
            'client_id' => $body['client_id'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->quoteRepo->find($id), 201);
    }

    // GET /api/quotes/{id} — get
    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $quote = $this->quoteRepo->find($id);
        if (!$quote) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }
        if (!SupplierGuard::owns($request, $quote)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }
        return Json::ok($response, $quote);
    }

    // PUT /api/quotes/{id} — update
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $quote = $this->quoteRepo->find($id);
        if (!$quote) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }
        if (!SupplierGuard::owns($request, $quote)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }
        if ($quote['quote_status'] !== 'draft') {
            return Json::error($response, 'not_editable', 'Jen draft nabídku lze editovat.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $body = $this->defaults->resolve($body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $errors = InvoiceValidation::invoice($body, forQuote: true);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        $this->quoteRepo->update($id, $body);
        $this->quoteRepo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.updated', $user['id'] ?? null, 'invoice', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->quoteRepo->find($id));
    }

    // DELETE /api/quotes/{id}
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $quote = $this->quoteRepo->find($id);
        if (!$quote) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }
        if (!SupplierGuard::owns($request, $quote)) {
            return Json::error($response, 'not_found', 'Nabídka nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!$this->quoteRepo->delete($id)) {
            return Json::error($response, 'cannot_delete', 'Nabídku lze smazat jen ve stavu draft.', 409);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('quote.deleted', $user['id'] ?? null, 'invoice', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }
}
