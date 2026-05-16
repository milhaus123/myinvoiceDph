<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { itemsApi, type Item, type StockMovement } from '@/api/items'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'

const { t } = useI18n()
const router = useRouter()

// We show all items with their latest movements
const items = ref<Item[]>([])
const movements = ref<Array<{ item: Item; movement: StockMovement }>>([])
const loading = ref(true)

async function load() {
  loading.value = true
  try {
    const r = await itemsApi.list({ sort: 'updated_at', dir: 'desc' })
    items.value = r.data

    const allMovements: Array<{ item: Item; movement: StockMovement }> = []
    for (const item of r.data.slice(0, 50)) {
      try {
        const hist = await itemsApi.stockHistory(item.id, { limit: 5 })
        for (const m of hist.history) {
          allMovements.push({ item, movement: m })
        }
      } catch {
        // skip items without history
      }
    }
    // Sort by most recent first
    allMovements.sort((a, b) => new Date(b.movement.created_at).getTime() - new Date(a.movement.created_at).getTime())
    movements.value = allMovements
  } finally {
    loading.value = false
  }
}

onMounted(() => load())

function openItem(id: number) {
  router.push(`/items/${id}`)
}

function formatDate(d: string): string {
  return new Date(d).toLocaleString('cs-CZ')
}

function movementLabel(type: StockMovement['movement_type']): string {
  return t(`item.movement_${type}`)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('item.stock_movements') }}</h1>
      <RouterLink to="/items"
        class="inline-flex items-center gap-1.5 h-9 px-3 border border-neutral-300 hover:bg-neutral-50 text-neutral-700 text-sm font-medium rounded-md">
        {{ t('item.back_to_items') }}
      </RouterLink>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-4 py-3 border-b border-neutral-200 flex items-center justify-between">
        <span class="text-sm text-neutral-600">{{ t('item.movements_description') }}</span>
      </div>

      <TableSkeleton v-if="loading" :rows="8" :cols="5" />

      <div v-else-if="movements.length === 0" class="p-8 text-center text-neutral-500 text-sm">
        {{ t('item.no_history') }}
      </div>

      <table v-else class="w-full text-sm">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('item.sku') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('item.name') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('item.movement') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('item.quantity') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('item.stock_after') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('item.note') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.date') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr
            v-for="{ item, movement } in movements"
            :key="movement.id"
            @click="openItem(item.id)"
            class="cursor-pointer hover:bg-neutral-50"
          >
            <td class="px-4 py-2.5">
              <span class="font-mono text-xs text-neutral-600">{{ item.sku }}</span>
            </td>
            <td class="px-4 py-2.5 font-medium text-neutral-900">{{ item.name }}</td>
            <td class="px-4 py-2.5">
              <span class="inline-block px-2 py-0.5 text-xs font-medium rounded"
                :class="{
                  'bg-green-50 text-green-700': movement.movement_type === 'stock_in',
                  'bg-orange-50 text-orange-700': movement.movement_type === 'stock_out',
                  'bg-blue-50 text-blue-700': movement.movement_type === 'adjustment',
                }">
                {{ movementLabel(movement.movement_type) }}
              </span>
            </td>
            <td class="px-4 py-2.5 text-right font-mono font-medium">
              <span :class="movement.movement_type === 'stock_in' ? 'text-green-600' : movement.movement_type === 'stock_out' ? 'text-orange-600' : 'text-blue-600'">
                {{ movement.movement_type === 'stock_in' ? '+' : movement.movement_type === 'stock_out' ? '−' : '±' }}{{ movement.quantity.toLocaleString('cs-CZ') }}
              </span>
            </td>
            <td class="px-4 py-2.5 text-right font-mono text-neutral-500">{{ movement.stock_after.toLocaleString('cs-CZ') }}</td>
            <td class="px-4 py-2.5 text-neutral-600 text-xs max-w-xs truncate">{{ movement.note || '—' }}</td>
            <td class="px-4 py-2.5 text-neutral-500 text-xs">{{ formatDate(movement.created_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
