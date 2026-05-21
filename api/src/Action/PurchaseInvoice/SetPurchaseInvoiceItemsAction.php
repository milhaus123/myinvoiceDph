<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SetPurchaseInvoiceItemsAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
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
            return Json::error($response, 'not_editable', 'Položky lze měnit pouze u rozpracované faktury.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $items = (array) ($body['items'] ?? []);

        $this->repo->replaceItems($id, $items);
        $this->calc->recompute($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.items_updated', $user['id'] ?? null, 'purchase_invoice', $id, [
            'item_count' => count($items),
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
