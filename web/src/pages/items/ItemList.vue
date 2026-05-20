<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { itemsApi, type Item } from '@/api/items'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const router = useRouter()

const items = ref<Item[]>([])
const loading = ref(false)
const search = ref('')
const sort = ref<'name' | 'sku' | 'stock_quantity' | 'updated_at'>('name')
let searchTimeout: ReturnType<typeof setTimeout> | null = null

async function load() {
  loading.value = true
  try {
    const r = await itemsApi.list({ sort: sort.value, dir: 'asc' })
    let data = r.data
    if (search.value) {
      const q = search.value.toLowerCase()
      data = data.filter(i =>
        i.name.toLowerCase().includes(q) ||
        i.sku.toLowerCase().includes(q) ||
        (i.description ?? '').toLowerCase().includes(q)
      )
    }
    items.value = data
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
watch(sort, () => load())
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => load(), 300)
})

function openItem(item: Item) {
  router.push(`/items/${item.id}`)
}

function isLowStock(item: Item): boolean {
  return item.stock_quantity < item.min_stock_alert
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('item.title') }}</h1>
      <RouterLink
        to="/items/new"
        class="inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        {{ t('item.new') }}
      </RouterLink>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-4 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center gap-3">
        <input
          v-model="search"
          type="search"
          :placeholder="t('common.search')"
          class="flex-1 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <select v-model="sort" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="name">{{ t('item.sort_name') }}</option>
          <option value="sku">{{ t('item.sort_sku') }}</option>
          <option value="stock_quantity">{{ t('item.sort_stock') }}</option>
          <option value="updated_at">{{ t('item.sort_updated') }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="5" />

      <EmptyState v-else-if="!items.length"
        :title="t('item.no_data')"
        :cta="t('item.create_first')"
        to="/items/new" />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
            <tr>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('item.sku') }}</th>
              <th class="text-left px-4 py-2.5 font-medium">{{ t('item.name') }}</th>
              <th class="text-center px-4 py-2.5 font-medium">{{ t('item.unit') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('item.stock_quantity') }}</th>
              <th class="text-right px-4 py-2.5 font-medium">{{ t('item.low_stock_threshold') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr
              v-for="item in items"
              :key="item.id"
              @click="openItem(item)"
              class="cursor-pointer hover:bg-neutral-50"
            >
              <td class="px-4 py-3">
                <span class="font-mono text-xs text-neutral-600">{{ item.sku }}</span>
              </td>
              <td class="px-4 py-3">
                <div class="font-medium text-neutral-900">{{ item.name }}</div>
                <div v-if="item.description" class="text-xs text-neutral-500 mt-0.5 truncate max-w-xs">{{ item.description }}</div>
              </td>
              <td class="px-4 py-3 text-center text-neutral-600">{{ item.unit }}</td>
              <td class="px-4 py-3 text-right">
                <span
                  class="inline-flex items-center gap-1 font-mono"
                  :class="isLowStock(item) ? 'text-red-600 font-semibold' : 'text-neutral-900'"
                >
                  {{ item.stock_quantity.toLocaleString('cs-CZ') }}
                  <span v-if="isLowStock(item)" class="text-xs">⚠</span>
                </span>
              </td>
              <td class="px-4 py-3 text-right font-mono text-neutral-500 text-xs">
                {{ item.min_stock_alert.toLocaleString('cs-CZ') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="!loading && items.length > 0" class="px-4 py-3 border-t border-neutral-200 text-xs text-neutral-500">
        {{ t('item.count', { n: items.length }) }}
        <RouterLink to="/stock-movements" class="ml-2 text-primary-600 hover:underline">{{ t('item.stock_movements') }} →</RouterLink>
      </div>
    </div>
  </div>
</template>
