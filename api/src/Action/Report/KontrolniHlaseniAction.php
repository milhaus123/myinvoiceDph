<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/reports/kontrolni-hlaseni
 *
 * Export Kontrolního hlášení DPH ve formátu DPHKH1 pro EPO (Elektronické podání MF ČR).
 * Formát odpovídá specifikaci MF ČR — formulář č. 25 5564 (DPHKH1 verzePis="03.01").
 *
 * Sekce:
 *   VetaD  — hlavička KH (druh, rok/měsíc, datum podání)
 *   VetaP  — identifikace plátce
 *   VetaA4 — vydané faktury, jednotlivé řádky (plnění ≥ 10 000 Kč s DIC odběratele)
 *   VetaA5 — vydané faktury, souhrnné (ostatní plnění < 10 000 Kč nebo bez DIC)
 *   VetaB2 — přijaté faktury, jednotlivé řádky (plnění ≥ 10 000 Kč s DIC dodavatele)
 *   VetaB3 — přijaté faktury, souhrnné (ostatní)
 *   VetaC  — rekapitulace (obrat23/5, pln23/5, rez_pren, celk_zd_a2)
 *
 * Rate slots (DPHKH1):
 *   zakl_dane1 / dan1 → 21%
 *   zakl_dane2 / dan2 → 15% / 12%
 *   zakl_dane3 / dan3 → 10%
 *
 * Query params:
 *   year   int    (required)
 *   month  int    (required, 1–12)
 *   format string (xml | json; default xml)
 */
final class KontrolniHlaseniAction
{
    /** Threshold (CZK including VAT) for individual vs. aggregate reporting. */
    private const THRESHOLD = 10_000.0;

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly PurchaseInvoiceRepository $purchaseInvoiceRepo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q          = $request->getQueryParams();
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        [$dateFrom, $dateTo, $year, $month] = $this->resolvePeriod($q);
        $ourInfo = $this->getOurSupplierInfo($supplierId);

        $issuedInvoices   = $this->invoiceRepo->getIssuedInvoiceDetails($dateFrom, $dateTo, $supplierId);
        $receivedInvoices = $this->purchaseInvoiceRepo->getReceivedInvoiceDetails($dateFrom, $dateTo, $supplierId);

        if (($q['format'] ?? '') === 'json') {
            $payload = json_encode([
                'period'   => compact('dateFrom', 'dateTo', 'year', 'month'),
                'supplier' => $ourInfo,
                'issued'   => $issuedInvoices,
                'received' => $receivedInvoices,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response->getBody()->write((string) $payload);
            return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        }

        $dic      = $this->normalizeDic($ourInfo['dic']);
        $filename = sprintf('DPHKH1-%s-%s.xml', $dic, date('Ymd-His'));
        $xml      = $this->buildXml($issuedInvoices, $receivedInvoices, $ourInfo, $year, $month, $filename);

        $response->getBody()->write($xml);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    // =========================================================================
    // Period + supplier info
    // =========================================================================

    /** @return array{string, string, int, int} */
    private function resolvePeriod(array $q): array
    {
        $year  = (int) ($q['year']  ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        $month = max(1, min(12, $month));

        $dateFrom = sprintf('%04d-%02d-01', $year, $month);
        $dateTo   = date('Y-m-t', strtotime($dateFrom));

        return [$dateFrom, $dateTo, $year, $month];
    }

    private function getOurSupplierInfo(int $supplierId): array
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT s.dic, s.ic, s.company_name, s.display_name, s.street, s.city, s.zip, s.email, s.phone,
                    COALESCE(s.tax_ufo,        "")                AS tax_ufo,
                    COALESCE(s.tax_pracufo,    "")                AS tax_pracufo,
                    COALESCE(s.tax_typ_platce, "P")               AS tax_typ_platce,
                    COALESCE(s.tax_typ_ds,     "F")               AS tax_typ_ds,
                    COALESCE(s.tax_email,      s.email,   "")     AS tax_email,
                    COALESCE(s.tax_telef,      s.phone,   "")     AS tax_telef,
                    "ČESKÁ REPUBLIKA"                              AS tax_stat
               FROM supplier s
              WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return array_fill_keys([
                'dic', 'ic', 'company_name', 'display_name', 'street', 'city', 'zip', 'email', 'phone',
                'tax_ufo', 'tax_pracufo', 'tax_typ_platce', 'tax_typ_ds',
                'tax_email', 'tax_telef', 'tax_stat',
            ], '');
        }

        return array_map('strval', $row);
    }

    // =========================================================================
    // XML build — outer wrapper + Kontrola
    // =========================================================================

    private function buildXml(
        array $issuedInvoices,
        array $receivedInvoices,
        array $ourInfo,
        int $year,
        int $month,
        string $filename,
    ): string {
        $body = $this->renderBody($issuedInvoices, $receivedInvoices, $ourInfo, $year, $month);

        // Kontrola: MD5 checksum + byte length of DPHKH1 body element
        $kc           = md5($body);
        $delka        = strlen($body);
        $taxUfo       = $ourInfo['tax_ufo'] ?: '';
        $filenameBase = (string) preg_replace('/\.xml$/i', '', $filename);
        $kontrola     = "<Kontrola><Soubor Delka=\"{$delka}\" KC=\"{$kc}\" Nazev=\"{$filenameBase}\" c_ufo=\"{$taxUfo}\" /></Kontrola>";

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<Pisemnost nazevSW=\"EPO MF ČR\" verzeSW=\"47.2.1\">\n"
            . $body . "\n"
            . $kontrola . "</Pisemnost>\n";
    }

    // =========================================================================
    // Body — DPHKH1 element with all Veta sections
    // =========================================================================

    private function renderBody(
        array $issuedInvoices,
        array $receivedInvoices,
        array $ourInfo,
        int $year,
        int $month,
    ): string {
        $dic        = $this->normalizeDic($ourInfo['dic']);
        $d_poddp    = date('d.m.Y');          // date of submission = today
        $taxUfo     = $ourInfo['tax_ufo']     ?: '';
        $taxPracufo = $ourInfo['tax_pracufo'] ?: '';
        $typDs      = $ourInfo['tax_typ_ds']  ?: 'F';

        // --- VetaP attributes (empty optional fields omitted) ---
        $xDic      = $this->xe($dic);
        $xUfo      = $this->xe($taxUfo);
        $xPracufo  = $this->xe($taxPracufo);
        // Jméno plátce = obchodní jméno ze základních údajů (platí pro PO i OSVČ)
        $xJmeno    = $this->xe($ourInfo['company_name']);
        $xNazObce  = $this->xe($ourInfo['city']);
        $xPsc      = $this->xe(str_replace(' ', '', $ourInfo['zip']));
        $xStat     = $this->xe($ourInfo['tax_stat'] ?: 'ČESKÁ REPUBLIKA');
        $xEmail    = $this->xe($ourInfo['tax_email']);
        $xTelef    = $this->xe($ourInfo['tax_telef']);

        $vetaPAttrs = "dic=\"{$xDic}\"";
        if ($xUfo)      $vetaPAttrs .= " c_ufo=\"{$xUfo}\"";
        if ($xPracufo)  $vetaPAttrs .= " c_pracufo=\"{$xPracufo}\"";
        if ($typDs)     $vetaPAttrs .= " typ_ds=\"{$typDs}\"";
        if ($xJmeno)    $vetaPAttrs .= " jmeno=\"{$xJmeno}\"";
        if ($xNazObce)  $vetaPAttrs .= " naz_obce=\"{$xNazObce}\"";
        if ($xPsc)      $vetaPAttrs .= " psc=\"{$xPsc}\"";
        if ($xStat)     $vetaPAttrs .= " stat=\"{$xStat}\"";
        if ($xEmail)    $vetaPAttrs .= " email=\"{$xEmail}\"";
        if ($xTelef)    $vetaPAttrs .= " c_telef=\"{$xTelef}\"";

        // --- Split issued invoices: A.4 individual (≥10k + DIC) vs A.5 aggregate ---
        $a4Invoices = [];
        $a5Invoices = [];
        foreach ($issuedInvoices as $inv) {
            if ($inv['total_with_vat'] >= self::THRESHOLD && $inv['client_dic'] !== '') {
                $a4Invoices[] = $inv;
            } else {
                $a5Invoices[] = $inv;
            }
        }

        // --- Split received invoices: B.2 individual (≥10k + DIC) vs B.3 aggregate ---
        $b2Invoices = [];
        $b3Invoices = [];
        foreach ($receivedInvoices as $inv) {
            if ($inv['total_with_vat'] >= self::THRESHOLD && $inv['vendor_dic'] !== '') {
                $b2Invoices[] = $inv;
            } else {
                $b3Invoices[] = $inv;
            }
        }

        // --- Build section strings ---
        $a4Xml = $this->buildVetaA4($a4Invoices);
        $a5Xml = $this->buildVetaA5($a5Invoices);
        $b2Xml = $this->buildVetaB2($b2Invoices);
        $b3Xml = $this->buildVetaB3($b3Invoices);
        $cXml  = $this->buildVetaC($issuedInvoices, $receivedInvoices);

        return "<DPHKH1 verzePis=\"03.01\">\n"
            . "<VetaD dokument=\"KH1\" k_uladis=\"DPH\" mesic=\"{$month}\" rok=\"{$year}\" d_poddp=\"{$d_poddp}\" khdph_forma=\"B\" />\n"
            . "<VetaP {$vetaPAttrs} />\n"
            . $a4Xml
            . $a5Xml
            . $b2Xml
            . $b3Xml
            . $cXml
            . "</DPHKH1>";
    }

    // =========================================================================
    // Section builders
    // =========================================================================

    /**
     * VetaA4 — vydané faktury, jednotlivé řádky (≥ 10 000 Kč s DIC odběratele).
     * Emitted only when non-empty.
     */
    private function buildVetaA4(array $invoices): string
    {
        $xml    = '';
        $rowNum = 1;

        foreach ($invoices as $inv) {
            $slots = $this->sumItemsBySlot($inv['items']);
            $dppd  = $this->fmtDate($inv['tax_date']);
            $cEvid = $this->xe($inv['varsymbol'] ?: (string) $inv['id']);
            $dic   = $this->xe($inv['client_dic']);

            $xml .= sprintf(
                '<VetaA4 c_radku="%d" dic_odb="%s" c_evid_dd="%s" dppd="%s"'
                . ' zakl_dane1="%s" dan1="%s" zakl_dane2="%s" dan2="%s" zakl_dane3="%s" dan3="%s"'
                . ' kod_rezim_pl="0" zdph_44="N" />' . "\n",
                $rowNum++,
                $dic,
                $cEvid,
                $dppd,
                $this->fmt($slots[1]['base']),
                $this->fmt($slots[1]['vat']),
                $this->fmt($slots[2]['base']),
                $this->fmt($slots[2]['vat']),
                $this->fmt($slots[3]['base']),
                $this->fmt($slots[3]['vat']),
            );
        }

        return $xml;
    }

    /**
     * VetaA5 — vydané faktury, souhrnné (< 10 000 Kč nebo bez DIC).
     * Emitted ONLY when at least one slot is non-zero (EPO ji vynechá pokud je celá nulová).
     */
    private function buildVetaA5(array $invoices): string
    {
        $totals = $this->aggregateBySlot($invoices);

        // Nevypisovat prázdnou VetaA5 — EPO ji v reálných datech vynechává
        $hasValue = false;
        foreach ([1, 2, 3] as $slot) {
            if ($totals[$slot]['base'] != 0.0 || $totals[$slot]['vat'] != 0.0) {
                $hasValue = true;
                break;
            }
        }
        if (!$hasValue) {
            return '';
        }

        return sprintf(
            '<VetaA5 zakl_dane1="%s" dan1="%s" zakl_dane2="%s" dan2="%s" zakl_dane3="%s" dan3="%s" />' . "\n",
            $this->fmt($totals[1]['base']),
            $this->fmt($totals[1]['vat']),
            $this->fmt($totals[2]['base']),
            $this->fmt($totals[2]['vat']),
            $this->fmt($totals[3]['base']),
            $this->fmt($totals[3]['vat']),
        );
    }

    /**
     * VetaB2 — přijaté faktury, jednotlivé řádky (≥ 10 000 Kč s DIC dodavatele).
     * Emitted only when non-empty.
     */
    private function buildVetaB2(array $invoices): string
    {
        $xml    = '';
        $rowNum = 1;

        foreach ($invoices as $inv) {
            $slots = $this->sumItemsBySlot($inv['items']);
            $dppd  = $this->fmtDate($inv['tax_date']);
            // c_evid_dd = číslo daňového dokladu dodavatele (invoice_number = číslo přijaté faktury).
            // Dle KH metodiky se uvádí číslo dokladu tak, jak je uvedeno na přijatém dokladu.
            // Fallback: varsymbol (naše interní evidence), pak ID.
            $cEvid = $this->xe($inv['invoice_number'] ?: $inv['varsymbol'] ?: (string) $inv['id']);
            $dic   = $this->xe($inv['vendor_dic']);

            $xml .= sprintf(
                '<VetaB2 c_radku="%d" dic_dod="%s" c_evid_dd="%s" dppd="%s"'
                . ' zakl_dane1="%s" dan1="%s" zakl_dane2="%s" dan2="%s" zakl_dane3="%s" dan3="%s"'
                . ' kod_rezim_pl="0" pomer="N" zdph_44="N" />' . "\n",
                $rowNum++,
                $dic,
                $cEvid,
                $dppd,
                $this->fmt($slots[1]['base']),
                $this->fmt($slots[1]['vat']),
                $this->fmt($slots[2]['base']),
                $this->fmt($slots[2]['vat']),
                $this->fmt($slots[3]['base']),
                $this->fmt($slots[3]['vat']),
            );
        }

        return $xml;
    }

    /**
     * VetaB3 — přijaté faktury, souhrnné (< 10 000 Kč nebo bez DIC).
     * Always emitted (even when all zeros).
     */
    private function buildVetaB3(array $invoices): string
    {
        $totals = $this->aggregateBySlot($invoices);

        return sprintf(
            '<VetaB3 zakl_dane1="%s" dan1="%s" zakl_dane2="%s" dan2="%s" zakl_dane3="%s" dan3="%s" />' . "\n",
            $this->fmt($totals[1]['base']),
            $this->fmt($totals[1]['vat']),
            $this->fmt($totals[2]['base']),
            $this->fmt($totals[2]['vat']),
            $this->fmt($totals[3]['base']),
            $this->fmt($totals[3]['vat']),
        );
    }

    /**
     * VetaC — rekapitulace (obrat23/5 = vydané, pln23/5 = přijaté).
     *
     * Sums ALL issued (A.4 + A.5 combined) and ALL received (B.2 + B.3 combined).
     * rez_pren and celk_zd_a2 are 0 unless classifications indicate otherwise
     * (PDP / EU acquisition handling via separate VAT classification flow — future).
     */
    private function buildVetaC(array $issuedInvoices, array $receivedInvoices): string
    {
        $issuedTotals   = $this->aggregateBySlot($issuedInvoices);
        $receivedTotals = $this->aggregateBySlot($receivedInvoices);

        // obrat23 = issued 21% base; obrat5 = issued 12%/15% + 10% base
        $obrat23 = $issuedTotals[1]['base'];
        $obrat5  = $issuedTotals[2]['base'] + $issuedTotals[3]['base'];

        // pln23 = received 21% base; pln5 = received 12%/15% + 10% base
        $pln23 = $receivedTotals[1]['base'];
        $pln5  = $receivedTotals[2]['base'] + $receivedTotals[3]['base'];

        // Reverse charge / EU acquisitions — populated via vat_classification in future
        $rez_pren23   = 0.0;
        $rez_pren5    = 0.0;
        $pln_rez_pren = 0.0;
        $celk_zd_a2   = 0.0;

        return sprintf(
            '<VetaC obrat23="%s" obrat5="%s" pln23="%s" pln5="%s"'
            . ' pln_rez_pren="%s" rez_pren23="%s" rez_pren5="%s" celk_zd_a2="%s" />' . "\n",
            $this->fmt($obrat23),
            $this->fmt($obrat5),
            $this->fmt($pln23),
            $this->fmt($pln5),
            $this->fmt($pln_rez_pren),
            $this->fmt($rez_pren23),
            $this->fmt($rez_pren5),
            $this->fmt($celk_zd_a2),
        );
    }

    // =========================================================================
    // Rate-slot aggregation helpers
    // =========================================================================

    /**
     * Map VAT rate (%) to DPHKH1 slot: 1=21%, 2=12%/15%, 3=10%, 0=zero/exempt (skip).
     */
    private function rateToSlot(float $rate): int
    {
        if ($rate >= 20.5) return 1;  // 21%
        if ($rate >= 11.5) return 2;  // 15% (do 2024) / 12% (od 2025)
        if ($rate >= 5.0)  return 3;  // 10%
        return 0;                     // 0% nebo osvobozene - do KH nevstupuje
    }

    /**
     * Sum one invoice's items by rate slot.
     *
     * @param array<int, array{rate: float, base: float, vat: float}> $items
     * @return array<int, array{base: float, vat: float}>
     */
    private function sumItemsBySlot(array $items): array
    {
        $out = [
            1 => ['base' => 0.0, 'vat' => 0.0],
            2 => ['base' => 0.0, 'vat' => 0.0],
            3 => ['base' => 0.0, 'vat' => 0.0],
        ];
        foreach ($items as $item) {
            $slot = $this->rateToSlot((float) $item['rate']);
            if ($slot === 0) continue;
            $out[$slot]['base'] += (float) $item['base'];
            $out[$slot]['vat']  += (float) $item['vat'];
        }
        return $out;
    }

    /**
     * Aggregate items from multiple invoices by rate slot.
     *
     * @param array<int, array{items: array<int, array{rate: float, base: float, vat: float}>}> $invoices
     * @return array<int, array{base: float, vat: float}>
     */
    private function aggregateBySlot(array $invoices): array
    {
        $totals = [
            1 => ['base' => 0.0, 'vat' => 0.0],
            2 => ['base' => 0.0, 'vat' => 0.0],
            3 => ['base' => 0.0, 'vat' => 0.0],
        ];
        foreach ($invoices as $inv) {
            $bySlot = $this->sumItemsBySlot($inv['items']);
            foreach ([1, 2, 3] as $slot) {
                $totals[$slot]['base'] += $bySlot[$slot]['base'];
                $totals[$slot]['vat']  += $bySlot[$slot]['vat'];
            }
        }
        return $totals;
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    /** Convert YYYY-MM-DD to DD.MM.YYYY (EPO date format). */
    private function fmtDate(string $date): string
    {
        if ($date === '' || $date === '0000-00-00') {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', substr($date, 0, 10));
        return $dt !== false ? $dt->format('d.m.Y') : $date;
    }

    /** Format float to 2 decimal places (DPHKH1 uses decimals, unlike DPHDP3 integers). */
    private function fmt(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /** XML-escape a string for attribute values. */
    private function xe(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Strip CZ country prefix from DIC (EPO expects numeric DIC only). */
    private function normalizeDic(string $dic): string
    {
        $dic = strtoupper(trim($dic));
        return str_starts_with($dic, 'CZ') ? substr($dic, 2) : $dic;
    }
}
    }

    /** Format float to 2 decimal places (DPHKH1 uses decimals, unlike DPHDP3 integers). */
    private function fmt(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    /** XML-escape a string for attribute values. */
    private function xe(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Strip CZ country prefix from DIC (EPO expects numeric DIC only). */
    private function normalizeDic(string $dic): string
    {
        $dic = strtoupper(trim($dic));
        return str_starts_with($dic, 'CZ') ? substr($dic, 2) : $dic;
    }
}
