<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListPurchaseInvoicesAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);

        $filters = [
            'q'           => isset($q['q']) ? trim((string) $q['q']) : '',
            'status'      => $filter['status']      ?? null,
            'year'        => $filter['year']        ?? null,
            'month'       => $filter['month']       ?? null,
            'date_from'   => $filter['date_from']   ?? null,
            'date_to'     => $filter['date_to']     ?? null,
            'currency'    => $filter['currency']    ?? null,
            'unpaid_only' => !empty($filter['unpaid_only']),
            'overdue'     => !empty($filter['overdue']),
            'supplier_id' => (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
        ];

        // Status může být čárkou oddělené — split
        if (is_string($filters['status']) && $filters['status'] !== '' && str_contains($filters['status'], ',')) {
            $filters['status'] = explode(',', $filters['status']);
        }

        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.invoices_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));

        return Json::ok($response, $this->repo->listGroupedByMonth($filters, $page, $perPage));
    }
}
