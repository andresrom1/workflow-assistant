<template>
  <div class="mb-1">
    <!-- Header colapsable — solo cuando el sidebar está abierto y hay label -->
    <button
      v-if="open && label"
      type="button"
      @click="toggle"
      class="group w-full h-7 flex items-center justify-between px-4 overflow-hidden transition-colors"
    >
      <span
        class="text-[9px] font-semibold uppercase tracking-[.08em] whitespace-nowrap transition-colors"
        :style="`color: var(--sb-group-label);`"
      >
        {{ label }}
      </span>
      <svg
        class="w-3 h-3 transition-transform duration-200 flex-shrink-0"
        :class="collapsed ? '-rotate-90' : ''"
        :style="`color: var(--sb-group-label);`"
        fill="none" stroke="currentColor" viewBox="0 0 24 24"
      >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <!-- Separador en modo colapsado del sidebar (sin label) -->
    <div v-else-if="!open" class="h-7 flex items-center px-4 overflow-hidden">
      <div class="w-full h-px mx-1" :style="`background: var(--sb-divider);`" />
    </div>

    <!-- Items: grid-rows trick para una transición de altura suave -->
    <div
      class="grid transition-[grid-template-rows] duration-200 ease-in-out"
      :style="{ gridTemplateRows: showItems ? '1fr' : '0fr' }"
    >
      <div class="overflow-hidden">
        <div class="space-y-0.5">
          <slot />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'

const props = defineProps<{ label: string; open: boolean }>()

// Clave de persistencia por grupo (derivada del label)
const storageKey = computed(() => `sidebar-group-collapsed:${props.label}`)

const collapsed = ref(localStorage.getItem(storageKey.value) === '1')

const toggle = () => {
  collapsed.value = !collapsed.value
  localStorage.setItem(storageKey.value, collapsed.value ? '1' : '0')
}

// En modo colapsado del sidebar (open=false) siempre mostramos los íconos;
// el colapso por grupo solo aplica cuando el sidebar está abierto.
const showItems = computed(() => (props.open ? !collapsed.value : true))
</script>

<style scoped>
.slide-text-enter-active, .slide-text-leave-active { transition: opacity 0.12s ease; }
.slide-text-enter-from, .slide-text-leave-to { opacity: 0; }
</style>
