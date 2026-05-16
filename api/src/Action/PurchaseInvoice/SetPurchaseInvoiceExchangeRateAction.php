<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SetPurchaseInvoiceExchangeRateAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);

        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $rate = isset($body['exchange_rate']) ? (float) $body['exchange_rate'] : null;
        $rateDate = isset($body['exchange_rate_date']) ? trim((string) $body['exchange_rate_date']) : null;

        if ($rate === null || $rate <= 0) {
            return Json::error($response, 'validation_failed', 'exchange_rate musí být kladné číslo.', 400);
        }

        if ($rateDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rateDate)) {
            return Json::error($response, 'invalid_date', 'Neplatný formát datumu (očekáván YYYY-MM-DD).', 400);
        }

        $this->repo->setExchangeRate($id, $rate, $rateDate ?? $existing['issue_date']);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.exchange_rate_set', $user['id'] ?? null, 'purchase_invoice', $id, [
            'rate'      => $rate,
            'rate_date' => $rateDate ?? $existing['issue_date'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
