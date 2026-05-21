<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import { settingsApi, type CurrencyAccount } from '@/api/settings'

const { t } = useI18n()
const toast = useToast()

const props = defineProps<{ currencies: CurrencyAccount[] }>()
const emit = defineEmits<{ refresh: [] }>()

const editingCurrency = ref<number | null>(null)
const editingCurrencyLabel = ref<string>('')
const currencyDraft = reactive<Partial<CurrencyAccount>>({})

useHotkey('escape', () => { if (editingCurrency.value !== null) editingCurrency.value = null })

function startEditCurrency(c: CurrencyAccount) {
  editingCurrency.value = c.id
  editingCurrencyLabel.value = c.label
  Object.assign(currencyDraft, { ...c })
}

async function saveCurrency() {
  if (editingCurrency.value === null) return
  try {
    const updated = await settingsApi.updateCurrency(editingCurrency.value, {
      label:          currencyDraft.label,
      is_active:      currencyDraft.is_active,
      is_default:     currencyDraft.is_default,
      account_number: currencyDraft.account_number || null,
      bank_code:      currencyDraft.bank_code || null,
      bank_name:      currencyDraft.bank_name || null,
      iban:           currencyDraft.iban || null,
      bic:            currencyDraft.bic || null,
    })
    emit('refresh')
    editingCurrency.value = null
    toast.success(`${updated.code} (${updated.label}) — ${t('common.saved')}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function addCurrencyAccount(code: string) {
  const label = window.prompt(t('settings.add_account_prompt', { code }), t('settings.add_account_default_label', { code }))
  if (!label) return
  try {
    await settingsApi.createCurrency({ code, label, is_active: true })
    emit('refresh')
    toast.success(`${label} — ${t('common.saved')}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeCurrency(c: CurrencyAccount) {
  if (!window.confirm(t('settings.delete_account_confirm', { label: c.label }))) return
  try {
    await settingsApi.deleteCurrency(c.id)
    emit('refresh')
    toast.success(`${c.label} — ${t('common.deleted')}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
</script>

<template>
  <section class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
    <header class="px-5 py-3 border-b border-neutral-200">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.currencies_banks') }}</h2>
    </header>
    <div class="overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('settings.currency') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('settings.account_th') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('settings.account_cz') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('settings.iban') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('settings.bic') }}</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('common.default') }}</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('settings.active') }}</th>
            <th class="px-3 py-2 w-32"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="c in currencies" :key="c.id">
            <td class="px-3 py-2 font-mono">{{ c.code }} <span class="text-xs text-neutral-500">{{ c.symbol }}</span></td>
            <td class="px-3 py-2">{{ c.label }}</td>
            <td class="px-3 py-2 font-mono text-xs">
              {{ c.account_number }}<span v-if="c.bank_code"> / {{ c.bank_code }}</span>
            </td>
            <td class="px-3 py-2 font-mono text-xs">{{ c.iban || '—' }}</td>
            <td class="px-3 py-2 font-mono text-xs">{{ c.bic || '—' }}</td>
            <td class="px-3 py-2 text-center">
              <span v-if="c.is_default" class="text-primary-600">✓</span>
              <span v-else class="text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2 text-center">
              <span v-if="c.is_active" class="text-success-600">✓</span>
              <span v-else class="text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2 text-right">
              <button @click="startEditCurrency(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs">{{ t('common.edit') }}</button>
              <button v-if="(c.invoices_count ?? 0) === 0" @click="removeCurrency(c)"
                class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs ml-2">{{ t('common.delete') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="px-5 py-3 border-t border-neutral-200 bg-neutral-50 text-xs text-neutral-600 flex flex-wrap gap-3 items-center">
      <span>{{ t('settings.add_another_account') }}</span>
      <button v-for="code in [...new Set(currencies.map(c => c.code))]" :key="code"
        @click="addCurrencyAccount(code)"
        class="cursor-pointer px-2 h-7 border border-neutral-300 rounded text-xs hover:bg-white">
        + {{ code }}
      </button>
    </div>
  </section>

  <!-- Modal — editace měny -->
  <div v-if="editingCurrency" class="fixed inset-0 bg-neutral-900/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-lg max-w-md w-full p-5">
      <h3 class="text-lg font-semibold mb-3">{{ t('settings.edit_currency_label_full', { label: editingCurrencyLabel }) }}</h3>
      <div class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.account_label_form') }}</label>
          <input v-model="currencyDraft.label" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.account_cz') }}</label>
          <div class="flex gap-2">
            <input v-model="currencyDraft.account_number" type="text" :placeholder="t('settings.account_number_placeholder')" class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <input v-model="currencyDraft.bank_code" type="text" :placeholder="t('settings.bank_code_placeholder')" class="w-20 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <p class="text-xs text-neutral-400 mt-1">{{ t('settings.account_number_hint') }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.bank_name') }}</label>
          <input v-model="currencyDraft.bank_name" type="text" :placeholder="t('settings.bank_name_placeholder')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.iban') }}</label>
          <input v-model="currencyDraft.iban" type="text" placeholder="CZ00 0000 0000 0000 0000 0000" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.bic') }}</label>
          <input v-model="currencyDraft.bic" type="text" placeholder="XXXXCZPP" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" />
        </div>
        <div class="flex items-center gap-4">
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="currencyDraft.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('settings.active') }}
          </label>
          <label class="flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="currencyDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('common.default') }}
          </label>
        </div>
      </div>
      <div class="flex justify-end gap-2 mt-4">
        <button @click="editingCurrency = null" class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
          {{ t('common.cancel') }}
        </button>
        <button @click="saveCurrency" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
          {{ t('common.save') }}
        </button>
      </div>
    </div>
  </div>
</template>
