<template>
  <div class="h-screen flex overflow-hidden" style="background: var(--bg-app);">

    <!-- Overlay mobile -->
    <Transition name="fade">
      <div
        v-if="open && isMobile"
        class="fixed inset-0 bg-black/50 z-20 lg:hidden"
        @click="open = false"
      />
    </Transition>

    <!-- ═══════════════════════════════════════════
         SIDEBAR
         Usa tokens --sb-* que conmutan con el tema.
         Dark:  fondo #0a0b0f — igual al original
         Light: fondo #ffffff con bordes Ink-200
    ════════════════════════════════════════════ -->
    <aside
      :class="[
        'flex flex-col flex-shrink-0 transition-all duration-[250ms] ease-in-out',
        'lg:relative lg:translate-x-0',
        'fixed top-0 left-0 h-full z-30',
        open ? 'translate-x-0 w-[220px]' : '-translate-x-full lg:translate-x-0 lg:w-14 w-[220px]',
      ]"
      :style="`background: var(--sb-bg); border-right: 1px solid var(--sb-border);`"
    >

      <!-- ── Logo ─────────────────────────────── -->
      <div
        class="flex items-center h-14 px-3 flex-shrink-0"
        :style="`border-bottom: 1px solid var(--sb-border);`"
      >
        <div class="w-8 h-8 rounded-[8px] flex items-center justify-center flex-shrink-0 bg-[#5b5ef6] flex-shrink-0">
          <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944
                 a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9
                 c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03
                 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
          </svg>
        </div>
        <Transition name="slide-text">
          <span
            v-if="open"
            class="ml-2.5 text-[13px] font-semibold whitespace-nowrap tracking-tight"
            :style="`color: var(--sb-logo-text);`"
          >
            PAS Mobile
          </span>
        </Transition>
      </div>

      <!-- ── Navegación ────────────────────────── -->
      <nav class="flex-1 py-3 overflow-y-auto overflow-x-hidden">
        <NavGroup :label="open ? 'Principal' : ''" :open="open">
          <NavItem :open="open" href="/customers" :active="isActive('/customers')" label="Clientes">
            <template #icon>
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857
                     M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857
                     m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </template>
          </NavItem>

          <NavItem :open="open" href="/quotes" :active="isActive('/quotes')" label="Cotizaciones">
            <template #icon>
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0
                     012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0
                     01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </template>
          </NavItem>
        </NavGroup>

        <NavGroup :label="open ? 'Administración' : ''" :open="open">
          <NavItem
            :open="open"
            href="/admin/checkout-sessions"
            :active="isActive('/admin/checkout-sessions')"
            label="Auditoría Checkout"
          >
            <template #icon>
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0
                     002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0
                     002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
              </svg>
            </template>
          </NavItem>

          <NavItem
            :open="open"
            href="/admin/settings"
            :active="isActive('/admin/settings')"
            label="Configuración"
          >
            <template #icon>
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0
                     002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724
                     0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724
                     0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724
                     1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724
                     1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724
                     1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724
                     1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608
                     2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </template>
          </NavItem>
        </NavGroup>
      </nav>

      <!-- ── Footer: toggle de tema + colapsar ── -->
      <div
        class="flex-shrink-0"
        :style="`border-top: 1px solid var(--sb-border);`"
      >

        <!-- Selector de tema — visible cuando sidebar abierto -->
        <Transition name="slide-text">
          <div v-if="open" class="px-3 py-2.5">
            <!-- Tres botones: Light / System / Dark -->
            <div
              class="flex items-center rounded-[8px] p-0.5 gap-0.5"
              :style="`background: var(--sb-item-hover-bg);`"
            >
              <button
                v-for="opt in themeOptions"
                :key="opt.value"
                @click="setTheme(opt.value)"
                :title="opt.label"
                class="flex-1 flex items-center justify-center gap-1.5 h-7 rounded-[6px] text-[11px] font-medium transition-all"
                :style="currentTheme === opt.value
                  ? 'background: #5b5ef6; color: #ffffff;'
                  : `background: transparent; color: var(--sb-collapse-text);`"
              >
                <!-- Ícono SVG por opción -->
                <!-- Sol -->
                <svg v-if="opt.value === 'light'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <circle cx="12" cy="12" r="4" stroke-width="2"/>
                  <path stroke-linecap="round" stroke-width="2"
                    d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42
                       M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
                </svg>
                <!-- Monitor (sistema) -->
                <svg v-if="opt.value === 'system'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <rect x="2" y="3" width="20" height="14" rx="2" stroke-width="2"/>
                  <path stroke-linecap="round" stroke-width="2" d="M8 21h8M12 17v4"/>
                </svg>
                <!-- Luna -->
                <svg v-if="opt.value === 'dark'" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-width="2"
                    d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>
                </svg>

                <span v-if="open" class="leading-none">{{ opt.label }}</span>
              </button>
            </div>
          </div>
        </Transition>

        <!-- Modo colapsado: botón de tema como ícono solo -->
        <div v-if="!open" class="flex justify-center py-2">
          <button
            @click="cycleTheme"
            :title="`Tema: ${currentTheme}`"
            class="w-8 h-8 rounded-[8px] flex items-center justify-center transition-all"
            :style="`color: var(--sb-collapse-text);`"
          >
            <!-- Sol -->
            <svg v-if="currentTheme === 'light'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <circle cx="12" cy="12" r="4" stroke-width="2"/>
              <path stroke-linecap="round" stroke-width="2"
                d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42
                   M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
            </svg>
            <!-- Monitor -->
            <svg v-else-if="currentTheme === 'system'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <rect x="2" y="3" width="20" height="14" rx="2" stroke-width="2"/>
              <path stroke-linecap="round" stroke-width="2" d="M8 21h8M12 17v4"/>
            </svg>
            <!-- Luna -->
            <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-width="2"
                d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>
            </svg>
          </button>
        </div>

        <!-- Botón colapsar — solo desktop -->
        <div
          class="hidden lg:flex items-center h-11 px-3"
          :style="`border-top: 1px solid var(--sb-divider);`"
        >
          <button
            @click="open = !open"
            class="w-8 h-8 rounded-[8px] flex items-center justify-center transition-all"
            :style="`color: var(--sb-collapse-text);`"
            @mouseenter="e => { (e.currentTarget as HTMLElement).style.background = 'var(--sb-collapse-hover)'; (e.currentTarget as HTMLElement).style.color = 'var(--sb-item-hover-text)' }"
            @mouseleave="e => { (e.currentTarget as HTMLElement).style.background = 'transparent'; (e.currentTarget as HTMLElement).style.color = 'var(--sb-collapse-text)' }"
            :title="open ? 'Colapsar' : 'Expandir'"
          >
            <svg
              class="w-3.5 h-3.5 transition-transform duration-[250ms]"
              :class="open ? '' : 'rotate-180'"
              fill="none" stroke="currentColor" viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <Transition name="slide-text">
            <span v-if="open" class="ml-2 text-[11px] whitespace-nowrap" :style="`color: var(--sb-collapse-text);`">
              Colapsar
            </span>
          </Transition>
        </div>
      </div>
    </aside>

    <!-- ═══════════════════════════════════════════
         CONTENIDO PRINCIPAL
    ════════════════════════════════════════════ -->
    <div class="flex-1 flex flex-col min-w-0 h-full">

      <!-- Topbar mobile -->
      <header
        class="lg:hidden flex items-center h-14 px-4 flex-shrink-0"
        style="background: var(--bg-card); border-bottom: 1px solid var(--border); box-shadow: var(--shadow-card);"
      >
        <button
          @click="open = !open"
          class="w-8 h-8 rounded-[8px] flex items-center justify-center transition-all"
          style="color: var(--text-2);"
          aria-label="Abrir menú"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        <span class="ml-3 text-sm font-semibold" style="color: var(--text-1);">PAS Mobile</span>

        <!-- Toggle de tema en topbar mobile -->
        <button
          @click="cycleTheme"
          class="ml-auto w-8 h-8 rounded-[8px] flex items-center justify-center transition-all"
          style="color: var(--text-2);"
          :title="`Tema: ${currentTheme}`"
        >
          <svg v-if="currentTheme === 'light'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="4" stroke-width="2"/>
            <path stroke-linecap="round" stroke-width="2"
              d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42
                 M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
          </svg>
          <svg v-else-if="currentTheme === 'system'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <rect x="2" y="3" width="20" height="14" rx="2" stroke-width="2"/>
            <path stroke-linecap="round" stroke-width="2" d="M8 21h8M12 17v4"/>
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-width="2"
              d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>
          </svg>
        </button>
      </header>

      <main class="flex-1 overflow-y-auto">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import NavItem from '@/components/Sidebar/NavItem.vue'
import NavGroup from '@/components/Sidebar/NavGroup.vue'

// ─── Sidebar abierto/cerrado ──────────────────────────────────────────────
const isMobile = ref(false)
const open = ref(true)

const checkMobile = () => {
  isMobile.value = window.innerWidth < 1024
  open.value = !isMobile.value
}

onMounted(() => { checkMobile(); window.addEventListener('resize', checkMobile) })
onUnmounted(() => { window.removeEventListener('resize', checkMobile) })

// ─── Ruta activa ──────────────────────────────────────────────────────────
const page = usePage()
const isActive = (path: string) =>
  path === '/' ? page.url === '/' : page.url.startsWith(path)

// ─── Toggle de tema ───────────────────────────────────────────────────────
type Theme = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'pas-theme'

const themeOptions: { value: Theme; label: string }[] = [
  { value: 'light',  label: 'Claro' },
  { value: 'system', label: 'Auto'  },
  { value: 'dark',   label: 'Oscuro' },
]

// Leer el valor inicial desde el atributo ya aplicado en el blade
const getStoredTheme = (): Theme => {
  const stored = localStorage.getItem(STORAGE_KEY) as Theme | null
  return stored ?? 'system'
}

const currentTheme = ref<Theme>(getStoredTheme())

const applyTheme = (t: Theme) => {
  const html = document.documentElement
  if (t === 'system') {
    // Quitar data-theme para que tome la media query del OS
    html.removeAttribute('data-theme')
    // Pero queremos que la UI refleje la preferencia real del sistema
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
    html.setAttribute('data-theme', prefersDark ? 'dark' : 'light')
  } else {
    html.setAttribute('data-theme', t)
  }
}

const setTheme = (t: Theme) => {
  currentTheme.value = t
  localStorage.setItem(STORAGE_KEY, t)
  applyTheme(t)
}

// Ciclo simple para el botón de ícono (colapsado / mobile)
const themeOrder: Theme[] = ['light', 'system', 'dark']
const cycleTheme = () => {
  const idx = themeOrder.indexOf(currentTheme.value)
  setTheme(themeOrder[(idx + 1) % themeOrder.length])
}

// Escuchar cambios en la preferencia del sistema cuando está en modo "system"
let mediaQuery: MediaQueryList | null = null

onMounted(() => {
  // Asegurar que el atributo esté bien desde el inicio
  applyTheme(currentTheme.value)

  mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
  mediaQuery.addEventListener('change', () => {
    if (currentTheme.value === 'system') applyTheme('system')
  })
})

onUnmounted(() => {
  mediaQuery?.removeEventListener('change', () => {})
})
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-text-enter-active, .slide-text-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.slide-text-enter-from, .slide-text-leave-to { opacity: 0; transform: translateX(-4px); }
</style>
