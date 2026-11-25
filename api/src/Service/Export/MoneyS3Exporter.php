<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use PDO;

/**
 * Export vydaných a přijatých faktur do nativního XML formátu Money S3 (MoneyData).
 *
 * Import v Money S3 bez modulu XMLDE: Agenda → Výměna dat XML (nebo tlačítko
 * XML přenosy v seznamu faktur) → Import z XML → připravená definice _FP+FV
 * ("faktury přijaté a vydané").
 *
 * Struktura podle oficiálních XSD Money S3 (money.cz/navod/s3xmlde, _Document.xsd):
 *   <MoneyData ICAgendy=… ExpZkratka="_FP+FV">
 *     <SeznamFaktVyd><FaktVyd>…</FaktVyd></SeznamFaktVyd>
 *     <SeznamFaktPrij><FaktPrij>…</FaktPrij></SeznamFaktPrij>
 *   </MoneyData>
 *
 * Zásady:
 *   - Částky v SouhrnDPH / Celkem jsou v CZK; doklady v cizí měně mají navíc <Valuty>.
 *   - Sazby: 0 % → Zaklad0, 12 % → Zaklad5/DPH5, 21 % → Zaklad22/DPH22
 *     (Money používá historické názvy elementů), jiné sazby → SeznamDalsiSazby.
 *   - <Doklad> má max. 10 znaků — naše číslo faktury se použije, jen když se vejde;
 *     vždy je v EvCisDokl (vydané) / PrijatDokl (přijaté = číslo dokladu dodavatele).
 *   - Předkontace se neposílají — doklady zaúčtuje účetní (podvojné účetnictví).
 *   - Členění DPH (KodDPH) = výchozí zkratky Money S3 řady 19Ř…; kde Money nemá
 *     jednoznačnou výchozí zkratku (vývoz, dodání do EU, osvobozená plnění), KodDPH
 *     se vynechá a řádek přiznání je uveden v poznámce dokladu.
 *   - Přijatá plnění se samovyměřením (služby/zboží z EU, PDP, § 108) jdou jako
 *     doklad se základem v 0 % a členěním 19Ř00P; vypočtené samovyměření (základ,
 *     daň, řádky přiznání) je v poznámce — účetní ho v Money zaúčtuje interním
 *     dokladem (tak to dělají i Fakturoid/Taxorio, Money nemá napříč verzemi
 *     ověřenou zkratku pro automatické samovyměření).
 */
final class MoneyS3Exporter
{
    /** Výchozí zkratky členění DPH Money S3 pro naše kódy (vat_classifications). */
    private const KOD_DPH_SALES = [
        '01-02'  => '19Ř01,02',
        '01-02c' => '19Ř01,02',
        '01-02p' => '19Ř01,02',
        '01-02r' => '19Ř01,02',
    ];
    private const KOD_DPH_PURCHASES = [
        '40-41'   => '19Ř40,41',
        '40-41m'  => '19Ř40,41',
        '40-41k'  => '19Ř40,41',
        '40-41mk' => '19Ř40,41',
        '0P'      => '19Ř00P',
    ];
    /** Přijatá plnění bez vlivu na DPH / se samovyměřením řešeným interním dokladem. */
    private const KOD_DPH_PURCHASE_NO_VAT = '19Ř00P';

    /** Řádek DAP DPH pro poznámku tam, kde KodDPH neposíláme. */
    private const DAP_ROW_NOTE = [
        '20' => 'ř. 20 — dodání zboží do jiného členského státu',
        '21' => 'ř. 30/31 — třístranný obchod',
        '22' => 'ř. 22 — vývoz zboží',
        '25' => 'ř. 25 — přenesení daňové povinnosti (dodavatel)',
        '26' => 'ř. 26 — ostatní plnění s nárokem na odpočet',
        '31' => 'ř. 21 — poskytnutí služby do jiného členského státu',
        '50' => 'ř. 50 — osvobozené plnění bez nároku na odpočet',
        '23' => 'ř. 23 — záloha na tuzemské plnění',
        '24' => 'ř. 24 — záloha na vývoz',
    ];
    private const SELF_ASSESS_ROWS = [
        '03-04' => 'ř. 3 (pořízení zboží z EU) + odpočet ř. 43',
        '05-06' => 'ř. 5 (přijetí služby z EU) + odpočet ř. 43',
        '10-11' => 'ř. 10 (PDP § 92a, příjemce) + odpočet ř. 43',
        '12-13' => 'ř. 12 (ostatní plnění § 108) + odpočet ř. 43',
    ];

    private \DOMDocument $dom;
    /** @var string[] */
    private array $warnings = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param string $month  YYYY-MM
     * @param string $dateBy 'tax' (DUZP, default — shodné s obdobím DPH) | 'issue'
     * @return array{filename: string, content: string, mime: string, counts: array{issued: int, received: int}, warnings: string[]}
     */
    public function export(int $supplierId, string $month, string $dateBy = 'tax'): array
    {
        $this->warnings = [];
        $pdo = $this->db->pdo();

        $st = $pdo->prepare('SELECT company_name, ic, dic FROM supplier WHERE id = ?');
        $st->execute([$supplierId]);
        $supplier = $st->fetch(PDO::FETCH_ASSOC) ?: ['company_name' => '', 'ic' => '', 'dic' => ''];

        $issued   = $this->loadIssued($supplierId, $month, $dateBy);
        $received = $this->loadReceived($supplierId, $month, $dateBy);

        $year = (int) substr($month, 0, 4);
        $this->dom = new \DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->dom->createElement('MoneyData');
        $root->setAttribute('ICAgendy', (string) ($supplier['ic'] ?? ''));
        $root->setAttribute('KodAgendy', '');
        $root->setAttribute('HospRokOd', sprintf('%04d-01-01', $year));
        $root->setAttribute('HospRokDo', sprintf('%04d-12-31', $year));
        $root->setAttribute('JazykVerze', 'CZ');
        $root->setAttribute('description', 'faktury přijaté a vydané');
        $root->setAttribute('ExpZkratka', '_FP+FV');
        $root->setAttribute('ExpDate', date('Y-m-d'));
        $root->setAttribute('ExpTime', date('H:i:s'));
        $root->setAttribute('VyberZaznamu', '0');
        $root->setAttribute('GUID', $this->guid());
        $this->dom->appendChild($root);

        if ($issued !== []) {
            $list = $this->dom->createElement('SeznamFaktVyd');
            foreach ($issued as $inv) {
                $list->appendChild($this->buildIssued($inv));
            }
            $root->appendChild($list);
        }
        if ($received !== []) {
            $list = $this->dom->createElement('SeznamFaktPrij');
            foreach ($received as $inv) {
                $list->appendChild($this->buildReceived($inv));
            }
            $root->appendChild($list);
        }

        if ($this->warnings !== []) {
            $root->insertBefore(
                $this->dom->createComment(' Upozornění exportu: ' . implode(' | ', array_map(
                    fn ($w) => str_replace('--', '–', $w), $this->warnings,
                )) . ' '),
                $root->firstChild,
            );
        }

        $ic = preg_replace('/\D/', '', (string) ($supplier['ic'] ?? '')) ?: 'firma';
        return [
            'filename' => sprintf('money-s3-%s-%s.xml', $ic, $month),
            'content'  => (string) $this->dom->saveXML(),
            'mime'     => 'application/xml',
            'counts'   => ['issued' => count($issued), 'received' => count($received)],
            'warnings' => $this->warnings,
        ];
    }

    // =========================================================================
    // Načtení dat
    // =========================================================================

    private function loadIssued(int $supplierId, string $month, string $dateBy): array
    {
        $dateExpr = $dateBy === 'issue' ? 'i.issue_date' : 'COALESCE(i.tax_date, i.issue_date)';
        $st = $this->db->pdo()->prepare(
            "SELECT i.*, cur.code AS currency,
                    c.company_name AS p_company, c.first_name AS p_first, c.last_name AS p_last,
                    c.ic AS p_ic, c.dic AS p_dic, c.street AS p_street, c.city AS p_city, c.zip AS p_zip,
                    c.main_email AS p_email, co.iso2 AS p_country, co.name_cs AS p_country_name
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN countries co ON co.id = c.country_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND DATE_FORMAT($dateExpr, '%Y-%m') = ?
                AND i.status IN ('issued','sent','reminded','paid')
                AND i.invoice_type IN ('invoice','credit_note')
           ORDER BY $dateExpr, i.id"
        );
        $st->execute([$supplierId, $month]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $items = $this->db->pdo()->prepare(
            'SELECT description, quantity, unit, unit_price_without_vat, vat_rate_snapshot,
                    vat_classification, total_without_vat, total_vat, total_with_vat
               FROM invoice_items WHERE invoice_id = ? ORDER BY order_index, id'
        );
        foreach ($rows as &$r) {
            $items->execute([(int) $r['id']]);
            $r['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    private function loadReceived(int $supplierId, string $month, string $dateBy): array
    {
        $dateExpr = $dateBy === 'issue' ? 'pi.issue_date' : 'COALESCE(pi.tax_date, pi.issue_date)';
        $st = $this->db->pdo()->prepare(
            "SELECT pi.*, cur.code AS currency,
                    c.company_name AS p_company, c.first_name AS p_first, c.last_name AS p_last,
                    c.ic AS p_ic, c.dic AS p_dic, c.street AS p_street, c.city AS p_city, c.zip AS p_zip,
                    c.main_email AS p_email, co.iso2 AS p_country, co.name_cs AS p_country_name,
                    co.is_eu AS p_is_eu
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.supplier_id
          LEFT JOIN countries co ON co.id = c.country_id
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE c.supplier_id = ?
                AND DATE_FORMAT($dateExpr, '%Y-%m') = ?
                AND pi.status IN ('received','booked','paid')
                AND pi.document_kind <> 'payment'
           ORDER BY $dateExpr, pi.id"
        );
        $st->execute([$supplierId, $month]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $items = $this->db->pdo()->prepare(
            'SELECT description, quantity, unit, unit_price_without_vat, vat_rate_snapshot,
                    vat_classification, total_without_vat, total_vat, total_with_vat
               FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index, id'
        );
        foreach ($rows as &$r) {
            $items->execute([(int) $r['id']]);
            $r['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    // =========================================================================
    // Faktura vydaná
    // =========================================================================

    private function buildIssued(array $inv): \DOMElement
    {
        $f = $this->dom->createElement('FaktVyd');
        $isCredit = ($inv['invoice_type'] ?? '') === 'credit_note';
        // Money čeká dobropis se zápornými částkami; pokud už jsou uložené záporně, neotáčet
        $sign = ($isCredit && (float) $inv['total_with_vat'] > 0) ? -1.0 : 1.0;
        $fx = $this->fx($inv, 'Faktura vydaná ' . $inv['varsymbol']);
        $number = (string) ($inv['varsymbol'] ?? '');
        $taxDate = (string) ($inv['tax_date'] ?: $inv['issue_date']);

        // Klasifikace položek (fallback dle sazby)
        $items = array_map(function ($it) {
            $cls = trim((string) ($it['vat_classification'] ?? ''));
            if ($cls === '') {
                $cls = ((float) $it['vat_rate_snapshot']) > 0 ? '01-02' : '0U';
            }
            $it['cls'] = $cls;
            return $it;
        }, $inv['items']);
        $mainCls = $this->dominantClassification($items);

        if ($number !== '' && mb_strlen($number) <= 10) {
            $this->el($f, 'Doklad', $number);
        }
        $this->el($f, 'EvCisDokl', $number);
        $this->el($f, 'Popis', $this->popis($items, ($isCredit ? 'Dobropis ' : 'Faktura ') . $number));
        $this->el($f, 'Vystaveno', (string) $inv['issue_date']);
        $this->el($f, 'DatUcPr', $taxDate);
        $this->el($f, 'PlnenoDPH', $taxDate);
        $this->el($f, 'Splatno', (string) $inv['due_date']);
        if (isset(self::KOD_DPH_SALES[$mainCls])) {
            $this->el($f, 'KodDPH', self::KOD_DPH_SALES[$mainCls]);
        }
        $vs = preg_replace('/\D/', '', $number);
        if ($vs !== '' && strlen($vs) <= 20) {
            $this->el($f, 'VarSymbol', $vs);
        }
        $this->el($f, 'Druh', 'N');
        $this->el($f, 'Dobropis', $isCredit ? '1' : '0');
        $this->el($f, 'ZpVypDPH', '1');
        $this->el($f, 'SazbaDPH1', '12');
        $this->el($f, 'SazbaDPH2', '21');

        $this->appendSummary($f, $items, $fx, $sign, (float) ($inv['rounding'] ?? 0), (string) $inv['currency']);

        $notes = [];
        if (!isset(self::KOD_DPH_SALES[$mainCls]) && isset(self::DAP_ROW_NOTE[$mainCls])) {
            $notes[] = 'Členění DPH: ' . self::DAP_ROW_NOTE[$mainCls] . ' (kód ' . $mainCls . ') — doplňte členění v Money.';
        }
        if (!empty($inv['reverse_charge'])) {
            $notes[] = 'Daň odvede zákazník (přenesení daňové povinnosti).';
        }
        if ($notes !== []) {
            $this->el($f, 'Poznamka', implode("\n", $notes));
        }

        $f->appendChild($this->partner($inv));
        $f->appendChild($this->itemsList($items, $fx, $sign, false, self::KOD_DPH_SALES));
        return $f;
    }

    // =========================================================================
    // Faktura přijatá
    // =========================================================================

    private function buildReceived(array $inv): \DOMElement
    {
        $f = $this->dom->createElement('FaktPrij');
        $isCredit = ($inv['document_kind'] ?? '') === 'credit_note';
        $sign = ($isCredit && (float) $inv['total_with_vat'] > 0) ? -1.0 : 1.0;
        $fx = $this->fx($inv, 'Faktura přijatá ' . $inv['invoice_number']);
        $taxDate = (string) ($inv['tax_date'] ?: $inv['issue_date']);
        $country = strtoupper((string) ($inv['p_country'] ?? 'CZ'));

        // Efektivní klasifikace — stejná logika jako PurchaseInvoiceRepository (DPHDP3/KH)
        $items = array_map(function ($it) use ($inv, $country) {
            $cls  = trim((string) ($it['vat_classification'] ?? ''));
            $rate = (float) $it['vat_rate_snapshot'];
            if ($cls === '') {
                if (!empty($inv['reverse_charge']) && $rate == 0.0) {
                    $cls = $country === 'CZ' ? '10-11' : (!empty($inv['p_is_eu']) ? '05-06' : '12-13');
                } else {
                    $cls = $rate > 0 ? '40-41' : '0P';
                }
            }
            $it['cls'] = $cls;
            return $it;
        }, $inv['items']);
        $mainCls = $this->dominantClassification($items);
        $selfAssessed = in_array($mainCls, PurchaseInvoiceRepository::SELF_ASSESSED_CODES, true);

        $vendorNumber = (string) ($inv['invoice_number'] ?? '');
        $own = (string) ($inv['varsymbol'] ?? '');
        if ($own !== '' && mb_strlen($own) <= 10) {
            $this->el($f, 'Doklad', $own);
        }
        $this->el($f, 'Popis', $this->popis($items, ($isCredit ? 'Dobropis ' : 'Faktura ') . $vendorNumber));
        $this->el($f, 'Vystaveno', (string) $inv['issue_date']);
        $this->el($f, 'DatUcPr', $taxDate);
        $this->el($f, 'PlnenoDPH', $taxDate);
        $this->el($f, 'Splatno', (string) $inv['due_date']);
        if ($isCredit && !empty($inv['received_at'])) {
            $this->el($f, 'Doruceno', (string) $inv['received_at']);
        }
        $kod = $selfAssessed ? self::KOD_DPH_PURCHASE_NO_VAT : (self::KOD_DPH_PURCHASES[$mainCls] ?? null);
        if ($kod !== null) {
            $this->el($f, 'KodDPH', $kod);
        }
        // VS jen když je číslo dodavatele čistě číselné (jinak by vznikl nesmysl typu X56FTILY → 56)
        $vs = str_replace(' ', '', $vendorNumber);
        if (preg_match('/^\d{1,10}$/', $vs)) {
            $this->el($f, 'VarSymbol', $vs);
        }
        $this->el($f, 'PrijatDokl', mb_substr($vendorNumber, 0, 50));
        $this->el($f, 'Druh', 'N');
        $this->el($f, 'Dobropis', $isCredit ? '1' : '0');
        $this->el($f, 'ZpVypDPH', '1');
        $this->el($f, 'SazbaDPH1', '12');
        $this->el($f, 'SazbaDPH2', '21');

        $this->appendSummary($f, $items, $fx, $sign, (float) ($inv['rounding'] ?? 0), (string) $inv['currency']);

        // Poznámka: samovyměření / kurz / zaplaceno
        $notes = [];
        if ($selfAssessed) {
            $base = 0.0;
            foreach ($items as $it) {
                if (in_array($it['cls'], PurchaseInvoiceRepository::SELF_ASSESSED_CODES, true)) {
                    $base += (float) $it['total_without_vat'] * $fx;
                }
            }
            $base = round($base, 2) * $sign;
            $vat  = round($base * 0.21, 2);
            $notes[] = sprintf(
                'SAMOVYMĚŘENÍ DPH (§ 108 ZDPH): základ %s Kč, DPH 21 %% %s Kč — %s; KH oddíl %s. Dodavatel: %s%s. Zaúčtujte interním dokladem (daň na výstupu + odpočet).',
                $this->money($base), $this->money($vat),
                self::SELF_ASSESS_ROWS[$mainCls] ?? $mainCls,
                $mainCls === '10-11' ? 'B.1' : 'A.2',
                $country, $inv['p_dic'] ? ' / VAT ID ' . $inv['p_dic'] : '',
            );
        }
        if ($fx !== 1.0) {
            $notes[] = sprintf('Kurz %s: %s CZK (k %s).', $inv['currency'], rtrim(rtrim(number_format($fx, 6, '.', ''), '0'), '.'),
                (string) ($inv['exchange_rate_date'] ?? $taxDate));
        }
        if (($inv['status'] ?? '') === 'paid' && !empty($inv['paid_at'])) {
            $notes[] = 'Uhrazeno ' . $inv['paid_at'] . '.';
        }
        if (!empty($inv['note_above_items'])) {
            $notes[] = (string) $inv['note_above_items'];
        }
        if ($notes !== []) {
            $this->el($f, 'Poznamka', implode("\n", $notes));
        }

        $f->appendChild($this->partner($inv));
        // Samovyměřované položky jdou jako 0 % s členěním 19Ř00P
        $itemCodes = self::KOD_DPH_PURCHASES;
        foreach (PurchaseInvoiceRepository::SELF_ASSESSED_CODES as $c) {
            $itemCodes[$c] = self::KOD_DPH_PURCHASE_NO_VAT;
        }
        $f->appendChild($this->itemsList($items, $fx, $sign, true, $itemCodes));
        return $f;
    }

    // =========================================================================
    // Společné části
    // =========================================================================

    /** Kurz CZK/1 jednotku; u CZK 1. Chybějící kurz → 1 + upozornění. */
    private function fx(array $inv, string $label): float
    {
        if (($inv['currency'] ?? 'CZK') === 'CZK') {
            return 1.0;
        }
        $rate = (float) ($inv['exchange_rate'] ?? 0);
        if ($rate <= 0) {
            $this->warnings[] = "$label ({$inv['currency']}) nemá kurz — částky v CZK nejsou přepočtené, doplňte kurz v MyInvoice";
            return 1.0;
        }
        return $rate;
    }

    /**
     * SouhrnDPH (CZK) + Celkem + případně Valuty (cizí měna).
     */
    private function appendSummary(\DOMElement $f, array $items, float $fx, float $sign, float $rounding, string $currency): void
    {
        $cz = $this->bucket($items, $fx, $sign);
        $fc = $fx !== 1.0 || $currency !== 'CZK' ? $this->bucket($items, 1.0, $sign) : null;

        $roundingCzk = round($rounding * $fx, 2) * $sign;
        $cz['z0'] += $roundingCzk;

        $f->appendChild($this->summaryEl('SouhrnDPH', $cz));
        $this->el($f, 'Celkem', $this->num($cz['total'] + $roundingCzk));

        if ($currency !== 'CZK' && $fc !== null) {
            $fc['z0'] += $rounding * $sign;
            $val  = $this->dom->createElement('Valuty');
            $mena = $this->dom->createElement('Mena');
            $this->el($mena, 'Kod', $currency);
            $this->el($mena, 'Mnozstvi', '1');
            $this->el($mena, 'Kurs', $this->num($fx, 4));   // XSD castkaType: max. 4 desetinná místa
            $val->appendChild($mena);
            $val->appendChild($this->summaryEl('SouhrnDPH', $fc));
            $this->el($val, 'Celkem', $this->num($fc['total'] + $rounding * $sign));
            $f->appendChild($val);
        }
    }

    /** @return array{z0: float, z12: float, d12: float, z21: float, d21: float, other: array, total: float} */
    private function bucket(array $items, float $fx, float $sign): array
    {
        $b = ['z0' => 0.0, 'z12' => 0.0, 'd12' => 0.0, 'z21' => 0.0, 'd21' => 0.0, 'other' => [], 'total' => 0.0];
        foreach ($items as $it) {
            $rate = (float) $it['vat_rate_snapshot'];
            $base = round((float) $it['total_without_vat'] * $fx, 2) * $sign;
            $vat  = round((float) $it['total_vat'] * $fx, 2) * $sign;
            if ($rate == 0.0) {
                $b['z0'] += $base;
            } elseif (abs($rate - 21.0) < 0.01) {
                $b['z21'] += $base; $b['d21'] += $vat;
            } elseif (abs($rate - 12.0) < 0.01) {
                $b['z12'] += $base; $b['d12'] += $vat;
            } else {
                $k = number_format($rate, 2, '.', '');
                $b['other'][$k] ??= ['z' => 0.0, 'd' => 0.0];
                $b['other'][$k]['z'] += $base;
                $b['other'][$k]['d'] += $vat;
            }
            $b['total'] += $base + $vat;
        }
        return $b;
    }

    private function summaryEl(string $name, array $b): \DOMElement
    {
        $s = $this->dom->createElement($name);
        $this->el($s, 'Zaklad0', $this->num($b['z0']));
        $this->el($s, 'Zaklad5', $this->num($b['z12']));
        $this->el($s, 'Zaklad22', $this->num($b['z21']));
        $this->el($s, 'DPH5', $this->num($b['d12']));
        $this->el($s, 'DPH22', $this->num($b['d21']));
        if ($b['other'] !== []) {
            $list = $this->dom->createElement('SeznamDalsiSazby');
            foreach (array_slice($b['other'], 0, 6, true) as $rate => $v) {
                $d = $this->dom->createElement('DalsiSazba');
                $this->el($d, 'HladinaDPH', ((float) $rate) >= 15 ? '2' : '1');
                $this->el($d, 'Sazba', $this->num((float) $rate));
                $this->el($d, 'Zaklad', $this->num($v['z']));
                $this->el($d, 'DPH', $this->num($v['d']));
                $list->appendChild($d);
            }
            $s->appendChild($list);
        }
        return $s;
    }

    /** DodOdb — partner (odběratel u FV, dodavatel u FP). Limity dle XSD: Ulice 50, Misto 40, PSC 10, Stat 20, ICO 10, EMail 50. */
    private function partner(array $inv): \DOMElement
    {
        $p = $this->dom->createElement('DodOdb');
        $name = trim((string) ($inv['p_company'] ?? ''));
        $isFo = false;
        if ($name === '') {
            $name = trim(($inv['p_first'] ?? '') . ' ' . ($inv['p_last'] ?? ''));
            $isFo = $name !== '';
        }
        $name = mb_substr($name, 0, 96);
        $addr = function (string $tag) use ($inv): \DOMElement {
            $a = $this->dom->createElement($tag);
            $this->el($a, 'Ulice', mb_substr(trim((string) ($inv['p_street'] ?? '')), 0, 50));
            $this->el($a, 'Misto', mb_substr(trim((string) ($inv['p_city'] ?? '')), 0, 40));
            $zip = trim((string) ($inv['p_zip'] ?? ''));
            if (in_array(strtoupper((string) ($inv['p_country'] ?? 'CZ')), ['CZ', 'SK'], true)) {
                $zip = str_replace(' ', '', $zip);   // CZ/SK PSČ bez mezery; zahraniční (např. Eircode) ponechat
            }
            $this->el($a, 'PSC', mb_substr($zip, 0, 10));
            $this->el($a, 'Stat', mb_substr((string) ($inv['p_country_name'] ?? ''), 0, 20));
            $this->el($a, 'KodStatu', strtoupper((string) ($inv['p_country'] ?? 'CZ')));
            return $a;
        };
        $this->el($p, 'ObchNazev', $name);
        $p->appendChild($addr('ObchAdresa'));
        $this->el($p, 'FaktNazev', $name);
        $ic  = trim((string) ($inv['p_ic'] ?? ''));
        $dic = strtoupper(str_replace(' ', '', trim((string) ($inv['p_dic'] ?? ''))));
        if ($ic !== '')  $this->el($p, 'ICO', mb_substr($ic, 0, 10));
        if ($dic !== '') $this->el($p, 'DIC', mb_substr($dic, 0, 20));
        $p->appendChild($addr('FaktAdresa'));
        $this->el($p, 'Nazev', $name);
        $p->appendChild($addr('Adresa'));
        $email = trim((string) ($inv['p_email'] ?? ''));
        if ($email !== '') $this->el($p, 'EMail', mb_substr($email, 0, 50));
        $this->el($p, 'PlatceDPH', $dic !== '' ? '1' : '0');
        $this->el($p, 'FyzOsoba', $isFo ? '1' : '0');
        return $p;
    }

    /** SeznamPolozek — neskladové položky. */
    private function itemsList(array $items, float $fx, float $sign, bool $purchase, array $codes): \DOMElement
    {
        $list = $this->dom->createElement('SeznamPolozek');
        foreach (array_values($items) as $i => $it) {
            $p = $this->dom->createElement('Polozka');
            $desc = trim((string) $it['description']) ?: 'Položka';
            $this->el($p, 'Popis', mb_substr(preg_replace('/\s+/', ' ', $desc), 0, 50));
            if (mb_strlen($desc) > 50) {
                $this->el($p, 'Poznamka', $desc);
            }
            $qty  = (float) $it['quantity'] ?: 1.0;
            $rate = (float) $it['vat_rate_snapshot'];
            $unit = (float) $it['unit_price_without_vat'];
            $this->el($p, 'PocetMJ', $this->num($qty * $sign, 3));
            $this->el($p, 'SazbaDPH', $this->num($rate));
            $this->el($p, 'Cena', $this->num($unit * $fx, 4));

            $base = round((float) $it['total_without_vat'] * $fx, 2) * $sign;
            $vat  = round((float) $it['total_vat'] * $fx, 2) * $sign;
            $s = $this->dom->createElement('SouhrnDPH');
            $this->el($s, 'Zaklad_MJ', $this->num($unit * $fx, 4));
            $this->el($s, 'DPH_MJ', $this->num($qty != 0.0 ? ((float) $it['total_vat'] / $qty) * $fx : 0.0, 4));
            $this->el($s, 'Zaklad', $this->num($base));
            $this->el($s, 'DPH', $this->num($vat));
            if ($fx !== 1.0) {
                $v = $this->dom->createElement('Valuty');
                $this->el($v, 'Zaklad_MJ', $this->num($unit, 4));
                $this->el($v, 'DPH_MJ', $this->num($qty != 0.0 ? (float) $it['total_vat'] / $qty : 0.0, 4));
                $this->el($v, 'Zaklad', $this->num((float) $it['total_without_vat'] * $sign));
                $this->el($v, 'DPH', $this->num((float) $it['total_vat'] * $sign));
                $s->appendChild($v);
            }
            $p->appendChild($s);
            $this->el($p, 'CenaTyp', '0');
            $this->el($p, 'Poradi', (string) ($i + 1));
            if (isset($codes[$it['cls']])) {
                $this->el($p, 'KodDPH', $codes[$it['cls']]);
            }
            if ($fx !== 1.0) {
                $this->el($p, 'Valuty', $this->num($unit, 4));
            }
            $n = $this->dom->createElement('NesklPolozka');
            $mj = trim((string) ($it['unit'] ?? ''));
            if ($mj !== '') $this->el($n, 'MJ', mb_substr($mj, 0, 10));
            $this->el($n, 'Protizapis', '0');
            $p->appendChild($n);
            $list->appendChild($p);
        }
        return $list;
    }

    /** Dominantní klasifikace = klasifikace s největším absolutním základem. */
    private function dominantClassification(array $items): string
    {
        $sum = [];
        foreach ($items as $it) {
            $sum[$it['cls']] = ($sum[$it['cls']] ?? 0.0) + abs((float) $it['total_without_vat']);
        }
        if ($sum === []) return '';
        arsort($sum);
        return (string) array_key_first($sum);
    }

    private function popis(array $items, string $fallback): string
    {
        $first = trim((string) ($items[0]['description'] ?? ''));
        $text = $first !== '' ? $first : $fallback;
        return mb_substr(preg_replace('/\s+/', ' ', $text), 0, 50);
    }

    private function el(\DOMElement $parent, string $name, string $value): void
    {
        $e = $this->dom->createElement($name);
        $e->appendChild($this->dom->createTextNode($value));
        $parent->appendChild($e);
    }

    private function num(float $v, int $dec = 2): string
    {
        $s = number_format(round($v, $dec), $dec, '.', '');
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return $s === '-0' ? '0' : $s;
    }

    private function money(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }

    private function guid(): string
    {
        $h = bin2hex(random_bytes(16));
        return strtoupper(sprintf('{%s-%s-%s-%s-%s}', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12)));
    }
}
