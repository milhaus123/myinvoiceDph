# 21. Cenové nabídky — příprava před fakturací

Cenová nabídka slouží k návrhu nabídky klientovi před vystavením faktury. Po schválení
lze nabídku převést na finální fakturu jedním kliknutím.

## 21.1 Seznam nabídek

Přístup: **Prodej → Cenové nabídky** (`/quotes`)

![Seznam cenových nabídek](img/21_quotes_list.webp)

Seznam je seskupený podle **statusu** a data. Barevný indikátor signalizuje stav:

| Ikona | Status | Popis |
|---|---|---|
| 🟦 | **Koncept** | Rozpracovaná nabídka |
| 🟨 | **Odeslaná** | Nabídka odeslaná klientovi |
| ✅ | **Schválená** | Klient schválil, připravena k fakturaci |
| ❌ | **Zamítnutá** | Klient odmítl |
| 🔄 | **Převedena** | Již převedena na fakturu |

## 21.2 Vytvoření nabídky

1. Klikni **+ Nová nabídka**
2. Vyplň formulář:
   - **Klient** — výběr z existujících klientů
   - **Měna** — CZK, EUR, atd.
   - **Platnost do** — datum, do kdy je nabídka platná
   - **Položky** — přidání položek stejně jako ve faktuře (bez DPH datum)
3. Ulož jako **Koncept** nebo rovnou **Odešli**

## 21.3 Schválení a převod na fakturu

Když klient schválí nabídku:

1. Otevři detail schválené nabídky
2. Klikni **Schválit** → změní status na ✅ Schválená
3. Klikni **Vystavit fakturu** → systém vytvoří novou fakturu s předvyplněnými daty z nabídky
4. Faktura vznikne ve stavu **Koncept** — uprav a vystav jako běžnou fakturu

## 21.4 Často kladené otázky

**Mohu upravit schválenou nabídku?**
Ne — schválená nabídka je zamknutá. Vytvoř novou nabídku.

**Funguje pro zálohové faktury?**
Ano, lze vytvořit zálohovou fakturu z nabídky.

**Lze poslat emailem?**
Ano, z detailu nabídky lze odeslat email s PDF nabídky.

---

*Nová sekce — 2026-05-16*