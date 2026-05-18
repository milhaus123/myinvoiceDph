# MyInvoice.cz — GitHub Copilot Instructions

Czech multi-supplier invoicing system (self-hosted alternative to SaaS billing).

## Project Context

This is a **PHP 8.5 + Vue 3** full-stack application. Key characteristics:

- **Multi-supplier**: Single installation, multiple isolated suppliers (companies/IČ) with data scoping via `X-Supplier-Id` header
- **Czech/Slovak billing**: DPH (VAT) rates, ARES/VIES lookups, QR payments (SPAYD/SEPA EPC), IBAN
- **Session-based auth**: HTTP-only cookies + CSRF tokens, NO JWT
- **Database**: MariaDB 10.6+ with Redis fallback for sessions/cache

## Tech Stack Reference

| Layer | Technology |
|-------|------------|
| Backend | Slim 4, PHP-DI 7, ADR pattern |
| Database | MariaDB, Redis (predis) |
| Frontend | Vue 3.5, TypeScript 5.7, Tailwind CSS 4, Vite 8 |
| PDF | mPDF 8.2 |
| QR Payments | SPAYD (CZK), SEPA EPC (EUR) |
| Email | Symfony Mailer + Twig templates |

## Common Patterns

### API Routes

- Routes defined in `api/src/Routes.php` — **this is the source of truth**
- Auth: Session cookie + `X-CSRF-Token` header
- Multi-supplier: `X-Supplier-Id` header or `?supplier_id=N` query param

### Domain Model

```
supplier (1)
├── supplier_bank_accounts (N)
└── clients (N)
    └── projects (N)
        └── invoices (N)
            ├── invoice_items (N)
            └── work_report (0..1)
```

### Business Logic Locations

| Feature | Location |
|---------|----------|
| Invoice calculations (DPH, totals) | `api/src/Service/Invoice/InvoiceCalculator.php` |
| PDF generation | `api/src/Service/Pdf/InvoicePdfRenderer.php` |
| QR payment generation | `api/src/Service/Qr/QrPaymentGenerator.php` |
| Bank import/matching | `api/src/Service/Bank/` |
| ARES/VIES lookups | `api/src/Service/Ares/` |
| Auth/sessions | `api/src/Service/Auth/` |

### Build Commands

```bash
# Backend tests
cd api && vendor/bin/phpunit

# PHPStan analysis
cd api && vendor/bin/phpstan analyse

# Frontend dev
cd web && pnpm install && pnpm dev

# Frontend production build
cd web && pnpm build
```

## Important Conventions

1. **Multi-supplier data isolation**: Always scope queries by `supplier_id`
2. **Invoice immutability**: After issuing, PDF snapshots supplier/client/bank data
3. **Czech locale first**: UI and PDFs support CZ/EN
4. **Security**: Brute-force protection, TOTP 2FA, CSRF tokens, IP allowlist

## File Locations

| Purpose | Path |
|---------|------|
| API routes | `api/src/Routes.php` |
| Vue pages | `web/src/pages/` |
| Pinia stores | `web/src/stores/` |
| API axios | `web/src/api/` |
| DB migrations | `db/migrations/` |
| Cron scripts | `cmd/*.cmd` (Windows), `cmd/*.sh` (Linux) |
| Email/PDF templates | `api/templates/` |
