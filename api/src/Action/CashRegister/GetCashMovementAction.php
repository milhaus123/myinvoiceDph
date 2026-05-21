<?php

declare(strict_types=1);

namespace MyInvoice\Action\CashRegister;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CashRegisterRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetCashMovementAction
{
    public function __construct(
        private readonly CashRegisterRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $id = (int) ($args['id'] ?? 0);

        $movement = $this->repo->find($id, $sid);
        if ($movement === null) {
            return Json::error($response, 'not_found', 'Pohyb nenalezen.', 404);
        }

        return Json::ok($response, $movement);
    }
}
