# MyInvoice.cz — Coding Agent Skill

This is the source code for [MyInvoice.cz](https://myinvoice.cz/), a Czech self-hosted invoicing system. Forked to add purchase/received invoice support (přijaté faktury) for DPH (VAT) reporting.

## Tech Stack

- **Backend:** PHP 8.5+, Slim 4, Twig 3, MariaDB 11+
- **Frontend:** Vue 3, TypeScript, Vite, Tailwind CSS, Pinia, Vue Router, Axios
- **Database:** MariaDB (InnoDB), migrations in `db/migrations/*.sql`
- **Auth:** Session + CSRF tokens, Redis for sessions (fallback: DB)
- **PDF:** mPDF 8, QR payments: Rikudou/CzQrPayment, SEPA QR
- **Email:** Symfony Mailer

## Project Structure

```
myinvoiceDph/
├── api/                      # PHP backend
│   ├── composer.json
│   ├── phpunit.xml
│   ├── public/index.php      # Entry point
│   └── src/
│       ├── Routes.php        # All REST API routes
│       ├── Bootstrap.php     # DI container setup
│       ├── Action/           # Slim action handlers (one file per endpoint)
│       ├── Entity/           # Doctrine-like entities (NOT used — raw PDO)
│       ├── Repository/       # Data access (InvoiceRepository, ClientRepository, etc.)
│       ├── Service/          # Business logic (InvoiceCalculator, etc.)
│       └── ...
├── web/                      # Vue 3 frontend
│   ├── package.json
│   ├── vite.config.ts
│   ├── src/
│   │   ├── api/             # API client wrappers (invoices.ts, clients.ts, etc.)
│   │   ├── pages/           # Route pages (invoices/, clients/, admin/)
│   │   ├── components/      # Vue components
│   │   ├── composables/     # Vue composables (useFormat, useToast, etc.)
│   │   └── router/index.ts  # Vue Router config
├── db/
│   └── migrations/           # SQL migrations (numbered: 0001_init.sql, etc.)
└── docker-compose.yml       # Full stack (nginx, php-fpm, mariadb, redis)
```

## How to Run

### With Docker (recommended)

```bash
cp cfg.sample.php cfg.local.php  # Edit with your DB credentials
docker-compose up -d
# API: http://localhost:8080/api
# Web:  http://localhost:8080
```

### Local Development

**Backend:**
```bash
cd api
composer install
# Edit cfg.local.php
php -S localhost:8080 -t public/
```

**Frontend:**
```bash
cd web
npm install
npm run dev     # Development server
npm run build   # Production build
```

### Running Tests

```bash
cd api
composer test        # Runs PHPUnit
composer phpstan     # Static analysis
composer cs-fixer    # Code style fix

# Or directly:
vendor/bin/phpunit
vendor/bin/phpstan analyse src --level=5
vendor/bin/php-cs-fixer fix src --dry-run
```

### Database Migrations

```bash
php api/bin/migrate.php   # Run all pending migrations
```

Migrations are simple SQL files numbered sequentially (`0001_init.sql`, `0002_...`). After a major consolidation, all early migrations are merged into `0001_init.sql`.

## Key Conventions

### PHP Backend

- **Framework:** Slim 4 with custom Action classes (one class per HTTP endpoint)
- **Database:** Raw PDO (no ORM). All queries go through `Repository` classes.
- **Response format:** Always `Json::success($data)` or `Json::error($code, $message, $fields)` — single source of truth is `src/Http/Json.php`
- **Errors:** Always wrapped in `{"error": {"code": "...", "message": "...", "fields": {...}}}`
- **Validation:** `Respect\Validation` library
- **Naming:** `InvoiceRepository`, `InvoiceCalculator`, `InvoicePdfRenderer` — services live under `Service/Invoice/`
- **Snapshots:** When an invoice is issued, client/supplier/bank data is snapshotted into JSON columns — these are immutable thereafter
- **No router param IDs in constructor** — actions receive `Request $request, Response $response, array $args`

### Frontend (Vue 3)

- **TypeScript** throughout
- **Composables** for reusable logic (`useFormat`, `useToast`, `useHotkey`)
- **API clients** are modular: `invoices.ts`, `clients.ts`, `codebooks.ts`, etc. — all in `web/src/api/`
- **Formatting:** `useFormat` composable for money, dates, status labels
- **i18n:** `vue-i18n` with CS/EN JSON files in `web/src/i18n/`
- **No class components** — use `<script setup lang="ts">` Composition API throughout

### Database Conventions

- PK: `id BIGINT UNSIGNED AUTO_INCREMENT`
- FK: `<entity>_id BIGINT UNSIGNED`, `ON DELETE RESTRICT`
- Timestamps: `created_at`, `updated_at` with `ON UPDATE CURRENT_TIMESTAMP`
- Soft delete: `archived_at` for clients/projects
- Money: `DECIMAL(12,2)` for CZK/EUR amounts
- Table names: English, snake_case, plural (`invoices`, `clients`)
- **Invoice statuses:** `draft` → `issued` → `sent` → `reminded` → `paid` / `cancelled`
- **Invoice types:** `invoice`, `proforma`, `credit_note`, `cancellation`
- Only `draft` invoices can be edited/deleted; after `issued` they're immutable

### Coding Standards

- **PHP:** PSR-12, strict types (`declare(strict_types=1)`), PHP 8.5+
- **Vue/TS:** Vue 3 Composition API, TypeScript strict mode
- **CSS:** Tailwind CSS utility classes (no custom SCSS unless necessary)
- **Commits:** Conventional commits (`feat:`, `fix:`, `docs:`, `refactor:`)
- **PRs:** Always reference an issue; require code review

## Important Architecture Notes

### Invoice Flow

1. **Draft** → user edits items, due dates, client, etc.
2. **Issue** → varsymbol generated, snapshots taken, status `issued`
3. **Send** → PDF emailed to client, status `sent`
4. **Remind** → payment reminder sent, status `reminded`
5. **Mark Paid** → `paid_at` set, status `paid`

### Multi-Supplier

- `X-Supplier-Id` header scopes all data
- Single-row `supplier` table (id=1 always exists)
- Supplier switching UI in `SupplierSwitcher.vue`

### DPH/VAT Context

- Czech VAT rates: `CZ-21` (21%), `CZ-12` (12%), `CZ-0` (exempt), `CZ-RC` (reverse charge)
- VAT is calculated per line item and summed
- `vat_breakdown` in API response groups totals by rate
- For DPH reporting, **both issued invoices AND received/purchase invoices** are needed — this is what the fork adds

## Useful Commands

```bash
# Database shell (in docker)
docker exec -it myinvoice-mariadb mysql -u root -p myinvoice

# Clear Redis cache
docker exec -it myinvoice-redis redis-cli FLUSHDB

# View API logs
docker logs -f myinvoice-php 2>&1 | grep "POST /api/invoices"

# Rebuild after code change
docker-compose up -d --build
```
