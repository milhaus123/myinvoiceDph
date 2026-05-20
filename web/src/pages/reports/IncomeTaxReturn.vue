<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi } from '@/api/reports'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const toast = useToast()

const currentYear = new Date().getFullYear()
const year = ref(currentYear)
const type = ref<'DPFDP5' | 'DPPDP9'>('DPFDP5')
const loading = ref(false)

const years = Array.from({ length: 5 }, (_, i) => currentYear - i)

async function download() {
  loading.value = true
  try {
    const data = await reportsApi.incomeTaxReturn({ year: year.value, type: type.value })
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
    <h1 class="text-2xl font-semibold text-gray-900">{{ t('reports.incomeTaxReturn.title') }}</h1>

    <div class="bg-white rounded-lg shadow p-6 space-y-4">
      <p class="text-sm text-gray-600">{{ t('reports.incomeTaxReturn.description') }}</p>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.common.year') }}</label>
          <select v-model="year" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('reports.incomeTaxReturn.type') }}</label>
          <select v-model="type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-white text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
            <option value="DPFDP5">DPFDP5</option>
            <option value="DPPDP9">DPPDP9</option>
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
