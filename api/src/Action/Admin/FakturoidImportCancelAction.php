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
 * POST /api/admin/fakturoid-import/cancel
 *
 * Zruší queued import job.
 *
 * Body (JSON):
 *   job_id  int  ID jobu k zrušení
 */
final class FakturoidImportCancelAction
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
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $body  = (array)($request->getParsedBody() ?? []);
        $jobId = (int)($body['job_id'] ?? 0);

        if ($jobId <= 0) {
            return Json::error($response, 'missing_job_id', 'Parametr job_id je povinný.', 400);
        }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT id, status FROM fakturoid_import_jobs WHERE id = ? AND supplier_id = ? LIMIT 1"
        );
        $stmt->execute([$jobId, $supplierId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$job) {
            return Json::error($response, 'not_found', 'Job nebyl nalezen.', 404);
        }

        if ($job['status'] !== 'queued') {
            return Json::error($response, 'cannot_cancel',
                "Job nelze zrušit — aktuální stav: {$job['status']}. Zrušit lze pouze queued joby.",
                409);
        }

        $pdo->prepare(
            "UPDATE fakturoid_import_jobs SET status = 'cancelled', updated_at = NOW() WHERE id = ?"
        )->execute([$jobId]);

        error_log(sprintf(
            '[FakturoidImport] Job cancelled: job_id=%d, user=%d, supplier=%d',
            $jobId,
            $user['id'] ?? 0,
            $supplierId
        ));

        return Json::ok($response, [
            'job_id'  => $jobId,
            'status'  => 'cancelled',
            'message' => 'Import byl zrušen.',
        ]);
    }
}
