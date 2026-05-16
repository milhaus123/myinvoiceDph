<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { quotesApi, type QuoteListItem, type QuoteStatus } from '@/api/quotes'
import { clientsApi, type Client } from '@/api/clients'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()

useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/quotes/new') })

const router = useRouter()
const route = useRoute()

const items = ref<QuoteListItem[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const statusFilter = ref<QuoteStatus | ''>('')
const clientFilter = ref<number | ''>('')
const yearFilter = ref<number | ''>(new Date().getFullYear())
const clients = ref<Client[]>([])

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function statusLabel(s: QuoteStatus): string {
  const map: Record<QuoteStatus, string> = {
    draft: t('quote.status_draft'),
    sent: t('quote.status_sent'),
    approved: t('quote.status_approved'),
    rejected: t('quote.status_rejected'),
    converted: t('quote.status_converted'),
  }
  return map[s] ?? s
}

function statusBadgeClass(s: QuoteStatus): string {
  const map: Record<QuoteStatus, string> = {
    draft: 'bg-neutral-100 text-neutral-600',
    sent: 'bg-blue-50 text-blue-600',
    approved: 'bg-success-50 text-success-600',
    rejected: 'bg-danger-50 text-danger-600',
    converted: 'bg-primary-50 text-primary-600',
  }
  return map[s] ?? 'bg-neutral-100 text-neutral-600'
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
    const result = await quotesApi.list({
      status: statusFilter.value || undefined,
      client_id: clientFilter.value === '' ? undefined : Number(clientFilter.value),
      year: yearFilter.value === '' ? undefined : Number(yearFilter.value),
      search: search.value || undefined,
      page: page.value,
    })
    if (reset) {
      items.value = result.items
    } else {
      items.value.push(...result.items)
    }
    total.value = result.total
    pages.value = result.pages ?? 1
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.load_failed'))
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

onMounted(async () => {
  clientsApi.list({ archived: false, per_page: 200 }).then(r => { clients.value = r.data }).catch(() => {})
  await load(true)
})

watch([statusFilter, clientFilter, yearFilter], () => load(true))
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => load(true), 300)
})

const yearOptions = computed(() => {
  const y = new Date().getFullYear()
  return [y, y - 1, y - 2, y - 3, y - 4]
})

function openQuote(id: number) {
  router.push(`/quotes/${id}`)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('quote.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('quote.subtitle') }}</p>
      </div>
      <RouterLink
        to="/quotes/new"
        class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        {{ t('quote.new') }}
      </RouterLink>
    </div>

    <!-- Filtry -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
      <div class="flex flex-wrap items-center gap-2">
        <input
          v-model="search"
          type="search"
          :placeholder="t('invoice.search_placeholder')"
          class="flex-1 min-w-48 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">{{ t('invoice.all_statuses') }}</option>
          <option value="draft">{{ t('quote.status_draft') }}</option>
          <option value="sent">{{ t('quote.status_sent') }}</option>
          <option value="approved">{{ t('quote.status_approved') }}</option>
          <option value="rejected">{{ t('quote.status_rejected') }}</option>
          <option value="converted">{{ t('quote.status_converted') }}</option>
        </select>
        <select v-model="clientFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">{{ t('project.all_clients') }}</option>
          <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.company_name }}</option>
        </select>
        <select v-model="yearFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">{{ t('invoice.all_years') }}</option>
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="8" :cols="6" />
    </div>

    <div v-else-if="!items.length" class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState :title="t('invoice.no_data')" :cta="t('quote.new')" to="/quotes/new" />
    </div>

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('invoice.summary_count', { n: total, m: 1 }) }}</span>
      </div>

      <!-- Desktop: tabulka -->
      <div class="hidden md:block bg-white border border-neutral-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="text-left px-4 py-2 font-medium">Variabilní symbol</th>
                <th class="text-left px-4 py-2 font-medium">Klient</th>
                <th class="text-center px-4 py-2 font-medium">Vystaveno</th>
                <th class="text-center px-4 py-2 font-medium">Platnost do</th>
                <th class="text-right px-4 py-2 font-medium">Částka</th>
                <th class="text-center px-4 py-2 font-medium">Stav</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr
                v-for="q in items"
                :key="q.id"
                @click="openQuote(q.id)"
                class="cursor-pointer hover:bg-neutral-50 transition"
              >
                <td class="px-4 py-2.5 font-mono text-xs">
                  <span v-if="q.varsymbol">{{ q.varsymbol }}</span>
                  <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: q.id }) }}</span>
                </td>
                <td class="px-4 py-2.5 font-medium text-neutral-900">{{ q.client_company_name }}</td>
                <td class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  {{ formatDate(q.issue_date) }}
                </td>
                <td class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  {{ q.quote_valid_until ? formatDate(q.quote_valid_until) : '—' }}
                </td>
                <td class="px-4 py-2.5 text-right font-mono">
                  {{ formatMoney(q.total_with_vat, q.currency) }}
                </td>
                <td class="px-4 py-2.5 text-center">
                  <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(q.quote_status)">
                    {{ statusLabel(q.quote_status) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden bg-white border border-neutral-200 rounded-lg divide-y divide-neutral-100 overflow-hidden">
        <div
          v-for="q in items"
          :key="`m-${q.id}`"
          @click="openQuote(q.id)"
          class="cursor-pointer hover:bg-neutral-50 transition px-3 py-3"
        >
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 truncate">{{ q.client_company_name }}</div>
            <div class="font-mono text-sm font-semibold whitespace-nowrap">
              {{ formatMoney(q.total_with_vat, q.currency) }}
            </div>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
            <span class="font-mono">{{ q.varsymbol || t('invoice.draft_id_short', { id: q.id }) }}</span>
            <span>{{ formatDate(q.issue_date) }}</span>
          </div>
          <div class="flex items-center justify-end mt-2">
            <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(q.quote_status)">
              {{ statusLabel(q.quote_status) }}
            </span>
          </div>
        </div>
      </div>

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
