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
      company_name: props.supplier.company_name,
      display_name: props.supplier.display_name,
      street: props.supplier.street,
      c_pop: props.supplier.c_pop,
      city: props.supplier.city,
      zip: props.supplier.zip,
      ic: props.supplier.ic,
      dic: props.supplier.dic,
      is_vat_payer: props.supplier.is_vat_payer,
      email: props.supplier.email,
      phone: props.supplier.phone,
      web: props.supplier.web,
      tagline: props.supplier.tagline,
      commercial_register: props.supplier.commercial_register,
      default_payment_due_days: props.supplier.default_payment_due_days,
      default_hourly_rate: props.supplier.default_hourly_rate,
      auto_send_reminders: props.supplier.auto_send_reminders,
      auto_generate_recurring: props.supplier.auto_generate_recurring,
      embed_isdoc: props.supplier.embed_isdoc,
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
    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.supplier') }}</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.company_name') }} *</label>
        <input v-model="supplier.company_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.display_name') }}</label>
        <input v-model="supplier.display_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.street') }}</label>
          <input v-model="supplier.street" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          <p class="text-xs text-neutral-400 mt-1">{{ t('settings.street_epo_hint') }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.c_pop') }}</label>
          <input v-model="supplier.c_pop" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" placeholder="77" />
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.zip') }}</label>
          <input v-model="supplier.zip" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.city') }}</label>
          <input v-model="supplier.city" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.ic') }}</label>
        <input v-model="supplier.ic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.dic') }}</label>
        <input v-model="supplier.dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
      <div>
        <label class="flex items-center gap-2 text-sm mt-7">
          <input v-model="supplier.is_vat_payer" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('settings.is_vat_payer') }}
        </label>
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.email') }} *</label>
        <input v-model="supplier.email" type="email" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.phone') }}</label>
        <input v-model="supplier.phone" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.web') }}</label>
        <input v-model="supplier.web" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.tagline') }}</label>
        <input v-model="supplier.tagline" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.commercial_register') }}</label>
        <input v-model="supplier.commercial_register" type="text"
          :placeholder="t('settings.commercial_register_placeholder')"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        <p class="text-xs text-neutral-500 mt-1">{{ t('settings.commercial_register_hint') }}</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_due') }}</label>
        <input v-model.number="supplier.default_payment_due_days" type="number" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_hourly_rate') }} ({{ supplier.default_currency }})</label>
        <input v-model.number="supplier.default_hourly_rate" type="number" step="0.01" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
      <div class="md:col-span-2">
        <label class="flex items-center gap-2 text-sm">
          <input v-model="supplier.auto_send_reminders" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('settings.auto_send_reminders') }}
        </label>
        <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.auto_send_reminders_hint') }}</p>
      </div>
      <div class="md:col-span-2">
        <label class="flex items-center gap-2 text-sm">
          <input v-model="supplier.auto_generate_recurring" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('settings.auto_generate_recurring') }}
        </label>
        <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.auto_generate_recurring_hint') }}</p>
      </div>
      <div class="md:col-span-2">
        <label class="flex items-center gap-2 text-sm">
          <input v-model="supplier.embed_isdoc" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('settings.embed_isdoc') }}
        </label>
        <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.embed_isdoc_hint') }}</p>
      </div>
    </div>

    <div class="mt-4 flex justify-end">
      <button @click="save" class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
        {{ t('settings.save_supplier') }}
      </button>
    </div>
  </section>
</template>
