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
 * GET /api/reports/dphdp3
 *
 * Export DAP DPH ve formatu DPHDP3 pro EPO (Elektronicke podani orgánům veřejné moci).
 * Formát odpovídá specifikaci MF ČR — formulář č. 25 5412 (DPHDP3 verzePis="03.01").
 *
 * Pokryté sekce:
 *   VetaD  — hlavička přiznání (druh, typ, rok/mesic, trans)
 *   VetaP  — identifikace plátce
 *   Veta1  — výstupy: zdanitelná plnění tuzemsko (ř. 1/2) + PDP dodavatel (ř. 25)
 *   Veta2  — výstupy: plnění osv. s nárokem (vývoz, dodání zboží do EU, služby EU, PDP)
 *   Veta3  — opravy, dovoz osvobozený, třístranný obchod
 *   Veta4  — vstupy: odpočet daně tuzemsko (ř. 40/41), dovoz (ř. 42), ostatní (ř. 43)
 *   Veta5  — koeficient pro krácení odpočtu (osvobozená plnění bez nároku)
 *   Veta6  — rekapitulace (celková daň, celkový odpočet, vlastní daňová povinnost)
 *
 * Query params:
 *   year   int    (required)
 *   month  int    (required, 1–12)
 *   format string (xml | json; default xml)
 */
final class DphPriznaniAction
{
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
        $ourInfo    = $this->getOurSupplierInfo($supplierId);

        $issuedVat   = $this->invoiceRepo->getVatSummaryByClassification($dateFrom, $dateTo, $supplierId);
        $receivedVat = $this->purchaseInvoiceRepo->getVatSummaryByClassification($dateFrom, $dateTo, $supplierId);

        if (($q['format'] ?? '') === 'json') {
            $body = json_encode([
                'period'   => compact('dateFrom', 'dateTo', 'year', 'month'),
                'supplier' => $ourInfo,
                'issued'   => $issuedVat,
                'received' => $receivedVat,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response->getBody()->write((string) $body);
            return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        }

        $filename = sprintf('DPHDP3-%s-%d%02d.xml', $this->normalizeDic($ourInfo['dic']), $year, $month);
        $xml      = $this->buildXml($issuedVat, $receivedVat, $ourInfo, $year, $month, $filename);

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
                    COALESCE(s.tax_ufo,       "")               AS tax_ufo,
                    COALESCE(s.tax_pracufo,   "")               AS tax_pracufo,
                    COALESCE(s.tax_okec,      "")               AS tax_okec,
                    COALESCE(s.tax_typ_platce,"P")              AS tax_typ_platce,
                    COALESCE(s.tax_typ_ds,    "F")              AS tax_typ_ds,
                    COALESCE(s.tax_titul,     "")               AS tax_titul,
                    COALESCE(s.tax_jmeno,     "")               AS tax_jmeno,
                    COALESCE(s.tax_prijmeni,  "")               AS tax_prijmeni,
                    COALESCE(s.tax_c_pop,     "")               AS tax_c_pop,
                    COALESCE(s.tax_email,     s.email,  "")     AS tax_email,
                    COALESCE(s.tax_telef,     s.phone,  "")     AS tax_telef,
                    COALESCE(s.tax_stat,      "ČESKÁ REPUBLIKA") AS tax_stat,
                    c.iso2                                       AS country_iso
               FROM supplier s
               JOIN countries c ON c.id = s.country_id
              WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return array_fill_keys([
                'dic','ic','company_name','display_name','street','city','zip','email','phone',
                'tax_ufo','tax_pracufo','tax_okec','tax_typ_platce','tax_typ_ds',
                'tax_titul','tax_jmeno','tax_prijmeni','tax_c_pop',
                'tax_email','tax_telef','tax_stat','country_iso',
            ], '');
        }

        return array_map('strval', $row);
    }

    // =========================================================================
    // XML build
    // =========================================================================

    private function buildXml(
        array $issuedVat,
        array $receivedVat,
        array $ourInfo,
        int $year,
        int $month,
        string $filename,
    ): string {
        $issued   = $this->indexByClassification($issuedVat);
        $received = $this->indexByClassification($receivedVat);

        // === Veta1 ===
        $domesticIssuedCodes = ['01-02', '01-02c', '01-02p', '01-02r'];
        $dan23   = $this->sumVat($issued, $domesticIssuedCodes, [21.0]);
        $obrat23 = $this->sumBase($issued, $domesticIssuedCodes, [21.0]);
        $dan5    = $this->sumVat($issued, $domesticIssuedCodes, [15.0, 12.0, 10.0]);
        $obrat5  = $this->sumBase($issued, $domesticIssuedCodes, [15.0, 12.0, 10.0]);

        $rez_pren23 = $this->sumBase($issued, ['25'], [21.0]);
        $rez_pren5  = $this->sumBase($issued, ['25'], [15.0, 12.0, 10.0]);

        // === Veta2 ===
        $pln_vyvoz    = $this->sumBase($issued, ['22'], null);
        $dod_zb       = $this->sumBase($issued, ['20'], null);
        $pln_sluzby   = $this->sumBase($issued, ['31'], null);
        $pln_rez_pren = $this->sumBase($issued, ['25'], null);
        $pln_ost      = $this->sumBase($issued, ['50'], null);

        // === Veta4 ===
        $fullCodes    = ['40-41', '40-41m'];
        $partialCodes = ['40-41k', '40-41mk'];
        $importCodes  = ['42', '42m'];

        $odp_tuz23_nar = $this->sumVat($received, $fullCodes, [21.0]);
        $odp_tuz5_nar  = $this->sumVat($received, $fullCodes, [15.0, 12.0, 10.0]);
        $pln23         = $this->sumBase($received, $fullCodes, [21.0]);
        $pln5          = $this->sumBase($received, $fullCodes, [15.0, 12.0, 10.0]);

        $odp_tuz23  = $this->sumVat($received, $partialCodes, [21.0]);
        $odp_tuz5   = $this->sumVat($received, $partialCodes, [15.0, 12.0, 10.0]);
        $pln23     += $this->sumBase($received, $partialCodes, [21.0]);
        $pln5      += $this->sumBase($received, $partialCodes, [15.0, 12.0, 10.0]);

        $odp_ost_nar = $this->sumVat($received, ['43'], null);
        $dov_cu      = $this->sumBase($received, $importCodes, null);
        $odp_cu_nar  = $this->sumVat($received, $importCodes, null);

        $odp_sum_nar = $odp_tuz23_nar + $odp_tuz5_nar + $odp_cu_nar + $odp_ost_nar;
        $odp_sum_kr  = $odp_tuz23 + $odp_tuz5;

        // === Veta6 ===
        $dan_zocelk = $dan23 + $dan5;
        $odp_zocelk = $odp_sum_nar + $odp_sum_kr;
        $net        = $dan_zocelk - $odp_zocelk;
        $dano_da    = max(0.0, $net);
        $dano_no    = max(0.0, -$net);
        $trans      = $net >= 0 ? 'A' : 'N';

        // === VetaD / VetaP ===
        $dic        = $this->normalizeDic($ourInfo['dic']);
        $d_poddp    = date('d.m.Y');
        $taxOkec    = $ourInfo['tax_okec']      ?: '631000';
        $taxUfo     = $ourInfo['tax_ufo']        ?: '';
        $taxPracufo = $ourInfo['tax_pracufo']    ?: '';
        $typPlatce  = $ourInfo['tax_typ_platce'] ?: 'P';
        $typDs      = $ourInfo['tax_typ_ds']     ?: 'F';

        $body = $this->renderBody(
            year: $year, month: $month, trans: $trans, typPlatce: $typPlatce,
            d_poddp: $d_poddp, taxOkec: $taxOkec,
            dic: $dic, taxUfo: $taxUfo, taxPracufo: $taxPracufo, typDs: $typDs,
            ourInfo: $ourInfo,
            dan23: $dan23, obrat23: $obrat23, dan5: $dan5, obrat5: $obrat5,
            rez_pren23: $rez_pren23, rez_pren5: $rez_pren5,
            pln_vyvoz: $pln_vyvoz, dod_zb: $dod_zb, pln_sluzby: $pln_sluzby,
            pln_rez_pren: $pln_rez_pren, pln_ost: $pln_ost,
            odp_tuz23_nar: $odp_tuz23_nar, odp_tuz5_nar: $odp_tuz5_nar,
            odp_tuz23: $odp_tuz23, odp_tuz5: $odp_tuz5,
            pln23: $pln23, pln5: $pln5,
            dov_cu: $dov_cu, odp_cu_nar: $odp_cu_nar,
            odp_sum_nar: $odp_sum_nar, odp_sum_kr: $odp_sum_kr,
            dan_zocelk: $dan_zocelk, odp_zocelk: $odp_zocelk,
            dano_da: $dano_da, dano_no: $dano_no,
        );

        $kc           = md5($body);
        $delka        = strlen($body);
        $filenameBase = preg_replace('/\.xml$/i', '', $filename);
        $kontrola     = "<Kontrola><Soubor Delka=\"{$delka}\" KC=\"{$kc}\" Nazev=\"{$filenameBase}\" c_ufo=\"{$taxUfo}\" /></Kontrola>";

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<Pisemnost nazevSW=\"EPO MF ČR\" verzeSW=\"47.2.1\">\n"
            . $body . "\n"
            . $kontrola . "</Pisemnost>\n";
    }

    private function renderBody(
        int $year, int $month, string $trans, string $typPlatce,
        string $d_poddp, string $taxOkec,
        string $dic, string $taxUfo, string $taxPracufo, string $typDs,
        array $ourInfo,
        float $dan23, float $obrat23, float $dan5, float $obrat5,
        float $rez_pren23, float $rez_pren5,
        float $pln_vyvoz, float $dod_zb, float $pln_sluzby,
        float $pln_rez_pren, float $pln_ost,
        float $odp_tuz23_nar, float $odp_tuz5_nar,
        float $odp_tuz23, float $odp_tuz5,
        float $pln23, float $pln5,
        float $dov_cu, float $odp_cu_nar,
        float $odp_sum_nar, float $odp_sum_kr,
        float $dan_zocelk, float $odp_zocelk,
        float $dano_da, float $dano_no,
    ): string {
        $i = fn (float $v): string => (string) (int) round($v);

        $xDic        = $this->xe($dic);
        $xUfo        = $this->xe($taxUfo);
        $xPracufo    = $this->xe($taxPracufo);
        $xTitul      = $this->xe($ourInfo['tax_titul']);
        $xJmeno      = $this->xe($ourInfo['tax_jmeno']);
        $xPrijmeni   = $this->xe($ourInfo['tax_prijmeni']);
        $xCPop       = $this->xe($ourInfo['tax_c_pop']);
        $xNazObce    = $this->xe($ourInfo['city']);
        $xPsc        = $this->xe(str_replace(' ', '', $ourInfo['zip']));
        $xStat       = $this->xe($ourInfo['tax_stat'] ?: 'ČESKÁ REPUBLIKA');
        $xEmail      = $this->xe($ourInfo['tax_email']);
        $xTelef      = $this->xe($ourInfo['tax_telef']);

        $vetaPAttrs = "dic=\"{$xDic}\"";
        if ($xUfo)      $vetaPAttrs .= " c_ufo=\"{$xUfo}\"";
        if ($xPracufo)  $vetaPAttrs .= " c_pracufo=\"{$xPracufo}\"";
        if ($typDs)     $vetaPAttrs .= " typ_ds=\"{$typDs}\"";
        if ($xTitul)    $vetaPAttrs .= " titul=\"{$xTitul}\"";
        if ($xJmeno)    $vetaPAttrs .= " jmeno=\"{$xJmeno}\"";
        if ($xPrijmeni) $vetaPAttrs .= " prijmeni=\"{$xPrijmeni}\"";
        if ($xCPop)     $vetaPAttrs .= " c_pop=\"{$xCPop}\"";
        if ($xNazObce)  $vetaPAttrs .= " naz_obce=\"{$xNazObce}\"";
        if ($xPsc)      $vetaPAttrs .= " psc=\"{$xPsc}\"";
        if ($xStat)     $vetaPAttrs .= " stat=\"{$xStat}\"";
        if ($xEmail)    $vetaPAttrs .= " email=\"{$xEmail}\"";
        if ($xTelef)    $vetaPAttrs .= " c_telef=\"{$xTelef}\"";

        // kod_zo="M" je povinné pro prosinec (uzavírání zdaňovacího období)
        $kodZoAttr = ($month === 12) ? ' kod_zo="M"' : '';

        return "<DPHDP3 verzePis=\"03.01\">\n"
            . "<VetaD c_okec=\"{$taxOkec}\" d_poddp=\"{$d_poddp}\" dapdph_forma=\"B\" dokument=\"DP3\" k_uladis=\"DPH\" mesic=\"{$month}\" rok=\"{$year}\"{$kodZoAttr} trans=\"{$trans}\" typ_platce=\"{$typPlatce}\" />\n"
            . "<VetaP {$vetaPAttrs} />\n"
            . "<Veta1 dan23=\"{$i($dan23)}\" dan5=\"{$i($dan5)}\" dan_dzb23=\"0\" dan_dzb5=\"0\" dan_pdop_nrg=\"0\" dan_psl23_e=\"0\" dan_psl23_z=\"0\" dan_psl5_e=\"0\" dan_psl5_z=\"0\" dan_pzb23=\"0\" dan_pzb5=\"0\" dan_rpren23=\"0\" dan_rpren5=\"0\" dov_zb23=\"0\" dov_zb5=\"0\" obrat23=\"{$i($obrat23)}\" obrat5=\"{$i($obrat5)}\" p_dop_nrg=\"0\" p_sl23_e=\"0\" p_sl23_z=\"0\" p_sl5_e=\"0\" p_sl5_z=\"0\" p_zb23=\"0\" p_zb5=\"0\" rez_pren23=\"{$i($rez_pren23)}\" rez_pren5=\"{$i($rez_pren5)}\" />\n"
            . "<Veta2 dod_dop_nrg=\"0\" dod_zb=\"{$i($dod_zb)}\" pln_ost=\"{$i($pln_ost)}\" pln_rez_pren=\"{$i($pln_rez_pren)}\" pln_sluzby=\"{$i($pln_sluzby)}\" pln_vyvoz=\"{$i($pln_vyvoz)}\" pln_zaslani=\"0\" />\n"
            . "<Veta3 dov_osv=\"0\" opr_dluz=\"0\" opr_verit=\"0\" tri_dozb=\"0\" tri_pozb=\"0\" />\n"
            . "<Veta4 dov_cu=\"{$i($dov_cu)}\" nar_maj=\"0\" nar_zdp23=\"0\" nar_zdp5=\"0\" od_maj=\"0\" od_zdp23=\"0\" od_zdp5=\"0\" odkr_maj=\"0\" odp_cu=\"0\" odp_cu_nar=\"{$i($odp_cu_nar)}\" odp_sum_kr=\"{$i($odp_sum_kr)}\" odp_sum_nar=\"{$i($odp_sum_nar)}\" odp_tuz23=\"{$i($odp_tuz23)}\" odp_tuz23_nar=\"{$i($odp_tuz23_nar)}\" odp_tuz5=\"{$i($odp_tuz5)}\" odp_tuz5_nar=\"{$i($odp_tuz5_nar)}\" pln23=\"{$i($pln23)}\" pln5=\"{$i($pln5)}\" />\n"
            . "<Veta5 plnosv_kf=\"0\" />\n"
            . "<Veta6 dan_zocelk=\"{$i($dan_zocelk)}\" dano_da=\"{$i($dano_da)}\" dano_no=\"{$i($dano_no)}\" odp_zocelk=\"{$i($odp_zocelk)}\" />\n"
            . "</DPHDP3>";
    }

    // =========================================================================
    // VAT aggregation helpers
    // =========================================================================

    /**
     * @param array<int, array{classification: string, rate: float, base: float, vat: float}> $rows
     * @return array<string, array<float, array{base: float, vat: float}>>
     */
    private function indexByClassification(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $cls  = $r['classification'];
            $rate = (float) $r['rate'];
            if (!isset($out[$cls][$rate])) {
                $out[$cls][$rate] = ['base' => 0.0, 'vat' => 0.0];
            }
            $out[$cls][$rate]['base'] += $r['base'];
            $out[$cls][$rate]['vat']  += $r['vat'];
        }
        return $out;
    }

    /**
     * @param array<string, array<float, array{base: float, vat: float}>> $indexed
     * @param string[] $classifications
     * @param float[]|null $rates
     */
    private function sumVat(array $indexed, array $classifications, ?array $rates): float
    {
        $total = 0.0;
        foreach ($classifications as $cls) {
            if (!isset($indexed[$cls])) continue;
            foreach ($indexed[$cls] as $rate => $bv) {
                if ($rates === null || in_array((float) $rate, $rates, true)) {
                    $total += $bv['vat'];
                }
            }
        }
        return $total;
    }

    private function sumBase(array $indexed, array $classifications, ?array $rates): float
    {
        $total = 0.0;
        foreach ($classifications as $cls) {
            if (!isset($indexed[$cls])) continue;
            foreach ($indexed[$cls] as $rate => $bv) {
                if ($rates === null || in_array((float) $rate, $rates, true)) {
                    $total += $bv['base'];
                }
            }
        }
        return $total;
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    private function xe(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function normalizeDic(string $dic): string
    {
        $dic = strtoupper(trim($dic));
        return str_starts_with($dic, 'CZ') ? substr($dic, 2) : $dic;
    }
}
