# 18. Nastavení

V hlavním menu **Systém** je rozbalovací podmenu se 6 sekcemi:

- **Dodavatelé** — viz [17. Multi-supplier](17_Multi_supplier.md)
- **Číselníky** — měny, DPH sazby, země, jednotky
- **Uživatelé** — správa lidí, kteří se přihlašují
- **E-mail šablony** — texty automatických e-mailů
- **Activity log** — kdo co změnil
- **Exporty** — viz [15. Exporty](15_Exporty.md)

## 18.1 Číselníky

**Systém → Číselníky**.

![Číselníky — Měny](img/15_ciselniky_meny.webp)

4 záložky:

### 18.1.1 Měny

Každá měna pro aktuálního dodavatele = **1 bankovní účet**.

| Pole | Význam |
|---|---|
| Kód | ISO 4217 — `CZK`, `EUR`, `USD`, `GBP` |
| Označení | „CZK — KB", „EUR — Fio" — pro UI rozlišení (víc účtů per měna) |
| Symbol | `Kč`, `€`, `$`, `£` |
| Název CS / EN | „Koruna" / „Crown" |
| Decimals | Počet desetinných míst (2 typicky) |
| Aktivní | Vypnutá měna nelze pro nové faktury |
| Default pro kód | Pokud máš víc účtů per měna (např. 2× CZK), který je default |
| **Účet** (CZK) | Číslo účtu (např. `1000000005`) + bank kód (`0100`) + název banky |
| **Účet** (EUR) | IBAN + BIC + název banky |

> ⚠️ Po **změně bankovního účtu** se **automaticky invaliduje PDF cache**
> všech faktur, které renderují bank info live (drafty + faktury bez
> snapshotu). Faktury v stavu `issued+` mají immutable `bank_snapshot`.

### 18.1.2 Sazby DPH

![Číselníky — DPH](img/15_ciselniky_dph.webp)

| Pole | Význam |
|---|---|
| Kód | `CZ-21`, `CZ-12`, `CZ-0`, `CZ-RC` |
| Sazba | `21`, `12`, `0`, `0` |
| Stát | `CZ` (zatím) |
| Popisek CS / EN | Pro UI / PDF |
| Default | Která sazba se předvyplní v editoru |
| Reverse charge | Zatrhneme pro `CZ-RC` |
| Platnost od | Pro historické faktury (15 % v roce 2023) |

### 18.1.3 Země

Statický číselník — nemělo by být potřeba editovat. Obsahuje 200+ zemí podle
ISO 3166-1.

### 18.1.4 Jednotky

Číselník měrných jednotek pro položky faktury. Globální (sdílený mezi
dodavateli), nahrazuje volný textový vstup za dropdown.

| Pole | Význam |
|---|---|
| Kód | Krátký identifikátor (`h`, `ks`, `den`, `měs.`) |
| Popisek CS / EN | Co se zobrazí v UI / PDF (`hodina` / `hour`) |
| Default | Která jednotka se předvyplní při přidání nové položky (typicky `h`) |
| Pořadí | Číslo pro řazení v dropdownu |

> 💡 **Default = `hodina`** dává smysl, protože nová položka přebírá
> hodinovou sazbu z projektu/klienta. Pro jednorázové položky (paušál,
> licence, materiál) jednotku ručně přepneš.

> 🛈 **Auto-clean prázdných položek** — při uložení faktury se řádky bez
> popisu i bez ceny tiše smažou. Můžeš tedy v editoru přidat víc řádků na
> zásobu a nepoužité se neuloží.

## 18.2 Uživatelé

**Systém → Uživatelé** (jen pro admina).

![Uživatelé](img/15_users.webp)

Tabulka uživatelů, kteří se mohou přihlásit. Tlačítko **+ Nový uživatel**.

### 18.2.1 Pole formuláře

| Pole | Význam |
|---|---|
| Jméno | Zobrazení v UI |
| E-mail | Login |
| Heslo | Min. 12 znaků |
| Role | `admin` / `accountant` / `readonly` |
| Jazyk | `cs` / `en` |
| Aktivní | Vypnutý uživatel nemůže se přihlásit |

### 18.2.2 Role

| Role | Co může |
|---|---|
| **admin** | Vše — vystavování, konfigurace, uživatelé, force editace, smazání |
| **accountant** | Vystavování faktur, klienti, banka, exporty. **Bez** konfigurace systému, **bez** force editace, **bez** správy uživatelů |
| **readonly** | Pouze prohlížení — bez úprav, bez vystavování. Vhodné pro auditora / klienta |

> 🛈 Systém má **guard proti odebrání posledního aktivního admina** — pokud
> jsi sám admin a zkusíš si snížit roli, vrátí 409. Musí být minimálně 1
> admin v systému.

## 18.3 Můj profil

**Pravý horní roh → klik na jméno → Můj profil**. Stejná obrazovka jako
[§ 4.5 Můj profil](04_Prihlaseni.md) — viz screenshot tam.

Můžeš si změnit:

- **Jméno + jazyk**
- **Heslo** — vyžaduje původní heslo
- **2FA** — zapnout / vypnout (vyžaduje heslo + ověření TOTP)

Viz [19. Bezpečnost § 16.2](19_Bezpecnost.md) pro detail TOTP.

## 18.4 E-mailové šablony

**Systém → E-mail šablony**.

![E-mail šablony](img/15_emails_list.webp)

Seznam šablon:

| Kód | Použití |
|---|---|
| `invoice_new` | Odeslání nové faktury klientovi |
| `invoice_reminder` | Upomínka po splatnosti |
| `password_reset` | Reset hesla (system) |
| `welcome` | Uvítací e-mail novému uživateli |
| `test` | Pro Test odeslání (debug) |

### 18.4.1 Editor šablony

Klik na řádek → editor.

Záložky podle jazyka × formátu:

- **CS HTML** — česká verze, plný HTML
- **CS Text** — plain text fallback
- **EN HTML** — anglická verze
- **EN Text** — anglický plain text

Editor je **CodeMirror** s syntaxí Twig.

### 18.4.2 Předmět

Pole nahoře, podporuje placeholders (`{{ varsymbol }}`, …).

### 18.4.3 Test odeslání

Tlačítko **Test e-mail** dole — pošle vyplněnou šablonu na **tvůj** e-mail
(přihlášeného admina) s vzorovými daty (faktura `2605001`, klient „Test
Klient s.r.o.", …).

### 18.4.4 Placeholders

Závisí na typu šablony. `invoice_new`:

| Placeholder | Význam |
|---|---|
| `{{ varsymbol }}` | Variabilní symbol |
| `{{ amount }}` | Částka (formátovaná) |
| `{{ currency }}` | Měna |
| `{{ due_date }}` | Splatnost |
| `{{ client_name }}` | Klient |
| `{{ supplier_name }}` | Dodavatel |
| `{{ pdf_url }}` | Odkaz pro stažení PDF (pokud máš public link) |

## 18.5 Activity log

**Systém → Activity log**.

![Activity log](img/15_activity.webp)

Audit všech mutací — kdo a kdy co změnil. Lze filtrovat:

| Filtr | Hodnoty |
|---|---|
| Akce | `invoice.created`, `invoice.issued`, `invoice.sent`, `invoice.paid`, `client.updated`, … |
| Uživatel | Dropdown se všemi |
| Entita | Typ (`invoice` / `client` / `project` / …) + ID |
| IP | IPv4 / IPv6 |
| Období | Měsíc / vlastní rozsah |
| Dodavatel | Per-dodavatel filtrování |

Použití:

- **Audit chyby** — „Kdo upravil fakturu 2605007?" → filter `entity_type=invoice, entity_id=N`
- **Bezpečnostní audit** — „Bylo to z očekávané IP?" → filter `ip`
- **Outage timeline** — všechny akce v intervalu

> 🛈 Activity log se nepromaže automaticky. Cron `cron-cleanup.sh`
> standardně **neničí** activity log, ale lze nastavit retention v
> `cfg.php → app.activity_log_retention_days`.

## 18.6 Tipy

- **Test šablony** vždy před produkčním nasazením — typo v Twig syntaxi by
  rozbilo odesílání všem klientům.
- **Role accountant** je dobrá pro externí účetní — vidí faktury, banku,
  exporty, ale nemůže upravit uživatele ani konfiguraci.
- **Z Activity logu** zjistíš všechno — i kdo neúspěšně se zkoušel přihlásit
  (filter akce `auth.login_failed`).

---

## 18.7 DPH/EPO pole dodavatele (pro EPO XML exporty)

Aby bylo možné generovat **DAP DPH (DPHDP3)** a **Kontrolní hlášení (DPHKH1)**
ve formátu EPO MF ČR, je třeba jednou vyplnit identifikační údaje dodavatele.
Tato data se zapíší do sekce **VetaP** v obou EPO souborech.

> 📍 **Systém → Dodavatelé → [tvůj dodavatel] → Editovat → záložka DPH/EPO**

Bez vyplnění těchto polí bude VetaP v exportu prázdná nebo bude obsahovat
výchozí hodnoty, což může způsobit odmítnutí podání na portálu EPO.

### 18.7.1 Identifikace finančního úřadu

| Pole (DB) | UI popis | Příklad | Kde najít |
|---|---|---|---|
| `tax_ufo` | Kód FÚ (`c_ufo`) | `463` | [epodatelna.mfcr.cz](https://epodatelna.mfcr.cz/) → tvůj FÚ |
| `tax_pracufo` | Kód pracoviště FÚ (`c_pracufo`) | `3203` | Stejný zdroj jako `c_ufo` |
| `tax_okec` | NACE/OKÉČ kód hlavní činnosti | `621000` | Dle registrace na FÚ; kód z [nace.cz](https://www.nace.cz/) |

Kódy `c_ufo` a `c_pracufo` najdeš po přihlášení do EPO portálu nebo na doručovacím
adresáři daňového subjektu (DS). Pro OSVČ v obci Velké Albrechtice jsou to
`463` (FÚ Ostrava) a `3203`.

### 18.7.2 Typ subjektu

| Pole (DB) | UI popis | Hodnoty | Kdo nastaví |
|---|---|---|---|
| `tax_typ_platce` | Typ plátce | `P` = právnická osoba (s.r.o., a.s.) / `F` = fyzická osoba (OSVČ, živnostník) | Dle právní formy |
| `tax_typ_ds` | Typ datové schránky | `F` = fyzická osoba / `P` = právnická osoba | Obvykle shodné s `tax_typ_platce` |

### 18.7.3 Osobní údaje (pro FO / OSVČ)

Povinné pokud `tax_typ_platce = F` (fyzická osoba):

| Pole (DB) | UI popis | Příklad |
|---|---|---|
| `tax_titul` | Titul před jménem | `Bc.`, `Ing.`, `Mgr.` |
| `tax_jmeno` | Jméno | `Martin` |
| `tax_prijmeni` | Příjmení | `Říha` |

### 18.7.4 Adresa a kontakt

| Pole (DB) | UI popis | Příklad | Poznámka |
|---|---|---|---|
| `tax_c_pop` | Číslo popisné (`c_pop`) | `76` | Číslo popisné / orientační — **oddělené od názvu ulice** (EPO je vyžaduje zvlášť) |
| `tax_email` | E-mail pro EPO | `jmeno@example.com` | Pokud prázdné, použije se `supplier.email` |
| `tax_telef` | Telefon pro EPO | `+420737451014` | Formát s mezinárodní předvolbou |
| `tax_stat` | Stát | `ČESKÁ REPUBLIKA` | Velká písmena; výchozí = `ČESKÁ REPUBLIKA` |

> 💡 Pole `tax_c_pop` je **číslo popisné** (ne celá adresa). V obci bez
> pojmenovaných ulic se vyplní samotné číslo popisné (např. `76`). Ve městě
> s ulicemi se vyplní jen číslo (např. `42`), název ulice jde do běžného
> pole `supplier.street`.

### 18.7.5 Jak zjistit kódy FÚ

1. Přejdi na [https://epodatelna.mfcr.cz/](https://epodatelna.mfcr.cz/)
2. Přihlas se přes NIA nebo datovou schránkou
3. V sekci „Moje podání" / „Správce daně" najdeš svůj kód FÚ a pracoviště
4. Alternativně: na potvrzení od FÚ (výzva, platební výměr) jsou kódy
   uvedeny v záhlaví

### 18.7.6 Příklad vyplnění (OSVČ CZ)

Pro živnostníka Bc. Martin Říha, IČ 86120460 (FÚ Ostrava, pracoviště Bílovec):

| Pole | Hodnota |
|---|---|
| `tax_ufo` | `463` |
| `tax_pracufo` | `3203` |
| `tax_okec` | `621000` (vývoj softwaru) nebo `631000` (zpracování dat) |
| `tax_typ_platce` | `F` |
| `tax_typ_ds` | `F` |
| `tax_titul` | `Bc.` |
| `tax_jmeno` | `Martin` |
| `tax_prijmeni` | `Říha` |
| `tax_c_pop` | `76` |
| `tax_email` | `riha.martin@gmail.com` |
| `tax_telef` | `+420737451014` |
| `tax_stat` | `ČESKÁ REPUBLIKA` |

Po vyplnění a uložení bude každý nový EPO export automaticky obsahovat správnou
VetaP. Stávající exporty si tato data vezme vždy z aktuálního stavu nastavení
(nejsou snapshottována na faktuře).

---

## 18.8 Import z iDokladu

MyInvoice umí jednorázově importovat historická data z fakturačního systému
**iDoklad** (Solitea a.s.).

> 📍 **Systém → Dodavatelé → [tvůj dodavatel] → Editovat → záložka iDoklad import**

### 18.8.1 Předpoklady

Potřebuješ **API přístup k iDokladu** (Enterprise nebo vyšší plán):

1. V iDokladu: Nastavení → Aplikace → Vytvořit novou API aplikaci
2. Zapiš si `Client ID` a `Client Secret`
3. V MyInvoice: vlož do polí `idoklad_client_id` a `idoklad_client_secret`

### 18.8.2 Co se importuje

| Data | Výsledek v MyInvoice |
|---|---|
| Kontakty | → Klienti (přeskočí existující dle DIČ/IČO) |
| Vydané faktury + položky | → Faktury s členěním DPH |
| Dobropisy | → Dobropisy (napárované na původní fakturu) |
| Přijaté faktury | → Přijaté faktury s položkami |

Import přiřazuje **členění DPH** z iDokladu (číselník MF ČR je shodný) —
exporty DPHDP3 a DPHKH1 pak budou okamžitě plně funkční.

### 18.8.3 Spuštění importu

1. Vyplň `Client ID` a `Client Secret`
2. Klikni **Spustit import** (lze zatrhnout **Jen simulace** pro dry-run bez zápisu)
3. Import běží na pozadí — stav sleduj v logu pod tlačítkem
4. Po dokončení zkontroluj počty: klienti, faktury, přijaté faktury

> ⚠️ Import je **idempotentní** — faktury se stejným číslem se přeskočí, nepřepíší.
> Lze bezpečně spustit vícekrát (např. pro doimportování nových faktur).
