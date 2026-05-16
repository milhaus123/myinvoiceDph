<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { itemsApi, type Item } from '@/api/items'

const { t } = useI18n()
const router = useRouter()

const lowStockItems = ref<Item[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    const r = await itemsApi.lowStock()
    lowStockItems.value = r.data
  } catch {
    // silently fail — dashboard should not break
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div v-if="!loading && lowStockItems.length > 0" class="bg-white border border-red-200 rounded-lg shadow-sm overflow-hidden">
    <header class="px-5 py-3 border-b border-red-100 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="text-red-500">⚠</span>
        <h3 class="font-semibold text-red-700">{{ t('item.low_stock_alert') }}</h3>
      </div>
      <RouterLink to="/items"
        class="text-xs text-red-600 hover:underline font-medium">
        {{ t('item.view_all') }} →
      </RouterLink>
    </header>

    <div class="divide-y divide-red-50">
      <div
        v-for="item in lowStockItems.slice(0, 5)"
        :key="item.id"
        @click="router.push(`/items/${item.id}`)"
        class="flex items-center justify-between px-5 py-2.5 cursor-pointer hover:bg-red-50/50"
      >
        <div class="min-w-0">
          <div class="font-medium text-neutral-900 text-sm truncate">{{ item.name }}</div>
          <div class="text-xs text-neutral-500 font-mono">{{ item.sku }}</div>
        </div>
        <div class="text-right ml-4 shrink-0">
          <div class="font-mono font-semibold text-red-600 text-sm">
            {{ item.stock_quantity.toLocaleString('cs-CZ') }}
          </div>
          <div class="text-xs text-neutral-400">
            {{ t('item.of') }} {{ item.min_stock_alert.toLocaleString('cs-CZ') }}
          </div>
        </div>
      </div>
    </div>

    <div v-if="lowStockItems.length > 5" class="px-5 py-2 text-xs text-neutral-500 text-center border-t border-red-100">
      +{{ lowStockItems.length - 5 }} {{ t('item.more_low_stock') }}
    </div>
  </div>
</template>
