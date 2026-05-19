# 15. Exporty (PDF ZIP, ISDOC, Pohoda XML)

Pro účetní (interní oddělení nebo externí kancelář) nabízí MyInvoice tři
formáty hromadného exportu:

| Formát | Pro koho | Co obsahuje |
|---|---|---|
| **PDF ZIP** | Klasická archivace | Všechna PDF za zvolené období v ZIP archivu |
| **ISDOC 6.0.2** | Český národní standard pro B2B výměnu faktur | XML soubor pro každou fakturu, balené v ZIP |
| **Pohoda XML** | Stormware Pohoda — přímý import bez ručního opisu | Sloučený dataPack XML soubor |

## 15.1 Obrazovka exportů

V hlavním menu **Systém → Exporty**.

![Exporty](img/13_exporty.webp)

Formulář:

| Pole | Význam |
|---|---|
| Formát | `PDF ZIP` / `ISDOC` / `Pohoda XML` |
| Období | Měsíc-rok (např. „Duben 2026") |
| Typ | Všechny / Faktury / Zálohové / Dobropisy |
| Stav | Vystavené (default) / Zaplacené / Vše |

Klik **Stáhnout** → soubor stažen do prohlížeče.

## 15.2 PDF ZIP

Nejjednodušší archivace. ZIP obsahuje:

```
faktury-2026-04.zip
├── 2604001-Faktura.pdf
├── 2604002-Faktura.pdf
├── 92604001-Zalohova.pdf
├── 72604001-Dobropis.pdf
└── ...
```

Název souboru: `<varsymbol>-<typ>.pdf`.

Použití: **roční archivace** pro účetní (předáš ZIP/měsíc), **založení do
spisu**, **odeslání e-mailem revizorovi**.

## 15.3 ISDOC 6.0.2

ISDOC je český národní standard pro elektronickou výměnu faktur. Definovaný
[ISDOC.cz](http://www.isdoc.cz/) — používá ho většina českých účetních
softwarů (Money S3, Helios, Stereo, ABRA).

### 15.3.1 Struktura souboru

Každá faktura má vlastní `.isdoc` XML soubor podle ISDOC 6.0.2 schématu.
ZIP obsahuje:

```
isdoc-2026-04.zip
├── 2604001.isdoc       (XML)
├── 2604002.isdoc
├── ...
└── manifest.xml         (volitelný — seznam dokumentů)
```

### 15.3.2 DocumentType

Mapování v ISDOC:

| MyInvoice typ | ISDOC DocumentType |
|---|---|
| Faktura | `1` (běžná faktura) |
| Zálohová (proforma) | `2` (zálohová) |
| Dobropis | `5` (opravný daňový doklad) |
| Storno | (neexportuje se — interní) |

### 15.3.3 PaymentMeansCode

| Způsob platby | Kód |
|---|---|
| Bankovní převod (CZ) | `42` |
| SEPA převod (EU) | `31` |
| Hotovost | `10` |

### 15.3.4 Číslo zakázky a smlouvy

Pokud má faktura přiřazenou zakázku s vyplněným číslem zakázky / číslem
smlouvy, exportují se do ISDOC jako kolekce wrappers (XSD 6.0.2):

```xml
<OrderReferences>
  <OrderReference id="O1">
    <SalesOrderID>2026-042</SalesOrderID>      <!-- project_number -->
  </OrderReference>
</OrderReferences>
<ContractReferences>
  <ContractReference id="C1">
    <ID>SMLOUVA-001</ID>                       <!-- contract_number -->
    <IssueDate>2026-05-14</IssueDate>          <!-- IssueDate faktury -->
  </ContractReference>
</ContractReferences>
```

Některé účetní softwary tyto reference zachovávají při importu (Money S3,
Helios). MyInvoice je při [zpětném importu](16_Importy.md) také čte —
zakázka se podle `project_number` najde nebo automaticky vytvoří.

### 15.3.5 ISDOC v PDF příloze (3.6.2+)

Při generování PDF faktury se ISDOC XML přibalí jako PDF/A-3 attachment
(`/Names /EmbeddedFiles` + `/AF` v catalog). Účetní programy si data
extrahují přímo z PDF — stačí přeposlat jediný soubor. Pod variabilním
symbolem se v PDF zobrazí vizuální `ISDOC` badge.

- Vkládá se jen pro **CZK faktury s přiděleným VS**.
- Lze vypnout per-dodavatel v *Nastavení → Dodavatel → Vkládat ISDOC XML
  do PDF faktur* (default zapnuto).
- Adobe Reader / Foxit zobrazí ikonu sponky v sidebar „Attachments" panelu.

### 15.3.6 Import do účetního software

| Software | Kde naimportovat |
|---|---|
| **Money S3** | Karty → Faktury vydané → Načíst z ISDOC |
| **Pohoda** | Externí komunikace → Import dat → ISDOC |
| **Helios Orange** | Faktury vydané → Akce → Import ISDOC |
| **Stereo** | Účetní → Import → ISDOC |

## 15.4 Pohoda XML (Stormware data package)

Pohoda XML je **proprietary formát firmy Stormware** pro přímý import faktur
do účetního systému Pohoda. Na rozdíl od ISDOC je to **jeden velký XML**
(`dataPack`), ne soubor per fakturu.

### 15.4.1 Struktura

```xml
<?xml version="1.0" encoding="UTF-8"?>
<dat:dataPack xmlns:dat="..." xmlns:inv="..." xmlns:typ="..." version="2.0">
  <dat:dataPackItem id="2604001">
    <inv:invoice version="2.0">
      <inv:invoiceHeader>
        <inv:invoiceType>issuedInvoice</inv:invoiceType>
        <inv:number>
          <typ:numberRequested>2604001</typ:numberRequested>
        </inv:number>
        ...
```

### 15.4.2 Per-dodavatel konfigurace

Před prvním exportem do Pohody **musíš nastavit Pohoda kódy v dodavateli**:

**Systém → Dodavatelé → [tvůj] → Editovat → záložka Pohoda**

| Pole | Význam | Příklad |
|---|---|---|
| Číselná řada | Kód číselné řady v Pohodě | `FV` |
| Středisko | Kód střediska | `01` |
| Činnost | Kód činnosti | `100` |
| Předkontace | Kód předkontace | `300` |

Bez vyplnění některého z těchto polí export proběhne, ale **import do Pohody
hodí varování** — musíš v Pohodě dovyplnit při importu.

### 15.4.3 Číslo zakázky

Pokud má faktura zakázku s vyplněným číslem, exportuje se do hlavičky:

```xml
<inv:numberOrder>2026-042</inv:numberOrder>
```

Pohoda toto pole standardně načítá jako „Číslo zakázky" / „Číslo objednávky".
Pro per-supplier `pohoda_contract_code` (v Nastavení → Dodavatel → Pohoda)
nadále platí samostatný `<inv:contract>` blok — ten se zapisuje pro celou
číselnou řadu, `<inv:numberOrder>` per faktura.

### 15.4.4 VAT klasifikace

MyInvoice mapuje DPH sazby na **Pohoda kódy klasifikace**:

| MyInvoice DPH | Pohoda kód |
|---|---|
| 21 % | `UDA5` (úprava DPH 21 %) |
| 12 % | `UDA5_12` (úprava DPH 12 %) |
| 0 % osvobozeno | `UNX` (osvobozeno) |
| 0 % reverse charge | `PNAR` (přenesená daňová povinnost) |

### 15.4.5 Import do Pohody

1. Pohoda → **Soubor → Datová komunikace → XML import / export**
2. **Import** → vyber `myinvoice-pohoda-2026-04.xml`
3. Pohoda zobrazí náhled (kolik faktur, jaké částky)
4. Klik **Importovat** → faktury se založí

### 15.4.6 Co Pohoda XML neobsahuje

- **PDF přílohu faktury** (Pohoda generuje vlastní PDF z dat)
- **Výkaz víceprací** (přílohy se neexportují)
- **QR platbu** (Pohoda generuje vlastní)

Pokud klient potřebuje přesně tvoji PDF verzi, použij paralelně **PDF ZIP**.

## 15.5 Faktury v cizí měně (EUR / USD / …) — kurz CZK v exportu

Pro faktury v jiné měně než CZK MyInvoice automaticky přidává do exportů
**kurz ČNB** zafixovaný na faktuře — viz [§ 10.4.2](10_Faktura_editor.md#1042-faktura-v-cizí-měně-eur--usd---přepočet-do-czk).

### 15.5.1 ISDOC — `LocalCurrencyCode` + `CurrencyCode` + `CurrRate`

ISDOC export pro EUR fakturu obsahuje:

```xml
<LocalCurrencyCode>CZK</LocalCurrencyCode>     <!-- účetní měna dodavatele -->
<CurrencyCode>EUR</CurrencyCode>               <!-- faktur. měna -->
<CurrRate>24.360000</CurrRate>                 <!-- CZK / 1 EUR -->
<RefCurrRate>1</RefCurrRate>
```

Všechny `<…Amount currencyID="EUR">…</…Amount>` zůstávají v EUR. Účetní soft
si CZK ekvivalent dopočítá z `CurrRate`. Pokud faktura nemá zafixovaný kurz
(starší data před verzí 1.4 nebo selhal fetch z ČNB), `CurrRate=1` — uživatel
musí v účetním softu kurz ručně doplnit.

### 15.5.2 Pohoda XML — `inv:foreignCurrency` + `inv:homeCurrency`

Pohoda XML pro EUR fakturu obsahuje **oba** bloky v `<inv:invoiceSummary>`:

```xml
<inv:homeCurrency>                    <!-- CZK z přepočtu kurzem -->
  <typ:priceHigh>1218.00</typ:priceHigh>
  <typ:priceHighVAT>255.78</typ:priceHighVAT>
  <typ:priceSum>4055.94</typ:priceSum>
</inv:homeCurrency>
<inv:foreignCurrency>                 <!-- originál v EUR + kurz -->
  <typ:currency><typ:ids>EUR</typ:ids></typ:currency>
  <typ:rate>24.360000</typ:rate>
  <typ:amount>1</typ:amount>
  <typ:priceHigh>50.00</typ:priceHigh>
  <typ:priceHighVAT>10.50</typ:priceHighVAT>
  <typ:priceSum>166.50</typ:priceSum>
</inv:foreignCurrency>
```

Položky (`<inv:invoiceItem>`) pro non-CZK fakturu používají `<inv:foreignCurrency>`
místo `<inv:homeCurrency>` — Pohoda po importu položkové CZK hodnoty dopočítá
z globálního kurzu.

### 15.5.3 Tipy

- **Konzultuj kurz s účetní** — některé účetní software (zejm. Pohoda) má
  vlastní kurzovní lístek a může při importu kurz přepsat. Pokud chceš mít
  v Pohodě přesný kurz z faktury, nech přepis vypnutý.
- **Backfill při exportu** — když exportuješ starší fakturu bez kurzu, MyInvoice
  ho automaticky doplní (cache → ČNB → poslední známý). Když ČNB nedostupné
  a žádný kurz není, v ISDOC dostaneš `CurrRate=1` s varováním.

## 15.6 Filtrování

| Volba | Použití |
|---|---|
| Typ = Faktury (jen) | Klasický měsíční export pro účetní |
| Stav = Zaplacené | Pro výplatu DPH (jen reálně přijaté) |
| Typ = Dobropisy | Pro samostatnou agendu oprav |

## 15.7 Tipy

- **Měsíční rytmus** — exportuj 1. den následujícího měsíce za ten skončený
  měsíc.
- **ISDOC i Pohoda** — pokud si nejsi jistý, který formát použít, **ISDOC**
  je univerzální (otevřený standard, fungují různé softwary). Pohoda XML jen
  když víš, že příjemce má Pohodu.
- **Stáhni i PDF ZIP jako backup** — XML formáty obsahují data, ale ne grafiku
  PDF. Pokud archivuješ pro daňové účely, mít originální PDF je nutné.
- **Před prvním exportem do Pohody** → konzultuj s účetní, jaké chce kódy
  střediska / činnosti / předkontace. Bez nich import není čistý.

---

## 15.8 DAP DPH — DPHDP3 (EPO MF ČR)

**DAP DPH** je periodické přiznání k dani z přidané hodnoty podávané
elektronicky přes portál [EPO MF ČR](https://epodatelna.mfcr.cz/). MyInvoice
generuje soubor ve formátu `DPHDP3 verzePis="03.01"` připravený k přímému
nahrání do EPO.

> ⚠️ Export DPH je dostupný **pouze pro plátce DPH** (`Nastavení → Dodavatel →
> Plátce DPH` musí být zatrhnuto). Před prvním exportem **musíš vyplnit
> DPH/EPO pole dodavatele** — viz [§ 18.7](#187-dphoepo-pole-dodavatele-pro-epo-xml).

### 15.8.1 Jak spustit export

**Systém → Sestavy → DAP DPH**

Parametry:

| Pole | Hodnoty |
|---|---|
| Rok | Číslo roku (např. `2026`) |
| Měsíc | 1–12 (měsíční plátce) |
| Formát | `xml` (default) nebo `json` pro ladění dat |

Klikni **Stáhnout** → prohlížeč stáhne soubor `DPHDP3-XXXXXXXXXX-YYYYMM.xml`.
V EPO portal ho nahraješ přes **Podání → Nové podání → Nahrát soubor**.

### 15.8.2 Co soubor obsahuje

Soubor odpovídá formuláři č. 25 5412 MF ČR. Obsahuje sekce:

| Sekce | Co popisuje |
|---|---|
| **VetaD** | Hlavička: rok, měsíc, typ přiznání (`B`=běžné), OKÉČ/NACE kód hlavní činnosti, `kod_zo="M"` v prosinci |
| **VetaP** | Identifikace plátce: DIČ, FÚ (c_ufo/c_pracufo), jméno/příjmení, adresa, kontakt |
| **Veta1** | Výstupní DPH: zdanitelná plnění tuzemsko (ř. 1/2), PDP dodavatel (ř. 25) |
| **Veta2** | Osvobozená plnění s nárokem: vývoz (ř. 22), dodání zboží do EU (ř. 20), služby EU (ř. 21) |
| **Veta3** | Opravy, dovoz osvobozený, třístranný obchod (většinou nuly) |
| **Veta4** | Vstupní DPH: odpočet tuzemský (ř. 40/41 plný/krácený), dovoz (ř. 42), ostatní (ř. 43) |
| **Veta5** | Koeficient krácení (pro plnění bez nároku) |
| **Veta6** | Rekapitulace: celková daň, celkový odpočet, vlastní daňová povinnost / přeplatek |

### 15.8.3 Mapování členění DPH → řádky přiznání

Přiznání se sestavuje z pole **Členění DPH** na položkách faktur. Pokud
položky členění nemají, systém ho automaticky odvodí ze sazby.

**Vydané faktury (výstupy):**

| Kód členění | Řádek DAP | Popis |
|---|---|---|
| `01-02`, `01-02c`, `01-02p` | ř. 1/2 | Tuzemská zdanitelná plnění |
| `25` | ř. 25 | PDP dodavatel (přenesená daňová povinnost — ty jsi dodavatel) |
| `20` | ř. 20 | Dodání zboží do EU (§ 64) |
| `21` | ř. 21 | Třístranný obchod |
| `22` | ř. 22 | Vývoz zboží (§ 66) |
| `31` | ř. 21 | Poskytnutí služby do EU (§ 9) |
| `50` | ř. 50 | Osvobozené bez nároku na odpočet |
| `0U` | — | Plnění bez vlivu na DPH |

**Přijaté faktury (vstupy):**

| Kód členění | Řádek DAP | Popis |
|---|---|---|
| `40-41`, `40-41m` | ř. 40/41 | Odpočet daně tuzemský — plný nárok |
| `40-41k`, `40-41mk` | ř. 40/41 | Odpočet daně tuzemský — krácený |
| `42`, `42m` | ř. 42 | Odpočet daně — dovoz zboží |
| `43` | ř. 43 | Odpočet daně — ostatní |
| `10-11` | ř. 10/11 | PDP příjemce (§ 92a — ty jsi příjemce) |
| `0P` | — | Plnění bez vlivu na DPH |

### 15.8.4 Sazby DPH

DAP DPH rozlišuje dvě sazby:

| Sazba | Sloupec DAP | Poznámka |
|---|---|---|
| 21 % | `dan23` / `obrat23` | Základní sazba |
| 12 % nebo 10 % | `dan5` / `obrat5` | Snížená sazba (od 2025 = 12 %; historicky 15 % / 10 %) |

Systém automaticky rozlišuje sazby na základě hodnoty v položkách.

### 15.8.5 Prosinec — `kod_zo="M"`

Prosincové přiznání automaticky obsahuje `kod_zo="M"` v sekci VetaD — povinný
atribut uzávěrky zdaňovacího období. Není potřeba nic nastavovat ručně.

### 15.8.6 Ladění dat (formát JSON)

Přidej parametr `?format=json` k URL pro zobrazení zdrojových dat ve formátu
JSON — užitečné pro ověření, že systém správně načetl faktury a klasifikace
před samotným exportem XML.

---

## 15.9 Kontrolní hlášení DPH — DPHKH1 (EPO MF ČR)

**Kontrolní hlášení** je povinné podání pro plátce DPH, které detailně
vykazuje jednotlivé transakce. MyInvoice generuje soubor `DPHKH1 verzePis="03.01"`
připravený k nahrání do EPO.

> ⚠️ Stejné předpoklady jako pro DAP DPH — viz [§ 15.8](#158-dap-dph--dphdp3-epo-mf-čr).

### 15.9.1 Jak spustit export

**Systém → Sestavy → Kontrolní hlášení**

Parametry jsou stejné jako u DAP DPH (rok, měsíc, formát). Výstup:
`DPHKH1-XXXXXXXXXX-YYYYMMDD-HHMMSS.xml`.

### 15.9.2 Sekce KH a co obsahují

| Sekce | Obsah |
|---|---|
| **VetaD** | Hlavička: rok, měsíc, datum podání, `khdph_forma="B"` |
| **VetaP** | Identifikace plátce (stejná data jako v DPHDP3) |
| **VetaA4** | **Vydané faktury ≥ 10 000 Kč s DIČ odběratele** — 1 řádek per faktura |
| **VetaA5** | **Vydané faktury agregovaně** — součet faktur < 10 000 Kč a faktur bez DIČ odběratele (emituje se jen pokud je nenulová) |
| **VetaB2** | **Přijaté faktury ≥ 10 000 Kč s DIČ dodavatele** — 1 řádek per faktura |
| **VetaB3** | **Přijaté faktury agregovaně** — součet faktur < 10 000 Kč a bez DIČ dodavatele |
| **VetaC** | Rekapitulace (musí odpovídat Veta1/Veta4 v DAP DPH) |

### 15.9.3 Logika A.4 vs A.5 (vydané faktury)

| Podmínka faktury | Kam jde |
|---|---|
| Celková částka (s DPH) ≥ 10 000 Kč **a zároveň** odběratel má DIČ | → **VetaA4** (individuální řádek) |
| Celková částka < 10 000 Kč **nebo** odběratel nemá DIČ | → **VetaA5** (agregace) |

Pole `c_evid_dd` v A4 = `varsymbol` vydané faktury (naše číslo dokladu).

### 15.9.4 Logika B.2 vs B.3 (přijaté faktury)

| Podmínka faktury | Kam jde |
|---|---|
| Celková částka (s DPH) ≥ 10 000 Kč **a zároveň** dodavatel má DIČ | → **VetaB2** (individuální řádek) |
| Celková částka < 10 000 Kč **nebo** dodavatel nemá DIČ | → **VetaB3** (agregace) |

Pole `c_evid_dd` v B2 = `invoice_number` přijaté faktury (číslo dokladu jak ho
vydal dodavatel — tak jak je uveden na faktuře).

### 15.9.5 Mapování sazeb v KH

| Sazba | Sloupec KH | Poznámka |
|---|---|---|
| 21 % | `zakl_dane1` / `dan1` | Základní sazba |
| 12 % nebo 15 % | `zakl_dane2` / `dan2` | Snížená (od 2025 = 12 %) |
| 10 % | `zakl_dane3` / `dan3` | Druhá snížená |

Hodnoty jsou v KH na **2 desetinná místa** (na rozdíl od DAP DPH kde jsou
zaokrouhlena na celá čísla).

### 15.9.6 VetaC — rekapitulace

VetaC shrnuje celé hlášení:

| Pole VetaC | Co obsahuje |
|---|---|
| `obrat23` | Celkový základ daně vydaných faktur při 21 % |
| `obrat5` | Celkový základ daně vydaných faktur při 12 % / 10 % |
| `pln23` | Celkový základ daně přijatých faktur při 21 % |
| `pln5` | Celkový základ daně přijatých faktur při 12 % / 10 % |
| `rez_pren23/5` | Základy PDP vydaných (přenesená daňová povinnost) |
| `celk_zd_a2` | Základ sekce A.2 (pořízení zboží z EU apod.) |

Hodnoty v VetaC musí odpovídat součtu A4+A5 (pro obrat) resp. B2+B3 (pro
pln). EPO toto kříž-kontroluje automaticky.

### 15.9.7 Kontrola správnosti

Pro ověření konzistence obou exportů před podáním:

1. Generuj DAP DPH i KH pro stejný měsíc
2. Zkontroluj: `obrat23` v KH VetaC = `Veta1.obrat23` v DAP DPH
3. Zkontroluj: `pln23` v KH VetaC ≈ `Veta4.pln23` v DAP DPH
4. Drobné rozdíly jsou přípustné (zaokrouhlení celá čísla vs. decimály)
