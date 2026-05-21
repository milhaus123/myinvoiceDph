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
  <div v-else-if="!invoice" class="text-center text-neutral-500 py-12">{{ t('purchase_invoice.not_found') }}</div>
  <div v-else class="max-w-5xl space-y-4">

    <RouterLink to="/purchase-invoices" class="text-sm text-neutral-600 hover:text-neutral-900">
      ← {{ t('purchase_invoice.back_to_list') }}
    </RouterLink>

    <!-- Hlavička: číslo + stav + akce -->
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 md:gap-4">
      <h1 class="text-2xl font-semibold flex items-center gap-3 flex-wrap min-w-0">
        <span class="font-mono">{{ invoice.invoice_number }}</span>
        <span
          class="text-xs px-2 py-0.5 rounded font-normal"
          :class="statusBadgeClass(invoice.status)"
        >{{ statusLabel(invoice.status) }}</span>
      </h1>
      <div class="flex flex-wrap gap-2 md:justify-end">
        <RouterLink
          v-if="canEdit()"
          :to="`/purchase-invoices/${invoice.id}/edit`"
          class="btn btn-secondary btn-sm"
        >{{ t('common.edit') }}</RouterLink>
        <button
          v-if="canTransition() && invoice.status === 'draft'"
          class="btn btn-secondary btn-sm"
          :disabled="busy === 'received'"
          @click="transitionTo('received')"
        >{{ busy === 'received' ? '…' : t('purchase_invoice.mark_received') }}</button>
        <button
          v-if="canTransition() && (invoice.status === 'received' || invoice.status === 'draft')"
          class="btn btn-secondary btn-sm"
          :disabled="busy === 'booked'"
          @click="transitionTo('booked')"
        >{{ busy === 'booked' ? '…' : t('purchase_invoice.mark_booked') }}</button>
        <button
          v-if="canTransition() && invoice.status !== 'paid'"
          class="btn btn-primary btn-sm"
          :disabled="busy === 'paid'"
          @click="transitionTo('paid')"
        >{{ busy === 'paid' ? '…' : t('purchase_invoice.mark_paid') }}</button>
        <button
          v-if="canTransition() && invoice.status !== 'cancelled'"
          class="btn btn-secondary btn-sm text-neutral-500"
          :disabled="busy === 'cancelled'"
          @click="transitionTo('cancelled')"
        >{{ busy === 'cancelled' ? '…' : t('purchase_invoice.cancel') }}</button>
        <button
          v-if="isAdmin"
          class="btn btn-danger btn-sm"
          @click="deleteInvoice"
        >{{ t('common.delete') }}</button>
      </div>
    </div>

    <!-- Info grid: Dodavatel / Data / Platba -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

      <!-- Dodavatel -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.supplier') }}</h3>
        <div class="text-sm space-y-0.5">
          <div class="font-medium">{{ invoice.supplier_name_snapshot }}</div>
          <div v-if="invoice.supplier_ic_snapshot" class="text-neutral-500">IČ: {{ invoice.supplier_ic_snapshot }}</div>
          <div v-if="invoice.supplier_dic_snapshot" class="text-neutral-500">DIČ: {{ invoice.supplier_dic_snapshot }}</div>
        </div>
      </div>

      <!-- Data -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.dates_section') }}</h3>
        <dl class="text-sm space-y-1.5">
          <div v-if="invoice.received_date" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.received_date') }}</dt>
            <dd>{{ formatDate(invoice.received_date) }}</dd>
          </div>
          <div v-if="invoice.issue_date" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.issue_date') }}</dt>
            <dd>{{ formatDate(invoice.issue_date) }}</dd>
          </div>
          <div v-if="invoice.tax_date" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.tax_date') }}</dt>
            <dd>{{ formatDate(invoice.tax_date) }}</dd>
          </div>
          <div
            v-if="invoice.due_date"
            class="flex justify-between"
            :class="{ 'text-danger-600 font-medium': isOverdue(invoice.due_date, invoice.status) }"
          >
            <dt class="text-neutral-500">{{ t('purchase_invoice.due_date') }}</dt>
            <dd>{{ formatDate(invoice.due_date) }}</dd>
          </div>
          <div v-if="invoice.booked_at" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.booked_at') }}</dt>
            <dd>{{ formatDate(invoice.booked_at) }}</dd>
          </div>
          <div v-if="invoice.paid_at" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.paid_at') }}</dt>
            <dd>{{ formatDate(invoice.paid_at) }}</dd>
          </div>
          <div v-if="invoice.varsymbol" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.varsymbol') }}</dt>
            <dd class="font-mono">{{ invoice.varsymbol }}</dd>
          </div>
        </dl>
      </div>

      <!-- Platba -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.summary') }}</h3>
        <dl class="text-sm space-y-1.5">
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('purchase_invoice.currency') }}</dt>
            <dd class="font-mono">{{ invoice.currency }}</dd>
          </div>
          <div class="flex justify-between border-t border-neutral-200 pt-2 mt-1 font-semibold">
            <dt>{{ t('purchase_invoice.totals.total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.totals.with_vat, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.totals.advance_paid_amount > 0" class="flex justify-between text-neutral-600">
            <dt>{{ t('purchase_invoice.totals.advance_paid') }}</dt>
            <dd class="font-mono">−{{ formatMoney(invoice.totals.advance_paid_amount, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.totals.advance_paid_amount > 0" class="flex justify-between font-semibold">
            <dt>{{ t('purchase_invoice.totals.amount_to_pay') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.totals.amount_to_pay, invoice.currency) }}</dd>
          </div>
        </dl>
      </div>
    </div>

    <!-- Poznámky -->
    <div
      v-if="invoice.note_above_items || invoice.note_below_items"
      class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm space-y-3 text-sm"
    >
      <div v-if="invoice.note_above_items">
        <div class="font-medium text-neutral-500 text-xs uppercase tracking-wide mb-1">{{ t('purchase_invoice.note_above') }}</div>
        <div class="whitespace-pre-wrap">{{ invoice.note_above_items }}</div>
      </div>
      <div v-if="invoice.note_below_items">
        <div class="font-medium text-neutral-500 text-xs uppercase tracking-wide mb-1">{{ t('purchase_invoice.note_below') }}</div>
        <div class="whitespace-pre-wrap">{{ invoice.note_below_items }}</div>
      </div>
    </div>

    <!-- Položky -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('purchase_invoice.items') }}</h3>
      </div>

      <!-- Desktop tabulka -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 border-b border-neutral-200 text-neutral-600">
            <tr>
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
            <tr v-for="(item, i) in invoice.items" :key="item.id ?? i" class="hover:bg-neutral-50">
              <td class="px-4 py-2.5 whitespace-pre-wrap">{{ item.description }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ item.quantity }}</td>
              <td class="px-4 py-2.5 text-neutral-600">{{ item.unit }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(item.unit_price_without_vat, invoice.currency) }}</td>
              <td class="px-4 py-2.5 text-center text-xs">{{ vatRateLabel(item) }}</td>
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

      <!-- Mobilní karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="(item, i) in invoice.items" :key="`m-${item.id ?? i}`" class="p-3 space-y-1.5">
          <div class="text-sm whitespace-pre-wrap text-neutral-900">{{ item.description }}</div>
          <div class="flex items-baseline justify-between text-xs text-neutral-500">
            <span>
              <span class="font-mono text-neutral-700">{{ item.quantity }}</span>
              <span v-if="item.unit" class="ml-1">{{ item.unit }}</span>
              <template v-if="supplierIsVatPayer && item.vat_rate_snapshot">
                <span class="text-neutral-400 mx-1.5">·</span>
                <span>{{ item.vat_rate_snapshot }}%</span>
              </template>
            </span>
            <span class="font-mono">{{ formatMoney(item.unit_price_without_vat, invoice.currency) }}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-neutral-500">{{ t('purchase_invoice.items_table.total') }}</span>
            <span class="font-mono font-semibold">
              {{ formatMoney(item.quantity * item.unit_price_without_vat * (1 + (item.vat_rate_snapshot ?? 0) / 100), invoice.currency) }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Sumace -->
    <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('purchase_invoice.summary') }}</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

        <!-- Levý sloupec: DPH rozpad -->
        <dl class="space-y-1 text-sm">
          <template v-if="supplierIsVatPayer">
            <div v-for="b in invoice.vat_breakdown" :key="b.rate" class="flex justify-between">
              <dt class="text-neutral-500">{{ t('purchase_invoice.totals.base') }} {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.base, invoice.currency) }}</dd>
            </div>
            <div v-for="b in invoice.vat_breakdown" :key="'v'+b.rate" v-show="b.vat > 0" class="flex justify-between">
              <dt class="text-neutral-500">{{ t('purchase_invoice.totals.vat') }} {{ formatPercent(b.rate) }}</dt>
              <dd class="font-mono">{{ formatMoney(b.vat, invoice.currency) }}</dd>
            </div>
          </template>
        </dl>

        <!-- Pravý sloupec: celkové součty -->
        <dl class="space-y-1 text-sm">
          <div v-if="supplierIsVatPayer" class="flex justify-between font-semibold">
            <dt>{{ t('purchase_invoice.totals.without_vat') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.totals.without_vat, invoice.currency) }}</dd>
          </div>
          <div v-if="supplierIsVatPayer" class="flex justify-between font-semibold">
            <dt>{{ t('purchase_invoice.totals.vat_total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.totals.vat, invoice.currency) }}</dd>
          </div>
          <div class="flex justify-between border-t border-neutral-300 pt-2 mt-2 text-lg font-semibold text-primary-700">
            <dt>{{ t('purchase_invoice.totals.total') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.totals.with_vat, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.totals.advance_paid_amount > 0" class="flex justify-between text-sm text-neutral-600 pt-2">
            <dt>{{ t('purchase_invoice.totals.advance_paid') }}</dt>
            <dd class="font-mono">−{{ formatMoney(invoice.totals.advance_paid_amount, invoice.currency) }}</dd>
          </div>
          <div v-if="invoice.totals.advance_paid_amount > 0" class="flex justify-between text-base font-semibold">
            <dt>{{ t('purchase_invoice.totals.amount_to_pay') }}</dt>
            <dd class="font-mono">{{ formatMoney(invoice.totals.amount_to_pay, invoice.currency) }}</dd>
          </div>
        </dl>
      </div>
    </div>

  </div>
</template>
