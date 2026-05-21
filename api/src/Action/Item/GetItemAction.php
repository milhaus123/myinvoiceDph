<?php

declare(strict_types=1);

namespace MyInvoice\Action\Item;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ItemRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetItemAction
{
    public function __construct(private readonly ItemRepository $repo) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $id = (int) ($args['id'] ?? 0);

        $item = $this->repo->find($id, $supplierId);
        if ($item === null) {
            return Json::error($response, 'not_found', "Položka #$id nenalezena.", 404);
        }
        return Json::ok($response, $item);
    }
}
