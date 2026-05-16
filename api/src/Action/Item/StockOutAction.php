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

final class StockOutAction
{
    public function __construct(
        private readonly ItemRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $id = (int) ($args['id'] ?? 0);

        $body = (array) ($request->getParsedBody() ?? []);

        $quantity = isset($body['quantity']) ? (float) $body['quantity'] : 0;
        if ($quantity <= 0) {
            return Json::error($response, 'validation_failed', 'quantity musí být kladné číslo.', 400);
        }

        $note          = trim((string) ($body['note'] ?? ''));
        $referenceType = isset($body['reference_type']) ? trim((string) $body['reference_type']) : null;
        $referenceId   = isset($body['reference_id'])  ? (int) $body['reference_id']            : null;

        try {
            $movement = $this->repo->stockOut($id, $quantity, $supplierId, $note, $referenceType, $referenceId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'insufficient_stock', $e->getMessage(), 400);
        }

        $item = $this->repo->find($id, $supplierId);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('item.stock_out', $user['id'] ?? null, 'item', $id, [
            'quantity' => $quantity, 'note' => $note,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'item'     => $item,
            'movement' => $movement,
        ]);
    }
}
