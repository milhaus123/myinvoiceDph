<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import {
  recurringPurchaseInvoicesApi,
  type RecurringTemplate,
  type NextRun,
} from '@/api/recurringPurchaseInvoices'
import { formatMoney, formatDate, formatPercent } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'

const { t } = useI18n()
const toast = useToast()

const route = useRoute()
const router = useRouter()

const template = ref<RecurringTemplate | null>(null)
const nextRuns = ref<NextRun[]>([])
const generatedInvoices = ref<Array<{
  month: string
  count: number
  invoices: Array<{
    id: number
    invoice_number: string
    issue_date: string
    due_date: string
    total_with_vat: number
    currency: string
    status: string
  }>
}>>([])
const loading = ref(true)
const busy = ref<string | null>(null)
const runNowDate = ref<string>('')

function frequencyLabel(freq: string): string {
  const labels: Record<string, string> = {
    monthly: t('recurring_purchase.frequency.monthly'),
    quarterly: t('recurring_purchase.frequency.quarterly'),
    semi_annually: t('recurring_purchase.frequency.semi_annually'),
    annually: t('recurring_purchase.frequency.annually'),
  }
  return labels[freq] ?? freq
}

function statusBadgeClass(status: string): string {
  const classes: Record<string, string> = {
    active:  'bg-success-50 text-success-600',
    paused:  'bg-warning-50 text-warning-600',
    expired: 'bg-neutral-100 text-neutral-400',
  }
  return classes[status] ?? 'bg-neutral-100 text-neutral-600'
}

function invoiceStatusBadgeClass(status: string): string {
  const classes: Record<string, string> = {
    draft:     'bg-neutral-100 text-neutral-600',
    received:  'bg-primary-100 text-primary-700',
    booked:    'bg-accent-100 text-accent-600',
    paid:      'bg-success-50 text-success-600',
    cancelled: 'bg-neutral-100 text-neutral-400',
  }
  return classes[status] ?? 'bg-neutral-100 text-neutral-600'
}

function isDue(nextRunDate: string, status: string): boolean {
  if (status !== 'active') return false
  return new Date(nextRunDate) <= new Date()
}

async function load() {
  loading.value = true
  try {
    const id = Number(route.params.id)
    const [tpl, runs, invoices] = await Promise.all([
      recurringPurchaseInvoicesApi.get(id),
      recurringPurchaseInvoicesApi.nextRuns(),
      recurringPurchaseInvoicesApi.generatedInvoices(id),
    ])
    template.value = tpl
    nextRuns.value = runs.data.filter(r => r.template_id === id)
    generatedInvoices.value = invoices.data
  } finally {
    loading.value = false
  }
}

async function pauseTemplate() {
  if (!template.value) return
  busy.value = 'pause'
  try {
    template.value = await recurringPurchaseInvoicesApi.pause(template.value.id)
    toast.success(t('recurring_purchase.paused'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.save_failed'))
  } finally {
    busy.value = null
  }
}

async function resumeTemplate() {
  if (!template.value) return
  busy.value = 'resume'
  try {
    template.value = await recurringPurchaseInvoicesApi.resume(template.value.id)
    toast.success(t('recurring_purchase.resumed'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.save_failed'))
  } finally {
    busy.value = null
  }
}

async function runNow() {
  if (!template.value) return
  busy.value = 'run-now'
  try {
    const result = await recurringPurchaseInvoicesApi.runNow(
      template.value.id,
      runNowDate.value || undefined
    )
    toast.success(t('recurring_purchase.run_now_success', { number: result.invoice_number }))
    runNowDate.value = ''
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('recurring_purchase.run_now_failed'))
  } finally {
    busy.value = null
  }
}

async function deleteTemplate() {
  if (!template.value) return
  if (!confirm(t('recurring_purchase.delete_confirm'))) return
  try {
    await recurringPurchaseInvoicesApi.delete(template.value.id)
    router.push('/recurring-purchase-invoices')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.delete_failed'))
  }
}

onMounted(() => load())
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else-if="!template" class="text-center text-neutral-500 py-12">
    {{ t('recurring_purchase.not_found') }}
  </div>

  <div v-else>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <div>
        <RouterLink to="/recurring-purchase-invoices" class="text-sm text-neutral-600 hover:text-neutral-900">
          {{ t('recurring_purchase.back_to_list') }}
        </RouterLink>
        <h1 class="text-2xl font-semibold mt-1">
          {{ template.name }}
          <span class="ml-2 text-sm font-normal">
            <span class="px-2 py-0.5 rounded" :class="statusBadgeClass(template.status)">
              {{ t(`recurring_purchase.status.${template.status}`) }}
            </span>
          </span>
        </h1>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <button
          v-if="template.status === 'active'"
          @click="pauseTemplate"
          :disabled="busy !== null"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-warning-500 text-warning-600 hover:bg-warning-50 disabled:opacity-50 text-sm font-medium rounded-md">
          {{ busy === 'pause' ? '…' : t('recurring_purchase.pause') }}
        </button>
        <button
          v-if="template.status === 'paused'"
          @click="resumeTemplate"
          :disabled="busy !== null"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-success-600 hover:bg-success-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          {{ busy === 'resume' ? '…' : t('recurring_purchase.resume') }}
        </button>
        <button
          v-if="template.status === 'active'"
          @click="runNow"
          :disabled="busy !== null"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          {{ busy === 'run-now' ? '…' : t('recurring_purchase.run_now') }}
        </button>
        <RouterLink
          :to="`/recurring-purchase-invoices/${template.id}/edit`"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-primary-500 text-primary-700 hover:bg-primary-50 text-sm font-medium rounded-md">
          {{ t('common.edit') }}
        </RouterLink>
        <button
          @click="deleteTemplate"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 text-danger-500 hover:text-danger-600 text-sm">
          {{ t('common.delete') }}
        </button>
      </div>
    </div>

    <!-- Info grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
      <!-- Template info -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring_purchase.template_info') }}</h3>
        <dl class="space-y-1.5 text-sm">
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.supplier') }}</dt>
            <dd class="font-medium">{{ template.supplier_company_name }}</dd>
          </div>
          <div v-if="template.project_name" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.project') }}</dt>
            <dd class="font-medium">{{ template.project_name }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.frequency') }}</dt>
            <dd class="font-medium">{{ frequencyLabel(template.frequency) }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.day') }}</dt>
            <dd class="font-medium">
              <span v-if="template.end_of_month">{{ t('recurring_purchase.end_of_month') }}</span>
              <span v-else-if="template.day_of_month">{{ t('recurring_purchase.day_n', { n: template.day_of_month }) }}</span>
              <span v-else>—</span>
            </dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.anchor_date') }}</dt>
            <dd class="font-medium">{{ formatDate(template.anchor_date) }}</dd>
          </div>
          <div v-if="template.end_date" class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.end_date') }}</dt>
            <dd class="font-medium">{{ formatDate(template.end_date) }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.next_run') }}</dt>
            <dd class="font-medium" :class="isDue(template.next_run_date, template.status) ? 'text-danger-500 font-semibold' : ''">
              {{ formatDate(template.next_run_date) }}
            </dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.payment_due_days') }}</dt>
            <dd class="font-medium">{{ template.payment_due_days }} {{ t('recurring_purchase.days') }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.currency') }}</dt>
            <dd class="font-medium">{{ template.currency }}</dd>
          </div>
        </dl>
      </div>

      <!-- Settings -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring_purchase.settings') }}</h3>
        <dl class="space-y-1.5 text-sm">
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.payment_method') }}</dt>
            <dd class="font-medium">{{ t(`recurring_purchase.payment_method.${template.payment_method}`) }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.language') }}</dt>
            <dd class="font-medium">{{ template.language === 'cs' ? 'Čeština' : 'English' }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.reverse_charge') }}</dt>
            <dd class="font-medium">{{ template.reverse_charge ? '✓' : '—' }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.auto_issue') }}</dt>
            <dd class="font-medium">{{ template.auto_issue ? '✓' : '—' }}</dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-neutral-500">{{ t('recurring_purchase.increment_month') }}</dt>
            <dd class="font-medium">{{ template.increment_month_in_descriptions ? '✓' : '—' }}</dd>
          </div>
        </dl>
      </div>

      <!-- Upcoming runs -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring_purchase.upcoming_runs') }}</h3>
        <div v-if="nextRuns.length === 0" class="text-sm text-neutral-500">{{ t('recurring_purchase.no_upcoming_runs') }}</div>
        <ul v-else class="space-y-1.5 text-sm">
          <li v-for="run in nextRuns.slice(0, 5)" :key="run.next_run_date"
            class="flex justify-between items-center py-1 border-b border-neutral-100 last:border-0">
            <span class="text-neutral-600">{{ formatDate(run.next_run_date) }}</span>
            <span class="text-xs text-neutral-500">{{ frequencyLabel(run.frequency) }}</span>
          </li>
        </ul>
      </div>
    </div>

    <!-- Items preview -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
      <div class="px-5 py-3 border-b border-neutral-200">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring_purchase.items') }}</h3>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-2 text-left font-medium">#</th>
              <th class="px-4 py-2 text-left font-medium">{{ t('recurring_purchase.item_description') }}</th>
              <th class="px-4 py-2 text-right font-medium">{{ t('recurring_purchase.item_qty') }}</th>
              <th class="px-4 py-2 text-left font-medium">{{ t('recurring_purchase.item_unit') }}</th>
              <th class="px-4 py-2 text-right font-medium">{{ t('recurring_purchase.item_unit_price') }}</th>
              <th class="px-4 py-2 text-center font-medium">{{ t('recurring_purchase.item_vat') }}</th>
              <th class="px-4 py-2 text-right font-medium">{{ t('recurring_purchase.item_total') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(item, i) in template.items" :key="item.id ?? i">
              <td class="px-4 py-2.5 text-neutral-400 text-xs">{{ i + 1 }}</td>
              <td class="px-4 py-2.5 font-medium text-neutral-900">{{ item.description }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ item.quantity }}</td>
              <td class="px-4 py-2.5">{{ item.unit }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(item.unit_price_without_vat, template.currency) }}</td>
              <td class="px-4 py-2.5 text-center text-xs">
                <span v-if="item.vat_rate_percent !== undefined && item.vat_rate_percent !== null && item.vat_rate_percent > 0">
                  {{ item.vat_rate_percent }}%
                </span>
                <span v-else-if="item.vat_code === 'RC'" class="text-neutral-500">{{ t('recurring_purchase.reverse_charge') }}</span>
                <span v-else>0%</span>
              </td>
              <td class="px-4 py-2.5 text-right font-mono">
                {{ formatMoney(item.quantity * item.unit_price_without_vat * (1 + (item.vat_rate_percent ?? 0) / 100), template.currency) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Generated invoices -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="px-5 py-3 border-b border-neutral-200">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring_purchase.generated_invoices') }}</h3>
      </div>
      <div v-if="generatedInvoices.length === 0" class="px-5 py-8 text-center text-neutral-500 text-sm">
        {{ t('recurring_purchase.no_generated_invoices') }}
      </div>
      <div v-else>
        <section v-for="group in generatedInvoices" :key="group.month" class="border-b border-neutral-100 last:border-0">
          <header class="bg-neutral-50 px-5 py-2 flex items-center justify-between">
            <span class="text-sm font-semibold text-neutral-700">{{ group.month }}</span>
            <span class="text-xs text-neutral-500">{{ group.count }} {{ group.count === 1 ? 'faktura' : 'faktur' }}</span>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <tbody class="divide-y divide-neutral-100">
                <tr
                  v-for="inv in group.invoices"
                  :key="inv.id"
                  @click="router.push(`/purchase-invoices/${inv.id}`)"
                  class="cursor-pointer hover:bg-neutral-50 transition">
                  <td class="px-5 py-2.5 font-mono text-xs">{{ inv.invoice_number }}</td>
                  <td class="px-5 py-2.5 text-center text-xs text-neutral-600">{{ formatDate(inv.issue_date) }}</td>
                  <td class="px-5 py-2.5 text-center text-xs text-neutral-600">{{ formatDate(inv.due_date) }}</td>
                  <td class="px-5 py-2.5 text-right font-mono">{{ formatMoney(inv.total_with_vat, inv.currency) }}</td>
                  <td class="px-5 py-2.5 text-center">
                    <span class="text-xs px-2 py-0.5 rounded" :class="invoiceStatusBadgeClass(inv.status)">
                      {{ t(`purchase_invoice.status.${inv.status}`) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>
