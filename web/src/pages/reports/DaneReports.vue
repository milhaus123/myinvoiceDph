<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { reportsApi, type DphReportData } from '@/api/reports'
import { formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()

// Determine initial tab from query param (?tab=kh etc.)
type Tab = 'vykaz' | 'priznani' | 'kh' | 'dani'
const initialTab = (route.query.tab as Tab) || 'vykaz'
const tab = ref<Tab>(initialTab)

const now = new Date()
const currentYear = now.getFullYear()
const prevMonth = now.getMonth() === 0 ? 12 : now.getMonth()
const prevYear  = now.getMonth() === 0 ? currentYear - 1 : currentYear
const months = [
  { value: '', label: '—' },
  { value: 1, label: '1 – Leden' }, { value: 2, label: '2 – Únor' },
  { value: 3, label: '3 – Březen' }, { value: 4, label: '4 – Duben' },
  { value: 5, label: '5 – Květen' }, { value: 6, label: '6 – Červen' },
  { value: 7, label: '7 – Červenec' }, { value: 8, label: '8 – Srpen' },
  { value: 9, label: '9 – Září' }, { value: 10, label: '10 – Říjen' },
  { value: 11, label: '11 – Listopad' }, { value: 12, label: '12 – Prosinec' },
]
const monthsRequired = months.filter(m => m.value !== '')
const years = Array.from({ length: 5 }, (_, i) => currentYear - i)

// ─── Tab: DPH Výkaz ──────────────────────────────────────────────────────────
const vykaz = ref<DphReportData | null>(null)
const vykazYear = ref(currentYear)
const vykazMonth = ref<number | ''>('')
const vykazLoading = ref(false)

async function loadVykaz() {
  vykazLoading.value = true
  try {
    vykaz.value = await reportsApi.dphReport({
      year: vykazYear.value,
      month: vykazMonth.value === '' ? undefined : vykazMonth.value,
    })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('errors.generic'))
  } finally { vykazLoading.value = false }
}

// ─── Tab: DAP DPH (DPHDP3) ──────────────────────────────────────────────────
const priznaниYear = ref(prevYear)
const priznaниMonth = ref<number | ''>(prevMonth)
const priznaниLoading = ref(false)

async function downloadPriznani() {
  priznaниLoading.value = true
  try {
    const data = await reportsApi.dphPriznani({
      year: priznaниYear.value,
      month: priznaниMonth.value === '' ? undefined : priznaниMonth.value,
      form_type: 'DPHDP3',
    })
    triggerDownload(data.xml_content, data.filename)
    toast.success(t('reports.common.downloadStarted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('errors.generic'))
  } finally { priznaниLoading.value = false }
}

// ─── Tab: Kontrolní hlášení ──────────────────────────────────────────────────
const khYear = ref(prevYear)
const khMonth = ref(prevMonth)
const khLoading = ref(false)

async function downloadKH() {
  khLoading.value = true
  try {
    const data = await reportsApi.kontrolniHlaseni({ year: khYear.value, month: khMonth.value, type: 'KH1' })
    triggerDownload(data.xml_content, data.filename)
    toast.success(t('reports.common.downloadStarted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('errors.generic'))
  } finally { khLoading.value = false }
}

// ─── Tab: Přiznání k dani z příjmů ──────────────────────────────────────────
const daniYear = ref(currentYear - 1)
const daniType = ref<'DPFDP5' | 'DPPDP9'>('DPFDP5')
const daniLoading = ref(false)

async function downloadDani() {
  daniLoading.value = true
  try {
    const data = await reportsApi.incomeTaxReturn({ year: daniYear.value, type: daniType.value })
    triggerDownload(data.xml_content, data.filename)
    toast.success(t('reports.common.downloadStarted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('errors.generic'))
  } finally { daniLoading.value = false }
}

// ─── Helper ──────────────────────────────────────────────────────────────────
function triggerDownload(content: string, filename: string) {
  const blob = new Blob([content], { type: 'application/xml' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url; a.download = filename; a.click()
  URL.revokeObjectURL(url)
}

const TABS = [
  { key: 'vykaz',    label: 'DPH Výkaz' },
  { key: 'priznani', label: 'DAP DPH (DPHDP3)' },
  { key: 'kh',       label: 'Kontrolní hlášení' },
  { key: 'dani',     label: 'Přiznání k dani z příjmů' },
] as const
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('nav.reports') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">DPH přiznání, kontrolní hlášení a daňová podání pro EPO MF ČR</p>
    </div>

    <!-- Tab bar -->
    <div class="border-b border-neutral-200 mb-5 flex gap-1 flex-wrap">
      <button v-for="tt in TABS" :key="tt.key"
        @click="tab = tt.key"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition -mb-px"
        :class="tab === tt.key
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-500 hover:text-neutral-700 hover:border-neutral-300'">
        {{ tt.label }}
      </button>
    </div>

    <!-- ── DPH Výkaz ── -->
    <div v-if="tab === 'vykaz'" class="space-y-5">
      <div class="bg-white border border-neutral-200 rounded-lg p-4 shadow-sm flex flex-wrap gap-4 items-end">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('reports.common.year') }}</label>
          <select v-model="vykazYear" class="h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('reports.common.month') }}</label>
          <select v-model="vykazMonth" class="h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option v-for="m in months" :key="String(m.value)" :value="m.value">{{ m.label }}</option>
          </select>
        </div>
        <button @click="loadVykaz" :disabled="vykazLoading"
          class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
          {{ vykazLoading ? t('common.loading') : t('reports.common.refresh') }}
        </button>
      </div>

      <div v-if="vykazLoading" class="bg-white border border-neutral-200 rounded-lg p-8 text-center text-neutral-400 text-sm">
        {{ t('common.loading') }}
      </div>
      <template v-else-if="vykaz">
        <!-- Issued (výstupní) + Received (vstupní) side by side -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <!-- Výstupní DPH — vydané faktury -->
          <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
            <div class="px-4 py-3 bg-blue-50 border-b border-neutral-200">
              <h3 class="text-sm font-semibold text-blue-800">{{ vykaz.issued.label }}</h3>
            </div>
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-4 py-3 text-left font-medium">Sazba DPH</th>
                  <th class="px-4 py-3 text-right font-medium">Základ (Kč)</th>
                  <th class="px-4 py-3 text-right font-medium">DPH (Kč)</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in vykaz.issued.by_rate" :key="row.rate" class="hover:bg-neutral-50">
                  <td class="px-4 py-2 font-mono">{{ row.rate }}%</td>
                  <td class="px-4 py-2 text-right">{{ formatMoney(row.zaklad, 'CZK') }}</td>
                  <td class="px-4 py-2 text-right font-medium">{{ formatMoney(row.dph, 'CZK') }}</td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50 font-semibold text-sm">
                <tr>
                  <td class="px-4 py-2">Celkem</td>
                  <td class="px-4 py-2 text-right" colspan="1"></td>
                  <td class="px-4 py-2 text-right text-blue-700">{{ formatMoney(vykaz.totals.output_vat, 'CZK') }}</td>
                </tr>
              </tfoot>
            </table>
          </div>

          <!-- Vstupní DPH — přijaté faktury -->
          <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
            <div class="px-4 py-3 bg-orange-50 border-b border-neutral-200">
              <h3 class="text-sm font-semibold text-orange-800">{{ vykaz.received.label }}</h3>
            </div>
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-4 py-3 text-left font-medium">Sazba DPH</th>
                  <th class="px-4 py-3 text-right font-medium">Základ (Kč)</th>
                  <th class="px-4 py-3 text-right font-medium">DPH (Kč)</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in vykaz.received.by_rate" :key="row.rate" class="hover:bg-neutral-50">
                  <td class="px-4 py-2 font-mono">{{ row.rate }}%</td>
                  <td class="px-4 py-2 text-right">{{ formatMoney(row.zaklad, 'CZK') }}</td>
                  <td class="px-4 py-2 text-right font-medium">{{ formatMoney(row.dph, 'CZK') }}</td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50 font-semibold text-sm">
                <tr>
                  <td class="px-4 py-2">Celkem</td>
                  <td class="px-4 py-2 text-right" colspan="1"></td>
                  <td class="px-4 py-2 text-right text-orange-700">{{ formatMoney(vykaz.totals.input_vat, 'CZK') }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <!-- Bug 3: Vlastní daňová povinnost (delta) -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-4 flex flex-wrap items-center justify-between gap-3">
          <div class="text-sm text-neutral-600">
            <span class="font-medium">Období:</span>
            {{ vykaz.period.date_from }} – {{ vykaz.period.date_to }}
          </div>
          <div class="flex items-center gap-6">
            <div class="text-sm">
              <span class="text-neutral-500">Výstupní DPH:</span>
              <span class="ml-2 font-medium text-blue-700">{{ formatMoney(vykaz.totals.output_vat, 'CZK') }}</span>
            </div>
            <div class="text-sm">
              <span class="text-neutral-500">Vstupní DPH:</span>
              <span class="ml-2 font-medium text-orange-700">{{ formatMoney(vykaz.totals.input_vat, 'CZK') }}</span>
            </div>
            <div class="border-l border-neutral-300 pl-6 text-sm">
              <span class="text-neutral-600 font-medium">Vlastní daňová povinnost:</span>
              <span class="ml-2 text-lg font-bold"
                :class="vykaz.totals.delta >= 0 ? 'text-red-600' : 'text-green-600'">
                {{ formatMoney(vykaz.totals.delta, 'CZK') }}
              </span>
            </div>
          </div>
        </div>
      </template>
      <div v-else class="bg-white border border-neutral-200 rounded-lg p-8 text-center text-neutral-400 text-sm">
        Zvolte rok/měsíc a klikněte Načíst
      </div>
    </div>

    <!-- ── DAP DPH (DPHDP3) ── -->
    <div v-else-if="tab === 'priznani'">
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm max-w-xl space-y-4">
        <p class="text-sm text-neutral-600">{{ t('reports.dphPriznani.description') }}</p>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('reports.common.year') }}</label>
            <select v-model="priznaниYear" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('reports.common.month') }}</label>
            <select v-model="priznaниMonth" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option v-for="m in monthsRequired" :key="String(m.value)" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
        </div>
        <p class="text-xs text-neutral-500">
          Generuje soubor DPHDP3 ve formátu EPO MF ČR připravený k nahrání na
          <a href="https://epodatelna.mfcr.cz/" target="_blank" class="text-primary-600 hover:underline">epodatelna.mfcr.cz</a>.
          Pro správné VetaP vyplňte DPH/EPO pole v <a href="/admin/settings?tab=dph_epo" class="text-primary-600 hover:underline">Nastavení → DPH/EPO</a>.
        </p>
        <button @click="downloadPriznani" :disabled="priznaниLoading"
          class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
          {{ priznaниLoading ? t('common.loading') : '⬇ Stáhnout DPHDP3 XML' }}
        </button>
      </div>
    </div>

    <!-- ── Kontrolní hlášení ── -->
    <div v-else-if="tab === 'kh'">
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm max-w-xl space-y-4">
        <p class="text-sm text-neutral-600">{{ t('reports.kontrolniHlaseni.description') }}</p>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('reports.common.year') }}</label>
            <select v-model="khYear" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('reports.common.month') }}</label>
            <select v-model="khMonth" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option v-for="m in monthsRequired" :key="String(m.value)" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
        </div>
        <p class="text-xs text-neutral-500">
          Generuje soubor DPHKH1 ve formatu EPO MF CR. Faktury 10 000 Kc s DIC jdou do sekce A.4 / B.2,
          ostatni do souhrnne A.5 / B.3.
          Pro spravne VetaP vyplnte DPH/EPO pole v <a href="/admin/settings?tab=dph_epo" class="text-primary-600 hover:underline">Nastaveni DPH/EPO</a>.
        </p>
        <button @click="downloadKH" :disabled="khLoading"
          class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
          {{ khLoading ? t('common.loading') : '⬇ Stahnout DPHKH1 XML' }}
        </button>
      </div>
    </div>

    <!-- Priznani k dani z prijmu -->
    <div v-else-if="tab === 'dani'">
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm max-w-xl space-y-4">
        <p class="text-sm text-neutral-600">{{ t('reports.incomeTaxReturn.description') }}</p>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('reports.common.year') }}</label>
            <select v-model="daniYear" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('reports.incomeTaxReturn.type') }}</label>
            <select v-model="daniType" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option value="DPFDP5">DPFDP5 - FO</option>
              <option value="DPPDP9">DPPDP9 - PO</option>
            </select>
          </div>
        </div>
        <button @click="downloadDani" :disabled="daniLoading"
          class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
          {{ daniLoading