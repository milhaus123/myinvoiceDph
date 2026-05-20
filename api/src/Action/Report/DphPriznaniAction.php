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
 * Export DAP DPH ve formátu DPHDP3 pro EPO (Elektronické podání MF ČR).
 * Formát odpovídá specifikaci MF ČR — formulář č. 25 5412 (DPHDP3 verzePis="03.01").
 *
 * Pokryté sekce:
 *   VetaD  — hlavička přiznání (druh, typ, rok/mesic nebo ctvrt, trans)
 *   VetaP  — identifikace plátce (včetně ulice)
 *   Veta1  — výstupy: tuzemsko (ř. 1/2), EU zboží (ř. 3/4), EU služby (ř. 5/6),
 *             PDP dodavatel (ř. 25)
 *   Veta2  — výstupy: osvobozená s nárokem (vývoz, dodání do EU, služby EU, PDP, ostatní)
 *   Veta3  — třístranný obchod (ř. 30/31), opravy (ř. 16/17), dovoz osv. (ř. 23)
 *   Veta4  — vstupy: tuzemsko (ř. 40/41), dovoz (ř. 42), EU+ostatní (ř. 43)
 *   Veta5  — koeficient pro krácení odpočtu (plnosv_kf)
 *   Veta6  — rekapitulace (celková daň, odpočet, vlastní daňová povinnost)
 *
 * Query params:
 *   year    int    (required)
 *   month   int    (required pro měsíční plátce, 1–12)
 *   quarter int    (required pro čtvrtletní plátce, 1–4; má přednost před month)
 *   format  string (xml | json; default xml)
 *
 * Předpoklady:
 *   - supplier.tax_ufo a tax_pracufo musí být vyplněny (povinné v EPO VetaP).
 *   - supplier.tax_typ_platce: "P" = měsíční plátce, "Q" = čtvrtletní plátce.
 *
 * Klasifikace zboží a služeb (vat_classifications):
 *   Vydané (issued):
 *     01-02, 01-02c, 01-02p, 01-02r  → ř. 1/2 (tuzemsko)
 *     20   → ř. 20 Veta2 (dodání zboží do EU § 64)
 *     21   → ř. 30/31 Veta3 (třístranný obchod prostřední osoba)
 *     22   → ř. 22 Veta2 (vývoz § 66)
 *     25   → ř. 25 Veta1 + ř. 26 Veta2 (PDP dodavatel § 92a)
 *     31   → ř. 21 Veta2 (poskytnutí služby do EU § 9)
 *     50   → Veta2 pln_ost (ostatní osvobozená plnění)
 *   Přijaté (received):
 *     03-04  → ř. 3/4 Veta1 (pořízení zboží z EU) + ř. 43 Veta4 (odpočet)
 *     05-06  → ř. 5/6 Veta1 (přijetí služby ze zahraničí) + ř. 43 Veta4
 *     40-41, 40-41m   → ř. 40 Veta4 (tuzemský odpočet plný)
 *     40-41k, 40-41mk → ř. 41 Veta4 (tuzemský odpočet krácený)
 *     42, 42m         → ř. 42 Veta4 (dovoz odpočet)
 *     43              → ř. 43 Veta4 (ostatní odpočet)
 */
final class DphPriznaniAction
{
    private const LOW_RATES = [15.0, 12.0, 10.0];

    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly PurchaseInvoiceRepository $purchaseInvoiceRepo,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q          = $request->getQueryParams();
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        [$dateFrom, $dateTo, $year, $month, $quarter] = $this->resolvePeriod($q);
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

        $issuedVat   = $this->invoiceRepo->getVatSummaryByClassification($dateFrom, $dateTo, $supplierId);
        $receivedVat = $this->purchaseInvoiceRepo->getVatSummaryByClassification($dateFrom, $dateTo, $supplierId);

        if (($q['format'] ?? '') === 'json') {
            $body = json_encode([
                'period'   => compact('dateFrom', 'dateTo', 'year', 'month', 'quarter'),
                'supplier' => $ourInfo,
                'issued'   => $issuedVat,
                'received' => $receivedVat,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response->getBody()->write((string) $body);
            return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        }

        $periodKey        = $quarter !== null ? sprintf('%dQ%d', $year, $quarter) : sprintf('%d%02d', $year, $month);
        $epoFilename      = sprintf('DPHDP3-%s-%s.xml', $this->normalizeDic($ourInfo['dic']), $periodKey);
        $downloadFilename = sprintf('MyInvoice_DPH_%s.xml', $periodKey);

        $xml = $this->buildXml($issuedVat, $receivedVat, $ourInfo, $year, $month, $quarter, $epoFilename);

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

    /**
     * @return array{string, string, int, int, int|null}
     *         [dateFrom, dateTo, year, month, quarter|null]
     *
     * quarter je null pro měsíční plátce, 1–4 pro čtvrtletní.
     * month je pro čtvrtletní vždy první měsíc čtvrtletí (pro potřeby fallbacku).
     */
    private function resolvePeriod(array $q): array
    {
        $year    = (int) ($q['year'] ?? date('Y'));
        $quarter = isset($q['quarter']) ? max(1, min(4, (int) $q['quarter'])) : null;

        if ($quarter !== null) {
            // Čtvrtletní plátce: quarter=1 → Jan–Mar, 2 → Apr–Jun, …
            $firstMonth = ($quarter - 1) * 3 + 1;
            $lastMonth  = $firstMonth + 2;
            $dateFrom   = sprintf('%04d-%02d-01', $year, $firstMonth);
            $dateTo     = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $lastMonth)));
            return [$dateFrom, $dateTo, $year, $firstMonth, $quarter];
        }

        $month    = (int) ($q['month'] ?? date('n'));
        $month    = max(1, min(12, $month));
        $dateFrom = sprintf('%04d-%02d-01', $year, $month);
        $dateTo   = date('Y-m-t', strtotime($dateFrom));

        return [$dateFrom, $dateTo, $year, $month, null];
    }

    private function getOurSupplierInfo(int $supplierId): array
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT s.dic, s.ic, s.company_name, s.display_name, s.street, s.city, s.zip, s.email, s.phone,
                    COALESCE(s.tax_ufo,       "")                AS tax_ufo,
                    COALESCE(s.tax_pracufo,   "")                AS tax_pracufo,
                    COALESCE(s.tax_okec,      "")                AS tax_okec,
                    COALESCE(s.tax_typ_platce,"P")               AS tax_typ_platce,
                    COALESCE(s.tax_typ_ds,    "F")               AS tax_typ_ds,
                    COALESCE(s.tax_titul,     "")                AS tax_titul,
                    COALESCE(s.tax_jmeno,     "")                AS tax_jmeno,
                    COALESCE(s.tax_prijmeni,  "")                AS tax_prijmeni,
                    COALESCE(s.tax_c_pop,     "")                AS tax_c_pop,
                    COALESCE(s.tax_email,     s.email,  "")      AS tax_email,
                    COALESCE(s.tax_telef,     s.phone,  "")      AS tax_telef,
                    COALESCE(s.tax_stat,      "ČESKÁ REPUBLIKA") AS tax_stat,
                    c.iso2                                        AS country_iso
               FROM supplier s
               JOIN countries c ON c.id = s.country_id
              WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return array_fill_keys([
                'dic', 'ic', 'company_name', 'display_name', 'street', 'city', 'zip', 'email', 'phone',
                'tax_ufo', 'tax_pracufo', 'tax_okec', 'tax_typ_platce', 'tax_typ_ds',
                'tax_titul', 'tax_jmeno', 'tax_prijmeni', 'tax_c_pop',
                'tax_email', 'tax_telef', 'tax_stat', 'country_iso',
            ], '');
        }

        return array_map('strval', $row);
    }

    /**
     * Ověří, že jsou vyplněna povinná EPO pole.
     * @return array<string, string>  klíč = název pole, hodnota = popis problému
     */
    private function validateSupplierInfo(array $info): array
    {
        $errors = [];

        if ($info['tax_ufo'] === '') {
            $errors['tax_ufo'] = 'Kód finančního úřadu (c_ufo) je povinný. Vyplňte ho v Nastavení → Daňové údaje.';
        }
        if ($info['tax_pracufo'] === '') {
            $errors['tax_pracufo'] = 'Kód pracoviště finančního úřadu (c_pracufo) je povinný. Vyplňte ho v Nastavení → Daňové údaje.';
        }
        if ($info['dic'] === '') {
            $errors['dic'] = 'DIČ plátce je povinné.';
        }

        // typ_platce musí být P nebo Q (ne F nebo jiné hodnoty z původního nastavení)
        $typPlatce = strtoupper($info['tax_typ_platce'] ?: 'P');
        if (!in_array($typPlatce, ['P', 'Q'], true)) {
            $errors['tax_typ_platce'] = sprintf(
                'Neplatná hodnota typ_platce="%s". Povoleno: P (měsíční plátce) nebo Q (čtvrtletní plátce).',
                $info['tax_typ_platce'],
            );
        }

        return $errors;
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
        ?int $quarter,
        string $filename,
    ): string {
        $issued   = $this->indexByClassification($issuedVat);
        $received = $this->indexByClassification($receivedVat);

        // ── Veta1: tuzemská zdanitelná plnění (ř. 1/2) ────────────────────
        $domesticCodes = ['01-02', '01-02c', '01-02p', '01-02r'];
        $dan23   = $this->sumVat($issued,  $domesticCodes, [21.0]);
        $obrat23 = $this->sumBase($issued, $domesticCodes, [21.0]);
        $dan5    = $this->sumVat($issued,  $domesticCodes, self::LOW_RATES);
        $obrat5  = $this->sumBase($issued, $domesticCodes, self::LOW_RATES);

        // ── Veta1: PDP dodavatel (ř. 25 základ) ───────────────────────────
        $rez_pren23 = $this->sumBase($issued, ['25'], [21.0]);
        $rez_pren5  = $this->sumBase($issued, ['25'], self::LOW_RATES);

        // ── Veta1: EU pořízení zboží (ř. 3/4) — samozdanění z přijatých ──
        // Základ a daň jdou do Veta1 jako výstupní daň (povinnost přiznat).
        // Odpočet jde do Veta4 nar_zdp23/nar_zdp5 (ř. 43).
        $p_zb23     = $this->sumBase($received, ['03-04'], [21.0]);
        $dan_pzb23  = $this->sumVat($received,  ['03-04'], [21.0]);
        $p_zb5      = $this->sumBase($received, ['03-04'], self::LOW_RATES);
        $dan_pzb5   = $this->sumVat($received,  ['03-04'], self::LOW_RATES);

        // ── Veta1: přijetí služby ze zahraničí (ř. 5/6) — samozdanění ────
        $p_sl23_z    = $this->sumBase($received, ['05-06'], [21.0]);
        $dan_psl23_z = $this->sumVat($received,  ['05-06'], [21.0]);
        $p_sl5_z     = $this->sumBase($received, ['05-06'], self::LOW_RATES);
        $dan_psl5_z  = $this->sumVat($received,  ['05-06'], self::LOW_RATES);

        // ── Veta2: osvobozená plnění s nárokem na odpočet ─────────────────
        $pln_vyvoz    = $this->sumBase($issued, ['22'], null);
        $dod_zb       = $this->sumBase($issued, ['20'], null);
        $pln_sluzby   = $this->sumBase($issued, ['31'], null);
        $pln_rez_pren = $this->sumBase($issued, ['25'], null);
        $pln_ost      = $this->sumBase($issued, ['50'], null);

        // ── Veta3: třístranný obchod prostřední osoba (ř. 30/31) ──────────
        // Prostřední osoba: tri_pozb = hodnota dodání (náš prodej do cílového státu).
        // tri_dozb = hodnota pořízení (náš nákup od dodavatele v prvním státě).
        // Obě částky jsou informativní (žádná DPH se nevyměří).
        // Pokud nemáme separátní nákupní stranu, používáme hodnotu prodeje pro obě.
        $tri_pozb = $this->sumBase($issued, ['21'], null);
        $tri_dozb = $tri_pozb; // Bez separátní nákupní klasifikace → stejná hodnota

        // ── Veta4: tuzemský odpočet (ř. 40/41) ────────────────────────────
        $fullCodes    = ['40-41', '40-41m'];
        $partialCodes = ['40-41k', '40-41mk'];
        $importCodes  = ['42', '42m'];

        $odp_tuz23_nar = $this->sumVat($received,  $fullCodes, [21.0]);
        $odp_tuz5_nar  = $this->sumVat($received,  $fullCodes, self::LOW_RATES);
        $pln23         = $this->sumBase($received, $fullCodes, [21.0]);
        $pln5          = $this->sumBase($received, $fullCodes, self::LOW_RATES);

        $odp_tuz23  = $this->sumVat($received,  $partialCodes, [21.0]);
        $odp_tuz5   = $this->sumVat($received,  $partialCodes, self::LOW_RATES);
        $pln23     += $this->sumBase($received, $partialCodes, [21.0]);
        $pln5      += $this->sumBase($received, $partialCodes, self::LOW_RATES);

        // ── Veta4: dovoz (ř. 42) ──────────────────────────────────────────
        $dov_cu     = $this->sumBase($received, $importCodes, null);
        $odp_cu_nar = $this->sumVat($received,  $importCodes, null);

        // ── Veta4: ostatní odpočet ř. 43 ──────────────────────────────────
        // nar_zdp23/5 = plný odpočet z EU pořízení zboží + EU přijaté služby + kód "43"
        // od_zdp23/5  = krácený odpočet — zatím neimplementováno (vyžaduje UI pro krácení)
        $euAndOtherCodes = ['03-04', '05-06', '43'];
        $nar_zdp23 = $this->sumVat($received, $euAndOtherCodes, [21.0]);
        $nar_zdp5  = $this->sumVat($received, $euAndOtherCodes, self::LOW_RATES);

        // Celkové součty
        $odp_sum_nar = $odp_tuz23_nar + $odp_tuz5_nar + $odp_cu_nar + $nar_zdp23 + $nar_zdp5;
        $odp_sum_kr  = $odp_tuz23 + $odp_tuz5;

        // ── Veta6: rekapitulace ────────────────────────────────────────────
        // dan_zocelk zahrnuje i samozdanění z EU (ř. 3/4 + ř. 5/6)
        $dan_zocelk = $dan23 + $dan5 + $dan_pzb23 + $dan_pzb5 + $dan_psl23_z + $dan_psl5_z;
        $odp_zocelk = $odp_sum_nar + $odp_sum_kr;
        $dano       = $dan_zocelk - $odp_zocelk;   // kladné = VDP, záporné = nadměrný odpočet
        $dano_da    = max(0.0, $dano);
        $dano_no    = max(0.0, -$dano);
        $trans      = $dano >= 0.0 ? 'A' : 'N';

        // ── VetaD / VetaP ─────────────────────────────────────────────────
        $dic        = $this->normalizeDic($ourInfo['dic']);
        $d_poddp    = date('d.m.Y');
        $taxOkec    = $ourInfo['tax_okec']      ?: '631000';
        $taxUfo     = $ourInfo['tax_ufo'];
        $taxPracufo = $ourInfo['tax_pracufo'];
        // tax_typ_platce: "P" = měsíční, "Q" = čtvrtletní
        $typPlatce  = strtoupper($ourInfo['tax_typ_platce'] ?: 'P');
        $typDs      = $ourInfo['tax_typ_ds']     ?: 'F';

        $body = $this->renderBody(
            year: $year, month: $month, quarter: $quarter,
            trans: $trans, typPlatce: $typPlatce,
            d_poddp: $d_poddp, taxOkec: $taxOkec,
            dic: $dic, taxUfo: $taxUfo, taxPracufo: $taxPracufo, typDs: $typDs,
            ourInfo: $ourInfo,
            // Veta1
            dan23: $dan23, obrat23: $obrat23, dan5: $dan5, obrat5: $obrat5,
            rez_pren23: $rez_pren23, rez_pren5: $rez_pren5,
            p_zb23: $p_zb23, dan_pzb23: $dan_pzb23, p_zb5: $p_zb5, dan_pzb5: $dan_pzb5,
            p_sl23_z: $p_sl23_z, dan_psl23_z: $dan_psl23_z,
            p_sl5_z: $p_sl5_z, dan_psl5_z: $dan_psl5_z,
            // Veta2
            pln_vyvoz: $pln_vyvoz, dod_zb: $dod_zb, pln_sluzby: $pln_sluzby,
            pln_rez_pren: $pln_rez_pren, pln_ost: $pln_ost,
            // Veta3
            tri_dozb: $tri_dozb, tri_pozb: $tri_pozb,
            // Veta4
            odp_tuz23_nar: $odp_tuz23_nar, odp_tuz5_nar: $odp_tuz5_nar,
            odp_tuz23: $odp_tuz23, odp_tuz5: $odp_tuz5,
            pln23: $pln23, pln5: $pln5,
            dov_cu: $dov_cu, odp_cu_nar: $odp_cu_nar,
            nar_zdp23: $nar_zdp23, nar_zdp5: $nar_zdp5,
            odp_sum_nar: $odp_sum_nar, odp_sum_kr: $odp_sum_kr,
            // Veta6
            dan_zocelk: $dan_zocelk, odp_zocelk: $odp_zocelk,
            dano: $dano, dano_da: $dano_da, dano_no: $dano_no,
        );

        $kc           = md5($body);
        $delka        = strlen($body);
        $filenameBase = preg_replace('/\.xml$/i', '', $filename);
        $xUfo         = $this->xe($taxUfo);
        $kontrola     = "<Kontrola><Soubor Delka=\"{$delka}\" KC=\"{$kc}\" Nazev=\"{$filenameBase}\" c_ufo=\"{$xUfo}\" /></Kontrola>";

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<Pisemnost nazevSW=\"EPO MF ČR\" verzeSW=\"47.2.1\">\n"
            . $body . "\n"
            . $kontrola . "</Pisemnost>\n";
    }

    private function renderBody(
        int $year,
        int $month,
        ?int $quarter,
        string $trans,
        string $typPlatce,
        string $d_poddp,
        string $taxOkec,
        string $dic,
        string $taxUfo,
        string $taxPracufo,
        string $typDs,
        array $ourInfo,
        // Veta1
        float $dan23, float $obrat23, float $dan5, float $obrat5,
        float $rez_pren23, float $rez_pren5,
        float $p_zb23, float $dan_pzb23, float $p_zb5, float $dan_pzb5,
        float $p_sl23_z, float $dan_psl23_z, float $p_sl5_z, float $dan_psl5_z,
        // Veta2
        float $pln_vyvoz, float $dod_zb, float $pln_sluzby,
        float $pln_rez_pren, float $pln_ost,
        // Veta3
        float $tri_dozb, float $tri_pozb,
        // Veta4
        float $odp_tuz23_nar, float $odp_tuz5_nar,
        float $odp_tuz23, float $odp_tuz5,
        float $pln23, float $pln5,
        float $dov_cu, float $odp_cu_nar,
        float $nar_zdp23, float $nar_zdp5,
        float $odp_sum_nar, float $odp_sum_kr,
        // Veta6
        float $dan_zocelk, float $odp_zocelk,
        float $dano, float $dano_da, float $dano_no,
    ): string {
        // DPHDP3 používá celá čísla (zaokrouhlená Kč)
        $i = fn (float $v): string => (string) (int) round($v);

        // ── VetaP atributy ─────────────────────────────────────────────────
        $xDic      = $this->xe($dic);
        $xUfo      = $this->xe($taxUfo);
        $xPracufo  = $this->xe($taxPracufo);
        $xUlice    = $this->xe($ourInfo['street'] ?? '');
        // EPO vyžaduje název obce VELKÝMI PÍSMENY
        $xNazObce  = $this->xe(mb_strtoupper($ourInfo['city'], 'UTF-8'));
        $xCPop     = $this->xe($ourInfo['tax_c_pop']);
        $xPsc      = $this->xe(str_replace(' ', '', $ourInfo['zip']));
        $xStat     = $this->xe($ourInfo['tax_stat'] ?: 'ČESKÁ REPUBLIKA');
        $xEmail    = $this->xe($ourInfo['tax_email']);
        $xTelef    = $this->xe($ourInfo['tax_telef']);

        // Pro FO: osobní jméno z tax_jmeno/tax_prijmeni; pro PO: název firmy do jmeno
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

        // ── VetaD: perioda ─────────────────────────────────────────────────
        // Měsíční plátce: mesic="N"; čtvrtletní plátce: ctvrt="N"
        $periodAttr = $quarter !== null
            ? "ctvrt=\"{$quarter}\""
            : "mesic=\"{$month}\"";

        // kod_zo="M" je povinné pro prosinec (měsíční) nebo Q4 (čtvrtletní)
        $isLastPeriod  = ($quarter !== null) ? ($quarter === 4) : ($month === 12);
        $kodZoAttr     = $isLastPeriod ? ' kod_zo="M"' : '';

        return "<DPHDP3 verzePis=\"03.01\">\n"
            . "<VetaD c_okec=\"{$taxOkec}\" d_poddp=\"{$d_poddp}\" dapdph_forma=\"B\" dokument=\"DP3\" k_uladis=\"DPH\" {$periodAttr} rok=\"{$year}\"{$kodZoAttr} trans=\"{$trans}\" typ_platce=\"{$typPlatce}\" />\n"
            . "<VetaP {$vetaPAttrs} />\n"
            // Veta1: základ + daň pro každý řádek; dan_pzb/dan_psl = samozdanění z EU
            . "<Veta1"
            .  " dan23=\"{$i($dan23)}\" dan5=\"{$i($dan5)}\""
            .  " dan_dzb23=\"{$i($dan_pzb23)}\" dan_dzb5=\"{$i($dan_pzb5)}\""
            .  " dan_pdop_nrg=\"0\""
            .  " dan_psl23_e=\"0\" dan_psl5_e=\"0\""
            .  " dan_psl23_z=\"{$i($dan_psl23_z)}\" dan_psl5_z=\"{$i($dan_psl5_z)}\""
            .  " dan_pzb23=\"{$i($dan_pzb23)}\" dan_pzb5=\"{$i($dan_pzb5)}\""
            .  " dan_rpren23=\"0\" dan_rpren5=\"0\""
            .  " dov_zb23=\"0\" dov_zb5=\"0\""
            .  " obrat23=\"{$i($obrat23)}\" obrat5=\"{$i($obrat5)}\""
            .  " p_dop_nrg=\"0\""
            .  " p_sl23_e=\"0\" p_sl5_e=\"0\""
            .  " p_sl23_z=\"{$i($p_sl23_z)}\" p_sl5_z=\"{$i($p_sl5_z)}\""
            .  " p_zb23=\"{$i($p_zb23)}\" p_zb5=\"{$i($p_zb5)}\""
            .  " rez_pren23=\"{$i($rez_pren23)}\" rez_pren5=\"{$i($rez_pren5)}\""
            .  " />\n"
            // Veta2: osvobozená plnění s nárokem na odpočet
            . "<Veta2 dod_dop_nrg=\"0\" dod_zb=\"{$i($dod_zb)}\" pln_ost=\"{$i($pln_ost)}\" pln_rez_pren=\"{$i($pln_rez_pren)}\" pln_sluzby=\"{$i($pln_sluzby)}\" pln_vyvoz=\"{$i($pln_vyvoz)}\" pln_zaslani=\"0\" />\n"
            // Veta3: třístranný obchod + opravy (§44 věřitel/dlužník není implementováno)
            . "<Veta3 dov_osv=\"0\" opr_dluz=\"0\" opr_verit=\"0\" tri_dozb=\"{$i($tri_dozb)}\" tri_pozb=\"{$i($tri_pozb)}\" />\n"
            // Veta4: odpočet daně
            // nar_zdp23/5 = odpočet z EU pořízení (03-04), EU služeb (05-06) a ostatní (43)
            . "<Veta4"
            .  " dov_cu=\"{$i($dov_cu)}\""
            .  " nar_maj=\"0\" od_maj=\"0\" odkr_maj=\"0\""
            .  " nar_zdp23=\"{$i($nar_zdp23)}\" nar_zdp5=\"{$i($nar_zdp5)}\""
            .  " od_zdp23=\"0\" od_zdp5=\"0\""
            .  " odp_cu=\"0\" odp_cu_nar=\"{$i($odp_cu_nar)}\""
            .  " odp_sum_kr=\"{$i($odp_sum_kr)}\" odp_sum_nar=\"{$i($odp_sum_nar)}\""
            .  " odp_tuz23=\"{$i($odp_tuz23)}\" odp_tuz23_nar=\"{$i($odp_tuz23_nar)}\""
            .  " odp_tuz5=\"{$i($odp_tuz5)}\" odp_tuz5_nar=\"{$i($odp_tuz5_nar)}\""
            .  " pln23=\"{$i($pln23)}\" pln5=\"{$i($pln5)}\""
            .  " />\n"
            . "<Veta5 plnosv_kf=\"0\" />\n"
            // Veta6: dano = dan_zocelk - odp_zocelk (kladné = VDP, záporné = nadměrný odpočet)
            . "<Veta6 dan_zocelk=\"{$i($dan_zocelk)}\" dano=\"{$i($dano)}\" dano_da=\"{$i($dano_da)}\" dano_no=\"{$i($dano_no)}\" odp_zocelk=\"{$i($odp_zocelk)}\" />\n"
            . "</DPHDP3>";
    }

    // =========================================================================
    // VAT aggregation helpers
    // =========================================================================

    /**
     * @param array<string,array<float,array{base: float, vat: float}>> $indexed
     * @param string[]      $classifications
     * @param float[]|null  $rates   null = všechny sazby
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
str_starts_with($dic, 'CZ') ? substr($dic, 2) : $dic;
    }
}
