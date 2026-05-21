<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { quotesApi, type Quote, type QuotePayload } from '@/api/quotes'
import { clientsApi, type Client } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { formatMoney, formatPercent } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

const { t } = useI18n()
const toast = useToast()

useHotkey('ctrl+s', (e) => { e.preventDefault(); submit() })

const route = useRoute()
const router = useRouter()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const quoteId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loaded = ref(false)
const submitting = ref(false)
const error = ref('')

const clients = ref<Client[]>([])
const projects = ref<Project[]>([])
const vatRates = ref<VatRate[]>([])
const currencies = ref<Currency[]>([])
const units = ref<Unit[]>([])

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function defaultItemUnit(): string {
  return units.value.find(u => u.is_default)?.code || units.value[0]?.code || 'ks'
}

const form = ref<{
  client_id: number | null
  project_id: number | null
  issue_date: string
  currency_id: number
  reverse_charge: boolean
  language: 'cs' | 'en'
  note_above_items: string
  note_below_items: string
  quote_valid_until: string
  items: Array<{
    id?: number
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    vat_rate_snapshot?: number
    total_without_vat?: number
    total_vat?: number
    total_with_vat?: number
  }>
}>({
  client_id: null,
  project_id: null,
  issue_date: today(),
  currency_id: 1,
  reverse_charge: false,
  language: 'cs',
  note_above_items: '',
  note_below_items: '',
  quote_valid_until: '',
  items: [],
})

const selectedClient = computed(() => clients.value.find(c => c.id === form.value.client_id))
const selectedProject = computed(() => projects.value.find(p => p.id === form.value.project_id))
const selectedCurrency = computed(() => currencies.value.find(c => c.id === form.value.currency_id))
const selectedVatRate = (rateId: number) => vatRates.value.find(v => v.id === rateId)

const computedTotals = computed(() => {
  let totalWithoutVat = 0
  let totalVat = 0
  let totalWithVat = 0
  for (const item of form.value.items) {
    const rate = selectedVatRate(item.vat_rate_id)
    const r = rate ? parseFloat(String(rate.rate_percent)) : 0
    const qty = parseFloat(String(item.quantity)) || 0
    const price = parseFloat(String(item.unit_price_without_vat)) || 0
    const withoutVat = qty * price
    const vat = withoutVat * r / 100
    const withVat = withoutVat + vat
    totalWithoutVat += withoutVat
    totalVat += vat
    totalWithVat += withVat
  }
  return { totalWithoutVat, totalVat, totalWithVat }
})

const vatBreakdown = computed(() => {
  const groups: Record<string, { rate: number; base: number; vat: number }> = {}
  for (const item of form.value.items) {
    const rate = selectedVatRate(item.vat_rate_id)
    const r = rate ? parseFloat(String(rate.rate_percent)) : 0
    const qty = parseFloat(String(item.quantity)) || 0
    const price = parseFloat(String(item.unit_price_without_vat)) || 0
    const withoutVat = qty * price
    const vat = withoutVat * r / 100
    const key = String(r)
    if (!groups[key]) groups[key] = { rate: r, base: 0, vat: 0 }
    groups[key].base += withoutVat
    groups[key].vat += vat
  }
  return Object.values(groups)
})

const editorTitle = computed(() =>
  isEdit.value ? t('quote.edit_title') : t('quote.new_title')
)

async function loadQuote(id: number) {
  try {
    const q = await quotesApi.get(id)
    form.value.client_id = q.client_id
    form.value.project_id = q.project_id
    form.value.issue_date = q.issue_date
    form.value.currency_id = q.currency_id
    form.value.reverse_charge = !!q.reverse_charge
    form.value.language = q.language
    form.value.note_above_items = q.note_above_items ?? ''
    form.value.note_below_items = q.note_below_items ?? ''
    form.value.quote_valid_until = q.quote_valid_until ?? ''
    form.value.items = q.items.map(it => ({
      id: it.id,
      description: it.description,
      quantity: it.quantity,
      unit: it.unit,
      unit_price_without_vat: it.unit_price_without_vat,
      vat_rate_id: it.vat_rate_id,
      vat_rate_snapshot: it.vat_rate_snapshot,
      total_without_vat: it.total_without_vat,
      total_vat: it.total_vat,
      total_with_vat: it.total_with_vat,
    }))
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('common.load_failed')))
    router.push('/quotes')
  }
}

onMounted(async () => {
  const [clientsRes, currenciesRes, vatRatesRes, unitsRes] = await Promise.all([
    clientsApi.list({ archived: false, per_page: 200 }).catch(() => ({ data: [] })),
    codebooksApi.currencies().catch(() => []),
    codebooksApi.vatRates().catch(() => []),
    codebooksApi.units().catch(() => []),
  ])
  clients.value = clientsRes.data ?? clientsRes
  currencies.value = currenciesRes
  vatRates.value = vatRatesRes
  units.value = unitsRes

  if (isEdit.value && quoteId.value) {
    await loadQuote(quoteId.value)
  }

  // Předvyplň měnu z prvního klienta nebo výchozí
  if (!isEdit.value && clients.value.length > 0) {
    form.value.client_id = clients.value[0].id
  }
  if (currencies.value.length > 0) {
    form.value.currency_id = currencies.value[0].id
  }
  if (vatRates.value.length > 0 && form.value.items.length === 0) {
    addItem()
  }

  loaded.value = true
})

watch(() => form.value.client_id, async (newClientId) => {
  if (newClientId) {
    projectsApi.list({ client_id: newClientId }).then(r => { projects.value = r.data }).catch(() => { projects.value = [] })
  } else {
    projects.value = []
  }
})

function addItem() {
  form.value.items.push({
    description: '',
    quantity: 1,
    unit: defaultItemUnit(),
    unit_price_without_vat: 0,
    vat_rate_id: vatRates.value[0]?.id ?? 1,
  })
}

function removeItem(i: number) {
  form.value.items.splice(i, 1)
}

async function submit() {
  if (!form.value.client_id) {
    toast.error(t('invoice.client_required'))
    return
  }
  if (form.value.items.length === 0) {
    toast.error(t('invoice.issue_no_items'))
    return
  }

  submitting.value = true
  try {
    const payload: QuotePayload = {
      client_id: form.value.client_id,
      project_id: form.value.project_id,
      issue_date: form.value.issue_date,
      currency_id: form.value.currency_id,
      reverse_charge: form.value.reverse_charge,
      language: form.value.language,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      quote_valid_until: form.value.quote_valid_until || null,
      items: form.value.items.map((it, idx) => ({
        order_index: idx,
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
      })),
    }

    if (isEdit.value && quoteId.value) {
      await quotesApi.update(quoteId.value, payload)
      toast.success(t('common.saved'))
      router.push(`/quotes/${quoteId.value}`)
    } else {
      const created = await quotesApi.create(payload)
      toast.success(t('common.saved'))
      router.push(`/quotes/${created.id}`)
    }
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('common.save_failed')))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div v-if="loaded">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <RouterLink to="/quotes" class="text-neutral-400 hover:text-neutral-600 transition">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        </RouterLink>
        <h1 class="text-2xl font-semibold">{{ editorTitle }}</h1>
      </div>
      <button
        @click="submit"
        :disabled="submitting"
        class="cursor-pointer inline-flex items-center gap-2 h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md"
      >
        {{ submitting ? '…' : t('common.save') }}
      </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Hlavní formulář -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Základní údaje -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <h2 class="text-sm font-semibold text-neutral-700 mb-4">{{ t('invoice.basic_data') }}</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('invoice.client') }} *</label>
              <SearchableSelect
                :model-value="form.client_id === null ? null : form.client_id"
                @update:model-value="(v: number | null) => form.client_id = v"
                :options="clients.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
                :placeholder="t('invoice.select_client')"
              />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('project.title') }}</label>
              <SearchableSelect
                :model-value="form.project_id === null ? null : form.project_id"
                @update:model-value="(v: number | null) => form.project_id = v"
                :options="projects.map(p => ({ value: p.id, label: p.name }))"
                :placeholder="t('project.select_project')"
              />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('invoice.issue_date') }}</label>
              <input v-model="form.issue_date" type="date" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('quote.valid_until') }}</label>
              <input v-model="form.quote_valid_until" type="date" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('invoice.currency') }}</label>
              <select v-model="form.currency_id" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }} — {{ c.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('invoice.language') }}</label>
              <select v-model="form.language" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option value="cs">Čeština</option>
                <option value="en">English</option>
              </select>
            </div>
          </div>
        </div>

        <!-- Položky -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-neutral-700">{{ t('invoice.items') }}</h2>
            <button @click="addItem" class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 font-medium">
              + {{ t('invoice.add_item') }}
            </button>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-xs text-neutral-500 uppercase">
                <tr>
                  <th class="text-left px-2 py-1 w-12">#</th>
                  <th class="text-left px-2 py-1">{{ t('invoice.item_description') }}</th>
                  <th class="text-center px-2 py-1 w-20">{{ t('invoice.item_qty') }}</th>
                  <th class="text-center px-2 py-1 w-16">{{ t('invoice.item_unit') }}</th>
                  <th class="text-right px-2 py-1 w-28">{{ t('invoice.item_price') }}</th>
                  <th class="text-center px-2 py-1 w-20">{{ t('invoice.item_vat') }}</th>
                  <th class="text-right px-2 py-1 w-28">{{ t('invoice.item_total') }}</th>
                  <th class="w-8"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(item, i) in form.items" :key="i">
                  <td class="px-2 py-2 text-neutral-400 text-xs text-center">{{ i + 1 }}</td>
                  <td class="px-2 py-2">
                    <input
                      v-model="item.description"
                      type="text"
                      :placeholder="t('invoice.item_description_placeholder')"
                      class="w-full px-2 py-1 border border-neutral-300 rounded text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
                    />
                  </td>
                  <td class="px-2 py-2">
                    <input
                      v-model.number="item.quantity"
                      type="number"
                      min="0"
                      step="0.01"
                      class="w-full px-2 py-1 border border-neutral-300 rounded text-sm text-center focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
                    />
                  </td>
                  <td class="px-2 py-2">
                    <select v-model="item.unit" class="w-full h-8 px-2 border border-neutral-300 rounded-md bg-white text-sm">
                      <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                    </select>
                  </td>
                  <td class="px-2 py-2">
                    <input
                      v-model.number="item.unit_price_without_vat"
                      type="number"
                      min="0"
                      step="0.01"
                      class="w-full px-2 py-1 border border-neutral-300 rounded text-sm text-right focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
                    />
                  </td>
                  <td class="px-2 py-2">
                    <select v-model="item.vat_rate_id" class="w-full h-8 px-2 border border-neutral-300 rounded-md bg-white text-sm">
                      <option v-for="v in vatRates" :key="v.id" :value="v.id">{{ v.rate_percent }}%</option>
                    </select>
                  </td>
                  <td class="px-2 py-2 text-right font-mono text-xs">
                    {{ formatMoney((item.quantity || 0) * (item.unit_price_without_vat || 0) * (1 + (vatRates.find(v => v.id === item.vat_rate_id)?.rate_percent || 0) / 100), selectedCurrency?.code ?? 'CZK') }}
                  </td>
                  <td class="px-2 py-2">
                    <button @click="removeItem(i)" class="cursor-pointer text-neutral-400 hover:text-danger-500 transition">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="form.items.length === 0" class="text-center py-6 text-neutral-400 text-sm">
            {{ t('invoice.no_items') }}
          </div>
        </div>

        <!-- Poznámky -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <h2 class="text-sm font-semibold text-neutral-700 mb-4">{{ t('invoice.notes') }}</h2>
          <div class="space-y-4">
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('invoice.note_above_items') }}</label>
              <textarea v-model="form.note_above_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm resize-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('invoice.note_below_items') }}</label>
              <textarea v-model="form.note_below_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm resize-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
          </div>
        </div>
      </div>

      <!-- Postranní panel -->
      <div class="space-y-6">
        <!-- Přehled částek -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <h2 class="text-sm font-semibold text-neutral-700 mb-4">{{ t('invoice.summary') }}</h2>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-neutral-500">{{ t('invoice.total_without_vat') }}</span>
              <span class="font-mono">{{ formatMoney(computedTotals.totalWithoutVat, selectedCurrency?.code ?? 'CZK') }}</span>
            </div>
            <div v-for="vb in vatBreakdown" :key="vb.rate" class="flex justify-between">
              <span class="text-neutral-500">DPH {{ vb.rate }}%</span>
              <span class="font-mono">{{ formatMoney(vb.vat, selectedCurrency?.code ?? 'CZK') }}</span>
            </div>
            <div class="flex justify-between font-semibold text-neutral-900 pt-2 border-t border-neutral-200">
              <span>{{ t('invoice.total_with_vat') }}</span>
              <span class="font-mono">{{ formatMoney(computedTotals.totalWithVat, selectedCurrency?.code ?? 'CZK') }}</span>
            </div>
          </div>
        </div>

        <!-- Info -->
        <div v-if="selectedClient" class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <h2 class="text-sm font-semibold text-neutral-700 mb-3">{{ t('invoice.client_info') }}</h2>
          <div class="text-sm space-y-1">
            <div class="font-medium">{{ selectedClient.company_name }}</div>
            <div v-if="selectedClient.ic" class="text-neutral-500">IČ: {{ selectedClient.ic }}</div>
            <div v-if="selectedClient.dic" class="text-neutral-500">DIČ: {{ selectedClient.dic }}</div>
            <div v-if="selectedClient.main_email" class="text-neutral-500">{{ selectedClient.main_email }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="flex items-center justify-center h-64">
    <div class="animate-pulse text-neutral-400">{{ t('common.loading') }}</div>
  </div>
</template>
