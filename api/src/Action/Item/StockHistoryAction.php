<?php

declare(strict_types=1);

namespace MyInvoice\Action\Item;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ItemRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class StockHistoryAction
{
    public function __construct(private readonly ItemRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $id = (int) ($args['id'] ?? 0);

        $q = $request->getQueryParams();
        $limit  = min(500, max(1, (int) ($q['limit']  ?? 100)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        try {
            $history = $this->repo->stockHistory($id, $supplierId, $limit, $offset);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'not_found', $e->getMessage(), 404);
        }

        $item = $this->repo->find($id, $supplierId);

        return Json::ok($response, [
            'item'    => $item,
            'history' => $history,
            'meta'    => ['limit' => $limit, 'offset' => $offset],
        ]);
    }
}
