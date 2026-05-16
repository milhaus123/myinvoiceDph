<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi } from '@/api/reports'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

const currentYear = new Date().getFullYear()
const year = ref(currentYear)
const month = ref(new Date().getMonth() + 1)
const type = ref<'KH1' | 'KH2'>('KH1')
const loading = ref(false)

const months = [
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

async function download() {
  loading.value = true
  try {
    const data = await reportsApi.kontrolniHlaseni({ year: year.value, month: month.value, type: type.value })
    const blob = new Blob([data.xml_content], { type: 'application/xml' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = data.filename
    a.click()
    URL.revokeObjectURL(url)
    toast.success(t('reports.common.downloadStarted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('errors.generic'))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-2xl font-semibold text-gray-900">{{ t('reports.kontrolniHlaseni.title') }}</h1>

    <div class="bg-white rounded-lg shadow p-6 space-y-4">
      <p class="text-sm text-gray-600">{{ t('reports.kontrolniHlaseni.description') }}</p>

      <div class="grid grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.common.year') }}</label>
          <select v-model="year" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.common.month') }}</label>
          <select v-model="month" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.kontrolniHlaseni.type') }}</label>
          <select v-model="type" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="KH1">KH1</option>
            <option value="KH2">KH2</option>
          </select>
        </div>
      </div>

      <button
        @click="download"
        :disabled="loading"
        class="w-full bg-indigo-600 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-700 transition disabled:opacity-50"
      >
        {{ loading ? t('reports.common.loading') : t('reports.common.downloadXml') }}
      </button>
    </div>
  </div>
</template>
