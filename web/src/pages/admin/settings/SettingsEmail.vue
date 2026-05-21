<script setup lang="ts">
import { ref, watch, onMounted, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { settingsApi, type Supplier } from '@/api/settings'

const { t } = useI18n()
const toast = useToast()

const props = defineProps<{ supplier: Supplier }>()

const previewLocale = ref<'cs' | 'en'>('cs')
const previewHtml = ref<string>('')
const logoFileInput = ref<HTMLInputElement | null>(null)
const logoUploading = ref(false)

async function bumpPreview() {
  if (!props.supplier) return
  try {
    previewHtml.value = await settingsApi.emailPreviewHtml(previewLocale.value)
  } catch (e: any) {
    previewHtml.value = `<pre style="color:red">${e?.message || 'Preview failed'}</pre>`
  }
}

// Uloží jen branding pole; silent=true potlačí success toast (auto-save z watcheru).
async function saveBranding(silent = false) {
  if (!props.supplier) return
  if (!/^#[0-9A-Fa-f]{6}$/.test(props.supplier.email_accent_color || '')) {
    if (!silent) toast.error(t('settings.branding_color_invalid'))
    return
  }
  try {
    const updated = await settingsApi.updateSupplier({
      email_branding_enabled: props.supplier.email_branding_enabled,
      email_accent_color:     props.supplier.email_accent_color,
    })
    // Merge response — zachová local-only fields jako has_email_logo
    Object.assign(props.supplier, updated)
    if (!silent) toast.success(t('common.saved'))
    bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function pickLogo() { logoFileInput.value?.click() }

async function onLogoSelected(ev: Event) {
  const f = (ev.target as HTMLInputElement).files?.[0]
  if (!f || !props.supplier) return
  if (f.size > 1_048_576) {
    toast.error(t('settings.branding_logo_too_large'))
    if (logoFileInput.value) logoFileInput.value.value = ''
    return
  }
  logoUploading.value = true
  try {
    const result = await settingsApi.uploadEmailLogo(f)
    props.supplier.logo_path = result.logo_path
    props.supplier.has_email_logo = true
    toast.success(t('settings.branding_logo_uploaded'))
    bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    logoUploading.value = false
    if (logoFileInput.value) logoFileInput.value.value = ''
  }
}

async function removeLogo() {
  if (!props.supplier) return
  if (!window.confirm(t('settings.branding_logo_remove_confirm'))) return
  try {
    await settingsApi.deleteEmailLogo()
    props.supplier.logo_path = null
    props.supplier.has_email_logo = false
    toast.success(t('settings.branding_logo_removed'))
    bumpPreview()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// Načteme preview ihned po zobrazení záložky
onMounted(() => bumpPreview())

// Locale switch → obnoví preview
watch(previewLocale, () => { if (props.supplier) bumpPreview() })

// Auto-save watchers — aktivujeme je až v dalším ticku po mount,
// aby první render (s hodnotami z props) nepouštěl save.
let watching = false
nextTick(() => { watching = true })

let colorTimer: ReturnType<typeof setTimeout> | null = null
watch(() => props.supplier?.email_branding_enabled, () => { if (watching) saveBranding(true) })
watch(() => props.supplier?.email_accent_color, () => {
  if (!watching) return
  if (colorTimer) clearTimeout(colorTimer)
  colorTimer = setTimeout(() => saveBranding(true), 500)
})
</script>

<template>
  <section class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
    <div class="flex items-center justify-between mb-1">
      <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.branding_title') }}</h2>
      <label class="inline-flex items-center gap-2 cursor-pointer">
        <input v-model="supplier.email_branding_enabled" type="checkbox" class="h-4 w-4 accent-primary-600" />
        <span class="text-sm text-neutral-700">{{ t('settings.branding_enabled') }}</span>
      </label>
    </div>
    <p class="text-xs text-neutral-500 mb-4">{{ t('settings.branding_subtitle') }}</p>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <!-- Form -->
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.branding_logo') }}</label>
          <p class="text-xs text-neutral-500 mb-2">{{ t('settings.branding_logo_hint') }}</p>
          <div class="flex items-center gap-3">
            <button
              @click="pickLogo" type="button"
              :disabled="logoUploading || !supplier.email_branding_enabled"
              class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50 disabled:cursor-not-allowed">
              {{ logoUploading ? t('common.loading') : (supplier.has_email_logo ? t('settings.branding_logo_replace') : t('settings.branding_logo_upload')) }}
            </button>
            <button
              v-if="supplier.has_email_logo" @click="removeLogo" type="button"
              class="cursor-pointer text-sm text-danger-600 hover:text-danger-700">
              {{ t('common.remove') }}
            </button>
            <input ref="logoFileInput" @change="onLogoSelected" type="file"
              accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml" class="hidden" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.branding_accent_color') }}</label>
          <p class="text-xs text-neutral-500 mb-2">{{ t('settings.branding_accent_color_hint') }}</p>
          <div class="flex items-center gap-3">
            <input
              v-model="supplier.email_accent_color" type="color"
              :disabled="!supplier.email_branding_enabled"
              class="h-10 w-14 cursor-pointer rounded border border-neutral-300 disabled:opacity-50" />
            <input
              v-model="supplier.email_accent_color" type="text" placeholder="#3B2D83" pattern="^#[0-9A-Fa-f]{6}$"
              :disabled="!supplier.email_branding_enabled"
              class="h-10 w-32 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:opacity-50" />
            <button
              @click="supplier.email_accent_color = '#3B2D83'" type="button"
              :disabled="!supplier.email_branding_enabled"
              class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700 disabled:opacity-50 disabled:cursor-not-allowed">
              {{ t('settings.branding_accent_reset') }}
            </button>
          </div>
        </div>

        <p class="text-xs text-neutral-500">{{ t('settings.branding_save_hint') }}</p>

        <div class="pt-2">
          <button @click="() => saveBranding(false)"
            class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
            {{ t('settings.branding_save') }}
          </button>
        </div>
      </div>

      <!-- Preview -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="block text-sm font-medium text-neutral-700">{{ t('settings.branding_preview') }}</label>
          <div class="flex items-center gap-1 text-xs">
            <button @click="previewLocale = 'cs'" type="button"
              :class="previewLocale === 'cs' ? 'text-primary-600 font-semibold' : 'text-neutral-500 hover:text-neutral-700'"
              class="cursor-pointer px-2">CS</button>
            <span class="text-neutral-300">|</span>
            <button @click="previewLocale = 'en'" type="button"
              :class="previewLocale === 'en' ? 'text-primary-600 font-semibold' : 'text-neutral-500 hover:text-neutral-700'"
              class="cursor-pointer px-2">EN</button>
            <button @click="bumpPreview" type="button"
              class="cursor-pointer ml-2 px-2 text-neutral-500 hover:text-neutral-700" :title="t('common.refresh')">↻</button>
          </div>
        </div>
        <iframe :srcdoc="previewHtml" sandbox="allow-same-origin"
          class="w-full h-[420px] border border-neutral-200 rounded-md bg-neutral-50" />
      </div>
    </div>
  </section>
</template>
