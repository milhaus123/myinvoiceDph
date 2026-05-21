<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/approvals?status=requested|approved|rejected|all&overdue_days=5
 *
 * Vrací faktury filtrované podle approval_status pro admin „Approval inbox".
 * Scope = aktuální supplier (X-Supplier-Id). Default status='requested'.
 *
 * Query:
 *   ?status=requested|approved|rejected|all  (default: 'requested')
 *   ?overdue_days=N  (jen requested starší než N dní bez decize)
 */
final class ApprovalListAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Admin only.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není vybrán dodavatel.', 400);
        }

        $q = $request->getQueryParams();
        $status = (string) ($q['status'] ?? 'requested');
        if (!in_array($status, ['requested', 'approved', 'rejected', 'all'], true)) {
            return Json::error($response, 'invalid_status', 'Status musí být requested|approved|rejected|all.', 422);
        }
        $statusFilter = $status === 'all' ? null : $status;

        $overdueDays = isset($q['overdue_days']) && ctype_digit((string) $q['overdue_days'])
            ? (int) $q['overdue_days']
            : null;

        $rows = $this->repo->listForApprovalInbox(
            supplierId: $supplierId,
            statusFilter: $statusFilter,
            minDaysSince: $overdueDays,
            maxReminders: null,
        );

        return Json::ok($response, ['data' => $rows]);
    }
}
