import { api, type ApiListResponse } from './client'
import type { InvoiceItem, VatBreakdownRow, InvoiceTotals } from './invoices'

export type QuoteStatus = 'draft' | 'sent' | 'approved' | 'rejected' | 'converted'

export interface Quote {
  id: number
  varsymbol: string | null
  invoice_type: 'quote'
  client_id: number
  project_id: number | null
  issue_date: string
  tax_date: string | null
  currency_id: number
  currency: string
  currency_symbol: string
  currency_decimals: number
  reverse_charge: boolean
  language: 'cs' | 'en'
  note_above_items: string | null
  note_below_items: string | null
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  rounding: number
  status: string
  quote_status: QuoteStatus
  quote_sent_at: string | null
  quote_approved_at: string | null
  quote_rejected_at: string | null
  quote_rejection_reason: string | null
  quote_approved_by_email: string | null
  quote_valid_until: string | null
  quote_converted_to_invoice_id: number | null
  client_company_name: string
  client_main_email: string | null
  client_ic: string | null
  client_dic: string | null
  project_name: string | null
  items: InvoiceItem[]
  vat_breakdown: VatBreakdownRow[]
  totals: InvoiceTotals
  created_at: string
  updated_at: string
}

export interface QuoteListItem {
  id: number
  varsymbol: string | null
  issue_date: string
  quote_status: QuoteStatus
  quote_valid_until: string | null
  quote_sent_at: string | null
  quote_approved_at: string | null
  quote_rejected_at: string | null
  status: string
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  client_company_name: string
  currency: string
  currency_symbol: string
}

export interface QuotePayload {
  client_id: number
  project_id?: number | null
  issue_date: string
  currency_id: number
  reverse_charge?: boolean
  language?: 'cs' | 'en'
  note_above_items?: string | null
  note_below_items?: string | null
  quote_valid_until?: string | null
  items: InvoiceItem[]
}

export interface QuoteListResponse extends ApiListResponse {
  items: QuoteListItem[]
}

export interface ToInvoicePayload {
  issue_date?: string
  tax_date?: string
  due_date?: string
  payment_due_days?: number
  varsymbol?: string | null
}

async function list(params?: {
  status?: QuoteStatus | ''
  client_id?: number
  year?: number
  search?: string
  page?: number
}): Promise<QuoteListResponse> {
  const q = new URLSearchParams()
  if (params?.status) q.set('status', params.status)
  if (params?.client_id) q.set('client_id', String(params.client_id))
  if (params?.year) q.set('year', String(params.year))
  if (params?.search) q.set('search', params.search)
  if (params?.page) q.set('page', String(params.page))
  return api.get<QuoteListResponse>(`/quotes?${q}`)
}

async function create(payload: QuotePayload): Promise<Quote> {
  return api.post<Quote>('/quotes', payload)
}

async function get(id: number): Promise<Quote> {
  return api.get<Quote>(`/quotes/${id}`)
}

async function update(id: number, payload: Partial<QuotePayload>): Promise<Quote> {
  return api.put<Quote>(`/quotes/${id}`, payload)
}

async function remove(id: number): Promise<void> {
  return api.delete(`/quotes/${id}`)
}

async function transition(id: number, status: QuoteStatus, reason?: string): Promise<Quote> {
  return api.post<Quote>(`/quotes/${id}/transition`, { status, reason })
}

async function toInvoice(id: number, payload?: ToInvoicePayload): Promise<{ invoice_id: number; invoice: any }> {
  return api.post<{ invoice_id: number; invoice: any }>(`/quotes/${id}/to-invoice`, payload ?? {})
}

export const quotesApi = { list, create, get, update, remove, transition, toInvoice }
