<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  recurringInvoicesApi,
  type RecurringTemplate,
} from '@/api/recurringInvoices'
import { formatDate } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/recurring-invoices/new') })

const router = useRouter()

const templates = ref<RecurringTemplate[]>([])
const loading = ref(true)
const statusFilter = ref<string>('')

function frequencyLabel(freq: string): string {
  const labels: Record<string, string> = {
    monthly: t('recurring_invoice.frequency.monthly'),
    quarterly: t('recurring_invoice.frequency.quarterly'),
    semi_annually: t('recurring_invoice.frequency.semi_annually'),
    annually: t('recurring_invoice.frequency.annually'),
  }
  return labels[freq] ?? freq
}

function statusBadgeClass(status: string): string {
  const classes: Record<string, string> = {
    active:  'bg-success-50 text-success-600',
    paused:  'bg-warning-50 text-warning-600',
    expired: 'bg-neutral-100 text-neutral-400',
  }
  return classes[status] ?? 'bg-neutral-100 text-neutral-600'
}

function isDue(nextRunDate: string, status: string): boolean {
  if (status !== 'active') return false
  return new Date(nextRunDate) <= new Date()
}

async function load() {
  loading.value = true
  try {
    const result = await recurringInvoicesApi.list(
      statusFilter.value ? { status: statusFilter.value } : {}
    )
    templates.value = result.data
  } finally {
    loading.value = false
  }
}

async function pauseTemplate(id: number) {
  try {
    await recurringInvoicesApi.pause(id)
    toast.success(t('recurring_invoice.paused'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.save_failed'))
  }
}

async function resumeTemplate(id: number) {
  try {
    await recurringInvoicesApi.resume(id)
    toast.success(t('recurring_invoice.resumed'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.save_failed'))
  }
}

async function deleteTemplate(id: number) {
  if (!confirm(t('recurring_invoice.delete_confirm'))) return
  try {
    await recurringInvoicesApi.delete(id)
    toast.success(t('common.deleted'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.delete_failed'))
  }
}

onMounted(() => load())
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('recurring_invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('recurring_invoice.subtitle') }}</p>
      </div>
      <RouterLink
        to="/recurring-invoices/new"
        class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        {{ t('recurring_invoice.new') }}
      </RouterLink>
    </div>

    <!-- Filtry -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
      <div class="flex flex-wrap items-center gap-2">
        <select v-model="statusFilter" @change="load()"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">{{ t('recurring_invoice.all_statuses') }}</option>
          <option value="active">{{ t('recurring_invoice.status.active') }}</option>
          <option value="paused">{{ t('recurring_invoice.status.paused') }}</option>
          <option value="expired">{{ t('recurring_invoice.status.expired') }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="6" :cols="6" />
    </div>

    <div v-else-if="!templates.length" class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState :title="t('recurring_invoice.no_data')" :cta="t('recurring_invoice.create_first')" to="/recurring-invoices/new" />
    </div>

    <div v-else class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
            <tr>
              <th class="px-4 py-3 text-left font-medium">{{ t('recurring_invoice.name') }}</th>
              <th class="px-4 py-3 text-left font-medium">{{ t('recurring_invoice.client') }}</th>
              <th class="px-4 py-3 text-center font-medium">{{ t('recurring_invoice.frequency') }}</th>
              <th class="px-4 py-3 text-center font-medium">{{ t('recurring_invoice.next_run') }}</th>
              <th class="px-4 py-3 text-center font-medium">{{ t('recurring_invoice.status') }}</th>
              <th class="px-4 py-3 text-right font-medium">{{ t('recurring_invoice.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr
              v-for="tpl in templates"
              :key="tpl.id"
              @click="router.push(`/recurring-invoices/${tpl.id}`)"
              class="cursor-pointer hover:bg-neutral-50 transition"
              :class="{ 'bg-danger-50/20': isDue(tpl.next_run_date, tpl.status) }"
            >
              <td class="px-4 py-3">
                <div class="font-medium text-neutral-900">{{ tpl.name }}</div>
                <div v-if="tpl.project_name" class="text-xs text-neutral-500">{{ tpl.project_name }}</div>
              </td>
              <td class="px-4 py-3">
                <div class="text-neutral-900">{{ tpl.client_company_name }}</div>
              </td>
              <td class="px-4 py-3 text-center text-neutral-600">
                {{ frequencyLabel(tpl.frequency) }}
                <span v-if="tpl.end_of_month" class="ml-1 text-xs text-neutral-400">
                  ({{ t('recurring_invoice.end_of_month') }})
                </span>
                <span v-else-if="tpl.day_of_month" class="ml-1 text-xs text-neutral-400">
                  ({{ t('recurring_invoice.day_n', { n: tpl.day_of_month }) }})
                </span>
              </td>
              <td class="px-4 py-3 text-center">
                <span :class="isDue(tpl.next_run_date, tpl.status) ? 'text-danger-500 font-semibold' : 'text-neutral-600'">
                  {{ formatDate(tpl.next_run_date) }}
                </span>
              </td>
              <td class="px-4 py-3 text-center">
                <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(tpl.status)">
                  {{ t(`recurring_invoice.status.${tpl.status}`) }}
                </span>
              </td>
              <td class="px-4 py-3 text-right" @click.stop>
                <div class="flex items-center justify-end gap-1">
                  <button
                    v-if="tpl.status === 'active'"
                    @click="pauseTemplate(tpl.id)"
                    class="cursor-pointer p-1.5 text-neutral-500 hover:text-warning-600 hover:bg-warning-50 rounded"
                    :title="t('recurring_invoice.pause')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
                  </button>
                  <button
                    v-if="tpl.status === 'paused'"
                    @click="resumeTemplate(tpl.id)"
                    class="cursor-pointer p-1.5 text-neutral-500 hover:text-success-600 hover:bg-success-50 rounded"
                    :title="t('recurring_invoice.resume')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
                  </button>
                  <RouterLink
                    :to="`/recurring-invoices/${tpl.id}/edit`"
                    class="cursor-pointer p-1.5 text-neutral-500 hover:text-primary-600 hover:bg-primary-50 rounded"
                    :title="t('common.edit')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                  </RouterLink>
                  <button
                    @click="deleteTemplate(tpl.id)"
                    class="cursor-pointer p-1.5 text-neutral-500 hover:text-danger-600 hover:bg-danger-50 rounded"
                    :title="t('common.delete')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3M4 7h16"/></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
