# 22. Opakované (reccuring) faktury — automatické generování

Opakované faktury umožňují automatické vystavování faktur na základě šablony
v nastavených intervalech (denně, týdně, měsíčně, čtvrtletně, ročně).

## 22.1 Seznam šablon

Přístup: **Nákup → Opakované nákupní faktury** (`/recurring-purchase-invoices`) nebo
**Prodej → Opakované faktury** (`/recurring-invoices`)

![Seznam opakovaných šablon](img/22_recurring_list.webp)

## 22.2 Stav šablony

| Stav | Popis |
|---|---|
| 🟢 **Aktivní** | Šablona běží, faktury se generují podle plánu |
| ⏸️ **Pozastaveno** | Generování pozastaveno, lze kdykoliv obnovit |
| ❌ **Ukončeno** | Šablona neběží a nelze ji obnovit |

## 22.3 Vytvoření šablony

1. Klikni **+ Nová šablona**
2. Vyplň formulář:
   - **Název** — popis (např. "Elektřina PRE")
   - **Dodavatel** — výběr dodavatele
   - **Frekvence** — Denně / Týdně / Měsíčně / Čtvrtletně / Ročně
   - **Den v měsíci** — který den se má faktura generovat (pro měsíční)
   - **Zakázka** — volitelně propojit se zakázkou
   - **Položky** — položky šablony
3. Ulož a ** Aktivuj**

## 22.4 Generování

Systém generuje faktury **1x denně** podle nastaveného plánu.
Přehled nadcházejících generování je vidět v detailu šablony.

### Ruční spuštění

Pokud potřebuješ fakturu vygenerovat hned (mimo plán):
1. Otevři detail šablony
2. Klikni **Generovat nyní** → okamžitě se vystaví faktura

## 22.5 Často kladené otázky

**Jaký je rozdíl mezi opakovanou fakturou a šablonou?**
Šablona je nastavení "jak často a za co". Faktura je konkrétní vystavený dokument.

**Mohu upravit položky po aktivaci?**
Ano, upravy se projeví při dalším generování.

**Co když potřebuji přeskočit jeden měsíc?**
Pozastav šablonu na ten měsíc a pak znovu aktivuj.

---

*Nová sekce — 2026-05-16*