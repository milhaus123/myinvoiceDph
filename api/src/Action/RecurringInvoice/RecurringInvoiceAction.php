<?php

declare(strict_types=1);

namespace MyInvoice\Action\RecurringInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\RecurringInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PeriodicityCalculator;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * REST handlery pro pravidelné vydané faktury.
 *
 *   GET    /api/recurring-invoices             → list
 *   POST   /api/recurring-invoices             → create
 *   GET    /api/recurring-invoices/{id}        → detail
 *   PUT    /api/recurring-invoices/{id}        → update
 *   DELETE /api/recurring-invoices/{id}        → delete
 *   POST   /api/recurring-invoices/{id}/pause   → pause
 *   POST   /api/recurring-invoices/{id}/resume  → resume
 *   POST   /api/recurring-invoices/generate    → generate all due now
 *   POST   /api/recurring-invoices/{id}/run-now → manual run
 *   GET    /api/recurring-invoices/next-runs   → upcoming runs
 *   GET    /api/recurring-invoices/{id}/invoices → generated invoices
 */
final class RecurringInvoiceAction
{
    public function __construct(
        private readonly RecurringInvoiceRepository $repo,
        private readonly InvoiceRepository $invoices,
        private readonly ClientRepository $clients,
        private readonly RecurringInvoiceGenerator $generator,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $q = $request->getQueryParams();
        $filters = [];
        if (!empty($q['status'])) $filters['status'] = (string) $q['status'];

        // Filter by client's supplier ownership
        $clients = $this->clients->list(['supplier_id' => $supplierId]);
        $clientIds = array_column($clients, 'id');
        if (empty($clientIds)) {
            return Json::ok($response, ['data' => []]);
        }

        $rows = $this->repo->list(array_merge($filters, ['client_ids' => $clientIds]));
        return Json::ok($response, ['data' => $rows]);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if ($tpl === null || !SupplierGuard::owns($request, $this->clients->find((int) $tpl['client_id']))) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        return Json::ok($response, $tpl);
    }

    /**
     * GET /api/recurring-invoices/{id}/invoices
     * Vrátí seznam vydaných faktur vygenerovaných z této šablony.
     */
    public function invoices(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if ($tpl === null || !SupplierGuard::owns($request, $this->clients->find((int) $tpl['client_id']))) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $stmt = $this->invoices->listGroupedByMonth(['recurring_template_id' => $id]);
        return Json::ok($response, ['data' => $stmt]);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $clientId = (int) ($body['client_id'] ?? 0);
        $body['client_id'] = $clientId;

        // Verify client belongs to current supplier
        if ($clientId <= 0 || !SupplierGuard::owns($request, $this->clients->find($clientId))) {
            return Json::error($response, 'not_found', 'Odběratel nenalezen.', 404);
        }

        $errors = $this->validate($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        $body['next_run_date'] = $body['next_run_date'] ?? $body['anchor_date'];

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());

        $id = $this->repo->create($body, $userId);
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));

        $this->logger->log('recurring_invoice.created', $userId, 'recurring_invoice_template', $id, [
            'client_id' => $body['client_id'],
            'frequency' => $body['frequency'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if ($tpl === null || !SupplierGuard::owns($request, $this->clients->find((int) $tpl['client_id']))) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $body['client_id'] = (int) $tpl['client_id'];

        $errors = $this->validate($body);
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        $this->repo->update($id, $body);
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring_invoice.updated', $user['id'] ?? null, 'recurring_invoice_template', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if ($tpl === null || !SupplierGuard::owns($request, $this->clients->find((int) $tpl['client_id']))) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }

        $this->repo->delete($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('recurring_invoice.deleted', $user['id'] ?? null, 'recurring_invoice_template', $id, [
            'name' => $tpl['name'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }

    public function pause(Request $request, Response $response, array $args): Response
    {
        return $this->setStatus($request, $response, $args, 'paused', 'recurring_invoice.paused');
    }

    public function resume(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if ($tpl === null || !SupplierGuard::owns($request, $this->clients->find((int) $tpl['client_id']))) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        if (!empty($tpl['end_date']) && (string) $tpl['next_run_date'] > (string) $tpl['end_date']) {
            return Json::error($response, 'expired', 'Šablona vypršela (next_run > end_date).', 409);
        }
        return $this->setStatus($request, $response, $args, 'active', 'recurring_invoice.resumed');
    }

    /**
     * POST /api/recurring-invoices/generate
     * Spustí generování pro všechny splněné šablony (cron helper).
     */
    public function generate(Request $request, Response $response): Response
    {
        $due = $this->repo->findDue();
        $results = [];
        foreach ($due as $tpl) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $userId = (int) ($user['id'] ?? 0);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $ua = $request->getHeaderLine('User-Agent');

            try {
                $result = $this->generator->generate((int) $tpl['id'], null, $userId);
                $this->logger->log('recurring_invoice.generated', $userId, 'recurring_invoice_template', (int) $tpl['id'], [
                    'invoice_id' => $result['invoice_id'],
                ], $ip, $ua);
                $results[] = array_merge($result, ['template_id' => (int) $tpl['id'], 'template_name' => $tpl['name']]);
            } catch (\Throwable $e) {
                $results[] = [
                    'template_id' => (int) $tpl['id'],
                    'template_name' => $tpl['name'],
                    'error' => $e->getMessage(),
                ];
            }
        }
        return Json::ok($response, ['data' => $results]);
    }

    /**
     * POST /api/recurring-invoices/{id}/run-now
     * Manuální spuštění šablony.
     */
    public function runNow(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if ($tpl === null || !SupplierGuard::owns($request, $this->clients->find((int) $tpl['client_id']))) {
            return Json::error($response, 'not_found', 'Šablona nenelezena.', 404);
        }
        if ($tpl['status'] === 'expired') {
            return Json::error($response, 'expired', 'Šablona vypršela.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $forcedIssueDate = !empty($body['issue_date']) ? (string) $body['issue_date'] : null;

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');

        try {
            $result = $this->generator->generate($id, $forcedIssueDate, $userId);
            $this->logger->log('recurring_invoice.generated', $userId, 'recurring_invoice_template', $id, [
                'invoice_id' => $result['invoice_id'],
            ], $ip, $ua);
        } catch (\DomainException $e) {
            return Json::error($response, 'cannot_generate', $e->getMessage(), 409);
        } catch (\Throwable $e) {
            return Json::error($response, 'generation_failed', $e->getMessage(), 500);
        }

        return Json::ok($response, $result, 201);
    }

    /**
     * GET /api/recurring-invoices/next-runs
     * Vrátí nadcházející termíny generování pro aktivní šablony.
     */
    public function nextRuns(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $clients = $this->clients->list(['supplier_id' => $supplierId]);
        $clientIds = array_column($clients, 'id');
        if (empty($clientIds)) {
            return Json::ok($response, ['data' => []]);
        }
        $templates = $this->repo->list(['client_ids' => $clientIds, 'status' => 'active']);
        $runs = PeriodicityCalculator::upcomingRuns($templates, 20);
        return Json::ok($response, ['data' => $runs]);
    }

    private function setStatus(Request $request, Response $response, array $args, string $status, string $action): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $tpl = $this->repo->find($id);
        if ($tpl === null || !SupplierGuard::owns($request, $this->clients->find((int) $tpl['client_id']))) {
            return Json::error($response, 'not_found', 'Šablona nenalezena.', 404);
        }
        $this->repo->setStatus($id, $status);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'recurring_invoice_template', $id, null, $ip, $request->getHeaderLine('User-Agent'));
        return Json::ok($response, $this->repo->find($id));
    }

    /**
     * @return array<string, string[]>
     */
    private function validate(array $data): array
    {
        $err = [];

        if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
            $err['client_id'][] = 'Odběratel je povinný';
        }
        if (empty($data['name']) || trim((string) $data['name']) === '') {
            $err['name'][] = 'Název šablony je povinný';
        }
        $frequency = (string) ($data['frequency'] ?? '');
        if (!in_array($frequency, PeriodicityCalculator::FREQUENCIES, true)) {
            $err['frequency'][] = 'Neplatná periodicita';
        }
        if (empty($data['anchor_date']) || !self::isValidDate((string) $data['anchor_date'])) {
            $err['anchor_date'][] = 'Neplatné datum zahájení';
        }
        if (!empty($data['end_date'])) {
            if (!self::isValidDate((string) $data['end_date'])) {
                $err['end_date'][] = 'Neplatné datum ukončení';
            } elseif (!empty($data['anchor_date']) && (string) $data['end_date'] < (string) $data['anchor_date']) {
                $err['end_date'][] = 'Datum ukončení musí být po zahájení';
            }
        }
        $endOfMonth = !empty($data['end_of_month']);
        $dom = $data['day_of_month'] ?? null;
        if ($endOfMonth && $dom !== null && $dom !== '') {
            $err['day_of_month'][] = 'Nelze kombinovat „poslední den měsíce" a konkrétní den.';
        }
        if (!$endOfMonth && $dom !== null && $dom !== '') {
            $domInt = (int) $dom;
            if ($domInt < 1 || $domInt > 28) {
                $err['day_of_month'][] = 'Den v měsíci musí být 1–28';
            }
        }
        if (empty($data['currency_id']) || (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatná měna';
        }
        $paymentMethod = (string) ($data['payment_method'] ?? 'bank_transfer');
        if (!in_array($paymentMethod, ['bank_transfer', 'card', 'cash', 'other'], true)) {
            $err['payment_method'][] = 'Neplatný způsob úhrady';
        }

        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $err['items'][] = 'Šablona musí mít alespoň jednu položku';
        } else {
            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) { $err["items.{$i}"][] = 'Neplatná položka'; continue; }
                $err = array_merge($err, $this->validateItem($item, $i));
            }
        }

        // Basic amount check
        if (!empty($items) && is_array($items)) {
            $hasPositive = false;
            foreach ($items as $item) {
                $qty = (float) ($item['quantity'] ?? 1);
                $price = (float) ($item['unit_price_without_vat'] ?? 0);
                if ($qty > 0 && $price > 0) { $hasPositive = true; break; }
            }
            if (!$hasPositive) {
                $err['items'][] = 'Alespoň jedna položka musí mít kladné množství i cenu';
            }
        }

        return $err;
    }

    /**
     * @return array<string, string[]>
     */
    private function validateItem(array $item, int $index): array
    {
        $err = [];
        $prefix = "items.{$index}";
        if (empty($item['description']) || trim((string) $item['description']) === '') {
            $err["{$prefix}.description"][] = 'Popis položky je povinný';
        }
        if (!isset($item['vat_rate_id']) || (int) $item['vat_rate_id'] <= 0) {
            $err["{$prefix}.vat_rate_id"][] = 'Sazba DPH je povinná';
        }
        return $err;
    }

    private static function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
