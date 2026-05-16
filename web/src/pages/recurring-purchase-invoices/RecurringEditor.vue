<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import {
  recurringPurchaseInvoicesApi,
  type RecurringTemplatePayload,
  type RecurringTemplateItem,
} from '@/api/recurringPurchaseInvoices'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { useSupplierStore } from '@/stores/supplier'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const toast = useToast()

useHotkey('ctrl+s', (e) => { e.preventDefault(); submit() })

const supplierStore = useSupplierStore()
const route = useRoute()
const router = useRouter()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const templateId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loading = ref(false)
const submitting = ref(false)
const savedId = ref<number | null>(null)

const vatRates = ref<VatRate[]>([])
const currencies = ref<Currency[]>([])
const units = ref<Unit[]>([])

const supplierIsVatPayer = computed(() => supplierStore.currentSupplier?.is_vat_payer ?? true)

function defaultItemUnit(): string {
  return units.value.find(u => u.is_default)?.code || units.value[0]?.code || 'ks'
}

function defaultVatRateId(reverseCharge = false): number {
  if (!supplierIsVatPayer.value) {
    const zero = vatRates.value.find(v => Number(v.rate_percent) === 0 && !v.is_reverse_charge)
    if (zero) return zero.id
  }
  if (reverseCharge) {
    const rc = vatRates.value.find(v => v.is_reverse_charge)
    if (rc) return rc.id
  }
  const def = vatRates.value.find(v => v.is_default)
  return def?.id ?? vatRates.value[0]?.id ?? 0
}

const form = ref<RecurringTemplatePayload>({
  supplier_id: supplierStore.currentSupplier?.id ?? 0,
  project_id: null,
  name: '',
  frequency: 'monthly',
  day_of_month: null,
  end_of_month: false,
  anchor_date: today(),
  end_date: null,
  currency_id: 0,
  language: 'cs',
  payment_method: 'bank_transfer',
  reverse_charge: false,
  payment_due_days: 14,
  note_above_items: null,
  note_below_items: null,
  increment_month_in_descriptions: false,
  auto_issue: true,
  items: [],
})

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function blankItem(): RecurringTemplateItem {
  return {
    description: '',
    quantity: 1,
    unit: defaultItemUnit(),
    unit_price_without_vat: 0,
    vat_rate_id: defaultVatRateId(form.value.reverse_charge),
    order_index: form.value.items.length,
  }
}

function addItem() {
  form.value.items.push(blankItem())
}

function removeItem(index: number) {
  form.value.items.splice(index, 1)
  reindexItems()
}

function reindexItems() {
  form.value.items.forEach((item, i) => { item.order_index = i })
}

function itemTotal(item: RecurringTemplateItem, vatRate: VatRate | undefined): number {
  const withoutVat = item.quantity * item.unit_price_without_vat
  if (!vatRate) return withoutVat
  const rate = Number(vatRate.rate_percent) / 100
  return withoutVat * (1 + rate)
}

const grandTotal = computed(() => {
  return form.value.items.reduce((sum, item) => {
    const vr = vatRates.value.find(v => v.id === item.vat_rate_id)
    return sum + itemTotal(item, vr)
  }, 0)
})

const grandTotalWithoutVat = computed(() => {
  return form.value.items.reduce((sum, item) => sum + item.quantity * item.unit_price_without_vat, 0)
})

async function loadTemplate() {
  if (!templateId.value) return
  loading.value = true
  try {
    const tpl = await recurringPurchaseInvoicesApi.get(templateId.value)
    form.value = {
      supplier_id: tpl.supplier_id,
      project_id: tpl.project_id,
      name: tpl.name,
      frequency: tpl.frequency,
      day_of_month: tpl.day_of_month,
      end_of_month: tpl.end_of_month,
      anchor_date: tpl.anchor_date,
      end_date: tpl.end_date,
      currency_id: tpl.currency_id,
      language: tpl.language,
      payment_method: tpl.payment_method as RecurringTemplatePayload['payment_method'],
      reverse_charge: tpl.reverse_charge,
      payment_due_days: tpl.payment_due_days,
      note_above_items: tpl.note_above_items,
      note_below_items: tpl.note_below_items,
      increment_month_in_descriptions: tpl.increment_month_in_descriptions,
      auto_issue: tpl.auto_issue,
      items: tpl.items.map(i => ({
        description: i.description,
        quantity: i.quantity,
        unit: i.unit,
        unit_price_without_vat: i.unit_price_without_vat,
        vat_rate_id: i.vat_rate_id,
        order_index: i.order_index,
      })),
    }
    savedId.value = tpl.id
  } finally {
    loading.value = false
  }
}

async function submit() {
  if (!form.value.name.trim()) {
    toast.error(t('recurring_purchase.validation.name_required'))
    return
  }
  if (form.value.items.length === 0) {
    toast.error(t('recurring_purchase.validation.items_required'))
    return
  }
  submitting.value = true
  try {
    if (isEdit.value && templateId.value) {
      const updated = await recurringPurchaseInvoicesApi.update(templateId.value, form.value)
      toast.success(t('common.saved'))
      savedId.value = updated.id
    } else {
      const created = await recurringPurchaseInvoicesApi.create(form.value)
      toast.success(t('common.created'))
      savedId.value = created.id
      router.replace(`/recurring-purchase-invoices/${created.id}/edit`)
    }
  } catch (e: any) {
    const msg = apiErrorMessage(e, t('common.save_failed'))
    toast.error(msg)
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  const [vr, cur, un] = await Promise.all([
    codebooksApi.vatRates('CZ'),
    codebooksApi.currencies(),
    codebooksApi.units(),
  ])
  vatRates.value = vr
  currencies.value = cur
  units.value = un

  if (form.value.currency_id === 0) {
    const def = cur.find(c => c.is_default && c.code === 'CZK') || cur[0]
    if (def) form.value.currency_id = def.id
  }

  if (form.value.items.length === 0) {
    form.value.items.push(blankItem())
  }

  if (isEdit.value) {
    await loadTemplate()
  }
})

watch(() => form.value.reverse_charge, (val) => {
  if (val) {
    // when reverse charge is on, set all items to reverse charge VAT rate
    const rc = vatRates.value.find(v => v.is_reverse_charge)
    if (rc) {
      for (const item of form.value.items) {
        item.vat_rate_id = rc.id
      }
    }
  }
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <RouterLink to="/recurring-purchase-invoices" class="text-sm text-neutral-600 hover:text-neutral-900">
          {{ t('recurring_purchase.back_to_list') }}
        </RouterLink>
        <h1 class="text-2xl font-semibold mt-1">
          {{ isEdit ? t('recurring_purchase.edit_title') : t('recurring_purchase.new_title') }}
        </h1>
      </div>
      <div class="flex items-center gap-2">
        <RouterLink
          v-if="savedId"
          :to="`/recurring-purchase-invoices/${savedId}`"
          class="inline-flex items-center gap-1.5 h-9 px-3 border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-sm font-medium rounded-md">
          {{ t('recurring_purchase.view_template') }}
        </RouterLink>
        <button
          @click="submit"
          :disabled="submitting"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white text-sm font-medium rounded-md">
          {{ submitting ? '…' : t('common.save') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Main settings -->
      <div class="lg:col-span-2 space-y-4">
        <!-- Basic info -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('recurring_purchase.basic_settings') }}</h2>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.name') }} *</label>
              <input v-model="form.name" type="text"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
                :placeholder="t('recurring_purchase.name_placeholder')" />
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.frequency') }}</label>
                <select v-model="form.frequency"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
                  <option value="monthly">{{ t('recurring_purchase.frequency.monthly') }}</option>
                  <option value="quarterly">{{ t('recurring_purchase.frequency.quarterly') }}</option>
                  <option value="semi_annually">{{ t('recurring_purchase.frequency.semi_annually') }}</option>
                  <option value="annually">{{ t('recurring_purchase.frequency.annually') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.currency') }}</label>
                <select v-model="form.currency_id"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
                  <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }} ({{ c.name }})</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.anchor_date') }} *</label>
                <input v-model="form.anchor_date" type="date"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.end_date') }}</label>
                <input v-model="form.end_date" type="date"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.day_of_month') }}</label>
                <div class="flex items-center gap-2">
                  <input v-model.number="form.day_of_month" type="number" min="1" max="28" placeholder="1–28"
                    :disabled="form.end_of_month"
                    class="flex-1 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none disabled:opacity-50" />
                  <label class="flex items-center gap-1.5 text-sm text-neutral-700">
                    <input v-model="form.end_of_month" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                    {{ t('recurring_purchase.end_of_month') }}
                  </label>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.payment_due_days') }}</label>
                <input v-model.number="form.payment_due_days" type="number" min="0"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.language') }}</label>
                <select v-model="form.language"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
                  <option value="cs">Čeština</option>
                  <option value="en">English</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.payment_method') }}</label>
                <select v-model="form.payment_method"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
                  <option value="bank_transfer">{{ t('recurring_purchase.payment_method.bank_transfer') }}</option>
                  <option value="card">{{ t('recurring_purchase.payment_method.card') }}</option>
                  <option value="cash">{{ t('recurring_purchase.payment_method.cash') }}</option>
                  <option value="other">{{ t('recurring_purchase.payment_method.other') }}</option>
                </select>
              </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="form.reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('recurring_purchase.reverse_charge') }}
            </label>
            <label class="flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="form.increment_month_in_descriptions" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('recurring_purchase.increment_month_in_descriptions') }}
            </label>
            <label class="flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="form.auto_issue" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('recurring_purchase.auto_issue') }}
            </label>
          </div>
        </div>

        <!-- Items -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring_purchase.items') }}</h2>
            <button @click="addItem"
              class="cursor-pointer inline-flex items-center gap-1 h-8 px-3 bg-primary-600 hover:bg-primary-700 text-white text-xs font-medium rounded-md">
              + {{ t('recurring_purchase.add_item') }}
            </button>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-4 py-2 text-left font-medium w-8">#</th>
                  <th class="px-4 py-2 text-left font-medium">{{ t('recurring_purchase.item_description') }}</th>
                  <th class="px-4 py-2 text-right font-medium w-24">{{ t('recurring_purchase.item_qty') }}</th>
                  <th class="px-4 py-2 text-left font-medium w-20">{{ t('recurring_purchase.item_unit') }}</th>
                  <th class="px-4 py-2 text-right font-medium w-28">{{ t('recurring_purchase.item_unit_price') }}</th>
                  <th class="px-4 py-2 text-center font-medium w-28">{{ t('recurring_purchase.item_vat') }}</th>
                  <th class="px-4 py-2 text-right font-medium w-28">{{ t('recurring_purchase.item_total') }}</th>
                  <th class="px-4 py-2 w-10"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(item, i) in form.items" :key="i">
                  <td class="px-4 py-2 text-neutral-400 text-xs">{{ i + 1 }}</td>
                  <td class="px-4 py-2">
                    <input v-model="item.description" type="text"
                      class="w-full h-8 px-2 border border-neutral-300 rounded text-sm focus:ring-1 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
                      :placeholder="t('recurring_purchase.item_description_placeholder')" />
                  </td>
                  <td class="px-4 py-2">
                    <input v-model.number="item.quantity" type="number" min="0" step="0.01"
                      class="w-full h-8 px-2 border border-neutral-300 rounded text-sm text-right focus:ring-1 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                  </td>
                  <td class="px-4 py-2">
                    <select v-model="item.unit"
                      class="w-full h-8 px-2 border border-neutral-300 rounded text-sm bg-white focus:ring-1 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                      <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                    </select>
                  </td>
                  <td class="px-4 py-2">
                    <input v-model.number="item.unit_price_without_vat" type="number" min="0" step="0.01"
                      class="w-full h-8 px-2 border border-neutral-300 rounded text-sm text-right focus:ring-1 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                  </td>
                  <td class="px-4 py-2">
                    <select v-model="item.vat_rate_id"
                      class="w-full h-8 px-2 border border-neutral-300 rounded text-sm bg-white focus:ring-1 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                      <option v-for="vr in vatRates" :key="vr.id" :value="vr.id">
                        {{ Number(vr.rate_percent) > 0 ? `${vr.rate_percent}%` : (vr.is_reverse_charge ? t('recurring_purchase.reverse_charge') : '0%') }}
                      </option>
                    </select>
                  </td>
                  <td class="px-4 py-2 text-right font-mono text-sm">
                    {{ formatMoney(itemTotal(item, vatRates.find(v => v.id === item.vat_rate_id)), currencies.find(c => c.id === form.currency_id)?.code || 'CZK') }}
                  </td>
                  <td class="px-4 py-2 text-center">
                    <button @click="removeItem(i)"
                      class="cursor-pointer p-1 text-neutral-400 hover:text-danger-600">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50 border-t border-neutral-200">
                <tr>
                  <td colspan="6" class="px-4 py-2 text-right text-sm font-medium text-neutral-600">{{ t('recurring_purchase.total_without_vat') }}:</td>
                  <td class="px-4 py-2 text-right font-mono text-sm">{{ formatMoney(grandTotalWithoutVat, currencies.find(c => c.id === form.currency_id)?.code || 'CZK') }}</td>
                  <td></td>
                </tr>
                <tr>
                  <td colspan="6" class="px-4 py-2 text-right text-sm font-semibold text-neutral-900">{{ t('recurring_purchase.total_with_vat') }}:</td>
                  <td class="px-4 py-2 text-right font-mono text-sm font-semibold">{{ formatMoney(grandTotal, currencies.find(c => c.id === form.currency_id)?.code || 'CZK') }}</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <!-- Notes -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('recurring_purchase.notes') }}</h2>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.note_above_items') }}</label>
              <textarea v-model="form.note_above_items" rows="2"
                class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none resize-none"></textarea>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring_purchase.note_below_items') }}</label>
              <textarea v-model="form.note_below_items" rows="2"
                class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none resize-none"></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div class="space-y-4">
        <!-- Summary -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring_purchase.summary') }}</h2>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between">
              <dt class="text-neutral-500">{{ t('recurring_purchase.status') }}</dt>
              <dd class="font-medium">{{ isEdit ? '—' : t('recurring_purchase.status.active') }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-neutral-500">{{ t('recurring_purchase.next_run') }}</dt>
              <dd class="font-medium">{{ formatDate(form.anchor_date) }}</dd>
            </div>
            <div class="flex justify-between">
              <dt class="text-neutral-500">{{ t('recurring_purchase.items_count') }}</dt>
              <dd class="font-medium">{{ form.items.length }}</dd>
            </div>
          </dl>
        </div>

        <!-- Quick info -->
        <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-5">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('recurring_purchase.how_it_works') }}</h2>
          <p class="text-xs text-neutral-600 leading-relaxed">
            {{ t('recurring_purchase.how_it_works_description') }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { formatDate } from '@/composables/useFormat'
</script>
