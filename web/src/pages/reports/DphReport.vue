<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi, type DphReportData } from '@/api/reports'
import { formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

const currentYear = new Date().getFullYear()
const year = ref(currentYear)
const month = ref<number | ''>('')
const loading = ref(false)
const report = ref<DphReportData | null>(null)

async function load() {
  loading.value = true
  try {
    report.value = await reportsApi.dphReport({
      year: year.value,
      month: month.value === '' ? undefined : month.value,
    })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('errors.generic'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([year, month], load)

const months = [
  { value: '', label: '-' },
  { value: 1, label: '1 Leden' },
  { value: 2, label: '2 Unor' },
  { value: 3, label: '3 Brezen' },
  { value: 4, label: '4 Duben' },
  { value: 5, label: '5 Kveten' },
  { value: 6, label: '6 Cerven' },
  { value: 7, label: '7 Cervenec' },
  { value: 8, label: '8 Srpen' },
  { value: 9, label: '9 Zari' },
  { value: 10, label: '10 Rijen' },
  { value: 11, label: '11 Listopad' },
  { value: 12, label: '12 Prosinec' },
]

const years = Array.from({ length: 5 }, (_, i) => currentYear - i)
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold text-gray-900">{{ t('reports.dph.title') }}</h1>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-4 items-end">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.common.year') }}</label>
        <select v-model="year" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
          <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.common.month') }}</label>
        <select v-model="month" class="border border-gray-300 rounded-md px-3 py-2 text-sm">
          <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
        </select>
      </div>
      <button @click="load" class="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-700 transition">
        {{ t('reports.common.refresh') }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
      {{ t('reports.common.loading') }}
    </div>

    <!-- Report: issued + received side by side -->
    <template v-else-if="report">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Vystupni DPH -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
          <div class="px-4 py-3 bg-blue-50 border-b border-gray-200">
            <h3 class="text-sm font-semibold text-blue-800">{{ report.issued.label }}</h3>
          </div>
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left font-medium text-gray-700">{{ t('reports.dph.vatRate') }}</th>
                <th class="px-4 py-3 text-right font-medium text-gray-700">{{ t('reports.dph.baseCzk') }}</th>
                <th class="px-4 py-3 text-right font-medium text-gray-700">{{ t('reports.dph.vatCzk') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="row in report.issued.by_rate" :key="row.rate">
                <td class="px-4 py-2 font-mono">{{ row.rate }}%</td>
                <td class="px-4 py-2 text-right">{{ formatMoney(row.zaklad, 'CZK') }}</td>
                <td class="px-4 py-2 text-right font-medium">{{ formatMoney(row.dph, 'CZK') }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 font-medium">
              <tr>
                <td class="px-4 py-2">{{ t('reports.common.total') }}</td>
                <td class="px-4 py-2 text-right"></td>
                <td class="px-4 py-2 text-right text-blue-700">{{ formatMoney(report.totals.output_vat, 'CZK') }}</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Vstupni DPH -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
          <div class="px-4 py-3 bg-orange-50 border-b border-gray-200">
            <h3 class="text-sm font-semibold text-orange-800">{{ report.received.label }}</h3>
          </div>
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left font-medium text-gray-700">{{ t('reports.dph.vatRate') }}</th>
                <th class="px-4 py-3 text-right font-medium text-gray-700">{{ t('reports.dph.baseCzk') }}</th>
                <th class="px-4 py-3 text-right font-medium text-gray-700">{{ t('reports.dph.vatCzk') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="row in report.received.by_rate" :key="row.rate">
                <td class="px-4 py-2 font-mono">{{ row.rate }}%</td>
                <td class="px-4 py-2 text-right">{{ formatMoney(row.zaklad, 'CZK') }}</td>
                <td class="px-4 py-2 text-right font-medium">{{ formatMoney(row.dph, 'CZK') }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50 font-medium">
              <tr>
                <td class="px-4 py-2">{{ t('reports.common.total') }}</td>
                <td class="px-4 py-2 text-right"></td>
                <td class="px-4 py-2 text-right text-orange-700">{{ formatMoney(report.totals.input_vat, 'CZK') }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Vlastni danove povinnost (delta) -->
      <div class="bg-white rounded-lg shadow p-4 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-600">
          <span class="font-medium">Obdobi:</span>
          {{ report.period.date_from }} - {{ report.period.date_to }}
        </div>
        <div class="flex items-center gap-6">
          <div class="text-sm">
            <span class="text-gray-500">Vystupni DPH:</span>
            <span class="ml-2 font-medium text-blue-700">{{ formatMoney(report.totals.output_vat, 'CZK') }}</span>
          </div>
          <div class="text-sm">
            <span class="text-gray-500">Vstupni DPH:</span>
            <span class="ml-2 font-medium text-orange-700">{{ formatMoney(report.totals.input_vat, 'CZK') }}</span>
          </div>
          <div class="border-l border-gray-300 pl-6 text-sm">
            <span class="font-medium text-gray-700">Vlastni danove povinnost:</span>
            <span class="ml-2 text-lg font-bold"
              :class="report.totals.delta >= 0 ? 'text-red-600' : 'text-green-600'">
              {{ formatMoney(report.totals.delta, 'CZK') }}
            </span>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
