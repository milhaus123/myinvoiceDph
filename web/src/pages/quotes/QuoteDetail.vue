<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { quotesApi, type Quote, type QuoteStatus } from '@/api/quotes'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const quote = ref<Quote | null>(null)
const loading = ref(true)
const busy = ref(false)
const rejectOpen = ref(false)
const rejectReason = ref('')
const toInvoiceOpen = ref(false)
const toInvoiceForm = ref({ issue_date: new Date().toISOString().slice(0, 10), payment_due_days: 14 })

const quoteId = computed(() => Number(route.params.id))

const statusLabel = computed(() => {
  if (!quote.value) return ''
  const labels: Record<QuoteStatus, string> = {
    draft: t('quote.status_draft'),
    sent: t('quote.status_sent'),
    approved: t('quote.status_approved'),
    rejected: t('quote.status_rejected'),
    converted: t('quote.status_converted'),
  }
  return labels[quote.value.quote_status] ?? quote.value.quote_status
})

const statusClass = computed(() => {
  if (!quote.value) return ''
  const map: Record<QuoteStatus, string> = {
    draft: 'bg-neutral-100 text-neutral-700',
    sent: 'bg-blue-100 text-blue-700',
    approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
    converted: 'bg-purple-100 text-purple-700',
  }
  return map[quote.value.quote_status] ?? 'bg-neutral-100'
})

const canTransition = computed(() => {
  const s = quote.value?.quote_status
  if (s === 'draft') return ['sent']
  if (s === 'sent') return ['approved', 'rejected']
  if (s === 'approved') return ['rejected']
  if (s === 'rejected') return ['draft']
  return []
})

const canConvert = computed(() => quote.value?.quote_status === 'approved' && !quote.value.quote_converted_to_invoice_id)

async function load() {
  loading.value = true
  try {
    quote.value = await quotesApi.get(quoteId.value)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.load_failed'))
    router.push('/quotes')
  } finally {
    loading.value = false
  }
}

async function transitionTo(status: QuoteStatus) {
  busy.value = true
  try {
    await quotesApi.transition(quoteId.value, status)
    await load()
    toast.success(t('quote.status_changed'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.transition_error'))
  } finally {
    busy.value = false
  }
}

async function sendQuote() {
  await transitionTo('sent')
}

async function approveQuote() {
  await transitionTo('approved')
}

async function rejectQuote() {
  busy.value = true
  try {
    await quotesApi.transition(quoteId.value, 'rejected', rejectReason.value)
    rejectOpen.value = false
    rejectReason.value = ''
    await load()
    toast.success(t('quote.status_changed'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.transition_error'))
  } finally {
    busy.value = false
  }
}

async function convertToInvoice() {
  busy.value = true
  try {
    const r = await quotesApi.toInvoice(quoteId.value, {
      issue_date: toInvoiceForm.value.issue_date,
      payment_due_days: toInvoiceForm.value.payment_due_days,
    })
    toast.success(t('quote.converted_to_invoice'))
    router.push(`/invoices/${r.invoice_id}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.convert_error'))
  } finally {
    busy.value = false
    toInvoiceOpen.value = false
  }
}

async function deleteQuote() {
  if (!confirm(t('quote.delete_confirm'))) return
  busy.value = true
  try {
    await quotesApi.remove(quoteId.value)
    toast.success(t('quote.deleted'))
    router.push('/quotes')
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('quote.delete_error'))
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl mx-auto">
    <div v-if="loading" class="text-center py-12 text-neutral-500">…</div>

    <div v-else-if="quote">

      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
          <RouterLink to="/quotes" class="text-neutral-400 hover:text-neutral-600">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
          </RouterLink>
          <div>
            <div class="flex items-center gap-2">
              <h1 class="text-2xl font-semibold">{{ t('quote.title') }}</h1>
              <span :class="['px-2 py-0.5 rounded text-sm font-medium', statusClass]">{{ statusLabel }}</span>
            </div>
            <p class="text-sm text-neutral-500 mt-0.5">
              {{ quote.client_company_name }}
              <span v-if="quote.varsymbol" class="font-mono text-neutral-400">· {{ quote.varsymbol }}</span>
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <!-- Akce podle statusu -->
          <template v-if="quote.quote_status === 'draft'">
            <button @click="sendQuote" :disabled="busy"
              class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-blue-300 text-blue-700 hover:bg-blue-50 disabled:opacity-50 text-sm font-medium rounded-md">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
              {{ t('quote.send') }}
            </button>
          </template>

          <template v-if="quote.quote_status === 'sent'">
            <button @click="approveQuote" :disabled="busy"
              class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-green-600 hover:bg-green-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              {{ t('quote.approve') }}
            </button>
            <button @click="rejectOpen = true" :disabled="busy"
              class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-red-500 hover:bg-red-600 disabled:opacity-50 text-white text-sm font-medium rounded-md">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
              {{ t('quote.reject') }}
            </button>
          </template>

          <template v-if="quote.quote_status === 'approved'">
            <button v-if="canConvert" @click="toInvoiceOpen = true" :disabled="busy"
              class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
              {{ t('quote.to_invoice') }}
            </button>
            <RouterLink v-if="quote.quote_converted_to_invoice_id" :to="`/invoices/${quote.quote_converted_to_invoice_id}`"
              class="inline-flex items-center gap-1.5 h-9 px-3 border border-purple-300 text-purple-700 hover:bg-purple-50 text-sm font-medium rounded-md">
              {{ t('quote.view_invoice') }} →
            </RouterLink>
          </template>

          <RouterLink :to="`/quotes/${quote.id}/edit`"
            v-if="quote.quote_status === 'draft'"
            class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-sm font-medium rounded-md">
            {{ t('common.edit') }}
          </RouterLink>

          <button v-if="quote.quote_status === 'draft'" @click="deleteQuote" :disabled="busy"
            class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-red-200 text-red-500 hover:bg-red-50 disabled:opacity-50 text-sm font-medium rounded-md">
            {{ t('common.delete') }}
          </button>
        </div>
      </div>

      <!-- Info karta -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5 mb-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('quote.issue_date') }}</div>
            <div class="font-medium">{{ formatDate(quote.issue_date) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('quote.valid_until') }}</div>
            <div :class="quote.quote_valid_until && new Date(quote.quote_valid_until) < new Date() ? 'text-red-600 font-medium' : ''">
              {{ quote.quote_valid_until ? formatDate(quote.quote_valid_until) : '—' }}
            </div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('quote.currency') }}</div>
            <div class="font-medium">{{ quote.currency }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('quote.language') }}</div>
            <div class="font-medium">{{ quote.language === 'cs' ? 'Česky' : 'English' }}</div>
          </div>
          <div v-if="quote.quote_sent_at">
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('quote.sent_at') }}</div>
            <div class="font-medium">{{ formatDate(quote.quote_sent_at) }}</div>
          </div>
          <div v-if="quote.quote_approved_at">
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('quote.approved_at') }}</div>
            <div class="font-medium text-green-700">{{ formatDate(quote.quote_approved_at) }}</div>
          </div>
          <div v-if="quote.quote_rejected_at">
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('quote.rejected_at') }}</div>
            <div class="font-medium text-red-700">{{ formatDate(quote.quote_rejected_at) }}</div>
          </div>
          <div v-if="quote.quote_rejection_reason">
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('quote.rejection_reason') }}</div>
            <div class="font-medium text-red-700">{{ quote.quote_rejection_reason }}</div>
          </div>
        </div>
      </div>

      <!-- Poznámky -->
      <div v-if="quote.note_above_items || quote.note_below_items"
        class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5 mb-6">
        <div v-if="quote.note_above_items" class="mb-3 text-sm text-neutral-700 whitespace-pre-line">{{ quote.note_above_items }}</div>
        <div v-if="quote.note_below_items" class="text-sm text-neutral-500 italic whitespace-pre-line">{{ quote.note_below_items }}</div>
      </div>

      <!-- Položky -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase">
            <tr>
              <th class="px-4 py-2.5 text-left font-medium w-8">#</th>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('quote.item_description') }}</th>
              <th class="px-4 py-2.5 text-center font-medium w-20">{{ t('quote.item_qty') }}</th>
              <th class="px-4 py-2.5 text-center font-medium w-16">{{ t('quote.item_unit') }}</th>
              <th class="px-4 py-2.5 text-right font-medium w-28">{{ t('quote.item_price') }}</th>
              <th class="px-4 py-2.5 text-center font-medium w-20">{{ t('quote.item_vat') }}</th>
              <th class="px-4 py-2.5 text-right font-medium w-28">{{ t('quote.item_total') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(item, i) in quote.items" :key="item.id ?? i">
              <td class="px-4 py-2.5 text-center text-neutral-400">{{ i + 1 }}</td>
              <td class="px-4 py-2.5">{{ item.description }}</td>
              <td class="px-4 py-2.5 text-center">{{ item.quantity }}</td>
              <td class="px-4 py-2.5 text-center">{{ item.unit }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(item.unit_price_without_vat) }}</td>
              <td class="px-4 py-2.5 text-center">{{ item.vat_rate_snapshot ?? '?' }}%</td>
              <td class="px-4 py-2.5 text-right font-mono font-medium">{{ formatMoney(item.total_with_vat) }}</td>
            </tr>
          </tbody>
        </table>

        <!-- Souhrn -->
        <div class="border-t border-neutral-200 px-6 py-4 flex justify-end">
          <div class="w-64 space-y-1 text-sm">
            <div class="flex justify-between">
              <span class="text-neutral-500">{{ t('quote.total_without_vat') }}</span>
              <span class="font-mono">{{ formatMoney(quote.total_without_vat) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-neutral-500">{{ t('quote.total_vat') }}</span>
              <span class="font-mono">{{ formatMoney(quote.total_vat) }}</span>
            </div>
            <div class="flex justify-between font-semibold text-base border-t border-neutral-200 pt-1">
              <span>{{ t('quote.total_with_vat') }}</span>
              <span class="font-mono">{{ formatMoney(quote.total_with_vat) }}</span>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- Reject modal -->
    <div v-if="rejectOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div class="bg-white rounded-lg shadow-xl p-6 w-96">
        <h3 class="text-lg font-semibold mb-3">{{ t('quote.reject') }}</h3>
        <textarea v-model="rejectReason" rows="3"
          class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm resize-none mb-4 focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
          :placeholder="t('quote.rejection_reason_placeholder')" />
        <div class="flex justify-end gap-2">
          <button @click="rejectOpen = false" class="px-4 py-2 text-sm text-neutral-600 hover:bg-neutral-100 rounded-md cursor-pointer">
            {{ t('common.cancel') }}
          </button>
          <button @click="rejectQuote" :disabled="busy"
            class="px-4 py-2 bg-red-500 hover:bg-red-600 disabled:opacity-50 text-white text-sm font-medium rounded-md cursor-pointer">
            {{ t('quote.reject') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Convert to invoice modal -->
    <div v-if="toInvoiceOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div class="bg-white rounded-lg shadow-xl p-6 w-96">
        <h3 class="text-lg font-semibold mb-3">{{ t('quote.to_invoice') }}</h3>
        <div class="space-y-3 mb-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('quote.invoice_issue_date') }}</label>
            <input v-model="toInvoiceForm.issue_date" type="date"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('quote.payment_due_days') }}</label>
            <input v-model.number="toInvoiceForm.payment_due_days" type="number" min="0" max="365"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
          </div>
        </div>
        <div class="flex justify-end gap-2">
          <button @click="toInvoiceOpen = false" class="px-4 py-2 text-sm text-neutral-600 hover:bg-neutral-100 rounded-md cursor-pointer">
            {{ t('common.cancel') }}
          </button>
          <button @click="convertToInvoice" :disabled="busy"
            class="px-4 py-2 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md cursor-pointer">
            {{ t('quote.to_invoice') }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>
