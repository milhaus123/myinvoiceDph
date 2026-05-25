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
const month = ref<number | ''>(prevMonth)
const formType = ref<'DPHDP3' | 'DPHDP4' | 'DPHDP5' | 'DPHDP6'>('DPHDP3')
const forma = ref<'B' | 'O' | 'D' | 'E'>('B')
const loading = ref(false)

// Chybový stav pro 422 — neúplné EPO nastavení
const epoConfigError = ref<{ message: string; fields: Record<string, string> } | null>(null)

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
const formTypes = ['DPHDP3', 'DPHDP4', 'DPHDP5', 'DPHDP6']

async function download() {
  loading.value = true
  epoConfigError.value = null
  try {
    const data = await reportsApi.dphPriznani({
      year: year.value,
      month: month.value === '' ? undefined : month.value,
      form_type: formType.value,
      forma: forma.value,
    })
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
    // Backend vrací { error: "string", fields: { field: "popis" } } pro 422
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
    <h1 class="text-2xl font-semibold text-gray-900">{{ t('reports.dphPriznani.title') }}</h1>

    <div class="bg-white rounded-lg shadow p-6 space-y-4">
      <p class="text-sm text-gray-600">{{ t('reports.dphPriznani.description') }}</p>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.common.year') }}</label>
          <select v-model="year" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.common.month') }}</label>
          <select v-model="month" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option v-for="m in months" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.dphPriznani.formType') }}</label>
          <select v-model="formType" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option v-for="ft in formTypes" :key="ft" :value="ft">{{ ft }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Typ přiznání</label>
          <select v-model="forma" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option value="B">B – Běžné (řádné)</option>
            <option value="O">O – Opravné</option>
            <option value="D">D – Dodatečné</option>
            <option value="E">E – Opravné k dodatečnému</option>
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

      <!-- 422: chybějící EPO nastavení (tax_ufo / tax_pracufo / dic)