<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { projectsApi, type Project } from '@/api/projects'
import { invoicesApi, type InvoiceListItem } from '@/api/invoices'
import { formatMoney, formatDate, statusLabel, typeLabel, statusBadgeClass, isOverdue, invoiceRowClass } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const route = useRoute()
const router = useRouter()

const project = ref<Project | null>(null)
const loading = ref(true)
const invoices = ref<InvoiceListItem[]>([])
const invoicesLoading = ref(false)
const invoicesLoadingMore = ref(false)
const invoicesTotal = ref(0)
const invoicesPage = ref(1)
const invoicesPages = ref(1)

const canDelete = computed(() => (project.value?.invoices_count ?? 0) === 0)

async function load() {
  const id = Number(route.params.id)
  loading.value = true
  invoicesLoading.value = true
  invoicesPage.value = 1
  try {
    const [p, grouped] = await Promise.all([
      projectsApi.get(id),
      invoicesApi.listGrouped({ project_id: id, page: 1 }),
    ])
    project.value = p
    invoices.value = grouped.data.flatMap(g => g.invoices)
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
  } finally {
    loading.value = false
    invoicesLoading.value = false
  }
}

async function loadMoreInvoices() {
  if (!project.value) return
  invoicesLoadingMore.value = true
  invoicesPage.value++
  try {
    const grouped = await invoicesApi.listGrouped({ project_id: project.value.id, page: invoicesPage.value })
    invoices.value.push(...grouped.data.flatMap(g => g.invoices))
    invoicesTotal.value = grouped.meta.total
    invoicesPages.value = grouped.meta.pages ?? 1
  } finally {
    invoicesLoadingMore.value = false
  }
}

onMounted(load)

async function archive() {
  if (!project.value) return
  if (!confirm(t('project.archive_confirm'))) return
  await projectsApi.archive(project.value.id)
  router.push(`/clients/${project.value.client_id}`)
}

async function deleteProject() {
  if (!project.value) return
  if (!confirm(t('project.delete_warning', { name: project.value.name }))) return
  try {
    await projectsApi.delete(project.value.id)
    router.push(`/clients/${project.value.client_id}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('project.delete_failed'))
  }
}
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

  <div v-else-if="project" class="space-y-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <RouterLink :to="`/clients/${project.client_id}`" class="text-sm text-neutral-600 hover:text-neutral-900">
          ← {{ project.client_company_name }}
        </RouterLink>
        <h1 class="text-2xl font-semibold mt-1">{{ project.name }}</h1>
        <div class="text-sm text-neutral-500 mt-1 flex items-center gap-2 flex-wrap">
          <span class="text-xs px-2 py-0.5 rounded"
            :class="{
              'bg-emerald-50 text-emerald-700': project.status === 'active',
              'bg-amber-50 text-amber-700': project.status === 'paused',
              'bg-neutral-100 text-neutral-600': project.status === 'closed',
            }">{{ project.status }}</span>
          <span v-if="project.project_number" class="font-mono text-xs">{{ project.project_number }}</span>
          <span v-if="project.requires_work_report_approval"
            class="text-xs px-2 py-0.5 rounded bg-primary-100 text-primary-700"
            :title="t('project.requires_approval_hint')">
            ✓ {{ t('project.requires_approval_badge') }}
          </span>
        </div>
      </div>
      <div class="flex flex-wrap gap-2 justify-end">
        <RouterLink v-if="project.status === 'active'"
          :to="`/invoices/new?client_id=${project.client_id}&project_id=${project.id}`"
          class="cursor-pointer px-3 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
          {{ t('project.new_invoice') }}
        </RouterLink>
        <RouterLink :to="`/projects/${project.id}/edit`"
          class="cursor-pointer px-3 h-9 text-sm border border-primary-500/40 rounded-md text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          {{ t('project.edit_project') }}
        </RouterLink>
        <RouterLink :to="`/clients/${project.client_id}/edit`"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z"/></svg>
          {{ t('project.edit_client') }}
        </RouterLink>
        <button @click="archive"
          class="cursor-pointer px-3 h-9 text-sm border border-warning-500/50 rounded-md text-warning-600 hover:bg-warning-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 1 1 0-4h14a2 2 0 1 1 0 4M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8m-9 4h4"/></svg>
          {{ t('common.archive') }}
        </button>
        <button v-if="canDelete" @click="deleteProject"
          class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 rounded-md text-danger-500 hover:bg-danger-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
          {{ t('common.delete') }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.rates_due_section') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.hourly_rate') }}</dt><dd class="font-mono">{{ project.hourly_rate.toLocaleString('cs') }} {{ project.currency }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.due_short') }}</dt><dd>{{ project.payment_due_days }} {{ t('common.days') }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.currency') }}</dt><dd class="font-mono">{{ project.currency }}</dd></div>
        </dl>
      </div>

      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('common.budgets') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.budget_total_short') }}</dt><dd class="font-mono">{{ project.budget_total?.toLocaleString('cs') ?? '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.yearly') }}</dt><dd class="font-mono">{{ project.budget_yearly?.toLocaleString('cs') ?? '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('common.monthly') }}</dt><dd class="font-mono">{{ project.budget_monthly?.toLocaleString('cs') ?? '—' }}</dd></div>
        </dl>
      </div>

      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.reference_section') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.project_number') }}</dt><dd class="font-mono">{{ project.project_number || '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('project.contract_number') }}</dt><dd class="font-mono">{{ project.contract_number || '—' }}</dd></div>
        </dl>
      </div>
    </div>

    <div v-if="project.billing_emails.length" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('project.billing_emails') }}</h3>
      <ul class="space-y-1.5 text-sm">
        <li v-for="b in project.billing_emails" :key="b.position" class="flex items-center justify-between border-b border-neutral-100 pb-1.5 last:border-b-0">
          <span class="text-neutral-900">{{ b.email }}</span>
          <span class="text-xs text-neutral-500">{{ b.label || '—' }}</span>
        </li>
      </ul>
      <p class="text-xs text-neutral-400 mt-2">{{ t('project.client_main_email_note', { email: project.client_main_email }) }}</p>
    </div>

    <div v-if="project.note" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ t('project.note') }}</h3>
      <p class="text-sm text-neutral-700 whitespace-pre-wrap">{{ project.note }}</p>
    </div>

    <!-- Faktury -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
        <h3 class="font-semibold">{{ t('nav.invoices') }} <span v-if="invoicesTotal" class="text-neutral-400 font-normal">({{ invoicesTotal }})</span></h3>
        <RouterLink v-if="project.status === 'active'"
          :to="`/invoices/new?client_id=${project.client_id}&project_id=${project.id}`"
          class="px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md inline-flex items-center">
          {{ t('invoice.new') }}
        </RouterLink>
      </div>
      <div v-if="invoicesLoading" class="p-8 text-center text-neutral-500 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="!invoices.length" class="p-8 text-center text-neutral-500 text-sm">
        {{ t('common.no_data') }}
      </div>
      <table v-else class="w-full text-sm">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.varsymbol') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.type') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.issue_date') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('invoice.due_date') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('invoice.amount_to_pay') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('invoice.status_label') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="inv in invoices" :key="inv.id" class="cursor-pointer hover:bg-neutral-50"
              :class="invoiceRowClass(inv.due_date, inv.status)"
              @click="router.push(`/invoices/${inv.id}`)">
            <td class="px-4 py-2.5 font-mono">{{ inv.varsymbol || `#${inv.id}` }}</td>
            <td class="px-4 py-2.5 text-neutral-600">{{ typeLabel(inv.invoice_type) }}</td>
            <td class="px-4 py-2.5 text-neutral-600">{{ formatDate(inv.issue_date) }}</td>
            <td class="px-4 py-2.5">
              <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-600 font-medium' : 'text-neutral-600'">
                {{ formatDate(inv.due_date) }}
              </span>
            </td>
            <td class="px-4 py-2.5 text-right font-mono">
              {{ formatMoney(inv.amount_to_pay || inv.total_with_vat, inv.currency) }}
            </td>
            <td class="px-4 py-2.5 text-center">
              <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                {{ statusLabel(inv.status) }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="invoices.length" class="px-5 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: invoices.length, total: invoicesTotal }) }}</span>
        <button v-if="invoicesPage < invoicesPages" @click="loadMoreInvoices" :disabled="invoicesLoadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ invoicesLoadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>
