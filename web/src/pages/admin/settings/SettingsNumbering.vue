<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { settingsApi, type Supplier } from '@/api/settings'
import { renderVarsymbolTemplate, hasCounterPlaceholder } from '@/utils/varsymbol'

const { t } = useI18n()
const toast = useToast()

const props = defineProps<{ supplier: Supplier }>()

function validateAndPreview(template: string | null) {
  const tmpl = (template ?? '').trim()
  if (tmpl === '') return { error: '', preview: '' }
  if (!hasCounterPlaceholder(tmpl)) return { error: t('settings.numbering_must_have_counter'), preview: '' }
  return { error: '', preview: renderVarsymbolTemplate(tmpl, new Date(), 1) }
}

const invoicePreview        = computed(() => validateAndPreview(props.supplier?.invoice_number_format ?? null).preview)
const invoiceFormatError    = computed(() => validateAndPreview(props.supplier?.invoice_number_format ?? null).error)
const proformaPreview       = computed(() => validateAndPreview(props.supplier?.proforma_number_format ?? null).preview)
const proformaFormatError   = computed(() => validateAndPreview(props.supplier?.proforma_number_format ?? null).error)
const creditNotePreview     = computed(() => validateAndPreview(props.supplier?.credit_note_number_format ?? null).preview)
const creditNoteFormatError = computed(() => validateAndPreview(props.supplier?.credit_note_number_format ?? null).error)

async function save() {
  const errs = [invoiceFormatError.value, proformaFormatError.value, creditNoteFormatError.value].filter(Boolean)
  if (errs.length > 0) {
    toast.error(errs[0])
    return
  }
  try {
    const updated = await settingsApi.updateSupplier({
      invoice_number_format:    props.supplier.invoice_number_format,
      proforma_number_format:   props.supplier.proforma_number_format,
      credit_note_number_format: props.supplier.credit_note_number_format,
      invoice_number_period:    props.supplier.invoice_number_period,
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
    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.numbering_section') }}</h2>
    <p class="text-xs text-neutral-500 mb-1">{{ t('settings.numbering_hint_intro') }}</p>
    <ul class="text-xs text-neutral-500 mb-3 space-y-0.5 ml-2">
      <li><code class="bg-neutral-100 px-1 rounded">{YYYY}</code> &mdash; {{ t('settings.numbering_hint_yyyy') }} <span class="text-neutral-400">(2026)</span></li>
      <li><code class="bg-neutral-100 px-1 rounded">{YY}</code> &mdash; {{ t('settings.numbering_hint_yy') }} <span class="text-neutral-400">(26)</span></li>
      <li><code class="bg-neutral-100 px-1 rounded">{MM}</code> &mdash; {{ t('settings.numbering_hint_mm') }} <span class="text-neutral-400">(05)</span></li>
      <li><code class="bg-neutral-100 px-1 rounded">{CC}</code>, <code class="bg-neutral-100 px-1 rounded">{CCC}</code>&hellip; &mdash; {{ t('settings.numbering_hint_c') }} <span class="text-neutral-400">(01, 001…)</span></li>
    </ul>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.invoice_number_format') }}</label>
        <input v-model="supplier.invoice_number_format" type="text"
          :placeholder="supplier.cfg_varsymbol_fallback?.invoice || '{YY}{MM}{CCC}'" maxlength="60"
          class="w-full h-9 px-3 border rounded-md text-sm font-mono"
          :class="invoiceFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
        <p v-if="invoiceFormatError" class="text-xs text-danger-500 mt-1">{{ invoiceFormatError }}</p>
        <p v-else-if="invoicePreview" class="text-xs text-success-600 mt-1">
          {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ invoicePreview }}</code>
        </p>
        <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.invoice_number_period') }}</label>
        <select v-model="supplier.invoice_number_period" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm">
          <option value="year">{{ t('settings.numbering_period_year') }}</option>
          <option value="month">{{ t('settings.numbering_period_month') }}</option>
          <option value="none">{{ t('settings.numbering_period_none') }}</option>
        </select>
        <p class="text-xs text-neutral-400 mt-1">{{ t('settings.invoice_number_period_hint') }}</p>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.proforma_number_format') }}</label>
        <input v-model="supplier.proforma_number_format" type="text"
          :placeholder="supplier.cfg_varsymbol_fallback?.proforma || '9{YY}{MM}{CCC}'" maxlength="60"
          class="w-full h-9 px-3 border rounded-md text-sm font-mono"
          :class="proformaFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
        <p v-if="proformaFormatError" class="text-xs text-danger-500 mt-1">{{ proformaFormatError }}</p>
        <p v-else-if="proformaPreview" class="text-xs text-success-600 mt-1">
          {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ proformaPreview }}</code>
        </p>
        <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.credit_note_number_format') }}</label>
        <input v-model="supplier.credit_note_number_format" type="text"
          :placeholder="supplier.cfg_varsymbol_fallback?.credit_note || '7{YY}{MM}{CCC}'" maxlength="60"
          class="w-full h-9 px-3 border rounded-md text-sm font-mono"
          :class="creditNoteFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
        <p v-if="creditNoteFormatError" class="text-xs text-danger-500 mt-1">{{ creditNoteFormatError }}</p>
        <p v-else-if="creditNotePreview" class="text-xs text-success-600 mt-1">
          {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ creditNotePreview }}</code>
        </p>
        <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
      </div>
    </div>

    <div class="mt-4 flex justify-end">
      <button @click="save" class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
        {{ t('settings.save_supplier') }}
      </button>
    </div>
  </section>
</template>
