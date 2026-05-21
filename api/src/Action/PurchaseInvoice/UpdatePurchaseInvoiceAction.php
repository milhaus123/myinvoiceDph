<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdatePurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);

        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        if ($existing['status'] !== 'draft') {
            return Json::error($response, 'not_editable', 'Lze editovat pouze rozpracovanou fakturu.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        // Supplier must still belong to us
        $supplierId = (int) ($body['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'validation_failed', 'supplier_id je povinné.', 400);
        }

        $supplier = $this->clients->find($supplierId);
        if (!SupplierGuard::owns($request, $supplier)) {
            return Json::error($response, 'supplier_not_found', 'Dodavatel neexistuje.', 400);
        }

        try {
            $this->repo->updateDraft($id, $body);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.updated', $user['id'] ?? null, 'purchase_invoice', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
