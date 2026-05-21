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

final class DeletePurchaseInvoiceAction
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

        $status = (string) ($existing['status'] ?? '');
        if (!in_array($status, ['draft', 'cancelled'], true)) {
            return Json::error(
                $response,
                'not_deletable',
                'Lze smazat pouze rozpracovanou nebo stornovanou fakturu.',
                409,
            );
        }

        $this->repo->delete($id);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.deleted', $user['id'] ?? null, 'purchase_invoice', $id, [
            'varsymbol' => $existing['varsymbol'] ?? null,
            'status_before' => $status,
            'total' => $existing['total_with_vat'] ?? null,
            'currency' => $existing['currency'] ?? null,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true]);
    }
}
