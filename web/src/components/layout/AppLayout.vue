<script setup lang="ts">
import { ref, watch, computed, onMounted } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { updateApi, type PublicVersion } from '@/api/update'
import SupplierSwitcher from './SupplierSwitcher.vue'

const { t, locale } = useI18n()
function setLocale(l: 'cs' | 'en') {
  locale.value = l
  localStorage.setItem('locale', l)
}

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const supplierStore = useSupplierStore()

const sidebarOpen = ref(false)   // mobile drawer stav

async function logout() {
  await auth.logout()
  router.push('/login')
}

// ── SVG ikony ────────────────────────────────────────────────────────────────
const ICONS = {
  dashboard: 'M3 12l9-9 9 9M5 10v10h14V10',
  invoice:   'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z',
  recurring: 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  stock:     'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
  bank:      'M3 9l9-7 9 7m-2 0v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9m4 11V13h4v7',
  cash:      'M17 9V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2m2 4h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm7-5a2 2 0 1 1-4 0 2 2 0 0 1 4 0z',
  stats:     'M3 3v18h18M7 14l4-4 4 4 5-5',
  clients:   'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  projects:  'M3 7l9-4 9 4-9 4-9-4zM3 12l9 4 9-4M3 17l9 4 9-4',
  dph:       'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z',
  settings:  'M10.325 4.317a1 1 0 0 1 1.94 0l.31 1.241a7.5 7.5 0 0 1 2.106.873l1.097-.633a1 1 0 0 1 1.371.366l.97 1.683a1 1 0 0 1-.366 1.366l-1.094.632a7.5 7.5 0 0 1 0 2.428l1.094.632a1 1 0 0 1 .366 1.366l-.97 1.683a1 1 0 0 1-1.371.366l-1.097-.633a7.5 7.5 0 0 1-2.106.873l-.31 1.241a1 1 0 0 1-1.94 0l-.31-1.241a7.5 7.5 0 0 1-2.106-.873l-1.097.633a1 1 0 0 1-1.371-.366l-.97-1.683a1 1 0 0 1 .366-1.366l1.094-.632a7.5 7.5 0 0 1 0-2.428l-1.094-.632a1 1 0 0 1-.366-1.366l.97-1.683a1 1 0 0 1 1.371-.366l1.097.633a7.5 7.5 0 0 1 2.106-.873l.31-1.241zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  quote:     'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-5l-4 4v-4z',
  users:     'M12 4.354a4 4 0 1 1 0 5.292M15 21H3v-1a6 6 0 0 1 12 0v1zm0 0h6v-1a6 6 0 0 0-9-5.197M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  help:      'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827V14m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
}

// ── Navigační struktura ───────────────────────────────────────────────────────
interface NavItem {
  to: string
  label: string
  icon: string
}
interface NavGroup {
  section?: string
  items: NavItem[]
}

const navGroups = computed<NavGroup[]>(() => {
  const isAdmin = auth.user?.role === 'admin'

  const groups: NavGroup[] = [
    {
      items: [
        { to: '/', label: t('nav.dashboard'), icon: ICONS.dashboard },
      ],
    },
    {
      section: t('nav.section_sales'),
      items: [
        { to: '/invoices',                    label: t('nav.invoices'),               icon: ICONS.invoice },
        { to: '/invoices?type=proforma',      label: t('nav.invoices_proforma'),      icon: ICONS.invoice },
        { to: '/invoices?type=credit_note',   label: t('nav.invoices_credit_note'),   icon: ICONS.invoice },
        { to: '/quotes',                      label: t('quote.nav_item'),             icon: ICONS.quote },
        { to: '/recurring-invoices',          label: t('recurring_invoice.nav_item'), icon: ICONS.recurring },
      ],
    },
    {
      section: t('nav.section_purchase'),
      items: [
        { to: '/purchase-invoices',           label: t('nav.purchase_invoices'),            icon: ICONS.invoice },
        { to: '/recurring-purchase-invoices', label: t('nav.recurring_purchase_invoices'),  icon: ICONS.recurring },
        { to: '/receipts',                    label: t('nav.receipts'),                     icon: ICONS.invoice },
        { to: '/items',                       label: t('nav.items'),                        icon: ICONS.stock },
      ],
    },
    {
      section: t('nav.section_finance'),
      items: [
        { to: '/bank',  label: t('nav.bank'),          icon: ICONS.bank },
        { to: '/cash',  label: t('nav.cash_register'), icon: ICONS.cash },
        { to: '/stats', label: t('nav.stats'),         icon: ICONS.stats },
      ],
    },
    {
      section: t('nav.section_clients'),
      items: [
        { to: '/clients',  label: t('nav.clients'),  icon: ICONS.clients },
        { to: '/projects', label: t('nav.projects'), icon: ICONS.projects },
      ],
    },
    {
      section: t('nav.reports'),
      items: [
        { to: '/reports/dph',               label: t('nav.report_dph'),               icon: ICONS.dph },
        { to: '/reports/kontrolni-hlaseni', label: t('nav.report_kontrolni_hlaseni'), icon: ICONS.dph },
        { to: '/reports/dphdp3',            label: t('nav.report_dphdp3'),            icon: ICONS.dph },
        { to: '/reports/priznani-dani',     label: t('nav.report_priznani_dani'),     icon: ICONS.dph },
      ],
    },
  ]

  if (isAdmin) {
    groups.push({
      section: t('nav.settings'),
      items: [
        { to: '/admin/settings', label: t('nav.settings'), icon: ICONS.settings },
        { to: '/admin/users',    label: t('nav.users'),    icon: ICONS.users },
      ],
    })
  }

  return groups
})

// Aktivní stav — respektuje query param (type=proforma atd.)
function isActive(to: string): boolean {
  const [toPath, toQuery] = to.split('?')
  if (toPath === '/') return route.path === '/'
  if (!route.path.startsWith(toPath)) return false
  if (toQuery) {
    const params = new URLSearchParams(toQuery)
    for (const [k, v] of params.entries()) {
      if ((route.query[k] as string | undefined) !== v) return false
    }
  } else {
    // Čistá cesta — neaktivuj pokud route má type query (patří jinému itemu)
    if (toPath === '/invoices' && route.query.type) return false
  }
  return true
}

// Zavři sidebar při navigaci
watch(() => route.path, () => { sidebarOpen.value = false })

// Verze
const versionInfo = ref<PublicVersion | null>(null)
onMounted(async () => {
  try { versionInfo.value = await updateApi.publicVersion() } catch {}
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">

    <!-- ── Topbar ─────────────────────────────────────────────────── -->
    <header class="sticky top-0 z-20 bg-white border-b border-neutral-200 shrink-0">
      <div class="h-14 px-4 flex items-center justify-between gap-4">

        <!-- Logo -->
        <RouterLink to="/" class="flex items-center gap-2.5 shrink-0" @click="sidebarOpen = false">
          <img src="/styles/logo.svg" alt="MyInvoice" class="w-8 h-8" />
          <span class="text-sm font-semibold leading-tight select-none">
            My<span class="text-primary-600">Invoice</span><span class="text-neutral-400 font-normal">.cz</span>
          </span>
        </RouterLink>

        <!-- Pravá strana topbaru -->
        <div class="flex items-center gap-2.5 text-sm">

          <!-- Jméno uživatele (desktop) -->
          <RouterLink
            to="/profile/totp"
            class="hidden lg:inline text-sm text-neutral-600 hover:text-primary-700 hover:underline"
            :title="t('auth.totp_2fa')"
          >{{ auth.user?.name }}</RouterLink>

          <!-- Přepínač jazyka -->
          <div class="hidden sm:inline-flex items-center border border-neutral-200 rounded-md overflow-hidden">
            <button
              @click="setLocale('cs')"
              title="Čeština"
              class="cursor-pointer h-8 px-2 inline-flex items-center"
              :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'"
            >
              <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                <rect width="6" height="2" fill="#ffffff"/>
                <rect y="2" width="6" height="2" fill="#d7141a"/>
                <polygon points="0,0 3,2 0,4" fill="#11457e"/>
              </svg>
            </button>
            <button
              @click="setLocale('en')"
              title="English"
              class="cursor-pointer h-8 px-2 inline-flex items-center border-l border-neutral-200"
              :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'"
            >
              <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                <clipPath id="t"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#t)" stroke="#C8102E" stroke-width="4"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
              </svg>
            </button>
          </div>

          <!-- Nápověda -->
          <a
            href="/manual"
            target="_blank"
            rel="noopener"
            class="hidden sm:inline-flex w-8 h-8 items-center justify-center rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-primary-700"
            :title="t('nav.help')"
            :aria-label="t('nav.help')"
          >
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
            </svg>
          </a>

          <!-- Odhlásit (desktop) -->
          <button
            @click="logout"
            class="cursor-pointer hidden sm:inline-flex px-3 h-8 items-center text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50"
          >{{ t('nav.logout') }}</button>

          <!-- Hamburger (mobile) -->
          <button
            type="button"
            @click="sidebarOpen = !sidebarOpen"
            :aria-expanded="sidebarOpen"
            aria-label="Menu"
            class="lg:hidden inline-flex items-center justify-center w-9 h-9 rounded-md text-neutral-700 hover:bg-neutral-100"
          >
            <svg v-if="!sidebarOpen" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Active supplier banner -->
      <div v-if="supplierStore.hasMultiple && supplierStore.currentSupplier" class="bg-primary-50 border-t border-primary-100">
        <div class="px-4 py-1.5 text-xs text-primary-700 flex items-center gap-2">
          <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/>
          </svg>
          <span class="flex-1 min-w-0 truncate">
            {{ t('supplier.active_label') }}: <strong class="font-semibold">{{ supplierStore.currentSupplier.company_name }}</strong>
            <span v-if="supplierStore.currentSupplier.ic" class="font-mono text-primary-600 ml-1">({{ t('common.ic') }} {{ supplierStore.currentSupplier.ic }})</span>
          </span>
          <SupplierSwitcher />
        </div>
      </div>
    </header>

    <!-- ── Tělo: sidebar + obsah ──────────────────────────────────── -->
    <div class="flex flex-1 min-h-0">

      <!-- Mobile backdrop -->
      <div
        v-if="sidebarOpen"
        @click="sidebarOpen = false"
        class="lg:hidden fixed inset-0 bg-neutral-900/30 z-30"
        aria-hidden="true"
      ></div>

      <!-- Sidebar -->
      <aside
        :class="[
          'fixed lg:sticky top-14 z-40 lg:z-auto',
          'h-[calc(100vh-3.5rem)] w-56 shrink-0',
          'bg-white border-r border-neutral-200',
          'flex flex-col',
          'transition-transform duration-200 ease-in-out',
          sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
        ]"
      >
        <!-- Nav skupiny -->
        <nav class="flex-1 overflow-y-auto px-2.5 py-3">
          <template v-for="(group, gi) in navGroups" :key="gi">

            <!-- Sekční nadpis -->
            <div
              v-if="group.section"
              class="px-2 pb-1 text-[10px] font-semibold text-neutral-400 uppercase tracking-wider"
              :class="gi === 0 ? 'pt-2' : 'pt-5'"
            >{{ group.section }}</div>

            <!-- Položky -->
            <RouterLink
              v-for="item in group.items"
              :key="item.to"
              :to="item.to"
              class="flex items-center gap-2.5 px-2.5 py-[7px] rounded-md text-sm transition-colors leading-tight"
              :class="isActive(item.to)
                ? 'bg-primary-50 text-primary-700 font-medium'
                : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'"
            >
              <svg class="w-[15px] h-[15px] shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
              </svg>
              {{ item.label }}
            </RouterLink>

          </template>
        </nav>

        <!-- Verze (dole) -->
        <div v-if="versionInfo" class="px-4 py-2.5 border-t border-neutral-100">
          <RouterLink
            v-if="auth.user?.role === 'admin'"
            to="/admin/update"
            class="inline-flex items-center gap-1.5 text-xs text-neutral-400 hover:text-neutral-600 transition-colors"
            :title="t('updates.title')"
          >
            <span>v{{ versionInfo.current }}</span>
            <span
              v-if="versionInfo.has_update"
              class="inline-flex items-center gap-1 rounded-full bg-primary-100 text-primary-700 px-1.5 py-0.5 text-[10px] font-semibold leading-none"
            >
              <svg class="w-2 h-2" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6"/></svg>
              v{{ versionInfo.latest }}
            </span>
          </RouterLink>
          <span v-else class="text-xs text-neutral-400">v{{ versionInfo.current }}</span>
        </div>

        <!-- Mobile only: uživatel + jazyk + odhlásit na dně sidebaru -->
        <div class="lg:hidden border-t border-neutral-200 px-4 py-3 bg-neutral-50 space-y-3">
          <div class="flex items-center justify-between">
            <div class="text-sm">
              <div class="font-medium text-neutral-900">{{ auth.user?.name }}</div>
              <div class="text-xs text-neutral-500">{{ auth.user?.email }} · {{ auth.user?.role }}</div>
            </div>
            <a
              href="/manual"
              target="_blank"
              rel="noopener"
              class="inline-flex w-9 h-9 items-center justify-center rounded-md text-neutral-600 hover:bg-white"
              :title="t('nav.help')"
            >
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.help" />
              </svg>
            </a>
          </div>
          <div class="flex items-center justify-between gap-3">
            <div class="inline-flex items-center border border-neutral-200 bg-white rounded-md overflow-hidden">
              <button
                @click="setLocale('cs')"
                title="Čeština"
                class="cursor-pointer h-9 px-3 inline-flex items-center"
                :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'"
              >
                <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                  <rect width="6" height="2" fill="#ffffff"/>
                  <rect y="2" width="6" height="2" fill="#d7141a"/>
                  <polygon points="0,0 3,2 0,4" fill="#11457e"/>
                </svg>
              </button>
              <button
                @click="setLocale('en')"
                title="English"
                class="cursor-pointer h-9 px-3 inline-flex items-center border-l border-neutral-200"
                :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'"
              >
                <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                  <clipPath id="t-mob"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                  <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                  <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                  <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#t-mob)" stroke="#C8102E" stroke-width="4"/>
                  <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                  <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
                </svg>
              </button>
            </div>
            <button
              @click="logout"
              class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-white"
            >{{ t('nav.logout') }}</button>
          </div>
        </div>
      </aside>

      <!-- ── Hlavní obsah ────────────────────────────────────────── -->
      <div class="flex-1 min-w-0 flex flex-col">
        <main class="flex-1 px-5 sm:px-8 py-6 max-w-5xl w-full">
          <RouterView />
        </main>

        <footer class="px-5 sm:px-8 py-5 border-t border-neutral-200 text-xs text-neutral-500 flex flex-wrap items-center gap-x-1.5 gap-y-1 leading-none max-w-5xl w-full">
          <span>Developed by</span>
          <a href="https://mywebdesign.cz" target="_blank" rel="noopener" class="hover:text-neutral-700">MyWebdesign.cz s.r.o.</a>
          <span aria-hidden="true">·</span>
          <a
            href="https://github.com/radekhulan/myinvoice"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 hover:text-neutral-700"
          >
            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/>
            </svg>
            GitHub
          </a>
        </footer>
      </div>

    </div>
  </div>
</template>
