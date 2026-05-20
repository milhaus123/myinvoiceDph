# Nastavení DPH / EPO — průvodce

Tento dokument popisuje, jak správně vyplnit pole v **Nastavení → Dodavatel → DPH / EPO**,
aby generování DPH přiznání (DPHDP3) a Kontrolního hlášení (DPHKH1) pro EPO portál MF ČR
fungovalo správně.

> ⚠️ EPO exporty byly vyvíjeny a testovány na konkrétní sadě dat (OSVČ, tuzemský provoz,
> měsíční přiznání). Pro jiné situace může být vygenerovaný XML soubor nesprávný.
> Viz [AUDIT_MFCR.md](../AUDIT_MFCR.md) pro detailní analýzu souladu s EPO specifikací.

---

## 1. Kde najít nastavení

V aplikaci přejdi na:

**Nastavení → Dodavatel → DPH / EPO**

Tato záložka je viditelná pouze přihlášenému adminovi.

---

## 2. Pole identifikace plátce (VetaP)

Tato pole se zapíší do sekce `<VetaP>` v XML — identifikují plátce DPH vůči finančnímu úřadu.

### Finanční úřad

| Pole v UI | Atribut XML | Popis |
|-----------|-------------|-------|
| **Kód FÚ (c_ufo)** | `c_ufo` | Číselný kód územního finančního orgánu, např. `463` pro FÚ pro Jihomoravský kraj |
| **Kód pracoviště (c_pracufo)** | `c_pracufo` | Kód konkrétního územního pracoviště, např. `3203` pro pracoviště Brno III |

**Jak zjistit kódy:** Přihlás se na [EPO portál](https://epodatelna.mfcr.cz/) a v sekci
*Přiznání k DPH* zkontroluj, jaké kódy jsou předvyplněny pro tvůj finanční úřad.
Alternativně se koukni na papírové přiznání, které sis někdy podal — kódy tam jsou vyplněny.

### OKÉČ / NACE kód

| Pole v UI | Atribut XML | Popis |
|-----------|-------------|-------|
| **OKÉČ kód (c_okec)** | `c_okec` | Kód hlavní podnikatelské činnosti dle NACE/OKÉČ |

Například `631000` pro poradenství v oblasti IT. Kód najdeš na svém živnostenském listě
nebo v ARES záznamu. Pokud pole necháš prázdné, použije se fallback `631000`.

### Typ plátce

| Pole v UI | Atribut XML | Hodnota | Kdy použít |
|-----------|-------------|---------|------------|
| **Typ plátce** | `typ_platce` | `P` | **Měsíční** plátce DPH (výchozí pro větší obraty) |
| **Typ plátce** | `typ_platce` | `Q` | **Čtvrtletní** plátce DPH (obrat do 10 mil. Kč/rok) |

Pokud pole necháš prázdné, použije se `P` (měsíční). Zkontroluj si na svém finančním
úřadě, jaké zdaňovací období máš přiděleno.

### Osobní údaje (pro OSVČ / fyzické osoby)

Tato pole jsou povinná pro fyzické osoby (OSVČ). Pro s.r.o. nebo a.s. (PO) pole
`jmeno` a `prijmeni` nevyplňuj — místo nich se použije název firmy.

| Pole v UI | Atribut XML | Popis |
|-----------|-------------|-------|
| **Titul** | `titul` | Titul před jménem — Bc., Ing., Dr., … (nepovinné) |
| **Jméno** | `jmeno` | Křestní jméno plátce |
| **Příjmení** | `prijmeni` | Příjmení plátce |

### Adresní pole

EPO rozlišuje **ulici** a **číslo popisné** jako samostatné atributy. Proto jsou
v nastavení dodavatele oddělena.

| Pole v UI | Atribut XML | Popis |
|-----------|-------------|-------|
| **Číslo popisné (c_pop)** | `c_pop` | Číslo popisné nebo orientační, např. `42` nebo `42/3` |
| **Město (naz_obce)** | `naz_obce` | Název obce **velkými písmeny** — EPO to vyžaduje, aplikace převede automaticky |
| **PSČ** | `psc` | PSČ bez mezer (aplikace odstraní automaticky) |
| **Stát** | `stat` | Např. `ČESKÁ REPUBLIKA` (velká písmena) |

> ⚠️ **Důležité:** Pole `c_pop` je **DPH-specifické** — vyplňuje se pro VetaP v DPH přiznání.
> Odlišuje se od pole **Číslo popisné** v záložce **Základní údaje**, které se používá
> pro PDF faktury a obálky.
>
> Pokud načítáš adresu přes ARES, hodnota `c_pop` se automaticky oddělí od ulice
> a uloží do správného sloupce.

#### Rozdíl: bydliště vs. sídlo podnikání

EPO v DPH přiznání pro OSVČ vyžaduje **adresu sídla podnikání** (místo podnikání),
nikoliv adresu trvalého bydliště, pokud jsou odlišné. Zkontroluj svůj živnostenský
list nebo ARES záznam — tam je uvedeno místo podnikání.

### Kontaktní údaje pro podání

| Pole v UI | Atribut XML | Popis |
|-----------|-------------|-------|
| **E-mail pro EPO** | `email` | Email pro VetaP — pokud prázdné, použije se `supplier.email` |
| **Telefon pro EPO** | `c_telef` | Telefon ve formátu `+420XXXXXXXXX` |

---

## 3. Sestavitel přiznání (sest_*)

Pole sestavitele jsou relevantní pouze v případě, kdy **přiznání za tebe podává
jiná osoba** (účetní, daňový poradce). Pokud podáváš sám, tato pole nech prázdná —
aplikace automaticky použije tvé vlastní údaje (fallback na `tax_jmeno`, `tax_prijmeni`,
`tax_telef`).

| Pole v UI | Atribut XML | Popis |
|-----------|-------------|-------|
| **Jméno sestavitele** | `sest_jmeno` | Jméno osoby, která přiznání sestavila |
| **Příjmení sestavitele** | `sest_prijmeni` | Příjmení sestavitele |
| **Telefon sestavitele** | `sest_telef` | Telefon sestavitele |

---

## 4. Kontrola vygenerovaného XML

Před nahráním XML do EPO portálu si soubor otevři v textovém editoru a zkontroluj:

1. **VetaD** — ověř `mesic`, `rok`, `typ_platce` (P nebo Q)
2. **VetaP** — ověř `dic`, `c_ufo`, `c_pracufo`, `jmeno`, `prijmeni`, `c_pop`, `naz_obce`
3. **Veta1 / Veta4** — ověř, zda součty `dan23`/`dan5` (výstupní DPH) a `odp_tuz23_nar`/
   `odp_tuz5_nar` (vstupní DPH) odpovídají fakturám za dané období
4. **Veta6** — `dano_da` (daňová povinnost) nebo `dano_no` (nadměrný odpočet)

Pokud čísla nesedí, zkontroluj **členění DPH** na položkách faktur — každá položka
musí mít správný kód klasifikace (např. `01-02` pro tuzemské zdanitelné plnění 21 %,
`40-41` pro plný odpočet z přijaté faktury).

---

## 5. Jak a kdy podat DPH přiznání

- **Lhůta:** DPH přiznání se podává do **25. dne** následujícího měsíce
  (tzn. za březen do 25. dubna)
- **Forma:** Elektronicky přes [EPO portál MF ČR](https://epodatelna.mfcr.cz/)
- **Formát:** XML soubor stažený z aplikace (DPHDP3)
- **Kontrolní hlášení:** podává se ve **stejné lhůtě** jako DPH přiznání

### Postup podání na EPO

1. Stáhni XML z aplikace (**Sestavy → DPH přiznání**, vyber rok a měsíc)
2. Zkontroluj soubor v textovém editoru
3. Přihlás se na [https://epodatelna.mfcr.cz/](https://epodatelna.mfcr.cz/)
4. Zvol **Přiznání k DPH → Nové podání → Nahrát soubor**
5. Nahraj XML — portál ho zvaliduje a zobrazí náhled
6. Pokud validace projde, potvrď odeslání
7. Ulož potvrzení o podání (PDF nebo e-mail)

> EPO XML soubor lze před nahráním ručně upravit v textovém editoru.
> Stačí změnit hodnoty atributů — struktura XML musí zůstat zachována.

---

## 6. Časté problémy

**EPO odmítne soubor s chybou „neúplná adresa"**
→ Vyplň `c_pop` a `naz_obce` v DPH/EPO nastavení. Pole `ulice` (název ulice)
se v aktuální verzi do XML nezapisuje — pokud EPO adresu odmítá, doplň ulici
ručně přímo do XML před nahráním.

**Čísla v přiznání nesedí se skutečností**
→ Zkontroluj členění DPH na položkách faktur za dané období. Každá faktura
musí mít na každé položce vybrán správný kód klasifikace DPH.

**Aplikace generuje `typ_platce="P"`, ale jsem čtvrtletní plátce**
→ Změň `typ_platce` na `Q` v DPH/EPO nastavení.

**Nevím, jaký je můj kód FÚ a pracoviště**
→ Podívej se na poslední DPH přiznání, které sis stáhl z EPO portálu —
kódy jsou tam vyplněny v sekci VetaD. Alternativně zavolej na svůj FÚ.
