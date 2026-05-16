<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { itemsApi, type Item } from '@/api/items'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()

const isEdit = !!route.params.id
const itemId = isEdit ? Number(route.params.id) : null

const loading = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
  sku: '',
  name: '',
  description: '',
  unit: 'ks',
  stock_quantity: 0,
  min_stock_alert: 0,
})

const errors = ref<Record<string, string>>({})

onMounted(async () => {
  if (isEdit && itemId) {
    loading.value = true
    try {
      const item = await itemsApi.get(itemId)
      form.value = {
        sku: item.sku,
        name: item.name,
        description: item.description ?? '',
        unit: item.unit,
        stock_quantity: item.stock_quantity,
        min_stock_alert: item.min_stock_alert,
      }
    } catch (e: any) {
      error.value = e?.response?.data?.error?.message || t('errors.not_found')
    } finally {
      loading.value = false
    }
  }
})

async function save() {
  errors.value = {}
  if (!form.value.sku.trim()) errors.value.sku = t('validation.required')
  if (!form.value.name.trim()) errors.value.name = t('validation.required')

  if (Object.keys(errors.value).length > 0) return

  saving.value = true
  error.value = ''
  try {
    if (isEdit && itemId) {
      await itemsApi.update(itemId, {
        sku: form.value.sku,
        name: form.value.name,
        description: form.value.description || null,
        unit: form.value.unit,
        min_stock_alert: form.value.min_stock_alert,
      })
    } else {
      await itemsApi.create({
        sku: form.value.sku,
        name: form.value.name,
        description: form.value.description || null,
        unit: form.value.unit,
        stock_quantity: form.value.stock_quantity,
        min_stock_alert: form.value.min_stock_alert,
      })
    }
    router.push('/items')
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.save_failed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div class="flex items-center gap-3 mb-6">
      <button @click="router.back()" class="text-neutral-500 hover:text-neutral-700">←</button>
      <h1 class="text-2xl font-semibold">{{ isEdit ? t('item.edit') : t('item.new') }}</h1>
    </div>

    <div v-if="loading" class="text-neutral-500">{{ t('common.loading') }}…</div>

    <div v-else class="bg-white border border-neutral-200 rounded-lg shadow-sm p-6 max-w-2xl">
      <div v-if="error" class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-md">{{ error }}</div>

      <form @submit.prevent="save" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('item.sku') }} *</label>
            <input v-model="form.sku" type="text"
              class="w-full h-9 px-3 border rounded-md text-sm outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
              :class="errors.sku ? 'border-red-400' : 'border-neutral-300'" />
            <div v-if="errors.sku" class="text-xs text-red-600 mt-1">{{ errors.sku }}</div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('item.unit') }}</label>
            <input v-model="form.unit" type="text" placeholder="ks"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('item.name') }} *</label>
          <input v-model="form.name" type="text"
            class="w-full h-9 px-3 border rounded-md text-sm outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
            :class="errors.name ? 'border-red-400' : 'border-neutral-300'" />
          <div v-if="errors.name" class="text-xs text-red-600 mt-1">{{ errors.name }}</div>
        </div>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('item.description') }}</label>
          <textarea v-model="form.description" rows="3"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 resize-none"></textarea>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('item.stock_quantity') }}</label>
            <input v-model.number="form.stock_quantity" type="number" min="0" step="0.0001"
              :disabled="isEdit"
              class="w-full h-9 px-3 border rounded-md text-sm outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 disabled:bg-neutral-50 disabled:text-neutral-500"
              :class="isEdit ? 'border-neutral-200' : 'border-neutral-300'" />
            <div v-if="isEdit" class="text-xs text-neutral-500 mt-1">{{ t('item.stock_edit_hint') }}</div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('item.low_stock_threshold') }}</label>
            <input v-model.number="form.min_stock_alert" type="number" min="0" step="0.0001"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
          </div>
        </div>

        <div class="flex items-center gap-3 pt-4 border-t border-neutral-200">
          <button type="submit" :disabled="saving"
            class="h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-primary-300 text-white text-sm font-medium rounded-md">
            {{ saving ? t('common.saving') + '…' : t('common.save') }}
          </button>
          <button type="button" @click="router.back()"
            class="h-9 px-4 border border-neutral-300 hover:bg-neutral-50 text-neutral-700 text-sm font-medium rounded-md">
            {{ t('common.cancel') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
