<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { settingsApi, type Supplier, type CurrencyAccount } from '@/api/settings'
import SettingsBasic     from './settings/SettingsBasic.vue'
import SettingsNumbering from './settings/SettingsNumbering.vue'
import SettingsPohoda    from './settings/SettingsPohoda.vue'
import SettingsDph       from './settings/SettingsDph.vue'
import SettingsEmail     from './settings/SettingsEmail.vue'
import SettingsMeny      from './settings/SettingsMeny.vue'
import SettingsIdoklad   from './settings/SettingsIdoklad.vue'
import SettingsFakturoid from './settings/SettingsFakturoid.vue'

const { t } = useI18n()
const route = useRoute()

type SettingsTab = 'zakladni' | 'cislovani' | 'dph_epo' | 'pohoda' | 'email' | 'meny' | 'idoklad' | 'fakturoid'
const SETTINGS_TABS: { key: SettingsTab; label: string }[] = [
  { key: 'zakladni',  label: 'Základní údaje' },
  { key: 'cislovani', label: 'Číslování' },
  { key: 'dph_epo',   label: 'DPH / EPO' },
  { key: 'pohoda',    label: 'Pohoda XML' },
  { key: 'email',     label: 'E-mail' },
  { key: 'meny',      label: 'Měny' },
  { key: 'idoklad',   label: 'iDoklad' },
  { key: 'fakturoid', label: 'Fakturoid' },
]
const activeTab = ref<SettingsTab>((route.query.tab as SettingsTab) || 'zakladni')

const supplier   = ref<Supplier>(null as unknown as Supplier)
const currencies = ref<CurrencyAccount[]>([])
const loading    = ref(true)

async function load() {
  loading.value = true
  try {
    [supplier.value, currencies.value] = await Promise.all([
      settingsApi.getSupplier(),
      settingsApi.listCurrencies(),
    ])
  } finally { loading.value = false }
}

async function reloadCurrencies() {
  currencies.value = await settingsApi.listCurrencies()
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('settings.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('settings.subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="supplier">
      <!-- Tab bar -->
      <div class="border-b border-neutral-200 mb-5 flex gap-1 flex-wrap">
        <button v-for="tt in SETTINGS_TABS" :key="tt.key"
          @click="activeTab = tt.key"
          class="cursor-pointer px-4 py-2 text-sm border-b-2 transition -mb-px"
          :class="activeTab === tt.key
            ? 'border-primary-600 text-primary-700 font-medium'
            : 'border-transparent text-neutral-500 hover:text-neutral-700 hover:border-neutral-300'">
          {{ tt.label }}
        </button>
      </div>

      <!-- Tab obsah — každá záložka se mountuje/unmountuje zvlášť -->
      <SettingsBasic
        v-if="activeTab === 'zakladni'"
        :supplier="supplier"
      />
      <SettingsNumbering
        v-else-if="activeTab === 'cislovani'"
        :supplier="supplier"
      />
      <SettingsDph
        v-else-if="activeTab === 'dph_epo'"
        :supplier="supplier"
      />
      <SettingsPohoda
        v-else-if="activeTab === 'pohoda'"
        :supplier="supplier"
      />
      <SettingsEmail
        v-else-if="activeTab === 'email'"
        :supplier="supplier"
      />
      <SettingsMeny
        v-else-if="activeTab === 'meny'"
        :currencies="currencies"
        @refresh="reloadCurrencies"
      />
      <SettingsIdoklad
        v-else-if="activeTab === 'idoklad'"
        :supplier="supplier"
      />
      <SettingsFakturoid
        v-else-if="activeTab === 'fakturoid'"
        :supplier="supplier"
      />
    </div>
  </div>
</template>
