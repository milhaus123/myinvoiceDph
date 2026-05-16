import { api } from './client'

export interface DphReportParams {
  year: number
  month?: number
}

export interface DphReportRow {
  vat_rate: number
  base_czk: number
  vat_czk: number
  base_foreign: number
  vat_foreign: number
  total_vat_czk: number
}

export interface DphReportData {
  year: number
  month: number | null
  rows: DphReportRow[]
  total_base_czk: number
  total_vat_czk: number
  total_base_foreign: number
  total_vat_foreign: number
}

export interface KontrolniHlaseniParams {
  year: number
  month: number
  type?: ' KH1' | 'KH2'
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
  // DPH report — returns aggregated VAT data
  dphReport: (params: DphReportParams) =>
    api.get<DphReportData>('/reports/dph', { params }).then(r => r.data),

  // DPHKH1 kontrolní hlášení — XML download
  kontrolniHlaseni: (params: KontrolniHlaseniParams) =>
    api.get<KontrolniHlaseniData>('/reports/kontrolni-hlaseni', { params }).then(r => r.data),

  // DPHDP3 / DPHDP4 / DPHDP5 / DPHDP6 — XML download
  dphPriznani: (params: DphPriznaniParams) =>
    api.get<DphPriznaniData>('/reports/dphdp3', { params }).then(r => r.data),

  // DPFDP5 / DPPDP9 — income tax return XML download
  incomeTaxReturn: (params: IncomeTaxReturnParams) =>
    api.get<IncomeTaxReturnData>('/reports/priznani-dani', { params }).then(r => r.data),
}
