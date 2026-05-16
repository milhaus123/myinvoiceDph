import { api } from './client'

export interface Item {
  id: number
  supplier_id: number
  sku: string
  name: string
  description: string | null
  unit: string
  stock_quantity: number
  min_stock_alert: number
  created_at: string
  updated_at: string
}

export interface StockMovement {
  id: number
  item_id: number
  movement_type: 'stock_in' | 'stock_out' | 'adjustment'
  quantity: number
  stock_before: number
  stock_after: number
  reference_type: string | null
  reference_id: number | null
  note: string | null
  created_at: string
}

export interface StockHistoryResponse {
  item: Item
  history: StockMovement[]
  meta: { limit: number; offset: number }
}

export const itemsApi = {
  list(params?: { sort?: string; dir?: string }): Promise<{ data: Item[]; meta: { total: number } }> {
    return api.get('/items', { params })
  },

  get(id: number): Promise<Item> {
    return api.get(`/items/${id}`)
  },

  create(data: {
    sku: string
    name: string
    description?: string | null
    unit?: string
    stock_quantity?: number
    min_stock_alert?: number
  }): Promise<Item> {
    return api.post('/items', data)
  },

  update(id: number, data: {
    sku?: string
    name?: string
    description?: string | null
    unit?: string
    min_stock_alert?: number
  }): Promise<Item> {
    return api.put(`/items/${id}`, data)
  },

  delete(id: number): Promise<void> {
    return api.delete(`/items/${id}`)
  },

  lowStock(): Promise<{ data: Item[] }> {
    return api.get('/items/low-stock')
  },

  stockIn(id: number, data: { quantity: number; note?: string; reference_type?: string; reference_id?: number }): Promise<{ item: Item; movement: StockMovement }> {
    return api.post(`/items/${id}/stock-in`, data)
  },

  stockOut(id: number, data: { quantity: number; note?: string; reference_type?: string; reference_id?: number }): Promise<{ item: Item; movement: StockMovement }> {
    return api.post(`/items/${id}/stock-out`, data)
  },

  stockHistory(id: number, params?: { limit?: number; offset?: number }): Promise<StockHistoryResponse> {
    return api.get(`/items/${id}/stock-history`, { params })
  },
}
