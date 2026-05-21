<?php

declare(strict_types=1);

namespace MyInvoice\Action\CashRegister;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CashRegisterRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UpdateCashMovementAction
{
    public function __construct(
        private readonly CashRegisterRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $id = (int) ($args['id'] ?? 0);

        $existing = $this->repo->find($id, $sid);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Pohyb nenalezen.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        if (isset($body['movement_type']) && !in_array($body['movement_type'], ['income', 'expense'], true)) {
            return Json::error($response, 'validation_failed', 'movement_type musí být income nebo expense.', 400);
        }
        if (isset($body['amount']) && (float) $body['amount'] <= 0) {
            return Json::error($response, 'validation_failed', 'amount musí být kladné číslo.', 400);
        }

        try {
            $this->repo->update($id, $sid, $body);
        } catch (\Throwable $e) {
            return Json::error($response, 'update_failed', 'Nelze aktualizovat: ' . $e->getMessage(), 500);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('cash_register.movement_updated', $user['id'] ?? null, 'cash_register_movement', $id, $body, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $sid));
    }
}
