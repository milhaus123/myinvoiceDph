import { api } from './client'

/** Frequency options matching PeriodicityCalculator::FREQUENCIES */
export type RecurringFrequency = 'monthly' | 'quarterly' | 'semi_annually' | 'annually'

export type RecurringStatus = 'active' | 'paused' | 'expired'

export interface RecurringTemplateItem {
  id?: number
  template_id?: number
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  vat_rate_percent?: number
  vat_code?: string
  order_index: number
}

export interface RecurringTemplate {
  id: number
  client_id: number
  project_id: number | null
  name: string
  frequency: RecurringFrequency
  day_of_month: number | null
  end_of_month: boolean
  anchor_date: string
  end_date: string | null
  next_run_date: string
  last_run_date: string | null
  currency_id: number
  currency: string
  currency_symbol?: string
  language: 'cs' | 'en'
  payment_method: 'bank_transfer' | 'card' | 'cash' | 'other'
  reverse_charge: boolean
  payment_due_days: number
  note_above_items: string | null
  note_below_items: string | null
  increment_month_in_descriptions: boolean
  auto_issue: boolean
  auto_send_email: boolean
  status: RecurringStatus
  created_at: string
  updated_at: string
  // Joined fields
  client_company_name: string
  client_main_email?: string
  client_ic?: string | null
  client_dic?: string | null
  client_language?: 'cs' | 'en'
  client_reverse_charge?: boolean
  project_name?: string | null
  invoices_generated_count?: number
  // Items
  items: RecurringTemplateItem[]
}

export interface RecurringTemplatePayload {
  client_id: number
  project_id?: number | null
  name: string
  frequency: RecurringFrequency
  day_of_month?: number | null
  end_of_month?: boolean
  anchor_date: string
  end_date?: string | null
  next_run_date?: string
  currency_id: number
  language?: 'cs' | 'en'
  payment_method?: 'bank_transfer' | 'card' | 'cash' | 'other'
  reverse_charge?: boolean
  payment_due_days?: number
  note_above_items?: string | null
  note_below_items?: string | null
  increment_month_in_descriptions?: boolean
  auto_issue?: boolean
  auto_send_email?: boolean
  items: Array<{
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    order_index: number
  }>
}

export interface NextRun {
  template_id: number
  template_name: string
  next_run_date: string
  frequency: RecurringFrequency
}

export interface GeneratedInvoice {
  invoice_id: number
  invoice_number: string
  template_id: number
  template_name: string
}

export const recurringInvoicesApi = {
  list: (filters: { status?: string } = {}) => {
    const params: Record<string, string | number> = {}
    if (filters.status) params['status'] = filters.status
    return api.get<{ data: RecurringTemplate[] }>('/recurring-invoices', { params })
      .then(r => r.data)
  },

  get: (id: number) =>
    api.get<RecurringTemplate>(`/recurring-invoices/${id}`).then(r => r.data),

  create: (payload: RecurringTemplatePayload) =>
    api.post<RecurringTemplate>('/recurring-invoices', payload).then(r => r.data),

  update: (id: number, payload: RecurringTemplatePayload) =>
    api.put<RecurringTemplate>(`/recurring-invoices/${id}`, payload).then(r => r.data),

  delete: (id: number) =>
    api.delete<{ deleted: boolean }>(`/recurring-invoices/${id}`).then(r => r.data),

  pause: (id: number) =>
    api.post<RecurringTemplate>(`/recurring-invoices/${id}/pause`).then(r => r.data),

  resume: (id: number) =>
    api.post<RecurringTemplate>(`/recurring-invoices/${id}/resume`).then(r => r.data),

  runNow: (id: number, issueDate?: string) =>
    api.post<GeneratedInvoice>(`/recurring-invoices/${id}/run-now`, {
      ...(issueDate ? { issue_date: issueDate } : {}),
    }).then(r => r.data),

  nextRuns: () =>
    api.get<{ data: NextRun[] }>('/recurring-invoices/next-runs').then(r => r.data),

  generatedInvoices: (id: number) =>
    api.get<{ data: Array<{ month: string; count: number; invoices: Array<{
      id: number
      invoice_number: string
      issue_date: string
      due_date: string
      total_with_vat: number
      currency: string
      status: string
    }> }> }>(`/recurring-invoices/${id}/invoices`).then(r => r.data),
}
