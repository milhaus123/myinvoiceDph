# MyInvoice.cz — AI Coding Agent Instructions

> Czech multi-supplier invoicing system for freelancers and small businesses. Self-hosted, open-source alternative to SaaS billing services.

## Quick Start Commands

```bash
# Backend tests
cd api && vendor/bin/phpunit

# PHPStan static analysis
cd api && vendor/bin/phpstan analyse

# Frontend build
cd web && pnpm install && pnpm build

# Docker development
docker compose up -d
```

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.5 (Slim 4 + ADR pattern, PHP-DI 7) |
| Database | MariaDB 10.6+ |
| Cache/Sessions | Redis (predis 3) with MariaDB MEMORY fallback |
| Frontend | Vue 3.5 + TypeScript 5.7 + Vite 8 + Tailwind CSS 4 |
| PDF | mPDF 8.2 |
| QR Payments | SPAYD (CZK), SEPA EPC (EUR) |
| Email | Symfony Mailer 8 + Twig 3.10 |
| Auth | Session-based (HTTP-only cookie) + TOTP 2FA |

## Project Structure

```
api/src/
├── Action/          # ADR pattern - one invokable per endpoint
│   ├── Auth/        # Login, Logout, Forgot, Reset, TOTP
│   ├── Client/      # CRUD + archive
│   ├── Project/     # CRUD + archive
│   ├── Invoice/     # Full lifecycle + PDF + email
│   ├── WorkReport/  # Work reports linked to invoices
│   └── Supplier/    # Multi-supplier settings
├── Domain/          # Entities + value objects
├── Repository/      # PDO repositories (one per aggregate)
├── Service/        # Business logic (Invoice, Pdf, Qr, Mail, Ares)
├── Middleware/     # IpAllowlist, Auth, Csrf, RateLimit, ErrorHandler
└── Infrastructure/ # Database, Cache, Config

web/src/
├── pages/          # Route pages (Login, Dashboard, Clients, Invoices, etc.)
├── components/    # Reusable UI components
├── stores/       # Pinia stores (auth, clients, invoices, etc.)
├── api/          # Axios wrapper + endpoint functions
└── composables/   # Vue composables

db/migrations/     # Laravel-style migrations
cmd/              # Cron scripts, Docker scripts, publish scripts
```

## Key Patterns & Conventions

### Multi-Supplier Architecture
- All data scoped by `supplier_id` (X-Supplier-Id header or ?supplier_id=N)
- Single installation, multiple isolated suppliers (companies/IČ)
- Supplier switcher in top navbar
- Each supplier has own bank accounts, invoice number series, clients, projects

### API Conventions
- Base: `/api` (JSON, UTF-8, ISO 8601 dates)
- Auth: Session cookie + `X-CSRF-Token` header for mutations
- All routes defined in `api/src/Routes.php` — source of truth
- Response format: consistent error structure `{error: string, code?: string}`

### Czech/Slovak Business Context
- **DPH** (VAT): 21%, 15%, 10% rates; reverse charge
- **IČO** (Company ID): 8 digits, ARES lookup
- **DIČ** (VAT ID): CZ + 8-10 digits, VIES validation
- **Variabilní symbol**: invoice identifier for bank matching
- **QR platby**: SPAYD format for CZK, SEPA EPC for EUR
- **IBAN/FIK**: Payment reference formats

### Security Patterns
- Brute-force protection: 5 fails/5min → CAPTCHA, 30 fails/hour → 24h lockout
- Passwords: bcrypt cost 12, peppered (APP_PEPPER env)
- CSRF: token in X-CSRF-Token header
- IP allowlist: IPv4/IPv6 + CIDR support
- TOTP 2FA for admin users

## Common Tasks

### Adding a new API endpoint
1. Create Action class in `api/src/Action/{Resource}/`
2. Register route in `api/src/Routes.php`
3. Add repository method if needed in `api/src/Repository/`
4. Add service logic in `api/src/Service/`
5. Add frontend API call in `web/src/api/`

### Adding a new database field
1. Create migration in `db/migrations/` (see existing format)
2. Update Entity in `api/src/Domain/`
3. Update Repository if needed
4. Add validation in `api/src/Service/Validation/`
5. Update frontend form if needed

### PDF/Email templates
- Templates: `api/templates/invoice/` and `api/templates/email/`
- CSS for PDF: `api/templates/invoice/pdf.css`
- Use DejaVu Sans font for PDF compatibility

## Important Files

| File | Purpose |
|------|---------|
| `api/src/Routes.php` | API route definitions (source of truth) |
| `api/src/Domain/Invoice.php` | Invoice entity with all business logic |
| `api/src/Service/Invoice/InvoiceCalculator.php` | Tax/DPH calculations |
| `api/src/Service/Pdf/InvoicePdfRenderer.php` | PDF generation |
| `api/src/Service/Qr/QrPaymentGenerator.php` | QR payment generation |
| `web/src/stores/invoices.ts` | Invoice Pinia store |
| `web/src/pages/invoices/InvoiceEditor.vue` | Invoice edit page |

## Development Tips

- PHP backend runs on `http://localhost:8080/api` (Docker) or IIS/Apache
- Vue frontend on `http://localhost:5173` (Vite dev server)
- Use `?supplier_id=N` or X-Supplier-Id header to scope API requests
- Always validate Czech company data via ARES (IČ lookup)
- Invoice PDF snapshots supplier/client/bank data — invoice is immutable after issue
- Bank import: GPC format (ABO), SHA256 dedupe, auto-matching by VS + amount

## Testing

```bash
# Run all tests
cd api && vendor/bin/phpunit

# Run specific test
cd api && vendor/bin/phpunit --filter=InvoiceCalculatorTest

# PHPStan
cd api && vendor/bin/phpstan analyse --memory-limit=512M
```
