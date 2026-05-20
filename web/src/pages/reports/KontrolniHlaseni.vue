<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { reportsApi } from '@/api/reports'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()
const router = useRouter()

const now = new Date()
const currentYear = now.getFullYear()
const prevMonth = now.getMonth() === 0 ? 12 : now.getMonth()
const prevYear  = now.getMonth() === 0 ? currentYear - 1 : currentYear
const year = ref(prevYear)
const month = ref(prevMonth)
const type = ref<'KH1' | 'KH2'>('KH1')
const loading = ref(false)

// Chybovy stav pro 422 - neuplne EPO nastaveni
const epoConfigError = ref<{ message: string; fields: Record<string, string> } | null>(null)

const months = [
  { value: 1, label: '1 – Leden' },
  { value: 2, label: '2 – únor' },
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
  epoConfigError.value = null
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
    const data = e?.response?.data
    // Backend vraci { error: "string", fields: { field: "popis" } } pro 422
    if (data?.error && data?.fields) {
      epoConfigError.value = { message: data.error, fields: data.fields }
    } else {
      const msg = typeof data?.error === 'string' ? data.error : (data?.error?.message || t('errors.generic'))
      toast.error(msg)
    }
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

      <!-- 422: chybejici EPO nastaveni (tax_ufo / tax_pracufo / dic) -->
      <div v-if="epoConfigError" class="mt-4 border border-amber-300 bg-amber-50 rounded-md p-4">
        <p class="text-sm font-semibold text-amber-800 mb-2">⚠ {{ epoConfigError.message }}</p>
        <ul class="text-xs text-amber-700 space-y-1 mb-3">
          <li v-for="(desc, field) in epoConfigError.fields" :key="field">
            <span class="font-mono font-semibold">{{ field }}:</span> {{ desc }}
          </li>
        </ul>
        <button
          @click="router.push('/admin/settings?tab=dph_epo')"
          class="text-xs font-medium text-indigo-600 hover:text-indigo-800 underline"
        >
          → Přejít do Nastavení → DPH / EPO
        </button>
      </div>
    </div>
  </div>
</template>
