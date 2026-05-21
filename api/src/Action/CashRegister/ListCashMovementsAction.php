<?php

declare(strict_types=1);

namespace MyInvoice\Action\CashRegister;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CashRegisterRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListCashMovementsAction
{
    public function __construct(
        private readonly CashRegisterRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $q = $request->getQueryParams();

        $filters = [
            'movement_type' => isset($q['movement_type']) ? trim((string) $q['movement_type']) : '',
            'category'     => isset($q['category']) ? trim((string) $q['category']) : '',
            'client_id'    => isset($q['client_id']) ? (int) $q['client_id'] : 0,
            'date_from'    => isset($q['date_from']) ? trim((string) $q['date_from']) : '',
            'date_to'      => isset($q['date_to']) ? trim((string) $q['date_to']) : '',
            'q'            => isset($q['q']) ? trim((string) $q['q']) : '',
        ];

        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? 50)));

        // categories
        $categories = $this->repo->categories($sid);

        $result = $this->repo->list($sid, $filters, $page, $perPage);
        $result['categories'] = $categories;

        return Json::ok($response, $result);
    }
}
