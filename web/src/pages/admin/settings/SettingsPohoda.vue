<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { settingsApi, type Supplier } from '@/api/settings'

const { t } = useI18n()
const toast = useToast()

const props = defineProps<{ supplier: Supplier }>()

async function save() {
  try {
    const updated = await settingsApi.updateSupplier({
      pohoda_account_code:   props.supplier.pohoda_account_code,
      pohoda_centre_code:    props.supplier.pohoda_centre_code,
      pohoda_activity_code:  props.supplier.pohoda_activity_code,
      pohoda_contract_code:  props.supplier.pohoda_contract_code,
    })
    Object.assign(props.supplier, updated)
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
</script>

<template>
  <section class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-1">{{ t('settings.pohoda_section') }}</h2>
    <p class="text-xs text-neutral-500 mb-3">{{ t('settings.pohoda_hint') }}</p>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_account_code') }}</label>
        <input v-model="supplier.pohoda_account_code" type="text" placeholder="KB" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_centre_code') }}</label>
        <input v-model="supplier.pohoda_centre_code" type="text" placeholder="STR1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_activity_code') }}</label>
        <input v-model="supplier.pohoda_activity_code" type="text" placeholder="ACT1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_contract_code') }}</label>
        <input v-model="supplier.pohoda_contract_code" type="text" placeholder="ZAK1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
    </div>

    <div class="mt-4 flex justify-end">
      <button @click="save" class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
        {{ t('settings.save_supplier') }}
      </button>
    </div>
  </section>
</template>
