<script setup lang="ts">
import type { Component } from 'vue'
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { usePage, Link } from '@inertiajs/vue3'
import {
  Shield,
  MessagesSquare,
  Users,
  FileText,
  ShieldCheck,
  FileStack,
  TriangleAlert,
  Download,
  Zap,
  BarChart3,
  CreditCard,
  MessageCircleWarning,
  ClipboardCheck,
  Lightbulb,
  Settings,
  UserPlus,
  PanelLeft,
  Sun,
  Moon,
  Monitor,
  LogOut,
} from '@lucide/vue'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarSeparator,
} from '@/components/UI/sidebar'
import { provideSidebarContext } from '@/components/UI/sidebar/utils'
import { TooltipProvider } from '@/components/UI/tooltip'

type Theme = 'light' | 'dark' | 'system'
type NavItem = { href: string; label: string; icon: Component; activePath?: string }

const props = defineProps<{
  modelValue?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
}>()

// ─── Sidebar state ─────────────────────────────────────────────────────────
const MOBILE_BREAKPOINT = 1024

const isMobile = ref(false)
const internalOpen = ref(true)

const open = computed({
  get: () => props.modelValue ?? internalOpen.value,
  set: (value: boolean) => {
    internalOpen.value = value
    emit('update:modelValue', value)
  },
})

const checkMobile = () => {
  isMobile.value = window.innerWidth < MOBILE_BREAKPOINT
}

onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
  open.value = !isMobile.value
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

const state = computed(() => (open.value ? 'expanded' : 'collapsed'))
const openMobile = computed({
  get: () => open.value,
  set: (value: boolean) => {
    open.value = value
  },
})

const setOpen = (value: boolean) => {
  open.value = value
}

const setOpenMobile = (value: boolean) => {
  open.value = value
}

const toggleSidebar = () => {
  open.value = !open.value
}

provideSidebarContext({
  state,
  open,
  setOpen,
  isMobile,
  openMobile,
  setOpenMobile,
  toggleSidebar,
})

// ─── Active route ──────────────────────────────────────────────────────────
const page = usePage()

const isActive = (path: string) =>
  path === '/' ? page.url === '/' : page.url.startsWith(path)

// ─── Auth ──────────────────────────────────────────────────────────────────
const auth = computed(() => (page.props as any).auth)

// ─── Theme ─────────────────────────────────────────────────────────────────
const STORAGE_KEY = 'pas-theme'

const themeOptions: { value: Theme; label: string; icon: Component }[] = [
  { value: 'light', label: 'Claro', icon: Sun },
  { value: 'system', label: 'Auto', icon: Monitor },
  { value: 'dark', label: 'Oscuro', icon: Moon },
]

const getStoredTheme = (): Theme => {
  if (typeof window === 'undefined') {
    return 'system'
  }

  const stored = localStorage.getItem(STORAGE_KEY) as Theme | null
  return stored ?? 'system'
}

const currentTheme = ref<Theme>(getStoredTheme())

const applyTheme = (theme: Theme) => {
  if (typeof window === 'undefined') {
    return
  }

  const html = document.documentElement

  if (theme === 'system') {
    html.removeAttribute('data-theme')
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
    html.setAttribute('data-theme', prefersDark ? 'dark' : 'light')
  } else {
    html.setAttribute('data-theme', theme)
  }
}

const setTheme = (theme: Theme) => {
  currentTheme.value = theme
  localStorage.setItem(STORAGE_KEY, theme)
  applyTheme(theme)
}

const themeOrder: Theme[] = ['light', 'system', 'dark']
const cycleTheme = () => {
  const index = themeOrder.indexOf(currentTheme.value)
  setTheme(themeOrder[(index + 1) % themeOrder.length])
}

let mediaQuery: MediaQueryList | null = null
let mediaHandler: (() => void) | null = null

onMounted(() => {
  applyTheme(currentTheme.value)

  mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
  mediaHandler = () => {
    if (currentTheme.value === 'system') {
      applyTheme('system')
    }
  }
  mediaQuery.addEventListener('change', mediaHandler)
})

onUnmounted(() => {
  if (mediaQuery && mediaHandler) {
    mediaQuery.removeEventListener('change', mediaHandler)
  }
})

// ─── Navigation ────────────────────────────────────────────────────────────
const mainItems: NavItem[] = [
  { href: '/conversations', label: 'Conversaciones', icon: MessagesSquare },
  { href: '/customers', label: 'Clientes', icon: Users },
  { href: '/quotes', label: 'Cotizaciones', icon: FileText },
  { href: '/polizas', label: 'Pólizas', icon: ShieldCheck },
  { href: '/policy-documents', label: 'Docs Pólizas', icon: FileStack },
  { href: '/documentacion-pendiente', label: 'Doc. pendiente', icon: TriangleAlert },
  { href: '/ingesta-pendientes', label: 'Ingesta', icon: Download },
  { href: '/mantenimiento-cartera', label: 'Mantenimiento de cartera', icon: Zap },
  { href: '/reporte-cartera', label: 'Reporte de cartera', icon: BarChart3 },
]

const adminItems: NavItem[] = [
  { href: '/admin/facturacion', label: 'Facturación', icon: CreditCard },
  { href: '/coverage-documents', label: 'Documentacion', icon: FileText },
  { href: '/admin/conversations', label: 'Auditoría Chats', icon: MessageCircleWarning },
  { href: '/admin/checkout-sessions', label: 'Auditoría Checkout', icon: ClipboardCheck },
  { href: '/admin/agent-prompts', label: 'Prompts IA', icon: Lightbulb },
  { href: '/admin/analytics/funnel', label: 'Analytics', activePath: '/admin/analytics', icon: BarChart3 },
  { href: '/admin/settings', label: 'Configuración', icon: Settings },
  { href: '/admin/users/create', label: 'Usuarios', activePath: '/admin/users', icon: UserPlus },
]

const userInitials = computed(() => {
  const name = auth.value?.user?.name as string | undefined
  return name ? name.charAt(0).toUpperCase() : ''
})

const currentThemeIcon = computed(() => {
  return themeOptions.find((option) => option.value === currentTheme.value)?.icon ?? Sun
})
</script>

<template>
  <div
    class="contents"
    :style="{
      '--sidebar-width': '220px',
      '--sidebar-width-icon': '56px',
    }"
  >
    <TooltipProvider :delay-duration="0">
      <Sidebar collapsible="icon">
        <SidebarHeader class="h-14 p-0 px-3 flex items-center border-b border-[var(--sb-border)]">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 bg-[#5b5ef6]">
            <Shield class="w-4 h-4 text-white" />
          </div>
          <span
            v-if="state === 'expanded'"
            class="ml-2.5 text-[13px] font-semibold whitespace-nowrap tracking-tight text-[var(--sb-logo-text)]"
          >
            PAS Mobile
          </span>
        </SidebarHeader>

        <SidebarContent class="app-sidebar-content py-3">
          <SidebarGroup class="px-0 py-0">
            <SidebarGroupLabel
              v-if="state === 'expanded'"
              class="h-7 px-4 text-[9px] font-semibold uppercase tracking-[.08em] text-[var(--sb-group-label)]"
            >
              Principal
            </SidebarGroupLabel>
            <SidebarSeparator v-else class="my-2 bg-[var(--sb-divider)]" />

            <SidebarGroupContent>
              <SidebarMenu>
                <SidebarMenuItem v-for="item in mainItems" :key="item.href">
                  <SidebarMenuButton
                    as-child
                    :is-active="isActive(item.activePath ?? item.href)"
                    :tooltip="item.label"
                    class="h-9 text-[13px] font-medium mx-2 px-2.5 rounded-lg data-[active=true]:!bg-[var(--accent-600)] data-[active=true]:!text-white data-[active=true]:hover:!bg-[var(--accent-600)] data-[active=true]:hover:!text-white"
                  >
                    <Link :href="item.href">
                      <component :is="item.icon" />
                      <span>{{ item.label }}</span>
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>

          <SidebarGroup v-if="auth?.user?.role === 'admin'" class="px-0 py-0">
            <SidebarGroupLabel
              v-if="state === 'expanded'"
              class="h-7 px-4 text-[9px] font-semibold uppercase tracking-[.08em] text-[var(--sb-group-label)]"
            >
              Administración
            </SidebarGroupLabel>
            <SidebarSeparator v-else class="my-2 bg-[var(--sb-divider)]" />

            <SidebarGroupContent>
              <SidebarMenu>
                <SidebarMenuItem v-for="item in adminItems" :key="item.href">
                  <SidebarMenuButton
                    as-child
                    :is-active="isActive(item.activePath ?? item.href)"
                    :tooltip="item.label"
                    class="h-9 text-[13px] font-medium mx-2 px-2.5 rounded-lg data-[active=true]:!bg-[var(--accent-600)] data-[active=true]:!text-white data-[active=true]:hover:!bg-[var(--accent-600)] data-[active=true]:hover:!text-white"
                  >
                    <Link :href="item.href">
                      <component :is="item.icon" />
                      <span>{{ item.label }}</span>
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
        </SidebarContent>

        <SidebarFooter class="p-0 border-t border-[var(--sb-border)]">
          <!-- Theme selector: expanded desktop -->
          <div v-if="state === 'expanded' && !isMobile" class="px-3 py-2.5">
            <div class="flex items-center rounded-lg p-0.5 gap-0.5" style="background: var(--sb-item-hover-bg);">
              <button
                v-for="option in themeOptions"
                :key="option.value"
                type="button"
                :title="option.label"
                class="flex-1 flex items-center justify-center gap-1.5 h-7 rounded-md text-[11px] font-medium transition-all"
                :class="currentTheme === option.value
                  ? 'bg-[#5b5ef6] text-white'
                  : 'bg-transparent text-[var(--sb-collapse-text)]'"
                @click="setTheme(option.value)"
              >
                <component :is="option.icon" class="w-3 h-3" />
                <span class="leading-none">{{ option.label }}</span>
              </button>
            </div>
          </div>

          <!-- Theme button: collapsed desktop or mobile -->
          <div v-if="state === 'collapsed' || isMobile" class="flex justify-center py-2">
            <button
              type="button"
              :title="`Tema: ${currentTheme}`"
              class="w-8 h-8 rounded-lg flex items-center justify-center transition-all text-[var(--sb-collapse-text)] hover:text-[var(--sb-item-hover-text)] hover:bg-[var(--sb-collapse-hover)]"
              @click="cycleTheme"
            >
              <component :is="currentThemeIcon" class="w-3.5 h-3.5" />
            </button>
          </div>

          <SidebarSeparator class="bg-[var(--sb-divider)]" />

          <!-- Authenticated user -->
          <div
            v-if="state === 'expanded' && auth?.user"
            class="px-3 py-2.5 flex items-center gap-2 border-t border-[var(--sb-divider)]"
          >
            <div class="w-6 h-6 rounded-full flex-shrink-0 flex items-center justify-center text-[10px] font-bold text-white bg-[#5b5ef6]">
              {{ userInitials }}
            </div>
            <div class="flex-1 min-w-0">
              <Link
                href="/profile"
                class="text-[11px] font-medium truncate block hover:underline"
                style="color: var(--sb-item-text);"
              >
                {{ auth.user.name }}
              </Link>
            </div>
            <Link
              href="/logout"
              method="post"
              as="button"
              title="Cerrar sesión"
              class="w-6 h-6 rounded-md flex items-center justify-center transition-all text-[var(--sb-collapse-text)] hover:text-[var(--sb-item-hover-text)] hover:bg-[var(--sb-collapse-hover)]"
            >
              <LogOut class="w-3.5 h-3.5" />
            </Link>
          </div>

          <!-- Desktop collapse toggle -->
          <div
            v-if="!isMobile"
            class="hidden lg:flex items-center h-11 px-3 border-t border-[var(--sb-divider)]"
          >
            <button
              type="button"
              :title="state === 'expanded' ? 'Colapsar' : 'Expandir'"
              class="w-8 h-8 rounded-lg flex items-center justify-center transition-all text-[var(--sb-collapse-text)] hover:text-[var(--sb-item-hover-text)] hover:bg-[var(--sb-collapse-hover)]"
              @click="toggleSidebar"
            >
              <PanelLeft
                class="w-3.5 h-3.5 transition-transform duration-200"
                :class="state === 'collapsed' ? 'rotate-180' : ''"
              />
            </button>
            <span
              v-if="state === 'expanded'"
              class="ml-2 text-[11px] whitespace-nowrap text-[var(--sb-collapse-text)]"
            >
              Colapsar
            </span>
          </div>
        </SidebarFooter>
      </Sidebar>
    </TooltipProvider>
  </div>
</template>

<style scoped>
.app-sidebar-content :deep(.no-scrollbar) {
  scrollbar-width: thin;
  scrollbar-color: var(--sb-divider) transparent;
}

.app-sidebar-content :deep(.no-scrollbar::-webkit-scrollbar) {
  width: 6px;
}

.app-sidebar-content :deep(.no-scrollbar::-webkit-scrollbar-track) {
  background: transparent;
}

.app-sidebar-content :deep(.no-scrollbar::-webkit-scrollbar-thumb) {
  background: var(--sb-divider);
  border-radius: 3px;
}

.app-sidebar-content :deep(.no-scrollbar:hover::-webkit-scrollbar-thumb) {
  background: var(--sb-group-label);
}
</style>
