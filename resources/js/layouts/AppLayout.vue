<template>
  <div class="h-screen flex overflow-hidden" style="background: var(--bg-app);">
    <AppSidebar v-model="open" />

    <div class="flex-1 flex flex-col min-w-0 h-full">
      <!-- Topbar mobile -->
      <header
        class="lg:hidden flex items-center h-14 px-4 flex-shrink-0"
        style="background: var(--bg-card); border-bottom: 1px solid var(--border); box-shadow: var(--shadow-card);"
      >
        <Button variant="ghost" size="icon" @click="open = true">
          <MenuIcon class="size-4" />
        </Button>
        <span class="ml-3 text-sm font-semibold" style="color: var(--text-1);">PAS Mobile</span>
      </header>

      <main class="flex-1 overflow-y-auto">
        <slot />
      </main>
    </div>

    <Toaster
      position="bottom-right"
      :duration="4000"
      close-button
      :style="{
        '--normal-bg': 'var(--bg-card)',
        '--normal-text': 'var(--text-1)',
        '--normal-border': 'var(--border)',
        '--success-bg': 'var(--badge-ok-bg)',
        '--success-text': 'var(--badge-ok-txt)',
        '--success-border': 'var(--badge-ok-bg)',
        '--error-bg': 'var(--badge-danger-bg)',
        '--error-text': 'var(--badge-danger-txt)',
        '--error-border': 'var(--badge-danger-bg)',
      }"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Toaster, toast } from 'vue-sonner'
import { Button } from '@/components/UI/button'
import { Menu } from '@lucide/vue'
import AppSidebar from '@/components/App/Sidebar.vue'

const MenuIcon = Menu
const open = ref(true)

const page = usePage()
watch(
  () => (page.props as any).flash,
  (flash: any) => {
    if (flash?.success) toast.success(flash.success)
    if (flash?.error) toast.error(flash.error)
  },
  { immediate: true },
)
</script>
