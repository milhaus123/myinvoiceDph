<?php

declare(strict_types=1);

namespace MyInvoice\Action\CashRegister;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CashRegisterRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CashRegisterSummaryAction
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

        $q = $request->getQueryParams();
        $currencyId = isset($q['currency_id']) ? (int) $q['currency_id'] : $this->defaultCurrencyId($sid);

        $summary = $this->repo->summary($sid, $currencyId);

        return Json::ok($response, $summary);
    }

    private function defaultCurrencyId(int $supplierId): int
    {
        // fallback — použije se v repo, tady jen pro Query param
        return 1;
    }
}
