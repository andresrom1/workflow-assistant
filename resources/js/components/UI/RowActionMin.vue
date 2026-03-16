<template>
  <Link
    :href="href"
    :title="label"
    class="inline-flex items-center justify-center w-7 h-7 rounded-[6px] transition-all"
    style="color: var(--text-3);"
    @mouseenter="hovered = true"
    @mouseleave="hovered = false"
    :style="hovered
      ? 'color: #5b5ef6; background: var(--accent-50);'
      : 'color: var(--text-3); background: transparent;'"
  >
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
    </svg>
  </Link>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
defineProps<{ href: string; label: string }>()
const hovered = ref(false)
</script>
