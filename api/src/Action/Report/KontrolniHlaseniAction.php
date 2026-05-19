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
 *   VetaA1 — PDP dodavatel §92a (stavební práce, zlato, emisní povolenky)
 *             → vydané faktury s klasifikací "25"
 *   VetaA3 — osvobozená plnění bez nároku na odpočet (§51)
 *             → vydané faktury s klasifikací "50" (souhrnný řádek obrat_osv)
 *   VetaA4 — standardní vydaná plnění ≥ 10 000 Kč s DIČ odběratele
 *   VetaA5 — souhrnný řádek pro ostatní vydaná plnění (< 10 000 Kč nebo bez DIČ)
 *   VetaB1 — přijaté od neplátce nebo samozdanění (klasifikace "10-11", "12-13")
 *   VetaB2 — standardní přijatá plnění ≥ 10 000 Kč s DIČ dodavatele
 *   VetaB3 — souhrnný řádek pro ostatní přijatá plnění
 *   VetaC  — rekapitulace (obrat, pln, PDP, EU acquisitions)
 *
 * Rate sloty (DPHKH1):
 *   zakl_dane1 / dan1 → 21 %
 *   zakl_dane2 / dan2 → 15 % / 12 %
 *   zakl_dane3 / dan3 → 10 %
 *
 * Query params:
 *   year   int    (required)
 *   month  int    (required, 1–12)
 *   format string (xml | json; default xml)
 */
final class KontrolniHlaseniAction
{
    /** Práh (Kč s DPH) pro individuální vs. souhrnný řádek. */
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

        // ── Validace povinných EPO polí ─────────────────────────────────────
        $validationErrors = $this->validateSupplierInfo($ourInfo);
        if ($validationErrors !== []) {
            $body = json_encode([
                'error'  => 'Neúplné nastavení pro EPO podání',
                'fields' => $validationErrors,
            ], JSON_UNESCAPED_UNICODE);
            $response->getBody()->write((string) $body);
            return $response->withHeader('Content-Type', 'application/json; charset=UTF-8')
                            ->withStatus(422);
        }

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

        $dic              = $this->normalizeDic($ourInfo['dic']);
        $epoFilename      = sprintf('DPHKH1-%s-%s.xml', $dic, date('Ymd-His'));
        $downloadFilename = sprintf('MyInvoice_KontrolniHlaseni_%d_%02d.xml', $year, $month);

        $xml = $this->buildXml($issuedInvoices, $receivedInvoices, $ourInfo, $year, $month, $epoFilename);

        $body = json_encode([
            'xml_content' => $xml,
            'filename'    => $downloadFilename,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
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
                    COALESCE(s.tax_titul,      "")                AS tax_titul,
                    COALESCE(s.tax_jmeno,      "")                AS tax_jmeno,
                    COALESCE(s.tax_prijmeni,   "")                AS tax_prijmeni,
                    COALESCE(s.tax_c_pop,      "")                AS tax_c_pop,
                    COALESCE(s.tax_email,      s.email,   "")     AS tax_email,
                    COALESCE(s.tax_telef,      s.phone,   "")     AS tax_telef,
                    COALESCE(s.tax_stat,       "ČESKÁ REPUBLIKA") AS tax_stat
               FROM supplier s
              WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return array_fill_keys([
                'dic', 'ic', 'company_name', 'display_name', 'street', 'city', 'zip', 'email', 'phone',
                'tax_ufo', 'tax_pracufo', 'tax_typ_platce', 'tax_typ_ds',
                'tax_titul', 'tax_jmeno', 'tax_prijmeni', 'tax_c_pop',
                'tax_email', 'tax_telef', 'tax_stat',
            ], '');
        }

        return array_map('strval', $row);
    }

    /** @return array<string, string> */
    private function validateSupplierInfo(array $info): array
    {
        $errors = [];
        if ($info['tax_ufo'] === '') {
            $errors['tax_ufo'] = 'Kód finančního úřadu (c_ufo) je povinný. Vyplňte ho v Nastavení → Daňové údaje.';
        }
        if ($info['tax_pracufo'] === '') {
            $errors['tax_pracufo'] = 'Kód pracoviště finančního úřadu (c_pracufo) je povinný.';
        }
        if ($info['dic'] === '') {
            $errors['dic'] = 'DIČ plátce je povinné.';
        }
        return $errors;
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

        $kc           = md5($body);
        $delka        = strlen($body);
        $xUfo         = $this->xe($ourInfo['tax_ufo']);
        $filenameBase = (string) preg_replace('/\.xml$/i', '', $filename);
        $kontrola     = "<Kontrola><Soubor Delka=\"{$delka}\" KC=\"{$kc}\" Nazev=\"{$filenameBase}\" c_ufo=\"{$xUfo}\" /></Kontrola>";

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
        $d_poddp    = date('d.m.Y');
        $taxUfo     = $ourInfo['tax_ufo'];
        $taxPracufo = $ourInfo['tax_pracufo'];
        $typDs      = $ourInfo['tax_typ_ds'] ?: 'F';

        // ── VetaP atributy ─────────────────────────────────────────────────
        $xDic      = $this->xe($dic);
        $xUfo      = $this->xe($taxUfo);
        $xPracufo  = $this->xe($taxPracufo);
        $xUlice    = $this->xe($ourInfo['street'] ?? '');
        $xNazObce  = $this->xe(mb_strtoupper($ourInfo['city'], 'UTF-8'));
        $xCPop     = $this->xe($ourInfo['tax_c_pop']);
        $xPsc      = $this->xe(str_replace(' ', '', $ourInfo['zip']));
        $xStat     = $this->xe($ourInfo['tax_stat'] ?: 'ČESKÁ REPUBLIKA');
        $xEmail    = $this->xe($ourInfo['tax_email']);
        $xTelef    = $this->xe($ourInfo['tax_telef']);

        $isFo = ($ourInfo['tax_jmeno'] !== '' || $ourInfo['tax_prijmeni'] !== '');
        if ($isFo) {
            $xTitul    = $this->xe($ourInfo['tax_titul']);
            $xJmeno    = $this->xe($ourInfo['tax_jmeno']);
            $xPrijmeni = $this->xe($ourInfo['tax_prijmeni']);
        } else {
            $xTitul    = '';
            $xJmeno    = $this->xe($ourInfo['company_name']);
            $xPrijmeni = '';
        }

        $vetaPAttrs = "dic=\"{$xDic}\"";
        if ($xUfo)      $vetaPAttrs .= " c_ufo=\"{$xUfo}\"";
        if ($xPracufo)  $vetaPAttrs .= " c_pracufo=\"{$xPracufo}\"";
        if ($typDs)     $vetaPAttrs .= " typ_ds=\"{$typDs}\"";
        if ($xTitul)    $vetaPAttrs .= " titul=\"{$xTitul}\"";
        if ($xJmeno)    $vetaPAttrs .= " jmeno=\"{$xJmeno}\"";
        if ($xPrijmeni) $vetaPAttrs .= " prijmeni=\"{$xPrijmeni}\"";
        if ($xUlice)    $vetaPAttrs .= " ulice=\"{$xUlice}\"";
        if ($xCPop)     $vetaPAttrs .= " c_pop=\"{$xCPop}\"";
        if ($xNazObce)  $vetaPAttrs .= " naz_obce=\"{$xNazObce}\"";
        if ($xPsc)      $vetaPAttrs .= " psc=\"{$xPsc}\"";
        if ($xStat)     $vetaPAttrs .= " stat=\"{$xStat}\"";
        if ($xEmail)    $vetaPAttrs .= " email=\"{$xEmail}\"";
        if ($xTelef)    $vetaPAttrs .= " c_telef=\"{$xTelef}\"";

        // ── Rozdělení vydaných faktur dle klasifikace ──────────────────────
        //   A1: PDP dodavatel §92a (klasifikace "25")
        //   A3: Osvobozená bez nároku (klasifikace "50")
        //   A4: standardní ≥ 10 000 Kč s DIČ
        //   A5: ostatní (souhrnné)
        $a1Invoices = [];
        $a3Invoices = [];
        $a4Invoices = [];
        $a5Invoices = [];

        foreach ($issuedInvoices as $inv) {
            $cls = $inv['classification'];
            if ($cls === '25') {
                $a1Invoices[] = $inv;
            } elseif ($cls === '50') {
                $a3Invoices[] = $inv;
            } elseif ($inv['total_with_vat'] >= self::THRESHOLD && $inv['client_dic'] !== '') {
                $a4Invoices[] = $inv;
            } else {
                $a5Invoices[] = $inv;
            }
        }

        // ── Rozdělení přijatých faktur dle klasifikace ─────────────────────
        //   B1: samozdanění / od neplátce (klasifikace "10-11", "12-13")
        //   B2: standardní ≥ 10 000 Kč s DIČ
        //   B3: ostatní (souhrnné)
        $b1Invoices = [];
        $b2Invoices = [];
        $b3Invoices = [];

        foreach ($receivedInvoices as $inv) {
            $cls = $inv['classification'];
            if (in_array($cls, ['10-11', '12-13'], true)) {
                $b1Invoices[] = $inv;
            } elseif ($inv['total_with_vat'] >= self::THRESHOLD && $inv['vendor_dic'] !== '') {
                $b2Invoices[] = $inv;
            } else {
                $b3Invoices[] = $inv;
            }
        }

        // ── Sestavení XML sekcí ────────────────────────────────────────────
        $a1Xml = $this->buildVetaA1($a1Invoices);
        $a3Xml = $this->buildVetaA3($a3Invoices);
        $a4Xml = $this->buildVetaA4($a4Invoices);
        $a5Xml = $this->buildVetaA5($a5Invoices);
        $b1Xml = $this->buildVetaB1($b1Invoices);
        $b2Xml = $this->buildVetaB2($b2Invoices);
        $b3Xml = $this->buildVetaB3($b3Invoices);
        $cXml  = $this->buildVetaC($a1Invoices, $a4Invoices, $a5Invoices, $receivedInvoices);

        return "<DPHKH1 verzePis=\"03.01\">\n"
            . "<VetaD dokument=\"KH1\" k_uladis=\"DPH\" mesic=\"{$month}\" rok=\"{$year}\" d_poddp=\"{$d_poddp}\" khdph_forma=\"B\" />\n"
            . "<VetaP {$vetaPAttrs} />\n"
            . $a1Xml
            . $a3Xml
            . $a4Xml
            . $a5Xml
            . $b1Xml
            . $b2Xml
            . $b3Xml
            . $cXml
            . "</DPHKH1>";
    }

    // =========================================================================
    // Sekce A — vydaná plnění
    // =========================================================================

    /**
     * VetaA1 — PDP dodavatel §92a (stavební práce, zlato, emisní povolenky).
     * Faktury s klasifikací "25". DIČ odběratele je povinné (PDP = plátce → má DIČ).
     * kod_rezim_pl="1" = stavební práce §92e — nejčastější případ §92a.
     * Pro jiné typy (zlato=4, emisní=6) by bylo třeba rozlišit typem položky.
     */
    private function buildVetaA1(array $invoices): string
    {
        if ($invoices === []) {
            return '';
        }

        $xml    = '';
        $rowNum = 1;

        foreach ($invoices as $inv) {
            $slots = $this->sumItemsBySlot($inv['items']);
            $dppd  = $this->fmtDate($inv['tax_date']);
            $cEvid = $this->xe($inv['varsymbol'] ?: (string) $inv['id']);
            $dic   = $this->xe($inv['client_dic']);

            $xml .= sprintf(
                '<VetaA1 c_radku="%d" dic_odb="%s" c_evid_dd="%s" dppd="%s"'
                . ' zakl_dane1="%s" dan1="%s" zakl_dane2="%s" dan2="%s" zakl_dane3="%s" dan3="%s"'
                . ' kod_rezim_pl="1" zdph_44="N" />' . "\n",
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
     * VetaA3 — osvobozená plnění bez nároku na odpočet (§51).
     * Souhrnný řádek s celkovým obratem (základ DPH bez daně, DPH=0).
     */
    private function buildVetaA3(array $invoices): string
    {
        if ($invoices === []) {
            return '';
        }

        $obrat_osv = 0.0;
        foreach ($invoices as $inv) {
            foreach ($inv['items'] as $item) {
                $obrat_osv += (float) $item['base'];
            }
        }

        if ($obrat_osv == 0.0) {
            return '';
        }

        return sprintf('<VetaA3 obrat_osv="%s" />' . "\n", $this->fmt($obrat_osv));
    }

    /**
     * VetaA4 — standardní vydaná plnění ≥ 10 000 Kč s DIČ odběratele.
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
     * VetaA5 — souhrnný řádek pro ostatní vydaná plnění (< 10 000 Kč nebo bez DIČ).
     * Vynechán pokud jsou všechny sloty nulové.
     */
    private function buildVetaA5(array $invoices): string
    {
        $totals = $this->aggregateBySlot($invoices);

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

    // =========================================================================
    // Sekce B — přijatá plnění
    // =========================================================================

    /**
     * VetaB1 — přijatá plnění s povinností přiznat daň:
     *   "10-11": PDP příjemce §92a (stavební práce přijaté od plátce)
     *   "12-13": ostatní s povinností přiznat §108 (přijaté od neplátce, samozdanění)
     *
     * dic_dod je volitelné — neplátce DPH nemá DIČ, proto se generuje jen pokud vyplněno.
     */
    private function buildVetaB1(array $invoices): string
    {
        if ($invoices === []) {
            return '';
        }

        $xml    = '';
        $rowNum = 1;

        foreach ($invoices as $inv) {
            $slots = $this->sumItemsBySlot($inv['items']);
            $dppd  = $this->fmtDate($inv['tax_date']);
            $cEvid = $this->xe($inv['invoice_number'] ?: $inv['varsymbol'] ?: (string) $inv['id']);

            $dicAttr = $inv['vendor_dic'] !== ''
                ? sprintf(' dic_dod="%s"', $this->xe($inv['vendor_dic']))
                : '';

            $xml .= sprintf(
                '<VetaB1 c_radku="%d"%s c_evid_dd="%s" dppd="%s"'
                . ' zakl_dane1="%s" dan1="%s" zakl_dane2="%s" dan2="%s" zakl_dane3="%s" dan3="%s" />' . "\n",
                $rowNum++,
                $dicAttr,
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
     * VetaB2 — standardní přijatá plnění ≥ 10 000 Kč s DIČ dodavatele.
     * pomer="A" pokud faktura obsahuje krácené klasifikace (40-41k, 40-41mk).
     */
    private function buildVetaB2(array $invoices): string
    {
        $xml    = '';
        $rowNum = 1;

        foreach ($invoices as $inv) {
            $slots = $this->sumItemsBySlot($inv['items']);
            $dppd  = $this->fmtDate($inv['tax_date']);
            $cEvid = $this->xe($inv['invoice_number'] ?: $inv['varsymbol'] ?: (string) $inv['id']);
            $dic   = $this->xe($inv['vendor_dic']);

            $hasPartial = $this->invoiceHasClassification($inv['items'], ['40-41k', '40-41mk']);
            $pomer      = $hasPartial ? 'A' : 'N';
            $xml .= sprintf(
                '<VetaB2 c_radku="%d" dic_dod="%s" c_evid_dd="%s" dppd="%s"'
                . ' zakl_dane1="%s" dan1="%s" zakl_dane2="%s" dan2="%s" zakl_dane3="%s" dan3="%s"'
                . ' kod_rezim_pl="0" pomer="%s" zdph_44="N" />' . "\n",
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
                $pomer,
            );
        }

        return $xml;
    }

    /**
     * VetaB3 — souhrnný řádek pro ostatní přijatá plnění.
     * Vždy emitováno (i jako nulový řádek) — EPO to vyžaduje.
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

    // =========================================================================
    // VetaC — rekapitulace
    // =========================================================================

    /**
     * VetaC — rekapitulační řádek.
     *
     * obrat23/5     = standardní vydaná zdanitelná plnění (A4+A5) 21% / snížená
     * pln23/5       = přijatá zdanitelná plnění (B1+B2+B3) 21% / snížená
     * rez_pren23/5  = základ PDP dodavatel §92a (A1) 21% / snížená
     * pln_rez_pren  = celkový základ PDP dodavatel (A1)
     * celk_zd_a2    = základ VetaA2 — zatím 0 (EU §92b–f neimplementováno)
     */
    private function buildVetaC(
        array $a1Invoices,
        array $a4Invoices,
        array $a5Invoices,
        array $receivedInvoices,
    ): string {
        $standardIssued = array_merge($a4Invoices, $a5Invoices);
        $issuedTotals   = $this->aggregateBySlot($standardIssued);
        $obrat23        = $issuedTotals[1]['base'];
        $obrat5         = $issuedTotals[2]['base'] + $issuedTotals[3]['base'];

        $receivedTotals = $this->aggregateBySlot($receivedInvoices);
        $pln23          = $receivedTotals[1]['base'];
        $pln5           = $receivedTotals[2]['base'] + $receivedTotals[3]['base'];

        $a1Totals     = $this->aggregateBySlot($a1Invoices);
        $rez_pren23   = $a1Totals[1]['base'];
        $rez_pren5    = $a1Totals[2]['base'] + $a1Totals[3]['base'];
        $pln_rez_pren = $rez_pren23 + $rez_pren5;

        $celk_zd_a2 = 0.0;

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

    private function rateToSlot(float $rate): int
    {
        if ($rate >= 20.5) return 1;
        if ($rate >= 11.5) return 2;
        if ($rate >= 5.0)  return 3;
        return 0;
    }

    /** @return array<int, array{base: float, vat: float}> */
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

    /** @return array<int, array{base: float, vat: float}> */
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

    private function invoiceHasClassification(array $items, array $classifications): bool
    {
        foreach ($items as $item) {
            if (in_array($item['classification'] ?? '', $classifications, true)) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    private function fmtDate(string $date): string
    {
        if ($date === '' || $date === '0000-00-00') return '';
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', substr($date, 0, 10));
        return $dt !== false ? $dt->format('d.m.Y') : $date;
    }

    private function fmt(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

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
