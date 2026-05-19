// Číselník finančních úřadů (UFO) — zdroj: adisspr.mfcr.cz
export interface FuEntry { code: string; name: string }

export const FU_CODES: FuEntry[] = [
  { code: '0', name: "Generální finanční ředitelství" },
  { code: '13', name: "Specializovaný finanční úřad" },
  { code: '451', name: "Finanční úřad pro hlavní město Prahu" },
  { code: '452', name: "Finanční úřad pro Středočeský kraj" },
  { code: '453', name: "Finanční úřad pro Jihočeský kraj" },
  { code: '454', name: "Finanční úřad pro Plzeňský kraj" },
  { code: '455', name: "Finanční úřad pro Karlovarský kraj" },
  { code: '456', name: "Finanční úřad pro Ústecký kraj" },
  { code: '457', name: "Finanční úřad pro Liberecký kraj" },
  { code: '458', name: "Finanční úřad pro Královéhradecký kraj" },
  { code: '459', name: "Finanční úřad pro Pardubický kraj" },
  { code: '460', name: "Finanční úřad pro Kraj Vysočina" },
  { code: '461', name: "Finanční úřad pro Jihomoravský kraj" },
  { code: '462', name: "Finanční úřad pro Olomoucký kraj" },
  { code: '463', name: "Finanční úřad pro Moravskoslezský kraj" },
  { code: '464', name: "Finanční úřad pro Zlínský kraj" },
]
