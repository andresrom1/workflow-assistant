<template>
  <div class="mb-1">
    <div class="h-7 flex items-center px-4 overflow-hidden">
      <Transition name="slide-text">
        <span
          v-if="open && label"
          class="text-[9px] font-semibold uppercase tracking-[.08em] whitespace-nowrap"
          :style="`color: var(--sb-group-label);`"
        >
          {{ label }}
        </span>
      </Transition>
      <div
        v-if="!open"
        class="w-full h-px mx-1"
        :style="`background: var(--sb-divider);`"
      />
    </div>
    <div class="space-y-0.5">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{ label: string; open: boolean }>()
</script>

<style scoped>
.slide-text-enter-active, .slide-text-leave-active { transition: opacity 0.12s ease; }
.slide-text-enter-from, .slide-text-leave-to { opacity: 0; }
</style>
