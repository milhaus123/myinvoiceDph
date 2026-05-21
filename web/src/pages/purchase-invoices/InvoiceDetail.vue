<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  purchaseInvoicesApi,
  type PurchaseInvoice,
  type PurchaseInvoiceStatus,
} from '@/api/purchaseInvoices'
import {
  recurringPurchaseInvoicesApi,
  type RecurringFrequency,
} from '@/api/recurringPurchaseInvoices'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatPercent, formatMoney } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const auth = useAuthStore()
const isAdmin = computed(() => auth.user?.role === 'admin')

const supplierStore = useSupplierStore()
const supplierIsVatPayer = computed(() => supplierStore.currentSupplier?.is_vat_payer ?? true)

const invoice = ref<PurchaseInvoice | null>(null)
const loading = ref(true)
const busy = ref<string | null>(null)

// ── Recurring modal ─────────────────────────────────────────────────────────
const showRecurringModal = ref(false)
const recurringForm = ref({
  frequency: 'monthly' as RecurringFrequency,
  day_of_month: null as number | null,
  end_of_month: false,
  anchor_date: '',
  end_date: '' as string,
})

function todayStr(): string {
  return new Date().toISOString().slice(0, 10)
}

function openRecurringModal() {
  recurringForm.value = {
    frequency: 'monthly',
    day_of_month: null,
    end_of_month: false,
    anchor_date: todayStr(),
    end_date: '',
  }
  showRecurringModal.value = true
}

async function saveRecurring() {
  if (!invoice.value) return
  busy.value = 'recurring-save'
  try {
    const inv = invoice.value
    const payload = {
      supplier_id: inv.supplier_id,
      name: t('purchase_invoice.recurring_template_name_default', { supplier: inv.supplier_company_name }),
      frequency: recurringForm.value.frequency,
      day_of_month: recurringForm.value.end_of_month ? null : (recurringForm.value.day_of_month ?? null),
      end_of_month: recurringForm.value.end_of_month,
      anchor_date: recurringForm.value.anchor_date,
      end_date: recurringForm.value.end_date || null,
      currency_id: inv.currency_id,
      language: inv.language,
      payment_method: 'bank_transfer' as const,
      reverse_charge: inv.reverse_charge,
      payment_due_days: 14,
      note_above_items: inv.note_above_items,
      note_below_items: inv.note_below_items,
      increment_month_in_descriptions: false,
      auto_issue: true,
      items: inv.items.map((item, i) => ({
        description: item.description,
        quantity: item.quantity,
        unit: item.unit,
        unit_price_without_vat: item.unit_price_without_vat,
        vat_rate_id: item.vat_rate_id,
        order_index: i,
      })),
    }
    await recurringPurchaseInvoicesApi.create(payload)
    // Reload invoice to get updated recurring_template_id
    invoice.value = await purchaseInvoicesApi.get(inv.id)
    showRecurringModal.value = false
    toast.success(t('purchase_invoice.recurring_saved'))
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('common.save_failed')))
  } finally {
    busy.value = null
  }
}

async function cancelRecurring() {
  if (!invoice.value?.recurring_template_id) return
  if (!confirm(t('purchase_invoice.recurring_cancel_confirm'))) return
  busy.value = 'recurring-cancel'
  try {
    await recurringPurchaseInvoicesApi.delete(invoice.value.recurring_template_id)
    invoice.value = await purchaseInvoicesApi.get(invoice.value.id)
    toast.success(t('purchase_invoice.recurring_cancelled'))
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('common.delete_failed')))
  } finally {
    busy.value = null
  }
}
// ────────────────────────────────────────────────────────────────────────────

function isOverdue(dueDate: string, status: string): boolean {
  if (status !== 'received' && status !== 'booked') return false
  const due = new Date(dueDate)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return due <= today
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

function vatRateLabel(item: { vat_rate_snapshot?: number; vat_code?: string; vat_label_cs?: string }): string {
  if (item.vat_rate_snapshot !== undefined && item.vat_rate_snapshot !== null) {
    if (item.vat_rate_snapshot > 0) return `${item.vat_rate_snapshot} %`
    if (item.vat_code === 'RC') return t('purchase_invoice.reverse_charge')
    return t('purchase_invoice.vat_exempt')
  }
  return ''
}

async function load() {
  loading.value = true
  invoice.value = await purchaseInvoicesApi.get(Number(route.params.id))
  loading.value = false
}

onMounted(() => load())

function canEdit(): boolean {
  return invoice.value?.status === 'draft' || invoice.value?.status === 'received'
}

function canTransition(): boolean {
  return !!invoice.value && invoice.value.status !== 'cancelled'
}

async function transitionTo(newStatus: PurchaseInvoiceStatus) {
  if (!invoice.value) return
  busy.value = newStatus
  try {
    invoice.value = await purchaseInvoicesApi.transitionStatus(invoice.value.id, newStatus)
    toast.success(t('purchase_invoice.status_changed'))
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('purchase_invoice.status_change_failed')))
  } finally {
    busy.value = null
  }
}

async function deleteInvoice() {
  if (!invoice.value) return
  if (!confirm(t('purchase_invoice.delete_confirm'))) return
  try {
    await purchaseInvoicesApi.delete(invoice.value.id)
    router.push('/purchase-invoices')
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('common.delete_failed')))
  }
}
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else-if="!invoice" class="text-center text-neutral-500 py-12">
    {{ t('purchase_invoice.not_found') }}
  </div>

  <div v-else class="max-w-5xl space-y-4">
    <RouterLink to="/purchase-invoices" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('purchase_invoice.back_to_list') }}</RouterLink>

    <!-- Header: číslo faktury + stav + akce -->
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 md:gap-4">
      <h1 class="text-2xl font-semibold flex items-center gap-3 flex-wrap min-w-0">
        <span class="font-mono">{{ invoice.invoice_number }}</span>
        <span class="text-xs px-2 py-0.5 rounded font-normal" :class="statusBadgeClass(invoice.status)">
          {{ statusLabel(invoice.status) }}
        </span>
        <!-- Badge: z opakované šablony -->
        <RouterLink
          v-if="invoice.recurring_template_id"
          :to="{ name: 'recurring-purchase-invoice-detail', params: { id: invoice.recurring_template_id } }"
          class="text-xs px-2 py-0.5 rounded font-normal bg-primary-50 text-primary-600 hover:bg-primary-100 inline-flex items-center gap-1"
          :title="t('purchase_invoice.recurring_template_link', { id: invoice.recurring_template_id })">
          <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06"/></svg>
          {{ t('purchase_invoice.recurring_badge') }}
        </RouterLink>
      </h1>
      <div class="flex flex-wrap gap-2 md:justify-end">
        <button v-if="canEdit()"
          @click="router.push(`/purchase-invoices/${invoice!.id}/edit`)"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          {{ t('common.edit') }}
        </button>
        <button v-if="invoice.status === 'draft'"
          @click="transitionTo('received')"
          :disabled="busy !== null"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          {{ busy === 'received' ? '…' : t('purchase_invoice.mark_received') }}
        </button>
        <button v-if="invoice.status === 'received'"
          @click="transitionTo('booked')"
          :disabled="busy !== null"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          {{ busy === 'booked' ? '…' : t('purchase_invoice.mark_booked') }}
        </button>
        <button v-if="['received', 'booked'].includes(invoice.status)"
          @click="transitionTo('paid')"
          :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-success-500/50 text-success-600 hover:bg-success-50 rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-success-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
          {{ busy === 'paid' ? '…' : t('purchase_invoice.mark_paid') }}
        </button>

        <!-- Opakování: Zrušit opakování (červené) pokud recurring_template_id je nastaven -->
        <button
          v-if="invoice.recurring_template_id"
          @click="cancelRecurring"
          :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded-md inline-flex items-center gap-1.5 disabled:opacity-50">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          {{ busy === 'recurring-cancel' ? '…' : t('purchase_invoice.recurring_cancel_btn') }}
        </button>

        <!-- Opakování: Opakovat fakturu (neutrální) pokud recurring_template_id není nastaven -->
        <button
          v-else-if="invoice.status !== 'cancelled'"
          @click="openRecurringModal"
          :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-primary-300 text-primary-700 hover:bg-primary-50 rounded-md inline-flex items-center gap-1.5 disabled:opacity-50">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06"/></svg>
          {{ t('purchase_invoice.recurring_btn') }}
        </button>

        <button v-if="canTransition() && invoice.status !== 'cancelled'"
          @click="transitionTo('cancelled')"
          :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50 inline-flex items-center gap-1.5">
          {{ busy === 'cancelled' ? '…' : t('purchase_invoice.cancel') }}
        </button>
        <button v-if="invoice.status === 'draft'" @click="deleteInvoice" :disabled="busy !== null"
          class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
          {{ t('common.delete') }}
        </button>
      </div>
    </div>

    <!-- Info grid: Dodavatel / Data / Platba -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <!-- Dodavatel -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.supplier') }}</h3>
        <dl class="space-y-1 text-sm">
          <div class="font-medium text-neutral-900">{{ invoice.supplier_company_name }}</div>
          <div v-if="invoice.supplier_ic" class="text-neutral-600">IČ: {{ invoice.supplier_ic }}</div>
          <div v-if="invoice.supplier_dic" class="text-neutral-600">DIČ: {{ invoice.supplier_dic }}</div>
          <div v-if="invoice.supplier_main_email" class="text-neutral-600">{{ invoice.supplier_main_email }}</div>
        </dl>
      </div>

      <!-- Datumy -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.dates_section') }}</h3>
        <dl class="space-y-1.5 text-sm">
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.received_date') }}</dt>
            <dd class="font-medium">{{ formatDate(invoice.received_at || invoice.issue_date) }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.issue_date') }}</dt>
            <dd class="font-medium">{{ formatDate(invoice.issue_date) }}</dd>
          </div>
          <div v-if="invoice.tax_date" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.tax_date') }}</dt>
            <dd class="font-medium">{{ formatDate(invoice.tax_date) }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.due_date') }}</dt>
            <dd class="font-medium" :class="isOverdue(invoice.due_date, invoice.status) ? 'text-danger-500 font-semibold' : ''">
              {{ formatDate(invoice.due_date) }}
            </dd>
          </div>
          <div v-if="invoice.booked_at" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.booked_at') }}</dt>
            <dd class="font-medium">{{ formatDate(invoice.booked_at) }}</dd>
          </div>
          <div v-if="invoice.paid_at" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.paid_at') }}</dt>
            <dd class="font-medium text-success-600">{{ formatDate(invoice.paid_at) }}</dd>
          </div>
          <div v-if="invoice.varsymbol" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.varsymbol') }}</dt>
            <dd class="font-mono font-medium">{{ invoice.varsymbol }}</dd>
          </div>
        </dl>
      </div>

      <!-- Platba -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.summary') }}</h3>
        <dl class="space-y-1.5 text-sm">
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.currency') }}</dt>
            <dd class="font-mono font-medium">{{ invoice.currency }}</dd>
          </div>
          <div class="flex justify-between border-t border-neutral-200 pt-2 mt-2 text-base font-semibold text-primary-700">
            <dt>{{ t('purchase_invoice.totals.total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.total_with_vat, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.advance_paid_amount > 0" class="flex justify-between text-sm text-neutral-600">
            <dt>{{ t('purchase_invoice.totals.advance_paid') }}</dt>
            <dd class="font-mono">−{{ formatMoney(invoice.advance_paid_amount, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.advance_paid_amount > 0" class="flex justify-between text-base font-semibold">
            <dt>{{ t('purchase_invoice.totals.amount_to_pay') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.amount_to_pay || invoice.total_with_vat, invoice.currency) }}</dd>
          </div>
        </dl>
      </div>
    </div>

    <!-- Poznámky -->
    <div v-if="invoice.note_above_items || invoice.note_below_items" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <div v-if="invoice.note_above_items" class="text-sm text-neutral-700 mb-3">
        <div class="font-medium text-neutral-500 text-xs uppercase tracking-wide mb-1">{{ t('purchase_invoice.note_above') }}</div>
        <p>{{ invoice.note_above_items }}</p>
      </div>
      <div v-if="invoice.note_below_items" class="text-sm text-neutral-700">
        <div class="font-medium text-neutral-500 text-xs uppercase tracking-wide mb-1">{{ t('purchase_invoice.note_below') }}</div>
        <p>{{ invoice.note_below_items }}</p>
      </div>
    </div>

    <!-- Položky -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-neutral-200">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('purchase_invoice.items') }}</h3>
      </div>
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-2 text-left font-medium">#</th>
              <th class="px-4 py-2 text-left font-medium">{{ t('purchase_invoice.items_table.description') }}</th>
              <th class="px-4 py-2 text-right font-medium">{{ t('purchase_invoice.items_table.quantity') }}</th>
              <th class="px-4 py-2 text-left font-medium">{{ t('purchase_invoice.items_table.unit') }}</th>
              <th class="px-4 py-2 text-right font-medium">{{ t('purchase_invoice.items_table.unit_price') }}</th>
              <th class="px-4 py-2 text-center font-medium">{{ t('purchase_invoice.items_table.vat_rate') }}</th>
              <th v-if="supplierIsVatPayer" class="px-4 py-2 text-left font-medium">{{ t('invoice.items_table.vat_classification') }}</th>
              <th class="px-4 py-2 text-right font-medium">{{ t('purchase_invoice.items_table.total') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(item, i) in invoice.items" :key="item.id ?? i">
              <td class="px-4 py-2.5 text-neutral-400 text-xs">{{ i + 1 }}</td>
              <td class="px-4 py-2.5 whitespace-pre-wrap">{{ item.description }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ item.quantity }}</td>
              <td class="px-4 py-2.5 text-neutral-600">{{ item.unit }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(item.unit_price_without_vat, invoice.currency) }}</td>
              <td class="px-4 py-2.5 text-center text-xs">
                <span v-if="item.vat_rate_snapshot !== undefined && item.vat_rate_snapshot !== null && item.vat_rate_snapshot > 0">
                  {{ item.vat_rate_snapshot }}%
                </span>
                <span v-else-if="item.vat_code === 'RC'" class="text-neutral-500">{{ t('purchase_invoice.reverse_charge') }}</span>
                <span v-else>0%</span>
              </td>
              <td v-if="supplierIsVatPayer" class="px-4 py-2.5 text-xs text-neutral-600">
                <span v-if="item.vat_classification" :title="item.vat_classification_label ?? ''">
                  {{ item.vat_classification }}
                </span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-4 py-2.5 text-right font-mono font-medium">
                {{ formatMoney(item.quantity * item.unit_price_without_vat * (1 + (item.vat_rate_snapshot ?? 0) / 100), invoice.currency) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Mobile: stack karet -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="(item, i) in invoice.items" :key="`m-${item.id ?? i}`" class="p-3 space-y-1.5">
          <div class="text-sm whitespace-pre-wrap text-neutral-900">{{ item.description }}</div>
          <div class="flex items-baseline justify-between text-xs text-neutral-500">
            <span>
              <span class="font-mono text-neutral-700">{{ item.quantity }}</span>
              <span class="ml-1">{{ item.unit }}</span>
              <template v-if="supplierIsVatPayer && item.vat_rate_snapshot">
                <span class="text-neutral-400 mx-1.5">·</span>
                <span>{{ item.vat_rate_snapshot }}%</span>
              </template>
            </span>
            <span class="font-mono">{{ formatMoney(item.unit_price_without_vat, invoice.currency) }}</span>
          </div>
          <div class="flex items-baseline justify-between pt-1 text-sm">
            <span class="text-xs text-neutral-500">{{ t('purchase_invoice.items_table.total') }}</span>
            <span class="font-mono font-semibold">{{ formatMoney(item.quantity * item.unit_price_without_vat * (1 + (item.vat_rate_snapshot ?? 0) / 100), invoice.currency) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Sumace -->
    <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.summary') }}</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <dl class="space-y-1 text-sm">
          <template v-if="supplierIsVatPayer">
            <div v-for="b in invoice.vat_breakdown" :key="b.rate" class="flex justify-between">
              <dt class="text-neutral-500">{{ t('purchase_invoice.totals.base') }} {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.base, invoice.currency) }}</dd>
            </div>
            <div v-for="b in invoice.vat_breakdown" :key="'v'+b.rate" class="flex justify-between">
              <dt class="text-neutral-500">{{ t('purchase_invoice.totals.vat') }} {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.vat, invoice.currency) }}</dd>
            </div>
          </template>
        </dl>
        <dl class="space-y-1 text-sm">
          <div v-if="supplierIsVatPayer" class="flex justify-between font-semibold">
            <dt>{{ t('purchase_invoice.totals.without_vat') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.total_without_vat, invoice.currency) }}</dd>
          </div>
          <div v-if="supplierIsVatPayer" class="flex justify-between font-semibold">
            <dt>{{ t('purchase_invoice.totals.vat_total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.total_vat, invoice.currency) }}</dd>
          </div>
          <div class="flex justify-between border-t border-neutral-300 pt-2 mt-2 text-lg font-semibold text-primary-700">
            <dt>{{ t('purchase_invoice.totals.total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.total_with_vat, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.advance_paid_amount > 0" class="flex justify-between text-sm text-neutral-600 pt-2">
            <dt>{{ t('purchase_invoice.totals.advance_paid') }}</dt>
            <dd class="font-mono">−{{ formatMoney(invoice.advance_paid_amount, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.advance_paid_amount > 0" class="flex justify-between text-base font-semibold">
            <dt>{{ t('purchase_invoice.totals.amount_to_pay') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.amount_to_pay || invoice.total_with_vat, invoice.currency) }}</dd>
          </div>
        </dl>
      </div>
    </div>
  </div>

  <!-- ══ Modal: Nastavit opakování ══════════════════════════════════════════ -->
  <Teleport to="body">
    <div
      v-if="showRecurringModal"
      class="fixed inset-0 z-50 flex items-center justify-center p-4"
      @click.self="showRecurringModal = false">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-neutral-900/40 backdrop-blur-sm" @click="showRecurringModal = false"></div>

      <!-- Panel -->
      <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl border border-neutral-200 z-10">
        <!-- Hlavička -->
        <div class="flex items-start justify-between px-6 py-5 border-b border-neutral-100">
          <div>
            <h2 class="text-base font-semibold text-neutral-900">{{ t('purchase_invoice.recurring_modal_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('purchase_invoice.recurring_modal_subtitle') }}</p>
          </div>
          <button @click="showRecurringModal = false" class="cursor-pointer text-neutral-400 hover:text-neutral-700 ml-4 mt-0.5">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <!-- Tělo formuláře -->
        <div class="px-6 py-5 space-y-4">
          <!-- Frekvence -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.frequency') }}</label>
            <select
              v-model="recurringForm.frequency"
              class="w-full border border-neutral-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-400">
              <option value="monthly">{{ t('recurring_purchase.frequency.monthly') }}</option>
              <option value="quarterly">{{ t('recurring_purchase.frequency.quarterly') }}</option>
              <option value="semi_annually">{{ t('recurring_purchase.frequency.semi_annually') }}</option>
              <option value="annually">{{ t('recurring_purchase.frequency.annually') }}</option>
            </select>
          </div>

          <!-- Den v měsíci / poslední den -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.day_of_month') }}</label>
            <div class="flex items-center gap-3">
              <input
                v-if="!recurringForm.end_of_month"
                type="number"
                v-model.number="recurringForm.day_of_month"
                min="1"
                max="28"
                :placeholder="t('recurring_purchase.day')"
                class="w-24 border border-neutral-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-400" />
              <label class="flex items-center gap-2 text-sm text-neutral-600 cursor-pointer select-none">
                <input
                  type="checkbox"
                  v-model="recurringForm.end_of_month"
                  class="rounded border-neutral-300 text-primary-600 focus:ring-primary-400" />
                {{ t('recurring_purchase.end_of_month') }}
              </label>
            </div>
          </div>

          <!-- Datum zahájení -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.anchor_date') }}</label>
            <input
              type="date"
              v-model="recurringForm.anchor_date"
              class="w-full border border-neutral-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-400" />
          </div>

          <!-- Datum ukončení (volitelné) -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">
              {{ t('recurring.end_date') }}
              <span class="font-normal text-neutral-400 ml-1">({{ t('recurring.end_date_hint') }})</span>
            </label>
            <input
              type="date"
              v-model="recurringForm.end_date"
              class="w-full border border-neutral-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary-400" />
          </div>
        </div>

        <!-- Footer: akce -->
        <div class="flex justify-end gap-2 px-6 py-4 border-t border-neutral-100">
          <button
            @click="showRecurringModal = false"
            class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button
            @click="saveRecurring"
            :disabled="busy === 'recurring-save'"
            class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md disabled:opacity-50 inline-flex items-center gap-1.5">
            <svg v-if="busy === 'recurring-save'" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg>
            {{ busy === 'recurring-save' ? '…' : t('common.save') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
