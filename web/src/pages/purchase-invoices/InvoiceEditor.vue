<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import {
  purchaseInvoicesApi,
  type PurchaseInvoice,
  type PurchaseInvoicePayload,
  type PurchaseInvoiceItem,
} from '@/api/purchaseInvoices'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { formatMoney, formatPercent } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'
import { useSupplierStore } from '@/stores/supplier'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

const { t, locale } = useI18n()
const toast = useToast()

useHotkey('ctrl+s', (e) => { e.preventDefault(); submit() })

const supplierStore = useSupplierStore()

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const invoiceId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loaded = ref(false)
const submitting = ref(false)
const error = ref('')

const clients = ref<Client[]>([])
const vatRates = ref<VatRate[]>([])
const currencies = ref<Currency[]>([])
const units = ref<Unit[]>([])

const supplierIsVatPayer = computed(() => supplierStore.currentSupplier?.is_vat_payer ?? true)

function defaultItemUnit(): string {
  return units.value.find(u => u.is_default)?.code || units.value[0]?.code || 'ks'
}

function defaultVatRateId(reverseCharge = false): number {
  if (!supplierIsVatPayer.value) {
    const zero = vatRates.value.find(v => Number(v.rate_percent) === 0 && !v.is_reverse_charge)
    if (zero) return zero.id
  }
  if (reverseCharge) {
    const rc = vatRates.value.find(v => v.is_reverse_charge)
    if (rc) return rc.id
  }
  const def = vatRates.value.find(v => v.is_default)
  return def?.id ?? vatRates.value[0]?.id ?? 0
}

function syncItemsVatRateToReverseCharge() {
  const target = defaultVatRateId(form.value.reverse_charge)
  if (!target) return
  for (const it of form.value.items) it.vat_rate_id = target
}

function vatRateLabel(r: VatRate): string {
  if (Number(r.rate_percent) > 0) return `${r.rate_percent} %`
  if (r.is_reverse_charge) return t('purchase_invoice.vat_rate_label.reverse_charge')
  return t('purchase_invoice.vat_rate_label.exempt')
}

const form = ref<{
  supplier_id: number | null
  invoice_number: string
  varsymbol: string
  issue_date: string
  tax_date: string
  due_date: string
  received_at: string
  currency_id: number
  currency: string
  reverse_charge: boolean
  language: 'cs' | 'en'
  note_above_items: string
  note_below_items: string
  advance_paid_amount: number
  exchange_rate: number | null
  items: PurchaseInvoiceItem[]
}>({
  supplier_id: null,
  invoice_number: '',
  varsymbol: '',
  issue_date: today(),
  tax_date: today(),
  due_date: addDays(today(), 14),
  received_at: today(),
  currency_id: 0,
  currency: 'CZK',
  reverse_charge: false,
  language: 'cs',
  note_above_items: '',
  note_below_items: '',
  advance_paid_amount: 0,
  exchange_rate: null,
  items: [],
})

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function addDays(date: string, days: number): string {
  const d = new Date(date)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

function blankItem(): PurchaseInvoiceItem {
  return {
    description: '',
    quantity: 1,
    unit: defaultItemUnit(),
    unit_price_without_vat: 0,
    vat_rate_id: defaultVatRateId(form.value.reverse_charge),
    order_index: form.value.items.length,
  }
}

watch(() => form.value.reverse_charge, (newVal, oldVal) => {
  if (loaded.value && newVal !== oldVal) syncItemsVatRateToReverseCharge()
})

onMounted(async () => {
  const [vr, cur, un] = await Promise.all([
    codebooksApi.vatRates('CZ'),
    codebooksApi.currencies(),
    codebooksApi.units(),
  ])
  vatRates.value = vr
  currencies.value = cur
  units.value = un
  if (form.value.currency_id === 0) {
    const def = cur.find(c => c.is_default && c.code === 'CZK') || cur[0]
    if (def) {
      form.value.currency_id = def.id
      form.value.currency = def.code
    }
  }

  const cl = await clientsApi.list({ archived: false })
  clients.value = cl.data

  if (isEdit.value && invoiceId.value) {
    const inv = await purchaseInvoicesApi.get(invoiceId.value)
    Object.assign(form.value, {
      supplier_id: inv.supplier_id,
      invoice_number: inv.invoice_number,
      varsymbol: inv.varsymbol ?? '',
      issue_date: inv.issue_date.slice(0, 10),
      tax_date: (inv.tax_date ?? inv.issue_date).slice(0, 10),
      due_date: inv.due_date.slice(0, 10),
      received_at: (inv.received_at ?? inv.issue_date).slice(0, 10),
      currency_id: inv.currency_id,
      currency: inv.currency,
      reverse_charge: inv.reverse_charge,
      language: inv.language,
      note_above_items: inv.note_above_items ?? '',
      note_below_items: inv.note_below_items ?? '',
      advance_paid_amount: inv.advance_paid_amount,
      items: inv.items.map(i => ({ ...i })),
      exchange_rate: inv.exchange_rate ?? null,
    })
  } else {
    if (form.value.items.length === 0) {
      form.value.items = [blankItem()]
    }
  }

  loaded.value = true
})

function onCurrencyChange() {
  const c = currencies.value.find(x => x.id === form.value.currency_id)
  if (c) form.value.currency = c.code
}

function addItem() {
  form.value.items.push(blankItem())
}

function removeItem(index: number) {
  form.value.items.splice(index, 1)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

function moveUp(index: number) {
  if (index === 0) return
  const [m] = form.value.items.splice(index, 1)
  form.value.items.splice(index - 1, 0, m)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

function moveDown(index: number) {
  if (index >= form.value.items.length - 1) return
  const [m] = form.value.items.splice(index, 1)
  form.value.items.splice(index + 1, 0, m)
  form.value.items.forEach((it, i) => (it.order_index = i))
}

const computed_totals = computed(() => {
  const breakdown = new Map<number, { rate: number; base: number; vat: number }>()
  let totalBase = 0
  let totalVat = 0

  for (const item of form.value.items) {
    const vatRate = (form.value.reverse_charge || !supplierIsVatPayer.value)
      ? 0
      : vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0
    const base = round2(item.quantity * item.unit_price_without_vat)
    const vat = round2(base * (vatRate / 100))

    totalBase += base
    totalVat += vat

    if (!breakdown.has(vatRate)) {
      breakdown.set(vatRate, { rate: vatRate, base: 0, vat: 0 })
    }
    const b = breakdown.get(vatRate)!
    b.base += base
    b.vat += vat
  }

  return {
    without_vat: round2(totalBase),
    vat: round2(totalVat),
    with_vat: round2(totalBase + totalVat),
    amount_to_pay: round2(totalBase + totalVat - form.value.advance_paid_amount),
    breakdown: Array.from(breakdown.values())
      .map(b => ({ rate: b.rate, base: round2(b.base), vat: round2(b.vat) }))
      .sort((a, b) => b.rate - a.rate),
  }
})

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

function itemTotal(item: PurchaseInvoiceItem): number {
  return round2(item.quantity * item.unit_price_without_vat)
}

async function submit() {
  form.value.items = form.value.items.filter(it =>
    (it.description || '').trim() !== '' || (Number(it.unit_price_without_vat) || 0) !== 0
  )
  form.value.items.forEach((it, i) => (it.order_index = i))

  submitting.value = true
  error.value = ''
  try {
    const payload: PurchaseInvoicePayload = {
      supplier_id: form.value.supplier_id!,
      invoice_number: form.value.invoice_number,
      varsymbol: form.value.varsymbol.trim() || null,
      issue_date: form.value.issue_date,
      tax_date: form.value.tax_date || null,
      due_date: form.value.due_date,
      received_at: form.value.received_at,
      currency_id: form.value.currency_id,
      reverse_charge: form.value.reverse_charge,
      language: form.value.language,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      advance_paid_amount: form.value.advance_paid_amount,
      exchange_rate: (form.value.currency !== 'CZK' && form.value.exchange_rate && form.value.exchange_rate > 0)
        ? form.value.exchange_rate : undefined,
      items: form.value.items.map((it, i) => ({
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
        order_index: i,
      })),
    }

    let saved: PurchaseInvoice
    if (isEdit.value && invoiceId.value) {
      saved = await purchaseInvoicesApi.update(invoiceId.value, payload)
    } else {
      saved = await purchaseInvoicesApi.create(payload)
    }

    router.push(`/purchase-invoices/${saved.id}`)
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('common.save_failed'))
  } finally {
    submitting.value = false
  }
}

async function deleteDraft() {
  if (!invoiceId.value) return
  if (!confirm(t('purchase_invoice.delete_draft_confirm'))) return
  try {
    await purchaseInvoicesApi.delete(invoiceId.value)
    router.push('/purchase-invoices')
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('common.delete_failed'))
  }
}
</script>

<template>
  <div v-if="!loaded" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else class="max-w-5xl">
    <div class="flex items-center justify-between mb-4">
      <div>
        <RouterLink to="/purchase-invoices" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('purchase_invoice.back_to_list') }}</RouterLink>
        <h1 class="text-2xl font-semibold mt-1">
          {{ isEdit ? t('purchase_invoice.edit_title') : t('purchase_invoice.new_title') }}
        </h1>
      </div>
      <button v-if="isEdit" @click="deleteDraft" class="text-sm text-danger-500 hover:text-danger-600 cursor-pointer">
        {{ t('purchase_invoice.delete_draft_btn') }}
      </button>
    </div>

    <form @submit.prevent="submit" class="space-y-4">
      <!-- Dodavatel + datumy -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.supplier') }}</h3>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.supplier') }} *</label>
              <SearchableSelect
                :model-value="form.supplier_id"
                @update:model-value="(v) => form.supplier_id = v"
                :options="clients.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
                :placeholder="t('purchase_invoice.select_supplier')"
                :clearable="false"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.invoice_number') }} *</label>
              <input v-model="form.invoice_number" type="text" required
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.varsymbol') }}</label>
              <input v-model="form.varsymbol" type="text" maxlength="20"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.currency') }}</label>
                <select v-model.number="form.currency_id" @change="onCurrencyChange"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white">
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.language') }}</label>
                <select v-model="form.language" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white">
                  <option value="cs">CZ</option>
                  <option value="en">EN</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.dates_section') }}</h3>
          <div class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.received_date') }} *</label>
              <input v-model="form.received_at" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.issue_date') }} *</label>
              <input v-model="form.issue_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.tax_date') }}</label>
              <input v-model="form.tax_date" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.due_date') }} *</label>
              <input v-model="form.due_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            </div>
            <div v-if="form.currency !== 'CZK' && form.exchange_rate !== null && form.exchange_rate > 0">
              <label class="block text-sm font-medium text-neutral-700 mb-1">
                {{ t('purchase_invoice.exchange_rate_label', { currency: form.currency }) }}
              </label>
              <input v-model.number="form.exchange_rate" type="number" step="0.0001" min="0"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
            </div>
          </div>
        </div>
      </div>

      <!-- Položky -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
        <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('purchase_invoice.items') }}</h3>
          <button type="button" @click="addItem" class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md">
            {{ t('purchase_invoice.add_item') }}
          </button>
        </div>
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-8"></th>
              <th class="px-3 py-2 text-left font-medium">{{ t('purchase_invoice.items_table.description') }}</th>
              <th class="px-3 py-2 text-right font-medium w-20">{{ t('purchase_invoice.items_table.qty') }}</th>
              <th class="px-3 py-2 text-left font-medium w-16">{{ t('purchase_invoice.items_table.unit') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ t('purchase_invoice.items_table.unit_price') }}</th>
              <th v-if="supplierIsVatPayer" class="px-3 py-2 text-center font-medium w-24">{{ t('purchase_invoice.totals.vat') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ t('purchase_invoice.totals.total') }}</th>
              <th class="px-3 py-2 w-12"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(item, i) in form.items" :key="i">
              <td class="px-2 py-2 text-center text-xs text-neutral-400">
                <button type="button" @click="moveUp(i)" :disabled="i === 0" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                <button type="button" @click="moveDown(i)" :disabled="i === form.items.length - 1" class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
              </td>
              <td class="px-3 py-2">
                <textarea v-model="item.description" rows="1" :placeholder="t('purchase_invoice.items_table.description')"
                  class="w-full px-2 py-1.5 border border-neutral-200 rounded text-sm resize-y min-h-[36px] focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
              </td>
              <td class="px-3 py-2">
                <input v-model.number="item.quantity" type="number" step="0.001" min="0"
                  class="w-full h-9 px-2 border border-neutral-200 rounded text-right font-mono text-sm" />
              </td>
              <td class="px-3 py-2">
                <select v-model="item.unit" class="w-full h-9 px-1 border border-neutral-200 rounded text-sm bg-white">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="item.unit && !units.some(u => u.code === item.unit)" :value="item.unit">{{ item.unit }}</option>
                </select>
              </td>
              <td class="px-3 py-2">
                <input v-model.number="item.unit_price_without_vat" type="number" step="0.01" min="0"
                  class="w-full h-9 px-2 border border-neutral-200 rounded text-right font-mono text-sm" />
              </td>
              <td v-if="supplierIsVatPayer" class="px-3 py-2">
                <select v-model.number="item.vat_rate_id" class="w-full h-9 px-1 border border-neutral-200 rounded text-sm bg-white">
                  <option v-for="r in vatRates" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                </select>
              </td>
              <td class="px-3 py-2 text-right font-mono text-sm">
                {{ formatMoney(itemTotal(item), form.currency) }}
              </td>
              <td class="px-2 py-2 text-center">
                <button type="button" @click="removeItem(i)" class="text-danger-500 hover:text-danger-600 text-lg leading-none">×</button>
              </td>
            </tr>
            <tr v-if="form.items.length === 0">
              <td :colspan="supplierIsVatPayer ? 8 : 7" class="px-4 py-6 text-center text-neutral-400 text-sm">
                {{ t('purchase_invoice.no_items') }} <button type="button" @click="addItem" class="text-primary-600 hover:underline">{{ t('purchase_invoice.add_first') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: stack -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-if="form.items.length === 0" class="px-4 py-6 text-center text-neutral-400 text-sm">
            {{ t('purchase_invoice.no_items') }} <button type="button" @click="addItem" class="text-primary-600 hover:underline">{{ t('purchase_invoice.add_first') }}</button>
          </div>
          <div v-for="(item, i) in form.items" :key="`m-${i}`" class="p-3 space-y-2">
            <div class="flex items-center justify-between text-xs text-neutral-500">
              <span class="font-mono">#{{ i + 1 }}</span>
              <div class="flex items-center gap-2">
                <button type="button" @click="moveUp(i)" :disabled="i === 0" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-200 rounded hover:bg-neutral-50 disabled:opacity-30 disabled:cursor-not-allowed">▲</button>
                <button type="button" @click="moveDown(i)" :disabled="i === form.items.length - 1" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-neutral-200 rounded hover:bg-neutral-50 disabled:opacity-30 disabled:cursor-not-allowed">▼</button>
                <button type="button" @click="removeItem(i)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded text-lg leading-none">×</button>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.items_table.description') }}</label>
              <textarea v-model="item.description" rows="2" :placeholder="t('purchase_invoice.items_table.description')"
                class="w-full px-3 py-2 border border-neutral-200 rounded text-sm resize-y min-h-[44px] focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.items_table.qty') }}</label>
                <input v-model.number="item.quantity" type="number" inputmode="decimal" step="0.001" min="0"
                  class="w-full h-10 px-3 border border-neutral-200 rounded text-right font-mono text-sm" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.items_table.unit') }}</label>
                <select v-model="item.unit" class="w-full h-10 px-2 border border-neutral-200 rounded text-sm bg-white">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="item.unit && !units.some(u => u.code === item.unit)" :value="item.unit">{{ item.unit }}</option>
                </select>
              </div>
            </div>
            <div :class="supplierIsVatPayer ? 'grid grid-cols-2 gap-2' : ''">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.items_table.unit_price') }}</label>
                <input v-model.number="item.unit_price_without_vat" type="number" inputmode="decimal" step="0.01" min="0"
                  class="w-full h-10 px-3 border border-neutral-200 rounded text-right font-mono text-sm" />
              </div>
              <div v-if="supplierIsVatPayer">
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.totals.vat') }}</label>
                <select v-model.number="item.vat_rate_id" class="w-full h-10 px-2 border border-neutral-200 rounded text-sm bg-white">
                  <option v-for="r in vatRates" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                </select>
              </div>
            </div>
            <div class="flex items-baseline justify-between pt-1 border-t border-neutral-100">
              <span class="text-xs font-medium text-neutral-500 uppercase tracking-wide">{{ t('purchase_invoice.totals.total') }}</span>
              <span class="font-mono font-semibold">{{ formatMoney(itemTotal(item), form.currency) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Sumace + poznámky -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="md:col-span-2 space-y-4">
          <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.note_above') }}</label>
            <textarea v-model="form.note_above_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
          <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.note_below') }}</label>
            <textarea v-model="form.note_below_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
        </div>

        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.summary') }}</h3>
          <dl class="space-y-1.5 text-sm">
            <template v-if="supplierIsVatPayer">
              <div v-for="b in computed_totals.breakdown" :key="b.rate" class="flex justify-between text-neutral-600">
                <dt>{{ t('purchase_invoice.totals.base') }} {{ formatPercent(b.rate) }}</dt>
                <dd class="font-mono">{{ formatMoney(b.base, form.currency) }}</dd>
              </div>
              <div v-for="b in computed_totals.breakdown" :key="'v'+b.rate" v-show="b.vat > 0" class="flex justify-between text-neutral-600">
                <dt>{{ t('purchase_invoice.totals.vat') }} {{ formatPercent(b.rate) }}</dt>
                <dd class="font-mono">{{ formatMoney(b.vat, form.currency) }}</dd>
              </div>
              <div class="flex justify-between border-t border-neutral-200 pt-2 mt-2 font-semibold">
                <dt>{{ t('purchase_invoice.totals.without_vat') }}</dt>
                <dd class="font-mono">{{ formatMoney(computed_totals.without_vat, form.currency) }}</dd>
              </div>
              <div class="flex justify-between font-semibold">
                <dt>{{ t('purchase_invoice.totals.vat_total') }}</dt>
                <dd class="font-mono">{{ formatMoney(computed_totals.vat, form.currency) }}</dd>
              </div>
            </template>
            <div class="flex justify-between border-t border-neutral-300 pt-2 mt-2 text-lg font-semibold text-primary-700">
              <dt>{{ t('purchase_invoice.totals.total') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.with_vat, form.currency) }}</dd>
            </div>
            <div v-if="form.advance_paid_amount > 0" class="flex justify-between text-sm text-neutral-600 pt-2">
              <dt>{{ t('purchase_invoice.totals.advance_deduction') }}</dt>
              <dd class="font-mono">−{{ formatMoney(form.advance_paid_amount, form.currency) }}</dd>
            </div>
            <div v-if="form.advance_paid_amount > 0" class="flex justify-between text-base font-semibold pt-1">
              <dt>{{ t('purchase_invoice.totals.amount_due') }}</dt>
              <dd class="font-mono">{{ formatMoney(computed_totals.amount_to_pay, form.currency) }}</dd>
            </div>
          </dl>
        </div>
      </div>

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ error }}
      </div>

      <!-- Action bar -->
      <div class="bg-white border border-neutral-200 rounded-lg p-4 flex justify-between items-center sticky bottom-3 shadow-md">
        <RouterLink to="/purchase-invoices" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('common.cancel') }}</RouterLink>
        <button type="submit" :disabled="submitting"
          class="px-5 h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </button>
      </div>
    </form>
  </div>
</template>
