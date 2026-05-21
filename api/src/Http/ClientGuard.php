<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Middleware\ClientScopeMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Pomocník pro per-client ownership check.
 *
 *   if (!ClientGuard::owns($request, $invoice)) {
 *       return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
 *   }
 *
 * Vrací 404 (ne 403) — neprozrazuje cizí entity.
 */
final class ClientGuard
{
    /** Současné client_id z X-Client-Id middleware (0 když chybí). */
    public static function currentId(Request $request): int
    {
        return (int) $request->getAttribute(ClientScopeMiddleware::ATTR_CURRENT_ID, 0);
    }

    /**
     * True jen když entity existuje a její client_id se shoduje s current.
     *
     * @param array<string,mixed>|null $entity   Záznam s klíčem `client_id` (např. z repo->find()).
     */
    public static function owns(Request $request, ?array $entity): bool
    {
        if ($entity === null) return false;
        return (int) ($entity['client_id'] ?? 0) === self::currentId($request);
    }
}
