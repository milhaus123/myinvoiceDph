# MyInvoice.cz — Documentation

> Version: 3.7.0 · Last updated: 2026-05-19

---

## Overview

MyInvoice.cz is a Czech invoicing application for small businesses and freelancers.
It handles the full lifecycle: quotes → invoices → payments → DPH reporting.

## Features

### Core Modules

| Module | Route | Description |
|--------|-------|-------------|
| Dashboard | `/` | Overview with KPIs, alerts, recent activity |
| Issued Invoices | `/invoices` | Sales invoices (with filters: proforma, credit notes, etc.) |
| Proforma Invoices | `/invoices?type=proforma` | Advance/ zálohové faktury |
| Credit Notes | `/invoices?type=credit_note` | Dobropisy |
| Quotes | `/quotes` | Cenové nabídky — prepare before invoicing |
| Recurring Invoices (Sales) | `/recurring-invoices` | Automate invoice generation |
| Purchase Invoices | `/purchase-invoices` | Nákupní faktury |
| Recurring Purchase | `/recurring-purchase-invoices` | Opakované nákupní faktury |
| Receipts | `/receipts` | Účtenky (placeholders for future) |
| Items/Stock | `/items` | Sklad — inventory management |
| Bank | `/bank` | Bank import + payment matching |
| Cash Register | `/cash` | Pokladna — cash movements |
| Clients | `/clients` | Customer directory |
| Projects | `/projects` | Zakázky — project tracking |
| DPH Reports | `/reports/dph` | DPH výkaz (přehled) |
| DAP DPH (DPHDP3) | `/reports/dphdp3` | Export DAP DPH pro EPO MF ČR — Veta1–6, VetaD/P, MD5 Kontrola |
| Kontrolní hlášení (DPHKH1) | `/reports/kontrolni-hlaseni` | Export KH DPH pro EPO — VetaA4/A5/B2/B3/C |
| Income Tax | `/reports/priznani-dani` | Přiznání k dani z příjmu |
| iDoklad Import | `/admin/import/idoklad` | Hromadný import dat z iDokladu přes REST API |

### New in 3.7.0 (2026-05-19) — DPH exporty + iDoklad

- **DAP DPH export (DPHDP3)** — Kompletní přepis na formát EPO MF ČR
  (`verzePis="03.01"`). VetaD–VetaP–Veta1–6, MD5 Kontrola, `kod_zo="M"`
  pro prosinec, mapování klasifikací DPH na správné řádky přiznání
- **Kontrolní hlášení DPH (DPHKH1)** — EPO formát s VetaA4 (faktury ≥10k
  s DIČ), VetaA5 (agregace), VetaB2/B3, VetaC rekapitulace. Hodnoty na
  2 desetinná místa, DD.MM.YYYY datumový formát
- **Členění DPH (VAT classification)** — Nové pole na položkách faktur
  i přijatých faktur s kódy MF ČR (migrace 0031). Kompatibilní s iDokladem,
  Pohodou, Flexibee
- **Supplier tax/EPO fields** — 12 nových polí na dodavateli (c_ufo,
  c_pracufo, tax_okec, typ_platce, osobní a kontaktní údaje) pro VetaP
  v EPO XML (migrace 0032)
- **Import z iDokladu** — REST API import kontaktů, vydaných faktur
  (incl. členění DPH), dobropisů, přijatých faktur. Async background worker

### New in 2.1.0 (Sprint 2026-05-16)

- **Quotes module** — Create quotes, send to client, convert to invoice
- **Recurring invoices** — Automated invoice generation (sales)
- **EET 2.0** — Electronic cash register integration
- **Cash register** — Track cash income/expenses
- **Inventory/Stock** — Item catalog with stock levels + low-stock alerts
- **Menu restructure** — Prodej / Nákup / Finance / Klienti sections

---

## Architecture

### Tech Stack

| Layer | Technology |
|-------|-------------|
| Frontend | Vue 3 + TypeScript + Vite |
| Backend | PHP (custom MVC) |
| Database | MariaDB 11 |
| Cache | Redis |
| Web Server | Apache 2.4 |
| Container | Docker |

### Directory Structure

```
/
├── api/                    # PHP backend
│   ├── src/
│   │   ├── Action/         # Controllers/actions
│   │   ├── Repository/     # Data access
│   │   ├── Service/        # Business logic
│   │   └── Routes.php      # API routes
│   └── db/
│       └── migrations/     # Database migrations
├── web/                    # Vue frontend
│   ├── src/
│   │   ├── api/            # API clients
│   │   ├── components/     # Reusable components
│   │   ├── composables/    # Vue composables
│   │   ├── pages/          # Page components
│   │   ├── i18n/           # Translations (cs.json, en.json)
│   │   └── router/         # Vue Router
│   └── dist/               # Built assets (served by Apache)
├── manual/                 # User documentation (Markdown)
└── docker-compose.yml
```

### API Endpoints

All API endpoints require authentication via Bearer token (PAT).

| Prefix | Description |
|--------|-------------|
| `/api/invoices` | Issued invoices CRUD |
| `/api/purchase-invoices` | Purchase invoices CRUD |
| `/api/quotes` | Quotes CRUD + status transitions |
| `/api/recurring-invoices` | Recurring invoice templates |
| `/api/recurring-purchase-invoices` | Recurring purchase templates |
| `/api/items` | Item catalog CRUD |
| `/api/stock-movements` | Stock movement records |
| `/api/cash-movements` | Cash register movements |
| `/api/bank-statements` | Bank statement import |
| `/api/clients` | Client directory |
| `/api/projects` | Project management |
| `/api/reports/*` | DPH and tax reports |
| `/api/eet/*` | EET submission and status |
| `/api/auth/*` | Authentication |

---

## Deployment

### Docker

```bash
# Build
docker compose build

# Start
docker compose up -d

# Logs
docker logs -f myinvoice-app-1

# Shell into container
docker exec -it myinvoice-app-1 bash
```

### Database Migrations

Migrations run automatically on container start. If you need to run manually:

```bash
docker exec myinvoice-app-1 php run-migrations.php
```

---

## Configuration

### Environment Variables

| Variable | Description |
|----------|-------------|
| `MYSQL_HOST` | Database host |
| `MYSQL_PORT` | Database port |
| `MYSQL_DATABASE` | Database name |
| `MYSQL_USER` | Database user |
| `MYSQL_PASSWORD` | Database password |
| `APP_ENV` | `development` or `production` |
| `APP_DEBUG` | Enable debug mode |
| `APP_SECRET` | Session encryption secret |
| `MAIL_*` | SMTP configuration |

### EET Configuration

| Setting | Description |
|---------|-------------|
| `EET_CERTIFICATE_PATH` | Path to EET certificate file |
| `EET_ENVIRONMENT` | `mock`, `sandbox`, or `production` |
| `EET_DIC` | Your tax identification number |

---

## User Guide

Full user documentation is in the `/manual` directory:

| Chapter | Content |
|---------|---------|
| 01 | Introduction |
| 02 | Installation |
| 03 | Setup wizard |
| 04 | Login |
| 05 | Dashboard |
| 06 | Invoicing guide (DPH, VAT, etc.) |
| 07 | Clients |
| 08 | Projects |
| 09 | Invoice list |
| 10 | Invoice editor |
| 11 | PDF generation |
| 12 | Bank import |
| 13 | Reminders |
| 14 | Exports |
| 15 | Imports |
| 16 | Multi-supplier |
| 17 | Settings |
| 18 | Security (2FA, roles) |
| 19 | Updates |
| 20 | REST API |
| **21** | **Quotes (NEW)** |
| **22** | **Recurring invoices (NEW)** |
| **23** | **Cash register (NEW)** |
| **24** | **EET (NEW)** |
| **25** | **Stock management (NEW)** |
| 99 | Troubleshooting |

---

## Troubleshooting

### Common Issues

**App doesn't start after migration failure:**
```bash
docker logs myinvoice-app-1 | grep "Migration"
```
Check the specific migration file and fix the SQL.

**EET submissions failing:**
- Verify DIC is correct
- Check certificate is valid and not expired
- Try `mock` environment first for testing

**Invoice PDF not generating:**
- Check Apache error log: `docker logs myinvoice-app-1 --stderr`
- Verify wkhtmltopdf is installed in container

### Logs

| Log | Location |
|-----|----------|
| Apache error log | Inside container: `/var/log/apache2/error.log` |
| Application log | `/var/www/html/log/app-YYYY-MM-DD.log` |
| Docker logs | `docker logs myinvoice-app-1` |

---

## Links

- **App**: https://myinvoice.mrsystems.cz/
- **GitHub**: https://github.com/milhaus123/myinvoiceDph
- **Docker Hub**: https://github.com/milhaus123/myinvoiceDph/pkgs/container/myinvoice

---

*Documentation generated: 2026-05-16*