<?php

declare(strict_types=1);

namespace MyInvoice\Action\Item;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ItemRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CreateItemAction
{
    public function __construct(
        private readonly ItemRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $errors = $this->validate($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        try {
            $id = $this->repo->create($body, $supplierId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }

        $item = $this->repo->find($id, $supplierId);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('item.created', $user['id'] ?? null, 'item', $id, [
            'sku' => $body['sku'], 'name' => $body['name'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $item, 201);
    }

    private function validate(array $body): array
    {
        $errors = [];
        if (empty(trim((string) ($body['sku'] ?? '')))) {
            $errors['sku'] = 'SKU je povinné.';
        }
        if (empty(trim((string) ($body['name'] ?? '')))) {
            $errors['name'] = 'Název je povinný.';
        }
        if (isset($body['min_stock_alert']) && (float) $body['min_stock_alert'] < 0) {
            $errors['min_stock_alert'] = 'Min. sklad nemůže být záporný.';
        }
        if (isset($body['stock_quantity']) && (float) $body['stock_quantity'] < 0) {
            $errors['stock_quantity'] = 'Množství nemůže být záporné.';
        }
        return $errors;
    }
}
