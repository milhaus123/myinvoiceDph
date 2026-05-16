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
  { value: '', label: '—' },
  { value: 1, label: '1 – Leden' },
  { value: 2, label: '2 – Únor' },
  { value: 3, label: '3 – Březen' },
  { value: 4, label: '4 – Duben' },
  { value: 5, label: '5 – Květen' },
  { value: 6, label: '6 – Červen' },
  { value: 7, label: '7 – Červenec' },
  { value: 8, label: '8 – Srpen' },
  { value: 9, label: '9 – Září' },
  { value: 10, label: '10 – Říjen' },
  { value: 11, label: '11 – Listopad' },
  { value: 12, label: '12 – Prosinec' },
]

const years = Array.from({ length: 5 }, (_, i) => currentYear - i)
</script>

<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <!-- Page header -->
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-semibold text-gray-900">{{ t('reports.dph.title') }}</h1>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-4 items-end">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.common.year') }}</label>
        <select v-model="year" class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.common.month') }}</label>
        <select v-model="month" class="border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
          <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
        </select>
      </div>
      <button
        @click="load"
        class="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-700 transition"
      >
        {{ t('reports.common.refresh') }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
      {{ t('reports.common.loading') }}
    </div>

    <!-- Report table -->
    <div v-else-if="report" class="bg-white rounded-lg shadow overflow-hidden">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left font-medium text-gray-700">{{ t('reports.dph.vatRate') }}</th>
            <th class="px-4 py-3 text-right font-medium text-gray-700">{{ t('reports.dph.baseCzk') }}</th>
            <th class="px-4 py-3 text-right font-medium text-gray-700">{{ t('reports.dph.vatCzk') }}</th>
            <th class="px-4 py-3 text-right font-medium text-gray-700">{{ t('reports.dph.baseForeign') }}</th>
            <th class="px-4 py-3 text-right font-medium text-gray-700">{{ t('reports.dph.vatForeign') }}</th>
            <th class="px-4 py-3 text-right font-medium text-gray-700">{{ t('reports.dph.totalVatCzk') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="row in report.rows" :key="row.vat_rate">
            <td class="px-4 py-2">{{ row.vat_rate }}%</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(row.base_czk, 'CZK') }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(row.vat_czk, 'CZK') }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(row.base_foreign, 'CZK') }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(row.vat_foreign, 'CZK') }}</td>
            <td class="px-4 py-2 text-right font-medium">{{ formatMoney(row.total_vat_czk, 'CZK') }}</td>
          </tr>
        </tbody>
        <tfoot class="bg-gray-50 font-medium">
          <tr>
            <td class="px-4 py-2">{{ t('reports.common.total') }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(report.total_base_czk, 'CZK') }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(report.total_vat_czk, 'CZK') }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(report.total_base_foreign, 'CZK') }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(report.total_vat_foreign, 'CZK') }}</td>
            <td class="px-4 py-2 text-right">{{ formatMoney(report.total_vat_czk + report.total_vat_foreign, 'CZK') }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</template>
