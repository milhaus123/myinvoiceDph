import { api } from './client'

export type PurchaseInvoiceStatus = 'draft' | 'received' | 'booked' | 'paid' | 'cancelled'

export interface PurchaseInvoiceItem {
  id?: number
  purchase_invoice_id?: number
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  vat_rate_snapshot?: number
  total_without_vat?: number
  total_vat?: number
  total_with_vat?: number
  order_index: number
  vat_code?: string
  vat_label_cs?: string
  vat_label_en?: string
}

export interface VatBreakdownRow {
  rate: number
  base: number
  vat: number
}

export interface PurchaseInvoiceTotals {
  without_vat: number
  vat: number
  with_vat: number
  rounding: number
  advance_paid_amount: number
  amount_to_pay: number
}

export interface PurchaseInvoice {
  id: number
  varsymbol: string | null
  invoice_number: string
  supplier_id: number
  issue_date: string
  tax_date: string | null
  due_date: string
  received_at: string | null
  currency_id: number
  currency: string
  currency_symbol?: string
  currency_decimals?: number
  reverse_charge: boolean
  language: 'cs' | 'en'
  note_above_items: string | null
  note_below_items: string | null
  advance_paid_amount: number
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  rounding: number
  amount_to_pay: number
  status: PurchaseInvoiceStatus
  booked_at: string | null
  paid_at: string | null
  cancelled_at: string | null
  created_at: string
  updated_at: string
  // Joined supplier info
  supplier_company_name: string
  supplier_main_email?: string
  supplier_ic?: string | null
  supplier_dic?: string | null
  supplier_language?: 'cs' | 'en'
  supplier_reverse_charge?: boolean
  // Bank
  bank_account_number?: string | null
  bank_code?: string | null
  bank_name?: string | null
  bank_iban?: string | null
  bank_bic?: string | null
  // Computed
  items: PurchaseInvoiceItem[]
  vat_breakdown: VatBreakdownRow[]
  totals: PurchaseInvoiceTotals
  exchange_rate?: number | null
  exchange_rate_date?: string | null
  czk_recap?: {
    rate: number
    rate_date: string
    fallback_used: boolean
    breakdown: Array<{
      rate: number
      base_czk: number
      vat_czk: number
      with_vat_czk: number
    }>
    total_without_vat_czk: number
    total_vat_czk: number
    total_with_vat_czk: number
  } | null
}

export interface PurchaseInvoiceListItem {
  id: number
  varsymbol: string | null
  invoice_number: string
  supplier_id: number
  issue_date: string
  tax_date: string | null
  due_date: string
  received_at: string | null
  currency_id: number
  currency: string
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  advance_paid_amount: number
  amount_to_pay: number
  status: PurchaseInvoiceStatus
  booked_at: string | null
  paid_at: string | null
  cancelled_at: string | null
  supplier_company_name: string
  month_bucket: string
}

export interface PurchaseInvoiceMonthGroup {
  month: string
  count: number
  totals_per_currency: Array<{
    currency: string
    without_vat: number
    vat: number
    with_vat: number
  }>
  invoices: PurchaseInvoiceListItem[]
}

export interface PurchaseInvoicePayload {
  supplier_id: number
  invoice_number?: string
  varsymbol?: string | null
  issue_date: string
  tax_date?: string | null
  due_date: string
  received_at?: string
  currency_id: number
  reverse_charge?: boolean
  language?: 'cs' | 'en'
  note_above_items?: string | null
  note_below_items?: string | null
  advance_paid_amount?: number
  exchange_rate?: number | null
  items: Array<{
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    order_index: number
  }>
}

export interface ListFilters {
  q?: string
  status?: string | string[]
  year?: number
  month?: number
  date_from?: string
  date_to?: string
  currency?: string
  unpaid_only?: boolean
  overdue?: boolean
  page?: number
  per_page?: number
}

export interface InvoiceListMeta {
  total: number
  page?: number
  per_page?: number
  pages?: number
}

export const purchaseInvoicesApi = {
  listGrouped: (filters: ListFilters = {}) => {
    const params: Record<string, string | number> = {}
    if (filters.q) params.q = filters.q
    if (filters.status) {
      params['filter[status]'] = Array.isArray(filters.status)
        ? filters.status.join(',')
        : filters.status
    }
    if (filters.year)       params['filter[year]']       = filters.year
    if (filters.month)      params['filter[month]']      = filters.month
    if (filters.date_from) params['filter[date_from]'] = filters.date_from
    if (filters.date_to)   params['filter[date_to]']   = filters.date_to
    if (filters.currency)   params['filter[currency]']   = filters.currency
    if (filters.unpaid_only) params['filter[unpaid_only]'] = 1
    if (filters.overdue)   params['filter[overdue]']     = 1
    if (filters.page)      params.page                   = filters.page
    if (filters.per_page)  params.per_page               = filters.per_page
    return api
      .get<{ data: PurchaseInvoiceMonthGroup[]; meta: InvoiceListMeta }>('/purchase-invoices', { params })
      .then(r => r.data)
  },

  get: (id: number) =>
    api.get<PurchaseInvoice>(`/purchase-invoices/${id}`).then(r => r.data),

  create: (payload: PurchaseInvoicePayload) =>
    api.post<PurchaseInvoice>('/purchase-invoices', payload).then(r => r.data),

  update: (id: number, payload: PurchaseInvoicePayload) =>
    api.put<PurchaseInvoice>(`/purchase-invoices/${id}`, payload).then(r => r.data),

  delete: (id: number) =>
    api.delete<{ ok: boolean }>(`/purchase-invoices/${id}`).then(r => r.data),

  setItems: (id: number, items: PurchaseInvoicePayload['items']) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/items`, { items }).then(r => r.data),

  setExchangeRate: (id: number, rate: number | null, rateDate: string | null) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/exchange-rate`, {
      exchange_rate: rate,
      exchange_rate_date: rateDate,
    }).then(r => r.data),

  transitionStatus: (id: number, status: PurchaseInvoiceStatus) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/status`, { status }).then(r => r.data),
}
