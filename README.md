# MyInvoiceDph — fork MyInvoice.cz s rozšířeními DPH

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP 8.5+](https://img.shields.io/badge/PHP-8.5+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MariaDB 10.6+](https://img.shields.io/badge/MariaDB-10.6+-003545?logo=mariadb&logoColor=white)](https://mariadb.org/)
[![Vue 3](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)](https://vuejs.org/)
[![Docker](https://img.shields.io/badge/Docker-multi--arch-2496ED?logo=docker&logoColor=white)](https://github.com/radekhulan/myinvoice/pkgs/container/myinvoice)

> **Fork projektu [MyInvoice.cz](https://github.com/radekhulan/myinvoice) (© MyWebdesign.cz s.r.o.)
> rozšířený o DPH/EPO výkazy, přijaté faktury, import z Fakturoid a klasifikaci DPH dle MFin ČR.**

Původní projekt vyvíjí **[MyWebdesign.cz s.r.o.](https://mywebdesign.cz/)**
Tento fork spravuje **Martin Říha** — [github.com/milhaus123/myinvoiceDph](https://github.com/milhaus123/myinvoiceDph)

> ⚠️ **UPOZORNĚNÍ K EPO EXPORTŮM (DPH přiznání, Kontrolní hlášení)**
>
> Všechny XML exporty pro EPO portál MF ČR jsou **vyvíjeny a testovány na jedné konkrétní
> sadě dat** (jeden plátce DPH, OSVČ, tuzemský provoz, měsíční přiznání).
> **Pro jiné uživatele, účetní situace nebo okrajové případy mohou vygenerovat nesprávný
> nebo nepřijatý soubor.**
>
> **Před odesláním jakéhokoliv exportu na finanční úřad vždy ověř vygenerované XML
> se svou účetní nebo daňovým poradcem.** Viz [AUDIT_MFCR.md](AUDIT_MFCR.md) pro
> detailní analýzu souladu s EPO specifikací.

---

## Co projekt dělá

**MyInvoiceDph** je self-hosted fakturační systém pro OSVČ a malé firmy s českou lokalizací.
Oproti upstreamovému MyInvoice.cz přidává kompletní podporu pro DPH výkazy podávané
přes EPO portál MF ČR a správu přijatých faktur (nákupů).

### Vydané faktury

- 4 typy dokladů: **faktura**, **zálohová (proforma)**, **opravný daňový doklad** (dobropis), **storno**
- Klonování faktur, opakované faktury, hromadné akce, QR platby (SPAYD / SEPA EPC)
- Export PDF, ISDOC 6.0.2, Pohoda XML
- Schvalování výkazu zákazníkem přes e-mailový odkaz

### Přijaté faktury (nákupy)

- Kompletní CRUD přijatých faktur (purchase invoices)
- Eviduje dodavatele, částky, DPH sazby, číslo dokladu dodavatele
- Klasifikace DPH dle číselníku MF ČR (kódy `40-41`, `40-41k`, `42`, `43` …)
- Vstupuje do Veta4 DPH přiznání a VetaB Kontrolního hlášení
- Opakované přijaté faktury (šablony)

### DPH přiznání (DPHDP3)

- Export XML ve formátu `DPHDP3 verzePis="03.01"` pro EPO portál MF ČR
- Pokrývá VetaD (metadata), VetaP (identifikace plátce), Veta1–Veta6
- Volba roku a měsíce, typ plátce (měsíční / čtvrtletní)
- Stažení přes **Nastavení → Dodavatel → DPH přiznání** nebo `GET /api/reports/dphdp3?year=2026&month=4`

### Kontrolní hlášení (DPHKH1)

- Export XML ve formátu `DPHKH1 verzePis="03.01"` pro EPO portál MF ČR
- VetaA4/A5 (vydané faktury) a VetaB2/B3 (přijaté faktury) s prahem 10 000 Kč
- VetaC rekapitulace
- Stažení přes **Nastavení → Dodavatel → Kontrolní hlášení** nebo `GET /api/reports/kontrolni-hlaseni?year=2026&month=4`

### Import z iDokladu

- Import kontaktů, vydaných faktur, dobropisů a přijatých faktur z iDoklad API v3
- Dry-run mód pro náhled bez zápisů
- Background joby s progress pollingem a logem v UI
- Storno běžících importů jedním klikem
- Prevence duplicit

### Import z Fakturoidu

- Import kontaktů, vydaných faktur, dobropisů a přijatých faktur z Fakturoid API v3
- Stejný dry-run mód a background joby jako u iDokladu
- OAuth2 Client Credentials autentizace

### ARES lookup

- Automatické vyplnění názvu, adresy a DIČ podle IČ (ARES REST API)
- Používá se při zakládání klienta, dodavatele i v setup wizardu
- Pole `c_pop` (číslo popisné) je odděleno od názvu ulice dle EPO požadavků

### Klienti a dodavatelé

- ARES + VIES lookup (IČ → adresa + DIČ, DIČ → ověření v EU)
- Multi-supplier: fakturuj za více firem / IČ z jedné instalace
- Přepínač dodavatele v horní liště, izolovaná data

---

## Technický stack

| Vrstva | Technologie |
|--------|-------------|
| Backend | PHP 8.5 + Slim 4 + PHP-DI 7 + Twig 3 + Monolog 3 + Guzzle 7 |
| Frontend | Vue 3 + TypeScript + Vite + Tailwind CSS 4 + Pinia + vue-router |
| Databáze | MariaDB 10.6+ (doporučeno 11.x) |
| PDF | mPDF 8 + Twig šablony |
| Mail | Symfony Mailer 8 (SMTP + DKIM) |
| Kontejnerizace | Docker Compose (multi-arch: amd64 + arm64) |

---

## Požadavky

### Docker (doporučeno)

- **Docker Desktop** (Windows / macOS) nebo **Docker Engine + compose-plugin** (Linux)
- Nic dalšího — PHP, MariaDB ani Node na hostu nepotřebuješ

### Nativní instalace

- **PHP 8.5+** s extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `json`, `iconv`, `gd`
- **MariaDB 10.6+** (doporučeno 11.x)
- **Composer 2.x**
- **Node.js 20+** a **pnpm 10+**
- Web server: Apache nebo IIS (repo má `.htaccess` i `web.config`)

---

## Spuštění lokálně (Docker — doporučeno)

```bash
git clone https://github.com/milhaus123/myinvoiceDph.git
cd myinvoiceDph

# Linux / macOS
cmd/docker-install.sh

# Windows PowerShell
.\cmd\docker-install.ps1
```

Skript automaticky: vygeneruje `.env` s náhodnými DB hesly, vytvoří `cfg.docker.php`,
sestaví Docker image, spustí stack a spustí DB migrace.

Po dokončení otevři: **[http://localhost:8080](http://localhost:8080)**

Projdi **setup wizard** (3 kroky):

1. **Administrátor** — jméno, e-mail, heslo (min. 12 znaků)
2. **Dodavatel** — zadej IČ → klikni *Načíst z ARES* → doplň bankovní účet
3. **Sample data** *(volitelné)* — testovací klienti, zakázky, faktury

### Základní Docker příkazy

```bash
docker compose up -d                                 # start
docker compose down                                  # stop (data zůstanou)
docker compose logs -f app                           # live logy
docker compose exec app bash                         # shell do kontejneru
docker compose exec app php api/bin/migrate.php      # spustit migrace ručně
```

---

## Spuštění lokálně (nativní)

```bash
git clone https://github.com/milhaus123/myinvoiceDph.git
cd myinvoiceDph
cp cfg.sample.php cfg.php
# Vyplň cfg.php — minimálně: db.user, db.pass, app.pepper
```

```bash
# Databáze
mysql -u root -p -e "CREATE DATABASE myinvoice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Backend
cd api && composer install && cd ..
php api/bin/migrate.php

# Frontend
cd web
pnpm install
pnpm build      # produkční build → web/dist/
# nebo: pnpm dev   # dev server na :5173
```

---

## DB migrace

Migrace se spouštějí automaticky při startu Docker kontejneru. Pro ruční spuštění:

```bash
# Docker
docker compose exec app php api/bin/migrate.php

# Nativně
php api/bin/migrate.php

# Stav migrací
php api/bin/migrate.php --status
```

Migrace jsou idempotentní — bezpečné ke spuštění opakovaně. Detailní popis migrací
viz [docs/MIGRACE.md](docs/MIGRACE.md).

---

## Build frontendu

```bash
cd web
pnpm install        # nainstaluje závislosti
pnpm build          # produkční build do web/dist/
pnpm dev            # dev server s HMR na :5173
```

V Docker variantě se frontend builduje automaticky uvnitř multi-stage Dockerfile —
na hostu nepotřebuješ Node ani pnpm.

---

## Nastavení aplikace

### Základní údaje dodavatele

Po přihlášení jdi do **Nastavení → Dodavatel → Základní údaje**:

- **IČ** → klikni *Načíst z ARES* — automaticky vyplní název, adresu, DIČ
- **Číslo popisné (c_pop)** — odděleno od názvu ulice (důležité pro EPO adresu)
- **Bankovní účty** — přidej aspoň jeden účet pro CZK faktury

### DPH / EPO nastavení

V **Nastavení → Dodavatel → DPH / EPO** vyplň pole potřebná pro DPH přiznání a Kontrolní hlášení.
Podrobný průvodce viz [docs/DPH_NASTAVENI.md](docs/DPH_NASTAVENI.md).

### E-mail

V **cfg.php** (nebo `cfg.docker.php`) nastav SMTP:

```php
'smtp' => [
    'host'     => 'smtp.example.com',
    'port'     => 587,
    'user'     => 'noreply@example.com',
    'pass'     => 'heslo',
    'from'     => 'Moje Firma <noreply@example.com>',
],
```

---

## Generování DPH přiznání

1. Jdi do **Nastavení → Dodavatel → DPH / EPO** a vyplň všechna povinná pole
   (viz [docs/DPH_NASTAVENI.md](docs/DPH_NASTAVENI.md))
2. Zkontroluj, že všechny faktury za dané období mají správně nastavené
   **členění DPH** (kód klasifikace) na jednotlivých položkách
3. V menu klikni na **Sestavy → DPH přiznání** nebo **Sestavy → Kontrolní hlášení**
4. Vyber **rok** a **měsíc** → klikni **Stáhnout XML**
5. Vygenerovaný soubor zkontroluj v textovém editoru a případně uprav
6. Nahraj XML do EPO portálu: [https://epodatelna.mfcr.cz/](https://epodatelna.mfcr.cz/)

> ⚠️ Vždy zkontroluj čísla ve vygenerovaném XML před odesláním. Autoři negarantují
> správnost pro všechny kombinace vstupních dat.

---

## Import z iDokladu a Fakturoidu

Podrobný průvodce viz [docs/IMPORT.md](docs/IMPORT.md).

Stručně:

1. V iDokladu / Fakturoid vygeneruj API credentials (Client ID + Client Secret)
2. Zadej je v **Nastavení → Dodavatel → Import → iDoklad / Fakturoid**
3. Nejdřív spusť **dry-run** (zaškrtni *Jen náhled*) a zkontroluj log
4. Pokud je vše v pořádku, spusť ostrý import

---

## Dokumentace

| Soubor | Obsah |
|--------|-------|
| [docs/DPH_NASTAVENI.md](docs/DPH_NASTAVENI.md) | Průvodce nastavením DPH/EPO polí |
| [docs/IMPORT.md](docs/IMPORT.md) | Import z iDokladu a Fakturoidu |
| [docs/MIGRACE.md](docs/MIGRACE.md) | DB migrace — seznam a popis |
| [AUDIT_MFCR.md](AUDIT_MFCR.md) | Audit souladu EPO exportů s MF ČR specifikací |
| [CHANGELOG.md](CHANGELOG.md) | Historie změn |
| [source/02-database.md](source/02-database.md) | DB schéma |
| [source/03-architecture.md](source/03-architecture.md) | Architektura aplikace |
| [source/04-api.md](source/04-api.md) | REST API dokumentace |

---

## Licence

**MIT** — [LICENSE](LICENSE). Původní kód © 2026 **[MyWebdesign.cz s.r.o.](https://mywebdesign.cz/)**
Rozšíření (DPH, přijaté faktury, Fakturoid import, klasifikace DPH) © 2026 **Martin Říha**

Tento projekt je fork [radekhulan/myinvoice](https://github.com/radekhulan/myinvoice).

## Zřeknutí se odpovědnosti

> **Software je poskytován „TAK JAK JE", bez záruky jakéhokoli druhu.**
> Autoři neodpovídají za chybně podaná daňová přiznání ani za jakékoli škody
> vzniklé v souvislosti s používáním tohoto softwaru.
>
> **EPO exporty** (DPH přiznání, Kontrolní hlášení) byly vyvíjeny a testovány
> na omezené sadě dat. Před každým podáním na finanční úřad ověř výkaz se svou
> účetní nebo daňovým poradcem.
>
> Plné znění viz [LICENSE](LICENSE) (MIT — sekce *„NO WARRANTY"*).
