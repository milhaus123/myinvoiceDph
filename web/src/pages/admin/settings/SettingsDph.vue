<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { settingsApi, type Supplier } from '@/api/settings'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { FU_CODES } from '@/codebooks/fuCodes'
import { PRACUFO_CODES } from '@/codebooks/pracufo'
import { NACE_CODES } from '@/codebooks/czNace'

const { t } = useI18n()
const toast = useToast()

const props = defineProps<{ supplier: Supplier }>()

const fuOptions = FU_CODES.map(e => ({ value: e.code, label: e.name, secondary: e.code }))
const pracufoOptions = computed(() =>
  PRACUFO_CODES
    .filter(e => !props.supplier?.tax_ufo || e.ufo === props.supplier.tax_ufo)
    .map(e => ({ value: e.code, label: e.name, secondary: e.code }))
)
const naceOptions = NACE_CODES.map(e => ({ value: e.code, label: e.name, secondary: e.code }))

async function save() {
  try {
    const updated = await settingsApi.updateSupplier({
      tax_ufo:          props.supplier.tax_ufo,
      tax_pracufo:      props.supplier.tax_pracufo,
      tax_okec:         props.supplier.tax_okec,
      tax_typ_platce:   props.supplier.tax_typ_platce,
      tax_typ_ds:       props.supplier.tax_typ_ds,
      tax_titul:        props.supplier.tax_titul,
      tax_jmeno:        props.supplier.tax_jmeno,
      tax_prijmeni:     props.supplier.tax_prijmeni,
      tax_c_pop:        props.supplier.tax_c_pop,
      tax_email:        props.supplier.tax_email,
      tax_telef:        props.supplier.tax_telef,
      tax_stat:         props.supplier.tax_stat,
      tax_sest_jmeno:   props.supplier.tax_sest_jmeno,
      tax_sest_prijmeni: props.supplier.tax_sest_prijmeni,
      tax_sest_telef:   props.supplier.tax_sest_telef,
    })
    Object.assign(props.supplier, updated)
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
</script>

<template>
  <section class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-1">{{ t('settings.tax_epo_section') }}</h2>
    <p class="text-xs text-neutral-500 mb-4">{{ t('settings.tax_epo_hint') }}</p>

    <!-- FÚ + pracFÚ -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_ufo') }}</label>
        <SearchableSelect
          :model-value="supplier.tax_ufo"
          :options="fuOptions"
          :placeholder="t('settings.tax_ufo_placeholder')"
          @update:model-value="supplier.tax_ufo = $event"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_pracufo') }}</label>
        <SearchableSelect
          :model-value="supplier.tax_pracufo"
          :options="pracufoOptions"
          :placeholder="t('settings.tax_pracufo_placeholder')"
          :disabled="!supplier.tax_ufo"
          @update:model-value="supplier.tax_pracufo = $event"
        />
        <p v-if="!supplier.tax_ufo" class="text-xs text-neutral-400 mt-1">{{ t('settings.tax_pracufo_select_ufo_first') }}</p>
      </div>
    </div>

    <!-- NACE + typ plátce -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_okec') }}</label>
        <SearchableSelect
          :model-value="supplier.tax_okec"
          :options="naceOptions"
          :placeholder="t('settings.tax_okec_placeholder')"
          @update:model-value="supplier.tax_okec = $event"
        />
        <p class="text-xs text-neutral-400 mt-1">{{ t('settings.tax_okec_hint') }}</p>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_typ_platce') }}</label>
        <select v-model="supplier.tax_typ_platce" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm">
          <option value="P">{{ t('settings.tax_typ_platce_p') }}</option>
          <option value="Q">{{ t('settings.tax_typ_platce_q') }}</option>
        </select>
      </div>
    </div>

    <!-- Fyzická osoba — jméno/příjmení/titul -->
    <div class="border border-amber-200 bg-amber-50 rounded-md p-3 mb-3">
      <p class="text-xs font-medium text-amber-700 mb-2">{{ t('settings.tax_fo_section') }}</p>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_titul') }}</label>
          <input v-model="supplier.tax_titul" type="text" placeholder="Ing., Mgr., …"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_jmeno') }} *</label>
          <input v-model="supplier.tax_jmeno" type="text" :placeholder="t('settings.tax_jmeno_placeholder')"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_prijmeni') }} *</label>
          <input v-model="supplier.tax_prijmeni" type="text" :placeholder="t('settings.tax_prijmeni_placeholder')"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white" />
        </div>
      </div>
    </div>

    <!-- Sestavitel přiznání -->
    <div class="border border-neutral-200 bg-neutral-50 rounded-md p-3 mb-3">
      <p class="text-xs font-medium text-neutral-600 mb-1">{{ t('settings.tax_sest_section') }}</p>
      <p class="text-xs text-neutral-400 mb-2">{{ t('settings.tax_sest_hint') }}</p>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_sest_jmeno') }}</label>
          <input v-model="supplier.tax_sest_jmeno" type="text" :placeholder="supplier.tax_jmeno ?? t('settings.tax_jmeno_placeholder')"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_sest_prijmeni') }}</label>
          <input v-model="supplier.tax_sest_prijmeni" type="text" :placeholder="supplier.tax_prijmeni ?? t('settings.tax_prijmeni_placeholder')"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_sest_telef') }}</label>
          <input v-model="supplier.tax_sest_telef" type="text" :placeholder="supplier.tax_telef ?? supplier.phone ?? '+420 123 456 789'"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono bg-white" />
        </div>
      </div>
    </div>

    <!-- Číslo popisné + typ DS + stát -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-3">
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_c_pop') }}</label>
        <input v-model="supplier.tax_c_pop" type="text" placeholder="76"
          class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
        <p class="text-xs text-neutral-400 mt-1">{{ t('settings.tax_c_pop_hint') }}</p>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">Typ datové schránky (typ_ds)</label>
        <select v-model="supplier.tax_typ_ds" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm">
          <option value="F">F — fyzická osoba</option>
          <option value="P">P — podnikatel</option>
          <option value="PO">PO — právnická osoba</option>
          <option value="OVM">OVM — orgán veřejné moci</option>
        </select>
        <p class="text-xs text-neutral-400 mt-1">Typ subjektu pro EPO. Výchozí: F (fyzická osoba).</p>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">Stát (stat)</label>
        <input v-model="supplier.tax_stat" type="text" placeholder="ČESKÁ REPUBLIKA"
          class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        <p class="text-xs text-neutral-400 mt-1">Výchozí: ČESKÁ REPUBLIKA (VELKÝMI PÍSMENY).</p>
      </div>
    </div>

    <!-- Kontakt pro EPO -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_telef') }}</label>
        <input v-model="supplier.tax_telef" type="text" :placeholder="supplier.phone ?? '+420 123 456 789'"
          class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
        <p class="text-xs text-neutral-400 mt-1">{{ t('settings.tax_telef_fallback_hint') }}</p>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_email') }}</label>
        <input v-model="supplier.tax_email" type="email" :placeholder="supplier.email ?? 'info@example.cz'"
          class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        <p class="text-xs text-neutral-400 mt-1">{{ t('settings.tax_email_fallback_hint') }}</p>
      </div>
    </div>

    <div class="mt-4 flex justify-end">
      <button @click="save" class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
        {{ t('settings.save_supplier') }}
      </button>
    </div>
  </section>
</template>
