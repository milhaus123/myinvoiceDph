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

final class TransitionPurchaseInvoiceStatusAction
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
        $newStatus = trim((string) ($body['status'] ?? ''));

        if ($newStatus === '') {
            return Json::error($response, 'validation_failed', 'status je povinný.', 400);
        }

        $allowed = ['received', 'booked', 'paid', 'cancelled'];
        if (!in_array($newStatus, $allowed, true)) {
            return Json::error($response, 'validation_failed', 'Neplatný status: ' . $newStatus, 400);
        }

        try {
            $this->repo->markStatus($id, $newStatus);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'invalid_transition', $e->getMessage(), 409);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.status_changed', $user['id'] ?? null, 'purchase_invoice', $id, [
            'from' => $existing['status'],
            'to'   => $newStatus,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
