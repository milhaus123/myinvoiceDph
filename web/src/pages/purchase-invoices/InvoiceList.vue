<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import {
  purchaseInvoicesApi,
  type PurchaseInvoiceMonthGroup,
  type PurchaseInvoiceListItem,
} from '@/api/purchaseInvoices'
import { formatDate, formatMonth, formatMoney } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import { codebooksApi, type Currency } from '@/api/codebooks'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t, tm, rt } = useI18n()
const toast = useToast()
useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/purchase-invoices/new') })

const router = useRouter()
const route = useRoute()


const groups = ref<PurchaseInvoiceMonthGroup[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const statusFilter = ref<string>('')
const docKindFilter = ref<string>(route.query.type as string || '')

type PeriodPreset = '' | 'this_month' | 'last_month' | 'this_quarter' | 'last_quarter' | 'this_year' | 'last_year' | 'custom'
const periodPreset = ref<PeriodPreset>('this_year')
const dateFrom = ref<string>('')
const dateTo = ref<string>('')

function computePeriodDates(preset: PeriodPreset): { from: string; to: string } | null {
  const now = new Date()
  const y = now.getFullYear()
  const m = now.getMonth() + 1
  const pad = (n: number) => String(n).padStart(2, '0')
  const lastDay = (yr: number, mo: number) => new Date(yr, mo, 0).getDate()
  if (preset === 'this_month') {
    return { from: `${y}-${pad(m)}-01`, to: `${y}-${pad(m)}-${lastDay(y, m)}` }
  }
  if (preset === 'last_month') {
    const lm = m === 1 ? 12 : m - 1
    const ly = m === 1 ? y - 1 : y
    return { from: `${ly}-${pad(lm)}-01`, to: `${ly}-${pad(lm)}-${lastDay(ly, lm)}` }
  }
  if (preset === 'this_quarter') {
    const q = Math.ceil(m / 3)
    const qFrom = (q - 1) * 3 + 1
    const qTo = q * 3
    return { from: `${y}-${pad(qFrom)}-01`, to: `${y}-${pad(qTo)}-${lastDay(y, qTo)}` }
  }
  if (preset === 'last_quarter') {
    let q = Math.ceil(m / 3) - 1
    let qy = y
    if (q === 0) { q = 4; qy = y - 1 }
    const qFrom = (q - 1) * 3 + 1
    const qTo = q * 3
    return { from: `${qy}-${pad(qFrom)}-01`, to: `${qy}-${pad(qTo)}-${lastDay(qy, qTo)}` }
  }
  if (preset === 'this_year') {
    return { from: `${y}-01-01`, to: `${y}-12-31` }
  }
  if (preset === 'last_year') {
    return { from: `${y - 1}-01-01`, to: `${y - 1}-12-31` }
  }
  return null // '' = vše, 'custom' = ruční
}

// Inicializace: nastav daty dle výchozí předvolby
const _initDates = computePeriodDates('this_year')
if (_initDates) { dateFrom.value = _initDates.from; dateTo.value = _initDates.to }
const overdueOnly = ref(false)
const unpaidOnly = ref(false)
const currencyFilter = ref<string>('')
const currencies = ref<Currency[]>([])

const selectedIds = ref<number[]>([])
const bulkBusy = ref(false)

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function toggleSelected(id: number) {
  const i = selectedIds.value.indexOf(id)
  if (i === -1) selectedIds.value.push(id)
  else selectedIds.value.splice(i, 1)
}

function statusLabel(status: string): string {
  const key = `purchase_invoice.status.${status}`
  const v = t(key)
  return v === key ? status : v
}

function statusBadgeClass(status: string): string {
  const classes: Record<string, string> = {
    draft:     'bg-neutral-100 text-neutral-600',
    received:  'bg-primary-100 text-primary-700',
    booked:    'bg-accent-100 text-accent-600',
    paid:      'bg-success-50 text-success-600',
    cancelled: 'bg-neutral-100 text-neutral-400',
  }
  return classes[status] ?? 'bg-neutral-100 text-neutral-600'
}

function isOverdue(dueDate: string, status: string): boolean {
  if (status !== 'received' && status !== 'booked') return false
  const due = new Date(dueDate)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return due <= today
}

function invoiceRowClass(dueDate: string, status: string): string {
  if (status === 'cancelled') return 'opacity-50'
  if (status === 'paid') return 'opacity-70'
  if (isOverdue(dueDate, status)) return 'bg-danger-50/30'
  return ''
}

async function bulkMarkPaid() {
  const list = selectedIds.value.filter(id =>
    ['received', 'booked'].includes(
      groups.value.flatMap(g => g.invoices).find(i => i.id === id)?.status ?? ''
    )
  )
  if (list.length === 0) {
    toast.warning(t('purchase_invoice.bulk_mark_paid_no_eligible'))
    return
  }
  if (!confirm(t('purchase_invoice.bulk_mark_paid_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const id of list) {
      try {
        await purchaseInvoicesApi.transitionStatus(id, 'paid')
        okCount++
      } catch (e: any) {
        const inv = groups.value.flatMap(g => g.invoices).find(i => i.id === id)
        errors.push(`${inv?.invoice_number || `#${id}`}: ${e?.response?.data?.error?.message || 'error'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('purchase_invoice.bulk_mark_paid_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('purchase_invoice.bulk_mark_paid_success', { n: okCount }))
    }
    await load(true)
  } finally {
    bulkBusy.value = false
  }
}

async function bulkBook() {
  const list = selectedIds.value.filter(id =>
    groups.value.flatMap(g => g.invoices).find(i => i.id === id)?.status === 'received'
  )
  if (list.length === 0) {
    toast.warning(t('purchase_invoice.bulk_book_no_eligible'))
    return
  }
  if (!confirm(t('purchase_invoice.bulk_book_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const id of list) {
      try {
        await purchaseInvoicesApi.transitionStatus(id, 'booked')
        okCount++
      } catch (e: any) {
        const inv = groups.value.flatMap(g => g.invoices).find(i => i.id === id)
        errors.push(`${inv?.invoice_number || `#${id}`}: ${e?.response?.data?.error?.message || 'error'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('purchase_invoice.bulk_book_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('purchase_invoice.bulk_book_success', { n: okCount }))
    }
    await load(true)
  } finally {
    bulkBusy.value = false
  }
}

async function exportCsv() {
  try {
    const r = await purchaseInvoicesApi.listGrouped({
      q: search.value || undefined,
      status: statusFilter.value || undefined,
      type: docKindFilter.value || undefined,
      date_from: dateFrom.value || undefined,
      date_to:   dateTo.value || undefined,
      currency:  currencyFilter.value || undefined,
    })
    // Build CSV from grouped data
    const rows: string[][] = []
    rows.push(['Month', 'Invoice Number', 'Supplier', 'Issue Date', 'Due Date', 'Status', 'Currency', 'Without VAT', 'VAT', 'With VAT'])
    for (const g of r.data) {
      for (const inv of g.invoices) {
        rows.push([
          g.month,
          inv.invoice_number,
          inv.supplier_company_name,
          inv.issue_date,
          inv.due_date,
          inv.status,
          inv.currency,
          String(inv.total_without_vat),
          String(inv.total_vat),
          String(inv.total_with_vat),
        ])
      }
    }
    const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `purchase-invoices-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('purchase_invoice.csv_export_failed'))
  }
}

function mergeGroups(existing: PurchaseInvoiceMonthGroup[], incoming: PurchaseInvoiceMonthGroup[]): PurchaseInvoiceMonthGroup[] {
  const byMonth = new Map<string, PurchaseInvoiceMonthGroup>()
  for (const g of existing) byMonth.set(g.month, g)
  for (const g of incoming) {
    const cur = byMonth.get(g.month)
    if (!cur) {
      byMonth.set(g.month, g)
      continue
    }
    cur.invoices.push(...g.invoices)
    cur.count += g.count
    for (const t of g.totals_per_currency) {
      const found = cur.totals_per_currency.find(x => x.currency === t.currency)
      if (found) {
        found.without_vat = Math.round((found.without_vat + t.without_vat) * 100) / 100
        found.vat         = Math.round((found.vat         + t.vat)         * 100) / 100
        found.with_vat    = Math.round((found.with_vat    + t.with_vat)    * 100) / 100
      } else {
        cur.totals_per_currency.push({ ...t })
      }
    }
  }
  return Array.from(byMonth.values()).sort((a, b) => b.month.localeCompare(a.month))
}

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const result = await purchaseInvoicesApi.listGrouped({
      q: search.value || undefined,
      status: statusFilter.value || undefined,
      type: docKindFilter.value || undefined,
      date_from: dateFrom.value || undefined,
      date_to:   dateTo.value || undefined,
      currency:  currencyFilter.value || undefined,
      overdue: overdueOnly.value || undefined,
      unpaid_only: unpaidOnly.value || undefined,
      page: page.value,
    })
    if (reset) {
      groups.value = result.data
    } else {
      groups.value = mergeGroups(groups.value, result.data)
    }
    total.value = result.meta.total
    pages.value = result.meta.pages ?? 1
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

onMounted(async () => {
  codebooksApi.currencies().then(r => {
    const seen = new Set<string>()
    currencies.value = r.filter(c => c.is_active && !seen.has(c.code) && seen.add(c.code))
  }).catch(() => {})
  await load(true)
})

watch([statusFilter, docKindFilter, dateFrom, dateTo, overdueOnly, unpaidOnly, currencyFilter], () => load(true))
// Sync docKindFilter to URL query params for shareable links
watch(docKindFilter, (v) => {
  const q: Record<string, string> = Object.fromEntries(
    Object.entries(route.query).map(([k, v]) => [k, Array.isArray(v) ? (v[0] ?? '') : (v ?? '')]) as [string, string][]
  )
  if (v) q.type = v
  else delete q.type
  router.replace({ query: q })
})
// Při změně předvolby období přepočítej dateFrom/dateTo
watch(periodPreset, (preset) => {
  if (preset === 'custom') return
  const dates = computePeriodDates(preset)
  if (dates) { dateFrom.value = dates.from; dateTo.value = dates.to }
  else { dateFrom.value = ''; dateTo.value = '' }
})
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => load(true), 300)
})

const loadedCount = computed(() => groups.value.reduce((s, g) => s + g.count, 0))

function openInvoice(inv: PurchaseInvoiceListItem) {
  router.push(`/purchase-invoices/${inv.id}`)
}

// Bulk actionable selections
const bookableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => ids.has(inv.id) && inv.status === 'received')
})

const payableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => ids.has(inv.id) && ['received', 'booked'].includes(inv.status))
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('purchase_invoice.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button v-if="bookableSelected.length > 0"
          @click="bulkBook"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-accent-500 text-accent-700 hover:bg-accent-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk_book', { n: bookableSelected.length }) }}
        </button>
        <button v-if="payableSelected.length > 0"
          @click="bulkMarkPaid"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-success-500 text-success-600 hover:bg-success-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk_mark_paid', { n: payableSelected.length }) }}
        </button>
        <RouterLink
          to="/purchase-invoices/new"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
        >
          {{ t('purchase_invoice.new') }}
        </RouterLink>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
      <div class="flex flex-wrap items-center gap-2">
        <input
          v-model="search"
          type="search"
          :placeholder="t('purchase_invoice.search_placeholder')"
          class="flex-1 min-w-48 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">{{ t('purchase_invoice.all_statuses') }}</option>
          <option value="draft">{{ t('purchase_invoice.status.draft') }}</option>
          <option value="received">{{ t('purchase_invoice.status.received') }}</option>
          <option value="booked">{{ t('purchase_invoice.status.booked') }}</option>
          <option value="paid">{{ t('purchase_invoice.status.paid') }}</option>
          <option value="cancelled">{{ t('purchase_invoice.status.cancelled') }}</option>
        </select>
        <select v-model="docKindFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">{{ t('purchase_doc_type.all') }}</option>
          <option value="invoice">{{ t('purchase_doc_type.invoice') }}</option>
          <option value="receipt">{{ t('purchase_doc_type.receipt') }}</option>
          <option value="credit_note">{{ t('purchase_doc_type.credit_note') }}</option>
          <option value="payment">{{ t('purchase_doc_type.payment') }}</option>
        </select>
        <select v-model="currencyFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">{{ t('purchase_invoice.all_currencies') }}</option>
          <option v-for="c in currencies" :key="c.id" :value="c.code">{{ c.code }}</option>
        </select>
        <select v-model="periodPreset" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">Všechna období</option>
          <option value="this_month">Aktuální měsíc</option>
          <option value="last_month">Minulý měsíc</option>
          <option value="this_quarter">Aktuální kvartál</option>
          <option value="last_quarter">Minulý kvartál</option>
          <option value="this_year">Tento rok</option>
          <option value="last_year">Minulý rok</option>
          <option value="custom">Vlastní rozsah</option>
        </select>
        <template v-if="periodPreset === 'custom'">
          <input v-model="dateFrom" type="date" placeholder="Od"
            class="h-9 px-2 border border-neutral-300 rounded-md text-sm" title="Datum od" />
          <input v-model="dateTo" type="date" placeholder="Do"
            class="h-9 px-2 border border-neutral-300 rounded-md text-sm" title="Datum do" />
        </template>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="overdueOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('purchase_invoice.overdue_only') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="unpaidOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('purchase_invoice.unpaid_only') }}
        </label>
        <button @click="exportCsv"
          class="cursor-pointer ml-auto h-9 px-3 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md text-sm inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 0 1 2-2h11l5 5v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
          {{ t('purchase_invoice.csv_export') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="8" :cols="7" />
    </div>

    <div v-else-if="!groups.length" class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState :title="t('purchase_invoice.no_data')" :cta="t('purchase_invoice.create_first')" to="/purchase-invoices/new" />
    </div>

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('purchase_invoice.summary_count', { n: total, m: groups.length }) }}</span>
        <span v-if="total > loadedCount">{{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
      </div>

      <!-- Skupiny po měsících -->
      <section v-for="g in groups" :key="g.month" class="mb-5">
        <header class="sticky top-16 z-[5] flex items-center justify-between bg-neutral-50/95 backdrop-blur border border-neutral-200 rounded-t-lg px-4 py-2.5 mb-0">
          <div class="flex items-center gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ formatMonth(g.month) }}</h2>
            <span class="text-xs text-neutral-500">{{ g.count }} {{ g.count === 1 ? t('purchase_invoice.doc_1') : (g.count < 5 ? t('purchase_invoice.doc_2_4') : t('purchase_invoice.doc_5plus')) }}</span>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span v-for="t in g.totals_per_currency" :key="t.currency" class="font-mono">
              <span class="text-neutral-500">{{ t.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoney(t.with_vat, t.currency) }}</span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-white border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="px-2 py-2 w-10"></th>
                <th class="text-left px-4 py-2 font-medium w-36">Číslo faktury</th>
                <th class="text-left px-4 py-2 font-medium">Dodavatel</th>
                <th class="text-center px-4 py-2 font-medium">Přijato / Vystaveno</th>
                <th class="text-center px-4 py-2 font-medium">Splatnost</th>
                <th class="text-right px-4 py-2 font-medium">DPH</th>
                <th class="text-right px-4 py-2 font-medium">{{ t('purchase_invoice.amount_to_pay') }}</th>
                <th class="text-center px-4 py-2 font-medium">Stav</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr
                v-for="inv in g.invoices"
                :key="inv.id"
                @click="openInvoice(inv)"
                class="cursor-pointer hover:bg-neutral-50 transition"
                :class="invoiceRowClass(inv.due_date, inv.status)"
              >
                <td class="px-2 py-2.5 text-center" @click.stop>
                  <input
                    type="checkbox"
                    :checked="selectedIds.includes(inv.id)"
                    @change="toggleSelected(inv.id)"
                    class="w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                  />
                </td>
                <td class="px-4 py-2.5 font-mono text-xs">
                  <span v-if="inv.invoice_number">{{ inv.invoice_number }}</span>
                  <span v-else class="text-neutral-400">{{ t('purchase_invoice.draft_id_short', { id: inv.id }) }}</span>
                </td>
                <td class="px-4 py-2.5">
                  <div class="font-medium text-neutral-900">{{ inv.supplier_company_name }}</div>
                </td>
                <td class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  {{ formatDate(inv.received_at || inv.issue_date) }}
                </td>
                <td class="px-4 py-2.5 text-center text-xs">
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : 'text-neutral-600'">
                    {{ formatDate(inv.due_date) }}
                  </span>
                </td>
                <td class="px-4 py-2.5 text-right font-mono text-neutral-500">
                  {{ formatMoney(inv.total_vat, inv.currency) }}
                </td>
                <td class="px-4 py-2.5 text-right font-mono">
                  {{ formatMoney(inv.amount_to_pay || inv.total_with_vat, inv.currency) }}
                </td>
                <td class="px-4 py-2.5 text-center">
                  <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                    {{ statusLabel(inv.status) }}
                  </span>
                  <span v-if="inv.paid_at" class="ml-1 text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                    :title="t('purchase_invoice.paid_at', { date: formatDate(inv.paid_at) })">✓</span>
                </td>
              </tr>
            </tbody>
          </table>
          </div>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden bg-white border border-t-0 border-neutral-200 rounded-b-lg divide-y divide-neutral-100 overflow-hidden">
          <div
            v-for="inv in g.invoices"
            :key="`m-${inv.id}`"
            @click="openInvoice(inv)"
            class="cursor-pointer hover:bg-neutral-50 transition px-3 py-3"
            :class="invoiceRowClass(inv.due_date, inv.status)"
          >
            <div class="flex items-start gap-3">
              <input
                type="checkbox"
                :checked="selectedIds.includes(inv.id)"
                @change="toggleSelected(inv.id)"
                @click.stop
                class="mt-0.5 w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                  <div class="font-medium text-neutral-900 truncate">{{ inv.supplier_company_name }}</div>
                  <div class="text-right">
                    <div class="font-mono text-sm font-semibold whitespace-nowrap">
                      {{ formatMoney(inv.amount_to_pay || inv.total_with_vat, inv.currency) }}
                    </div>
                    <div class="font-mono text-xs text-neutral-400 whitespace-nowrap">
                      DPH {{ formatMoney(inv.total_vat, inv.currency) }}
                    </div>
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
                  <div class="truncate">
                    <span class="font-mono">
                      <span v-if="inv.invoice_number">{{ inv.invoice_number }}</span>
                      <span v-else class="text-neutral-400">{{ t('purchase_invoice.draft_id_short', { id: inv.id }) }}</span>
                    </span>
                  </div>
                </div>
                <div class="flex items-center justify-between gap-2 mt-2">
                  <div class="text-xs text-neutral-600 whitespace-nowrap">
                    {{ formatDate(inv.received_at || inv.issue_date) }}
                    <span class="text-neutral-400"> → </span>
                    <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                      {{ formatDate(inv.due_date) }}
                    </span>
                  </div>
                  <div class="flex items-center gap-1 flex-wrap justify-end">
                    <span v-if="inv.paid_at" class="text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                      :title="t('purchase_invoice.paid_at', { date: formatDate(inv.paid_at) })">✓</span>
                    <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                      {{ statusLabel(inv.status) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div v-if="page < pages" class="text-center mt-3">
        <button @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-2 shadow-sm">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>
