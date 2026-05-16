<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { cashRegisterApi, type CashMovement, type CashCategory, type CashMovementSummary, type CreateCashMovementInput } from '@/api/cashRegister'
import { clientsApi } from '@/api/clients'
import { projectsApi } from '@/api/projects'
import { formatMoney, formatDate } from '@/composables/useFormat'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()

// Data
const movements = ref<CashMovement[]>([])
const categories = ref<CashCategory[]>([])
const summary = ref<CashMovementSummary | null>(null)
const clients = ref<any[]>([])
const projects = ref<any[]>([])

// Loading states
const loading = ref(false)
const saving = ref(false)
const loadingSummary = ref(false)

// Filters
const filterType = ref('')
const filterCategory = ref('')
const filterDateFrom = ref('')
const filterDateTo = ref('')
const searchQuery = ref('')

// Pagination
const page = ref(1)
const pages = ref(1)
const total = ref(0)

// Modal/form state
const showForm = ref(false)
const editingId = ref<number | null>(null)
const deleteId = ref<number | null>(null)
const deleteLoading = ref(false)

// Form fields
const form = ref<CreateCashMovementInput>({
  movement_type: 'expense',
  amount: 0,
  description: '',
  category: '',
  client_id: undefined,
  project_id: undefined,
})

// Form errors
const formErrors = ref<Record<string, string>>({})

// Client options (search)
const clientSearch = ref('')
const clientOptions = ref<any[]>([])
const loadingClients = ref(false)

const filteredProjects = computed(() => {
  if (!form.value.client_id) return []
  return projects.value.filter(p => p.client_id === form.value.client_id)
})

async function loadClients(q: string) {
  loadingClients.value = true
  try {
    const r = await clientsApi.list({ q, page: 1, per_page: 20 })
    clientOptions.value = r.data
  } finally {
    loadingClients.value = false
  }
}

async function loadProjects() {
  try {
    const r = await projectsApi.list({ page: 1, per_page: 100 })
    projects.value = r.data
  } catch {}
}

async function loadMovements(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  }
  try {
    const r = await cashRegisterApi.list({
      movement_type: filterType.value || undefined,
      category: filterCategory.value || undefined,
      date_from: filterDateFrom.value || undefined,
      date_to: filterDateTo.value || undefined,
      q: searchQuery.value || undefined,
      page: page.value,
      per_page: 50,
    })
    if (reset) {
      movements.value = r.data
    } else {
      movements.value.push(...r.data)
    }
    categories.value = r.categories
    total.value = r.meta.total
    pages.value = r.meta.pages
  } finally {
    loading.value = false
  }
}

async function loadSummary() {
  loadingSummary.value = true
  try {
    summary.value = await cashRegisterApi.summary()
  } finally {
    loadingSummary.value = false
  }
}

function openCreate(type: 'income' | 'expense') {
  editingId.value = null
  form.value = {
    movement_type: type,
    amount: 0,
    description: '',
    category: categories.value[0]?.name || '',
    client_id: undefined,
    project_id: undefined,
  }
  formErrors.value = {}
  showForm.value = true
}

function openEdit(m: CashMovement) {
  editingId.value = m.id
  form.value = {
    movement_type: m.movement_type,
    amount: m.amount,
    description: m.description,
    category: m.category,
    client_id: m.client_id ?? undefined,
    project_id: m.project_id ?? undefined,
  }
  formErrors.value = {}
  showForm.value = true
}

function closeForm() {
  showForm.value = false
  editingId.value = null
}

async function submitForm() {
  formErrors.value = {}
  if (!form.value.amount || form.value.amount <= 0) {
    formErrors.value['amount'] = t('cash_register.amount') + ' musí být kladné číslo.'
    return
  }
  if (!form.value.description?.trim()) {
    formErrors.value['description'] = t('cash_register.description') + ' je povinný.'
    return
  }

  saving.value = true
  try {
    if (editingId.value) {
      await cashRegisterApi.update(editingId.value, form.value)
    } else {
      await cashRegisterApi.create(form.value)
    }
    closeForm()
    await loadMovements(true)
    await loadSummary()
  } catch (e: any) {
    const msg = e?.response?.data?.message || 'Chyba při ukládání.'
    formErrors.value['_'] = msg
  } finally {
    saving.value = false
  }
}

async function confirmDelete(id: number) {
  deleteId.value = id
}

async function doDelete() {
  if (!deleteId.value) return
  deleteLoading.value = true
  try {
    await cashRegisterApi.delete(deleteId.value)
    deleteId.value = null
    await loadMovements(true)
    await loadSummary()
  } catch {
    deleteId.value = null
  } finally {
    deleteLoading.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadMovements(true), loadSummary(), loadProjects()])
})
</script>

<template>
  <div class="max-w-7xl mx-auto">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('cash_register.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('cash_register.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button
          @click="openCreate('income')"
          class="inline-flex items-center gap-1.5 h-9 px-3 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
          </svg>
          {{ t('cash_register.new_income') }}
        </button>
        <button
          @click="openCreate('expense')"
          class="inline-flex items-center gap-1.5 h-9 px-3 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
          </svg>
          {{ t('cash_register.new_expense') }}
        </button>
      </div>
    </div>

    <!-- Summary Cards -->
    <div v-if="summary" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-4">
        <div class="text-xs font-medium text-neutral-500 uppercase tracking-wide mb-1">{{ t('cash_register.balance') }}</div>
        <div class="text-xl font-semibold font-mono" :class="summary.balance >= 0 ? 'text-green-600' : 'text-red-600'">
          {{ formatMoney(summary.balance, 'CZK') }}
        </div>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-4">
        <div class="text-xs font-medium text-neutral-500 uppercase tracking-wide mb-1">{{ t('cash_register.month_income') }}</div>
        <div class="text-xl font-semibold font-mono text-green-600">
          {{ formatMoney(summary.month_income, 'CZK') }}
        </div>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-4">
        <div class="text-xs font-medium text-neutral-500 uppercase tracking-wide mb-1">{{ t('cash_register.month_expense') }}</div>
        <div class="text-xl font-semibold font-mono text-red-600">
          {{ formatMoney(summary.month_expense, 'CZK') }}
        </div>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg shadow-sm p-4">
        <div class="text-xs font-medium text-neutral-500 uppercase tracking-wide mb-1">{{ t('cash_register.monthly_balance') }}</div>
        <div class="text-xl font-semibold font-mono" :class="summary.month_balance >= 0 ? 'text-green-600' : 'text-red-600'">
          {{ formatMoney(summary.month_balance, 'CZK') }}
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm mb-4">
      <div class="px-4 py-3 flex flex-wrap items-center gap-3">
        <select v-model="filterType" @change="loadMovements(true)" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white">
          <option value="">{{ t('cash_register.all') }}</option>
          <option value="income">{{ t('cash_register.income') }}</option>
          <option value="expense">{{ t('cash_register.expense') }}</option>
        </select>
        <select v-model="filterCategory" @change="loadMovements(true)" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white">
          <option value="">{{ t('cash_register.filter_by_category') }}</option>
          <option v-for="c in categories" :key="c.id" :value="c.name">{{ c.name }}</option>
        </select>
        <input v-model="filterDateFrom" type="date" @change="loadMovements(true)" class="h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        <span class="text-neutral-400">–</span>
        <input v-model="filterDateTo" type="date" @change="loadMovements(true)" class="h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        <input
          v-model="searchQuery"
          type="search"
          :placeholder="t('cash_register.description')"
          @input="loadMovements(true)"
          class="flex-1 h-9 px-3 border border-neutral-300 rounded-md text-sm"
        />
      </div>
    </div>

    <!-- Movements Table -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <TableSkeleton v-if="loading" :rows="6" :cols="6" />

      <EmptyState
        v-else-if="!movements.length"
        :title="t('cash_register.no_movements')"
        :cta="t('cash_register.new_movement')"
        @click="openCreate('expense')"
      />

      <div v-else>
        <!-- Desktop Table -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('cash_register.date') }}</th>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('cash_register.movement_type') }}</th>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('cash_register.description') }}</th>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('cash_register.category') }}</th>
                <th class="text-left px-4 py-2.5 font-medium">{{ t('cash_register.client') }}</th>
                <th class="text-right px-4 py-2.5 font-medium">{{ t('cash_register.amount') }}</th>
                <th class="text-center px-4 py-2.5 font-medium w-20">&#8203;</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="m in movements" :key="m.id" class="hover:bg-neutral-50">
                <td class="px-4 py-3 text-neutral-600 text-xs whitespace-nowrap">{{ formatDate(m.created_at) }}</td>
                <td class="px-4 py-3">
                  <span
                    class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded"
                    :class="m.movement_type === 'income'
                      ? 'bg-green-100 text-green-800'
                      : 'bg-red-100 text-red-800'"
                  >
                    {{ m.movement_type === 'income' ? t('cash_register.income') : t('cash_register.expense') }}
                  </span>
                </td>
                <td class="px-4 py-3 text-neutral-900">{{ m.description }}</td>
                <td class="px-4 py-3 text-neutral-600">{{ m.category || '—' }}</td>
                <td class="px-4 py-3 text-neutral-600 text-xs">{{ m.client_name || '—' }}</td>
                <td class="px-4 py-3 text-right font-mono font-medium" :class="m.movement_type === 'income' ? 'text-green-700' : 'text-red-700'">
                  {{ m.movement_type === 'income' ? '+' : '-' }}{{ formatMoney(m.amount, m.currency_code) }}
                </td>
                <td class="px-4 py-3 text-center">
                  <div class="flex items-center justify-center gap-1">
                    <button @click="openEdit(m)" class="p-1 text-neutral-400 hover:text-primary-600 rounded" :title="t('common.edit')">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                      </svg>
                    </button>
                    <button @click="confirmDelete(m.id)" class="p-1 text-neutral-400 hover:text-red-600 rounded" :title="t('common.delete')">
                      <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Cards -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="m in movements" :key="`m-${m.id}`" class="px-4 py-3">
            <div class="flex items-start justify-between gap-2">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span
                    class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded"
                    :class="m.movement_type === 'income' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                  >
                    {{ m.movement_type === 'income' ? t('cash_register.income') : t('cash_register.expense') }}
                  </span>
                  <span class="text-xs text-neutral-500">{{ formatDate(m.created_at) }}</span>
                </div>
                <div class="text-sm font-medium text-neutral-900 truncate">{{ m.description }}</div>
                <div class="text-xs text-neutral-500 mt-0.5">
                  {{ m.category }}{{ m.client_name ? ' · ' + m.client_name : '' }}
                </div>
              </div>
              <div class="font-mono font-medium whitespace-nowrap" :class="m.movement_type === 'income' ? 'text-green-700' : 'text-red-700'">
                {{ m.movement_type === 'income' ? '+' : '-' }}{{ formatMoney(m.amount, m.currency_code) }}
              </div>
            </div>
            <div class="flex items-center gap-2 mt-2">
              <button @click="openEdit(m)" class="text-xs text-primary-600 hover:text-primary-700">{{ t('common.edit') }}</button>
              <button @click="confirmDelete(m.id)" class="text-xs text-red-600 hover:text-red-700">{{ t('common.delete') }}</button>
            </div>
          </div>
        </div>

        <!-- Load More -->
        <div v-if="page < pages" class="px-4 py-3 border-t border-neutral-200 text-center">
          <button
            @click="loadMovements(false)"
            :disabled="loading"
            class="text-sm text-primary-600 hover:text-primary-700 disabled:opacity-50"
          >
            {{ loading ? '…' : t('common.load_more') }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Create/Edit Modal -->
  <Teleport to="body">
    <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b border-neutral-200 flex items-center justify-between">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ editingId ? t('common.edit') : (form.movement_type === 'income' ? t('cash_register.new_income') : t('cash_register.new_expense')) }}
          </h2>
          <button @click="closeForm" class="text-neutral-400 hover:text-neutral-600 p-1">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="px-6 py-4 space-y-4">
          <!-- Type -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1.5">{{ t('cash_register.movement_type') }}</label>
            <div class="flex gap-2">
              <button
                type="button"
                @click="form.movement_type = 'income'"
                class="flex-1 h-9 rounded-md border text-sm font-medium transition"
                :class="form.movement_type === 'income'
                  ? 'border-green-500 bg-green-50 text-green-700'
                  : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'"
              >{{ t('cash_register.income') }}</button>
              <button
                type="button"
                @click="form.movement_type = 'expense'"
                class="flex-1 h-9 rounded-md border text-sm font-medium transition"
                :class="form.movement_type === 'expense'
                  ? 'border-red-500 bg-red-50 text-red-700'
                  : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'"
              >{{ t('cash_register.expense') }}</button>
            </div>
          </div>

          <!-- Amount -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1.5">{{ t('cash_register.amount') }} (CZK)</label>
            <input
              v-model.number="form.amount"
              type="number"
              step="0.01"
              min="0.01"
              class="w-full h-9 px-3 border rounded-md text-sm"
              :class="formErrors['amount'] ? 'border-red-500' : 'border-neutral-300'"
            />
            <p v-if="formErrors['amount']" class="mt-1 text-xs text-red-600">{{ formErrors['amount'] }}</p>
          </div>

          <!-- Description -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1.5">{{ t('cash_register.description') }}</label>
            <textarea
              v-model="form.description"
              rows="2"
              class="w-full px-3 py-2 border rounded-md text-sm resize-none"
              :class="formErrors['description'] ? 'border-red-500' : 'border-neutral-300'"
            />
            <p v-if="formErrors['description']" class="mt-1 text-xs text-red-600">{{ formErrors['description'] }}</p>
          </div>

          <!-- Category -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1.5">{{ t('cash_register.category') }}</label>
            <select v-model="form.category" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white">
              <option value="">—</option>
              <option v-for="c in categories" :key="c.id" :value="c.name">{{ c.name }}</option>
            </select>
          </div>

          <!-- Client -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1.5">{{ t('cash_register.client') }}</label>
            <input
              v-model="clientSearch"
              type="search"
              :placeholder="t('cash_register.client') + '…'"
              @input="loadClients(clientSearch)"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm"
            />
            <select v-if="clientOptions.length" v-model="form.client_id" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white">
              <option :value="undefined">—</option>
              <option v-for="c in clientOptions" :key="c.id" :value="c.id">{{ c.company_name }}</option>
            </select>
          </div>

          <!-- Project (shown if client selected) -->
          <div v-if="form.client_id && filteredProjects.length">
            <label class="block text-sm font-medium text-neutral-700 mb-1.5">{{ t('cash_register.project') }}</label>
            <select v-model="form.project_id" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white">
              <option :value="undefined">—</option>
              <option v-for="p in filteredProjects" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
          </div>

          <p v-if="formErrors['_']" class="text-xs text-red-600">{{ formErrors['_'] }}</p>
        </div>

        <div class="px-6 py-4 border-t border-neutral-200 flex items-center justify-end gap-2">
          <button @click="closeForm" class="h-9 px-4 border border-neutral-300 rounded-md text-sm text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button
            @click="submitForm"
            :disabled="saving"
            class="h-9 px-4 rounded-md text-sm font-medium text-white"
            :class="form.movement_type === 'income'
              ? 'bg-green-600 hover:bg-green-700'
              : 'bg-red-600 hover:bg-red-700'"
          >
            {{ saving ? '…' : t('common.save') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- Delete Confirm Modal -->
  <Teleport to="body">
    <div v-if="deleteId" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-white rounded-lg shadow-xl w-full max-w-sm">
        <div class="px-6 py-4">
          <h2 class="text-lg font-semibold text-neutral-900 mb-2">{{ t('common.delete') }}</h2>
          <p class="text-sm text-neutral-600">{{ t('cash_register.confirm_delete') }}</p>
        </div>
        <div class="px-6 py-4 border-t border-neutral-200 flex items-center justify-end gap-2">
          <button @click="deleteId = null" class="h-9 px-4 border border-neutral-300 rounded-md text-sm text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button
            @click="doDelete"
            :disabled="deleteLoading"
            class="h-9 px-4 bg-red-600 hover:bg-red-700 text-white rounded-md text-sm font-medium"
          >
            {{ deleteLoading ? '…' : t('common.delete') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
