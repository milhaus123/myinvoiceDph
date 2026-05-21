<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { settingsApi, type Supplier } from '@/api/settings'

const { t } = useI18n()
const toast = useToast()

const props = defineProps<{ supplier: Supplier }>()

// ── Přihlašovací údaje ───────────────────────────────────────────────────────

async function saveCredentials() {
  try {
    const updated = await settingsApi.updateSupplier({
      idoklad_client_id:     props.supplier.idoklad_client_id || null,
      idoklad_client_secret: props.supplier.idoklad_client_secret || null,
    })
    Object.assign(props.supplier, updated)
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ── Import ───────────────────────────────────────────────────────────────────

const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 10 }, (_, i) => currentYear - i)
const sectionOptions = [
  { key: 'contacts',     label: 'Kontakty' },
  { key: 'invoices',     label: 'Vydané faktury' },
  { key: 'credit-notes', label: 'Dobropisy' },
  { key: 'purchases',    label: 'Přijaté faktury' },
]

const selectedYears    = ref<number[]>([currentYear])
const selectedSections = ref<string[]>(['contacts', 'invoices', 'credit-notes', 'purchases'])
const dryRun           = ref(false)
const running          = ref(false)
const log              = ref<string[]>([])
const stats            = ref<Record<string, number> | null>(null)
const error            = ref<string>('')
const done             = ref(false)
const currentJobId     = ref<number | null>(null)

function toggleYear(y: number) {
  const idx = selectedYears.value.indexOf(y)
  if (idx >= 0) selectedYears.value.splice(idx, 1)
  else selectedYears.value.push(y)
}
function toggleSection(k: string) {
  const idx = selectedSections.value.indexOf(k)
  if (idx >= 0) selectedSections.value.splice(idx, 1)
  else selectedSections.value.push(k)
}

let pollInterval: ReturnType<typeof setInterval> | null = null

async function pollStatus(jobId: number) {
  try {
    const resp = await fetch(`/api/admin/idoklad-import/status?job_id=${jobId}`)
    if (!resp.ok) { console.error('Poll failed:', resp.status); return }
    const data = await resp.json()
    if (data.status === 'done') {
      if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
      log.value   = Array.isArray(data.log) ? data.log : []
      stats.value = data.stats || null
      done.value  = true
      running.value = false
      currentJobId.value = null
      toast.success('Import dokončen.')
    } else if (data.status === 'failed') {
      if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
      error.value = data.error || 'Import selhal.'
      running.value = false
      currentJobId.value = null
      toast.error('Import selhal.')
    } else if (data.status === 'cancelled') {
      if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
      log.value = [...log.value, '[IMPORT ZRUŠEN]']
      running.value = false
      currentJobId.value = null
      toast.info('Import byl zrušen.')
    } else {
      if (Array.isArray(data.log) && data.log.length > 0) {
        log.value = [...log.value, ...data.log]
      }
    }
  } catch (e: any) {
    console.error('Poll error:', e)
  }
}

async function cancelImport(jobId: number) {
  try {
    const resp = await fetch('/api/admin/idoklad-import/cancel', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ job_id: jobId }),
    })
    const data = await resp.json()
    if (!resp.ok) { toast.error(data.message || 'Nelze zrušit.'); return }
    toast.info('Import zrušen.')
  } catch {
    toast.error('Chyba při rušení importu.')
  }
}

async function runImport() {
  if (!props.supplier?.idoklad_client_id || !props.supplier?.idoklad_client_secret) {
    toast.error('Nejdříve zadej a ulož Client ID a Client Secret.')
    return
  }
  if (selectedYears.value.length === 0 && !selectedSections.value.includes('contacts')) {
    toast.error('Vyber alespoň jeden rok nebo sekci Kontakty.')
    return
  }
  running.value = true
  log.value     = []
  stats.value   = null
  error.value   = ''
  done.value    = false
  try {
    const result = await settingsApi.idokladImport({
      years:    selectedYears.value.length > 0 ? selectedYears.value : undefined,
      sections: selectedSections.value,
      dry_run:  dryRun.value,
    })
    if (result?.job_id && result?.status === 'queued') {
      currentJobId.value = result.job_id!
      log.value = ['Import běží na pozadí… (job #' + result.job_id + ')']
      if (pollInterval) clearInterval(pollInterval)
      pollInterval = setInterval(() => pollStatus(result.job_id!), 3000)
    } else {
      log.value   = Array.isArray(result?.log) ? result.log : []
      stats.value = result?.stats || null
      done.value  = true
      toast.success(result?.dry_run ? 'Dry-run dokončen.' : 'Import dokončen.')
      running.value = false
    }
  } catch (e: any) {
    console.error('Import error:', e)
    error.value = e?.response?.data?.message || e?.message || 'Import selhal.'
    toast.error(error.value)
    running.value = false
  }
}

// ── Danger Zone — smazání importovaných dat ──────────────────────────────────

const cleanupBusy   = ref(false)
const cleanupResult = ref<string>('')
const cleanupError  = ref<string>('')

async function runCleanup() {
  if (!confirm(t('import_cleanup.confirm_idoklad'))) return
  cleanupBusy.value   = true
  cleanupResult.value = ''
  cleanupError.value  = ''
  try {
    const r = await settingsApi.importCleanup('idoklad')
    cleanupResult.value = t('import_cleanup.success', {
      invoices: r.deleted_invoices,
      purchase: r.deleted_purchase_invoices,
      clients:  r.deleted_clients,
    })
    toast.success(cleanupResult.value)
  } catch (e: any) {
    cleanupError.value = e?.response?.data?.error?.message || t('import_cleanup.error')
    toast.error(cleanupError.value)
  } finally {
    cleanupBusy.value = false
  }
}
</script>

<template>
  <div>
    <section class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-1">iDoklad — import dat</h2>
      <p class="text-xs text-neutral-500 mb-4">Propoj svůj iDoklad účet a importuj kontakty, faktury, dobropisy a přijaté faktury. Opakované spuštění nevytváří duplicity.</p>

      <!-- Credentials -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">Client ID</label>
          <input v-model="supplier.idoklad_client_id" type="text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" autocomplete="off" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">Client Secret</label>
          <input v-model="supplier.idoklad_client_secret" type="password" placeholder="••••••••••••••••••••"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" autocomplete="off" />
        </div>
      </div>
      <button @click="saveCredentials"
        class="cursor-pointer mb-6 px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
        Uložit přihlašovací údaje
      </button>

      <hr class="border-neutral-200 mb-4" />

      <!-- Roky -->
      <div class="mb-4">
        <p class="text-sm font-medium text-neutral-700 mb-2">Roky k importu</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="y in yearOptions" :key="y" type="button"
            @click="toggleYear(y)"
            class="cursor-pointer px-3 h-8 text-sm rounded-md border transition"
            :class="selectedYears.includes(y)
              ? 'bg-primary-600 text-white border-primary-600'
              : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'">
            {{ y }}
          </button>
        </div>
      </div>

      <!-- Sekce -->
      <div class="mb-4">
        <p class="text-sm font-medium text-neutral-700 mb-2">Sekce</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="s in sectionOptions" :key="s.key" type="button"
            @click="toggleSection(s.key)"
            class="cursor-pointer px-3 h-8 text-sm rounded-md border transition"
            :class="selectedSections.includes(s.key)
              ? 'bg-primary-600 text-white border-primary-600'
              : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'">
            {{ s.label }}
          </button>
        </div>
      </div>

      <!-- Dry-run + spuštění -->
      <div class="flex flex-wrap items-center gap-4 mb-4">
        <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
          <input v-model="dryRun" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          Dry-run (pouze simulace, nic se neuloží)
        </label>
        <button @click="runImport" :disabled="running"
          class="cursor-pointer px-5 h-9 text-sm font-medium rounded-md transition"
          :class="running
            ? 'bg-neutral-300 text-neutral-500 cursor-not-allowed'
            : dryRun
              ? 'bg-amber-500 hover:bg-amber-600 text-white'
              : 'bg-green-600 hover:bg-green-700 text-white'">
          <span v-if="running" class="inline-flex items-center gap-2">
            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            Probíhá import…
          </span>
          <span v-else>{{ dryRun ? 'Spustit simulaci' : 'Spustit import' }}</span>
        </button>
        <button v-if="running" @click="() => currentJobId !== null && cancelImport(currentJobId)"
          class="cursor-pointer px-3 h-9 text-sm font-medium text-neutral-600 border border-neutral-300 rounded-md hover:bg-neutral-50">
          Zrušit
        </button>
      </div>

      <!-- Error -->
      <div v-if="error" class="mb-3 text-sm text-danger-600 bg-danger-50 border border-danger-200 rounded-md px-4 py-2">
        {{ error }}
      </div>

      <!-- Výsledek -->
      <div v-if="done && stats" class="mb-3">
        <p class="text-sm font-semibold text-success-700 mb-2">Import dokončen</p>
        <div class="flex flex-wrap gap-2">
          <span v-for="(v, k) in stats" :key="k"
            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-neutral-100 text-neutral-700">
            <span class="font-semibold text-primary-700">{{ v }}</span> {{ k }}
          </span>
        </div>
      </div>

      <!-- Log -->
      <div v-if="log && log.length > 0" class="mt-3">
        <p class="text-xs font-medium text-neutral-500 mb-1">Log:</p>
        <div class="max-h-64 overflow-y-auto bg-neutral-900 rounded-md p-3 font-mono text-xs text-neutral-200 space-y-0.5">
          <div v-for="(line, i) in log" :key="i"
            :class="{
              'text-green-400':  String(line).startsWith('[OK]') || String(line).startsWith('✓') || String(line).includes('INSERT'),
              'text-yellow-300': String(line).startsWith('[DRY') || String(line).startsWith('[SKIP]') || String(line).includes('SKIP'),
              'text-red-400':    String(line).startsWith('[ERR') || String(line).toLowerCase().includes('error'),
              'text-neutral-400': String(line).startsWith('---') || String(line).startsWith('==='),
            }">{{ line }}</div>
        </div>
      </div>
    </section>

    <!-- Danger Zone -->
    <section class="mt-6">
      <div class="border border-danger-200 rounded-lg p-5 bg-danger-50/30">
        <h3 class="text-base font-semibold text-danger-700 mb-1">{{ t('import_cleanup.title') }}</h3>
        <div class="mt-3">
          <p class="text-sm text-neutral-600 mb-3">{{ t('import_cleanup.desc_idoklad') }}</p>
          <button
            @click="runCleanup"
            :disabled="cleanupBusy"
            class="cursor-pointer inline-flex items-center gap-2 h-9 px-4 text-sm font-medium rounded-md border border-danger-400 text-danger-700 hover:bg-danger-100 disabled:opacity-50 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3M4 7h16"/>
            </svg>
            {{ cleanupBusy ? '…' : t('import_cleanup.btn_idoklad') }}
          </button>
        </div>
        <p v-if="cleanupResult" class="mt-3 text-sm text-success-700 font-medium">{{ cleanupResult }}</p>
        <p v-if="cleanupError" class="mt-3 text-sm text-danger-600">{{ cleanupError }}</p>
      </div>
    </section>
  </div>
</template>
