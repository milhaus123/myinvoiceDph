# 24. EET — Elektronická evidence tržeb

> **Poznámka k EET 2.0:** Od ledna 2027 platí nové povinnosti EET 2.0.
> Systém podporuje jak EET 3.0 (aktuální formát), tak EET 2.0 (připraveno na změny).

EET je povinná pro podnikatele přijímající hotovost v ČR. MyInvoice.cz umožňuje
odesílat EET evidence přímo z aplikace.

## 24.1 Kdy je EET povinná

EET se týká **hotovostních plateb** (a některých dalších). Konkrétní podmínky:
- Příjem hotovosti od zákazníka
- Platba kartou na terminálu (některé případy)
- Úhrada faktury v hotovosti

## 24.2 Nastavení

1. **Nastavení → EET**
2. Zadej **DIC** (daňové identifikační číslo)
3. Nastav **režim** (testovací / produkční)
4. Nahraj **certifikát** (pro produkční režim)

## 24.3 Odeslání tržby

Když vystavuješ fakturu s **typem platby: hotovost**:

1. Ve faktuře vyber **Typ platby: Hotovost**
2. Po vystavení se automaticky zobrazí **EET badge**
3. Kliknutím na badge lze **Odeslat do EET**
4. Systém odešle XML na EET server a uloží **FIK** (fiskální identifikační kód)

## 24.4 Stav EET

EET session má tyto stavy:

| Stav | Barva | Popis |
|---|---|---|
| 🟡 **Čeká** | Žlutá | Odesláno, čeká na odpověď |
| 🟢 **Potvrzeno** | Zelená | EET server potvrdil (FIK získán) |
| 🔴 **Chyba** | Červená | EET server vrátil chybu |
| 📴 **Offline fallback** | Šedá | Odesláno bez potvrzení (fallback) |

## 24.5 Offline režim

Pokud nelze spojit s EET serverem (výpadek), systém použije **offline režim**:
- Uloží PKP/BKP kódy
- Data se odešlou později
- Evidenci nelze považovat za splněnou EET povinnost do potvrzení

## 24.6 Často kladené otázky

**Musím EET odesílat manuálně?**
Lze nastavit automatické odeslání při vystavení faktury s hotovostní platbou.

**Co když nemám certifikát?**
Pro vývoj/testování lze použít mock režim bez reálného EET odeslání.

---

*Nová sekce — 2026-05-16*