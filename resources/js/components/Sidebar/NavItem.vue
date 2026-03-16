<template>
  <Link
    :href="href"
    :class="[
      'flex items-center gap-2.5 mx-2 px-2.5 rounded-[8px] transition-all duration-150',
      'h-9 text-[13px] font-medium group relative',
    ]"
    :style="active
      ? 'background: #5b5ef6; color: #ffffff;'
      : `background: transparent; color: var(--sb-item-text);`"
    @mouseenter="e => { if (!active) { (e.currentTarget as HTMLElement).style.background = 'var(--sb-item-hover-bg)'; (e.currentTarget as HTMLElement).style.color = 'var(--sb-item-hover-text)' } }"
    @mouseleave="e => { if (!active) { (e.currentTarget as HTMLElement).style.background = 'transparent'; (e.currentTarget as HTMLElement).style.color = 'var(--sb-item-text)' } }"
  >
    <!-- Ícono -->
    <span class="flex-shrink-0 flex items-center justify-center w-4 h-4">
      <slot name="icon" />
    </span>

    <!-- Label -->
    <Transition name="slide-text">
      <span v-if="open" class="whitespace-nowrap overflow-hidden">{{ label }}</span>
    </Transition>

    <!-- Tooltip colapsado — solo desktop -->
    <span
      v-if="!open"
      class="absolute left-full ml-3 px-2.5 py-1.5 rounded-[8px] text-xs font-medium
             whitespace-nowrap opacity-0 group-hover:opacity-100 pointer-events-none
             transition-opacity duration-150 z-50 hidden lg:block"
      style="background: #13151c; color: #eff0f7; border: 1px solid #2d3148;
             box-shadow: 0 4px 12px rgba(0,0,0,.4);"
    >
      {{ label }}
    </span>
  </Link>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
  open: boolean
  href: string
  active: boolean
  label: string
}>()
</script>

<style scoped>
.slide-text-enter-active, .slide-text-leave-active { transition: opacity 0.12s ease; }
.slide-text-enter-from, .slide-text-leave-to { opacity: 0; }
</style>
