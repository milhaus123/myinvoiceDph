<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { itemsApi, type Item, type StockMovement } from '@/api/items'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()

const id = Number(route.params.id)

const item = ref<Item | null>(null)
const history = ref<StockMovement[]>([])
const loading = ref(true)
const error = ref('')

const stockModal = ref<'in' | 'out' | null>(null)
const stockForm = ref({ quantity: 0, note: '' })
const stockSaving = ref(false)
const stockError = ref('')

const isLowStock = computed(() => item.value ? item.value.stock_quantity < item.value.min_stock_alert : false)

onMounted(async () => {
  try {
    const r = await itemsApi.stockHistory(id)
    item.value = r.item
    history.value = r.history
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.not_found')
  } finally {
    loading.value = false
  }
})

async function doStockAdjust() {
  if (!item.value || stockForm.value.quantity <= 0) return
  stockSaving.value = true
  stockError.value = ''
  try {
    if (stockModal.value === 'in') {
      await itemsApi.stockIn(item.value.id, { quantity: stockForm.value.quantity, note: stockForm.value.note })
    } else {
      await itemsApi.stockOut(item.value.id, { quantity: stockForm.value.quantity, note: stockForm.value.note })
    }
    stockModal.value = null
    stockForm.value = { quantity: 0, note: '' }
    // Reload
    const r = await itemsApi.stockHistory(id)
    item.value = r.item
    history.value = r.history
  } catch (e: any) {
    stockError.value = e?.response?.data?.error?.message || t('errors.save_failed')
  } finally {
    stockSaving.value = false
  }
}

async function deleteItem() {
  if (!item.value) return
  if (!confirm(t('item.delete_confirm'))) return
  try {
    await itemsApi.delete(item.value.id)
    router.push('/items')
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.delete_failed')
  }
}

function movementLabel(type: StockMovement['movement_type']): string {
  return t(`item.movement_${type}`)
}

function formatDate(d: string): string {
  return new Date(d).toLocaleString('cs-CZ')
}
</script>

<template>
  <div>
    <div class="flex items-center gap-3 mb-6">
      <button @click="router.push('/items')" class="text-neutral-500 hover:text-neutral-700">← {{ t('item.back_to_list') }}</button>
    </div>

    <div v-if="loading" class="text-neutral-500">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">{{ error }}</div>

    <div v-else-if="item" class="space-y-4">
      <!-- Header card -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-6">
        <div class="flex items-start justify-between mb-4">
          <div>
            <div class="flex items-center gap-3">
              <h1 class="text-2xl font-semibold">{{ item.name }}</h1>
              <span v-if="isLowStock" class="inline-flex items-center gap-1 px-2 py-0.5 bg-red-50 text-red-700 text-xs font-medium rounded-full">
                ⚠ {{ t('item.low_stock') }}
              </span>
            </div>
            <div class="text-sm text-neutral-500 mt-1">
              <span class="font-mono">{{ item.sku }}</span>
              <span class="mx-2">·</span>
              <span>{{ item.unit }}</span>
            </div>
          </div>
          <div class="flex gap-2">
            <RouterLink :to="`/items/${item.id}/edit`"
              class="h-9 px-3 border border-neutral-300 hover:bg-neutral-50 text-neutral-700 text-sm font-medium rounded-md flex items-center">
              {{ t('common.edit') }}
            </RouterLink>
            <button @click="deleteItem"
              class="h-9 px-3 border border-red-200 hover:bg-red-50 text-red-600 text-sm font-medium rounded-md">
              {{ t('common.delete') }}
            </button>
          </div>
        </div>

        <p v-if="item.description" class="text-neutral-600 text-sm mb-4">{{ item.description }}</p>

        <div class="grid grid-cols-3 gap-4">
          <div class="bg-neutral-50 rounded-lg p-4 text-center">
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('item.stock_quantity') }}</div>
            <div class="text-2xl font-mono font-semibold" :class="isLowStock ? 'text-red-600' : 'text-neutral-900'">
              {{ item.stock_quantity.toLocaleString('cs-CZ') }}
            </div>
            <div class="text-xs text-neutral-400 mt-1">{{ item.unit }}</div>
          </div>
          <div class="bg-neutral-50 rounded-lg p-4 text-center">
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('item.low_stock_threshold') }}</div>
            <div class="text-2xl font-mono text-neutral-500">{{ item.min_stock_alert.toLocaleString('cs-CZ') }}</div>
          </div>
          <div class="bg-neutral-50 rounded-lg p-4 text-center">
            <div class="text-xs text-neutral-500 uppercase tracking-wide mb-1">{{ t('item.stock_actions') }}</div>
            <div class="flex gap-2 justify-center mt-1">
              <button @click="stockModal = 'in'"
                class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded-md">
                {{ t('item.stock_in') }}
              </button>
              <button @click="stockModal = 'out'"
                class="px-3 py-1 bg-orange-600 hover:bg-orange-700 text-white text-xs font-medium rounded-md">
                {{ t('item.stock_out') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Stock history -->
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
        <div class="px-4 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h2 class="font-semibold text-neutral-900">{{ t('item.stock_history') }}</h2>
          <RouterLink to="/stock-movements" class="text-xs text-primary-600 hover:underline">{{ t('item.all_movements') }} →</RouterLink>
        </div>

        <div v-if="history.length === 0" class="p-8 text-center text-neutral-500 text-sm">
          {{ t('item.no_history') }}
        </div>

        <table v-else class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
            <tr>
              <th class="text-left px-4 py-2 font-medium">{{ t('item.movement') }}</th>
              <th class="text-right px-4 py-2 font-medium">{{ t('item.quantity') }}</th>
              <th class="text-right px-4 py-2 font-medium">{{ t('item.stock_before') }}</th>
              <th class="text-right px-4 py-2 font-medium">{{ t('item.stock_after') }}</th>
              <th class="text-left px-4 py-2 font-medium">{{ t('item.note') }}</th>
              <th class="text-left px-4 py-2 font-medium">{{ t('common.date') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="m in history" :key="m.id">
              <td class="px-4 py-2.5">
                <span class="inline-block px-2 py-0.5 text-xs font-medium rounded"
                  :class="{
                    'bg-green-50 text-green-700': m.movement_type === 'stock_in',
                    'bg-orange-50 text-orange-700': m.movement_type === 'stock_out',
                    'bg-blue-50 text-blue-700': m.movement_type === 'adjustment',
                  }">
                  {{ movementLabel(m.movement_type) }}
                </span>
              </td>
              <td class="px-4 py-2.5 text-right font-mono font-medium">
                <span :class="m.movement_type === 'stock_in' ? 'text-green-600' : m.movement_type === 'stock_out' ? 'text-orange-600' : 'text-blue-600'">
                  {{ m.movement_type === 'stock_in' ? '+' : m.movement_type === 'stock_out' ? '−' : '±' }}{{ m.quantity.toLocaleString('cs-CZ') }}
                </span>
              </td>
              <td class="px-4 py-2.5 text-right font-mono text-neutral-500">{{ m.stock_before.toLocaleString('cs-CZ') }}</td>
              <td class="px-4 py-2.5 text-right font-mono text-neutral-500">{{ m.stock_after.toLocaleString('cs-CZ') }}</td>
              <td class="px-4 py-2.5 text-neutral-600 text-xs max-w-xs truncate">{{ m.note || '—' }}</td>
              <td class="px-4 py-2.5 text-neutral-500 text-xs">{{ formatDate(m.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Stock adjustment modal -->
    <div v-if="stockModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-6">
        <h3 class="text-lg font-semibold mb-4">{{ stockModal === 'in' ? t('item.stock_in') : t('item.stock_out') }}</h3>

        <div v-if="stockError" class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-md">{{ stockError }}</div>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('item.quantity') }} *</label>
            <input v-model.number="stockForm.quantity" type="number" min="0.0001" step="0.0001"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('item.note') }}</label>
            <input v-model="stockForm.note" type="text"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm outline-none focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500" />
          </div>
        </div>

        <div class="flex gap-3 mt-6">
          <button @click="doStockAdjust" :disabled="stockSaving || stockForm.quantity <= 0"
            class="flex-1 h-9 bg-primary-600 hover:bg-primary-700 disabled:bg-primary-300 text-white text-sm font-medium rounded-md">
            {{ stockSaving ? '…' : t('common.save') }}
          </button>
          <button @click="stockModal = null"
            class="flex-1 h-9 border border-neutral-300 hover:bg-neutral-50 text-neutral-700 text-sm font-medium rounded-md">
            {{ t('common.cancel') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
