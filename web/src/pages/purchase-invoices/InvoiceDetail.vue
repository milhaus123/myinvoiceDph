<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  purchaseInvoicesApi,
  type PurchaseInvoice,
  type PurchaseInvoiceStatus,
} from '@/api/purchaseInvoices'
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

  <div v-else>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <div>
        <RouterLink to="/purchase-invoices" class="text-sm text-neutral-600 hover:text-neutral-900">
          {{ t('purchase_invoice.back_to_list') }}
        </RouterLink>
        <h1 class="text-2xl font-semibold mt-1">
          {{ t('purchase_invoice.detail_title', { number: invoice.invoice_number }) }}
          <span class="ml-2 text-sm font-normal">
            <span class="px-2 py-0.5 rounded" :class="statusBadgeClass(invoice.status)">
              {{ statusLabel(invoice.status) }}
            </span>
          </span>
        </h1>
      </div>
      <div class="flex items-center gap-2">
        <button v-if="canEdit()"
          @click="router.push(`/purchase-invoices/${invoice!.id}/edit`)"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-primary-500 text-primary-700 hover:bg-primary-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
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
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-accent-600 hover:bg-accent-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          {{ busy === 'booked' ? '…' : t('purchase_invoice.mark_booked') }}
        </button>
        <button v-if="['received', 'booked'].includes(invoice.status)"
          @click="transitionTo('paid')"
          :disabled="busy !== null"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-success-600 hover:bg-success-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          {{ busy === 'paid' ? '…' : t('purchase_invoice.mark_paid') }}
        </button>
        <button v-if="canTransition() && invoice.status !== 'cancelled'"
          @click="transitionTo('cancelled')"
          :disabled="busy !== null"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-danger-500 text-danger-500 hover:bg-danger-50 disabled:opacity-50 text-sm font-medium rounded-md">
          {{ busy === 'cancelled' ? '…' : t('purchase_invoice.cancel') }}
        </button>
        <button v-if="invoice.status === 'draft'"
          @click="deleteInvoice"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-danger-500 hover:text-danger-600 text-sm">
          {{ t('common.delete') }}
        </button>
      </div>
    </div>

    <!-- Info grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
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
        <dl class="space-y-1 text-sm">
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

      <!-- Částka -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.summary') }}</h3>
        <dl class="space-y-1.5 text-sm">
          <template v-if="supplierIsVatPayer">
            <div v-for="b in invoice.vat_breakdown" :key="b.rate" class="flex justify-between text-neutral-600">
              <dt>{{ t('purchase_invoice.totals.base') }} {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.base, invoice.currency) }}</dd>
            </div>
            <div v-for="b in invoice.vat_breakdown" :key="'v'+b.rate" class="flex justify-between text-neutral-600">
              <dt>{{ t('purchase_invoice.totals.vat') }} {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.vat, invoice.currency) }}</dd>
            </div>
            <div class="flex justify-between border-t border-neutral-200 pt-2 mt-2 font-semibold">
              <dt>{{ t('purchase_invoice.totals.without_vat') }}</dt>
              <dd class="font-mono">{{ formatMoney(invoice.total_without_vat, invoice.currency) }}</dd>
            </div>
            <div class="flex justify-between font-semibold">
              <dt>{{ t('purchase_invoice.totals.vat_total') }}</dt>
              <dd class="font-mono">{{ formatMoney(invoice.total_vat, invoice.currency) }}</dd>
            </div>
          </template>
          <div class="flex justify-between border-t border-neutral-200 pt-2 mt-2 text-lg font-semibold text-primary-700">
            <dt>{{ t('purchase_invoice.totals.total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.total_with_vat, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.advance_paid_amount > 0" class="flex justify-between text-sm text-neutral-600 pt-1">
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

    <!-- Notes -->
    <div v-if="invoice.note_above_items || invoice.note_below_items" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm mb-4">
      <div v-if="invoice.note_above_items" class="text-sm text-neutral-700 mb-3">
        <div class="font-medium text-neutral-500 text-xs uppercase tracking-wide mb-1">{{ t('purchase_invoice.note_above') }}</div>
        <p>{{ invoice.note_above_items }}</p>
      </div>
      <div v-if="invoice.note_below_items" class="text-sm text-neutral-700">
        <div class="font-medium text-neutral-500 text-xs uppercase tracking-wide mb-1">{{ t('purchase_invoice.note_below') }}</div>
        <p>{{ invoice.note_below_items }}</p>
      </div>
    </div>

    <!-- Items -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
      <div class="px-5 py-3 border-b border-neutral-200">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('purchase_invoice.items') }}</h3>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-2 text-left font-medium">#</th>
              <th class="px-4 py-2 text-left font-medium">{{ t('purchase_invoice.items_table.description') }}</th>
              <th class="px-4 py-2 text-right font-medium">{{ t('purchase_invoice.items_table.qty') }}</th>
              <th class="px-4 py-2 text-left font-medium">{{ t('purchase_invoice.items_table.unit') }}</th>
              <th class="px-4 py-2 text-right font-medium">{{ t('purchase_invoice.items_table.unit_price') }}</th>
              <th class="px-4 py-2 text-center font-medium">{{ t('purchase_invoice.totals.vat') }}</th>
              <th v-if="supplierIsVatPayer" class="px-4 py-2 text-left font-medium">{{ t('invoice.items_table.vat_classification') }}</th>
              <th class="px-4 py-2 text-right font-medium">{{ t('purchase_invoice.totals.total') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(item, i) in invoice.items" :key="item.id ?? i">
              <td class="px-4 py-2.5 text-neutral-400 text-xs">{{ i + 1 }}</td>
              <td class="px-4 py-2.5 font-medium text-neutral-900">{{ item.description }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ item.quantity }}</td>
              <td class="px-4 py-2.5">{{ item.unit }}</td>
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
              <td class="px-4 py-2.5 text-right font-mono">
                {{ formatMoney(item.quantity * item.unit_price_without_vat * (1 + (item.vat_rate_snapshot ?? 0) / 100), invoice.currency) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
