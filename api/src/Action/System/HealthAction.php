<?php

declare(strict_types=1);

namespace MyInvoice\Action\System;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedisProbe $redis,
        private readonly Config $config,
        private readonly SecretEncryption $crypto,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        // Neautorizované requesty dostanou jen minimální odpověď — bez interních detailů.
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::ok($response, ['status' => 'ok']);
        }

        $warnings = [];
        $keyWarning = $this->crypto->validateKey();
        if ($keyWarning !== null) {
            $warnings[] = [
                'code' => 'secret_encryption_key',
                'message' => $keyWarning,
            ];
        }

        return Json::ok($response,