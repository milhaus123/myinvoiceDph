import { api } from './client'

export interface DphReportParams {
  year: number
  month?: number
}

export interface DphReportByRate {
  rate: number
  zaklad: number
  dph: number
}

export interface DphReportSide {
  label: string
  by_rate: DphReportByRate[]
}

export interface DphReportTotals {
  output_vat: number
  input_vat: number
  delta: number
}

export interface DphReportData {
  period: {
    date_from: string
    date_to: string
  }
  issued: DphReportSide
  received: DphReportSide
  totals: DphReportTotals
}

export interface KontrolniHlaseniParams {
  year: number
  month: number
  type?: 'KH1' | 'KH2'
}

export interface KontrolniHlaseniData {
  xml_content: string
  filename: string
}

export interface DphPriznaniParams {
  year: number
  month?: number
  form_type?: 'DPHDP3' | 'DPHDP4' | 'DPHDP5' | 'DPHDP6'
}

export interface DphPriznaniData {
  xml_content: string
  filename: string
}

export interface IncomeTaxReturnParams {
  year: number
  type?: 'DPFDP5' | 'DPPDP9'
}

export interface IncomeTaxReturnData {
  xml_content: string
  filename: string
}

export const reportsApi = {
  // DPH report - returns aggregated VAT data
  dphReport: (params: DphReportParams) =>
    api.get<DphReportData>('/reports/dph', { params }).then(r => r.data),

  // DPHKH1 kontrolni hlaseni - XML download
  kontrolniHlaseni: (params: KontrolniHlaseniParams) =>
    api.get<KontrolniHlaseniData>('/reports/kontrolni-hlaseni', { params }).then(r => r.data),

  // DPHDP3 / DPHDP4 / DPHDP5 / DPHDP6 - XML download
  dphPriznani: (params: DphPriznaniParams) =>
    api.get<DphPriznaniData>('/reports/dphdp3', { params }).then(r => r.data),

  // DPFDP5 / DPPDP9 - income tax return XML download
  // FO -> /reports/priznani-dani-prijmu/fyzicke-osoby
  // PO -> /reports/priznani-dani-prijmu/pravnicke-osoby
  incomeTaxReturn: (params: IncomeTaxReturnParams) => {
    const path = params.type === 'DPPDP9'
      ? '/reports/priznani-dani-prijmu/pravnicke-osoby'
      : '/reports/priznani-dani-prijmu/fyzicke-osoby'
    return api.get<IncomeTaxReturnData>(path, { params }).then(r => r.data)
  },
}
