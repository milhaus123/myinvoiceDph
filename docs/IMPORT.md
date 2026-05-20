# Import dat — průvodce

Aplikace umožňuje jednorázový nebo opakovaný import faktur, dobropisů,
přijatých faktur a kontaktů ze dvou externích systémů:

- **iDoklad** — iDoklad API v3 (OAuth2 Client Credentials)
- **Fakturoid** — Fakturoid API v3 (OAuth2 Client Credentials)

Oba importy fungují stejným způsobem: nejdřív spusť **dry-run** (náhled bez zápisů),
zkontroluj log a teprve pak spusť ostrý import.

---

## Import z iDokladu

### 1. Kde najít API klíče v iDokladu

1. Přihlás se do [iDokladu](https://app.idoklad.cz/)
2. Jdi do **Nastavení → API přístup** (nebo **Uživatelský účet → API**)
3. Klikni **Vytvořit nový API klíč**
4. Zvol typ **Client Credentials** (pro server-to-server komunikaci)
5. Zkopíruj:
   - **Client ID** — identifikátor aplikace
   - **Client Secret** — tajný klíč (zobrazí se pouze jednou — ulož si ho!)

> iDoklad API v3 používá OAuth2 Client Credentials flow.
> Token má omezenou platnost a aplikace ho automaticky obnovuje před expirací.

### 2. Nastavení v aplikaci

Jdi do **Nastavení → Dodavatel → Import → iDoklad** a vyplň:

| Pole | Popis |
|------|-------|
| **Client ID** | zkopírovaný z iDokladu |
| **Client Secret** | zkopírovaný z iDokladu |

Klikni **Uložit**. Credentials se uloží šifrovaně do databáze per-dodavatel.

### 3. Spuštění importu

Na stejné stránce (**Nastavení → Dodavatel → Import → iDoklad**):

1. **Roky** — vyber, za které roky chceš importovat (výchozí: aktuální rok ± 1)
2. **Sekce** — vyber co importovat:
   - `contacts` — kontakty (klienti)
   - `invoices` — vydané faktury + položky + členění DPH
   - `credit-notes` — dobropisy
   - `purchases` — přijaté faktury
3. **Dry-run** — zaškrtni *Jen náhled (dry-run)* pro první spuštění
4. Klikni **Spustit import**

### 4. Co se importuje z iDokladu

| Sekce | Co se importuje |
|-------|----------------|
| **contacts** | Kontakty → klienti (IČ, název, adresa, DIČ, email, telefon) |
| **invoices** | Vydané faktury + položky + DPH klasifikace (`vat_classification`) |
| **credit-notes** | Dobropisy jako typ faktury `credit_note` |
| **purchases** | Přijaté faktury + položky + DPH klasifikace |

Existující záznamy se **neaktualizují** — import je aditivní. Duplicity jsou detekovány
pomocí iDoklad ID uloženého v databázi a stejný záznam se naimportuje jen jednou.

---

## Import z Fakturoidu

### 1. Kde najít API klíče ve Fakturoid

1. Přihlás se do [Fakturoid](https://app.fakturoid.cz/)
2. Jdi do **Nastavení → API** (nebo **Účet → Nastavení → API**)
3. V sekci **OAuth2 aplikace** klikni **Přidat aplikaci**
4. Zvol typ **Server (Client Credentials)**
5. Zkopíruj:
   - **Client ID**
   - **Client Secret** (zobrazí se pouze jednou)
6. Zjisti také svůj **slug účtu** — je vidět v URL, např. `https://app.fakturoid.cz/jannovak/...`
   → slug je `jannovak`

> Fakturoid API v3 používá OAuth2 Client Credentials flow.
> Slug je nutný jako součást URL každého API endpointu
> (`/api/v3/accounts/{slug}/invoices.json`).

### 2. Nastavení v aplikaci

Jdi do **Nastavení → Dodavatel → Import → Fakturoid** a vyplň:

| Pole | Popis |
|------|-------|
| **Client ID** | zkopírovaný z Fakturoid |
| **Client Secret** | zkopírovaný z Fakturoid |
| **Slug účtu** | tvůj slug (část URL), např. `jannovak` |

Klikni **Uložit**.

### 3. Spuštění importu

Na stejné stránce (**Nastavení → Dodavatel → Import → Fakturoid**):

1. **Roky** — vyber roky k importu
2. **Sekce** — vyber sekce (contacts / invoices / credit-notes / purchases)
3. **Dry-run** — zaškrtni *Jen náhled* pro první spuštění
4. Klikni **Spustit import**

### 4. Co se importuje z Fakturoidu

| Sekce | Co se importuje |
|-------|----------------|
| **contacts** | Kontakty (subjects) → klienti |
| **invoices** | Vydané faktury + řádky + DPH |
| **credit-notes** | Dobropisy |
| **purchases** | Přijaté faktury (expenses) |

Fakturoid vrací záznamy po stránkách (max 40 na stránku) — aplikace automaticky
stránkuje všechny výsledky.

---

## Dry-run mód

Dry-run mód spustí celý import proces, ale **nezapíše nic do databáze**. Slouží
k ověření, zda jsou credentials správné a jaká data budou naimportována.

**Jak použít:**

1. Zaškrtni **Jen náhled (dry-run)**
2. Klikni **Spustit import**
3. Import se provede synchronně (vrátí výsledek najednou) a zobrazí:
   - Statistiky: kolik záznamů bylo nalezeno, kolik by bylo vytvořeno, kolik přeskočeno
   - Detailní log každého záznamu

**Příklad výstupu dry-run:**

```
[contacts] Nalezeno 45 kontaktů — 40 nových, 5 přeskočeno (duplicita)
[invoices] Nalezeno 120 faktur — 115 nových, 5 přeskočeno
[purchases] Nalezeno 30 přijatých faktur — 30 nových
```

Pokud je výstup v pořádku, odstraň zaškrtnutí dry-run a spusť ostrý import.

---

## Jak probíhá ostrý import (background job)

Ostrý import (bez dry-run) se spustí jako **background worker** — aplikace ho
spustí na pozadí a okamžitě vrátí `job_id`. Průběh sleduj v UI:

1. Po spuštění se zobrazí **progress bar** s logem
2. Log se aktualizuje průběžně (polling každé 2 sekundy)
3. Po dokončení se zobrazí souhrn a případné chyby
4. Import lze **zrušit** tlačítkem *Zrušit import* — worker se bezpečně ukončí
   (aktuálně zpracovávaný batch se dokončí, pak se zastaví)

**Prevence duplicitních importů:** Nelze spustit dva importy se stejnými parametry
najednou — aplikace to detekuje a zobrazí chybovou hlášku.

---

## Časté problémy

**„Neplatné credentials" nebo 401 Unauthorized**
→ Zkontroluj Client ID a Client Secret — při kopírování se může přidat mezera nebo
zalomení řádku. Client Secret se v iDokladu/Fakturoid zobrazí jen jednou — pokud ho
nemáš, vygeneruj nový.

**Import se zasekl nebo „neodpovídá"**
→ Klikni *Zrušit import*. Pokud to nepomůže, restartuj aplikaci (nebo Docker kontejner)
a spusť import znovu.

**Faktury se importují, ale chybí DPH klasifikace**
→ V iDokladu/Fakturoid musí mít položky faktur nastaveno členění DPH.
Pokud členění chybí, aplikace použije fallback podle sazby DPH
(>0 % → `01-02` pro vydané / `40-41` pro přijaté; 0 % → `0U` / `0P`).

**Slug Fakturoid — kde ho najdu?**
→ Přihlás se do Fakturoid a podívej se na URL adresu v prohlížeči:
`https://app.fakturoid.cz/**jannovak**/invoices` — tučná část je tvůj slug.

**Kontakty se importují, ale fakturační email chybí**
→ iDoklad/Fakturoid nemusí mít k danému kontaktu email uložen. Doplň ho ručně
v aplikaci po importu.
