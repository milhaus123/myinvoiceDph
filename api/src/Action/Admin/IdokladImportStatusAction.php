<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/idoklad-import/status?job_id=N
 *
 * Vrátí stav background importu spuštěného přes POST /api/admin/idoklad-import
 * s dry_run=false.
 */
final class IdokladImportStatusAction
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Přístup odepřen.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $jobId      = (int) ($request->getQueryParams()['job_id'] ?? 0);

        if ($jobId <= 0) {
            return Json::error($response, 'missing_job_id', 'Parametr job_id je povinný.', 400);
        }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT status, result, log, error, created_at, updated_at
               FROM idoklad_import_jobs
              WHERE id = ? AND supplier_id = ?
              LIMIT 1"
        );
        $stmt->execute([$jobId, $supplierId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$job) {
            return Json::error($response, 'not_found', 'Job nebyl nalezen.', 404);
        }

        return Json::ok($response, [
            'job_id'     => $jobId,
            'status'     => $job['status'],
            'stats'      => $job['result'] ? json_decode((string) $job['result'], true) : null,
            'log'        => $job['log']    ? json_decode((string) $job['log'],    true) : [],
            'error'      => $job['error'] ?? null,
            'created_at' => $job['created_at'],
            'updated_at' => $job['updated_at'],
        ]);
    }
}
