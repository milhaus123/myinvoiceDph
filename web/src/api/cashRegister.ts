import { api } from './client'

export interface CashMovement {
  id: number
  supplier_id: number
  movement_type: 'income' | 'expense'
  amount: number
  currency_id: number
  currency_code: string
  currency_symbol: string
  description: string
  category: string
  client_id: number | null
  client_name?: string | null
  project_id: number | null
  project_name?: string | null
  project_number?: string | null
  created_at: string
  updated_at: string
}

export interface CashMovementSummary {
  total_income: number
  total_expense: number
  balance: number
  daily_income: number
  daily_expense: number
  daily_balance: number
  month_income: number
  month_expense: number
  month_balance: number
}

export interface CashCategory {
  id: number
  name: string
}

export interface CashMovementsResponse {
  data: CashMovement[]
  meta: {
    total: number
    page: number
    per_page: number
    pages: number
  }
  categories: CashCategory[]
}

export interface CreateCashMovementInput {
  movement_type: 'income' | 'expense'
  amount: number
  currency_id?: number
  description: string
  category?: string
  client_id?: number | null
  project_id?: number | null
}

export interface UpdateCashMovementInput {
  movement_type?: 'income' | 'expense'
  amount?: number
  currency_id?: number
  description?: string
  category?: string
  client_id?: number | null
  project_id?: number | null
}

export const cashRegisterApi = {
  list(params?: {
    movement_type?: string
    category?: string
    client_id?: number
    date_from?: string
    date_to?: string
    q?: string
    page?: number
    per_page?: number
  }): Promise<CashMovementsResponse> {
    return api.get('/cash-movements', { params }).then(r => r.data)
  },

  get(id: number): Promise<CashMovement> {
    return api.get(`/cash-movements/${id}`).then(r => r.data)
  },

  create(data: CreateCashMovementInput): Promise<CashMovement> {
    return api.post('/cash-movements', data).then(r => r.data)
  },

  update(id: number, data: UpdateCashMovementInput): Promise<CashMovement> {
    return api.put(`/cash-movements/${id}`, data).then(r => r.data)
  },

  delete(id: number): Promise<void> {
    return api.delete(`/cash-movements/${id}`).then(() => undefined)
  },

  summary(currencyId?: number): Promise<CashMovementSummary> {
    return api.get('/cash-register/summary', { params: currencyId ? { currency_id: currencyId } : {} }).then(r => r.data)
  },

  categories(): Promise<CashCategory[]> {
    return api.get('/cash-register/categories').then(r => r.data)
  },
}
