# 25. Sklad — správa zásob a položek

Sklad umožňuje evidenci zboží, sledování stavu zásob a automatické
odpočítávání při vystavení faktury.

## 25.1 Katalog položek

Přístup: **Nákup → Sklad** (`/items`)

![Sklad — katalog položek](img/25_items_list.webp)

## 25.2 Atributy položky

| Atribut | Popis |
|---|---|
| **SKU** | Interní kód položky |
| **Název** | Popisné jméno (CS/EN) |
| **Popis** | Detailní popis |
| **Jednotková cena** | Cena za jednotku bez DPH |
| **Skladové množství** | Aktuální stav na skladě |
| **Prahová hodnota** | Množství, pod které upozorní alert |
| **Aktivní** | Zda je položka dostupná k prodeji |

## 25.3 Stavy zásob

| Stav | Popis |
|---|---|
| 🟢 **Skladem** | Dostupné množství > práh |
| ⚠️ **Nízký stav** | Množství ≤ práh — zobrazí se upozornění |
| 🔴 **Vyprodáno** | Množství = 0 |

## 25.4 Skladové pohyby

Každá změna množství je zaznamenána jako **skladový pohyb**:

| Typ | Popis |
|---|---|
| **Příjem** | Položka přijata na sklad |
| **Výdej** | Položka vydána (prodej, spotřeba) |
| **Úprava** | Ruční korekce množství |

## 25.5 Low stock alerts

Widget na dashboardu zobrazuje položky, které mají množství pod nastavený práh.
Kliknutím na alert přejdeš rovnou na detail položky.

## 25.6 Automatické odpočítávání

Při vystavení faktury, která obsahuje sledovanou položku:
1. Systém zkontroluje skladové množství
2. Po vystavení (ne po uložení konceptu) se automaticky provede **výdej**

## 25.7 Často kladené otázky

**Musím sklad používat?**
Ne, sklad je volitelný modul. Položky bez sledování zásob fungují normálně.

**Jak přidat položku?**
Klikni **+ Nová položka** a vyplň formulář.

**Lze importovat položky hromadně?**
Ano, přes **Import** v sekci Sklad (CSV).

---

*Nová sekce — 2026-05-16*