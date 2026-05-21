# 23. Pokladna — hotovostní pohyby

Pokladna eviduje **hotovostní příjmy a výdaje** — peníze, které jdou přes
pokladnu, ne přes banku. Užitečné pro tracking cash transakcí.

## 23.1 Přehled

Přístup: **Finance → Pokladna** (`/cash`)

![Pokladna — seznam pohybů](img/23_cash_register.webp)

## 23.2 Typy pohybů

| Typ | Ikona | Popis |
|---|---|---|
| **Příjem** | ➕ | Hotovost, která do pokladny přišla |
| **Výdej** | ➖ | Hotovost, která z pokladny odešla |

## 23.3 Záznam nového pohybu

1. Klikni **+ Nový pohyb**
2. Vyplň:
   - **Typ** — příjem nebo výdej
   - **Částka** — v měně (CZK, EUR, ...)
   - **Datum** — datum pohybu
   - **Popis** — volný text
   - **Kategorie** — volba z předdefinovaných kategorií (Jídlo, Doprava, Materiál, Služby, ...)
   - **Klient** — volitelně
   - **Zakázka** — volitelně
3. Ulož

## 23.4 Kategorie

Kategorie lze spravovat v **Nastavení → Kategorie pokladny**.

Přednastavené kategorie:
- Jídlo
- Doprava
- Materiál
- Služby
- Kancelář
- Telefon
- Internet
- Pohoštění
- Cestovné
- Ostatní

## 23.5 Přehled / Bilance

V horní části stránky je přehled:
- **Aktuální zůstatek** — celková suma v pokladně
- **Příjmy tento měsíc** — součet příjmů
- **Výdaje tento měsíc** — součet výdajů

## 23.6 Rozdíl oproti bance

| Banka | Pokladna |
|---|---|
| Bezhotovostní platby | Hotovostní platby |
| Import výpisů | Ruční zadávání |
| Automatické párování | Ruční dohledávání |

---

*Nová sekce — 2026-05-16*