<?php

declare(strict_types=1);

namespace MyInvoice\Action\CashRegister;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CashRegisterRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CashRegisterCategoriesAction
{
    public function __construct(
        private readonly CashRegisterRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $categories = $this->repo->categories($sid);
        return Json::ok($response, $categories);
    }
}
