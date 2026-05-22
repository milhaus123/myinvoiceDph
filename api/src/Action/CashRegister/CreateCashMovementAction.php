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

final class CreateCashMovementAction
{
    public function __construct(
        private readonly CashRegisterRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        // Validation
        if (empty($body['movement_type']) || !in_array($body['movement_type'], ['income', 'expense'], true)) {
            return Json::error($response, 'validation_failed', 'Pole movement_type je povinné (income|expense).', 400);
        }
        if (!isset($body['amount']) || (float) $body['amount'] <= 0) {
            return Json::error($response, 'validation_failed', 'Pole amount musí být kladné číslo.', 400);
        }
        if (empty($body['description'])) {
            return Json::error($response, 'validation_failed', 'Popis je povinný.', 400);
        }

        try {
            $id = $this->repo->create($sid, $body);
        } catch (\Throwable $e) {
            return Json::error($response, 'create_failed', 'Nelze vytvořit pohyb: ' . $e->getMessage(), 500);
        }

        $movement = $this->repo->find($id, $sid);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('cash_register.movement_created', $user['id'] ?? null, 'cash_register_movement', $id, [
            'movement_type' => $body['movement_type'],
            'amount' => $body['amount'],
            'category' => $body['category'] ?? '',
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $movement, 201);
    }
}
