<template>
  <Link
    :href="href"
    class="inline-flex items-center gap-1.5 text-sm font-medium transition-colors"
    style="color: var(--text-3);"
    @mouseenter="hovered = true"
    @mouseleave="hovered = false"
    :style="hovered ? 'color: var(--text-1)' : 'color: var(--text-3)'"
  >
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
    </svg>
    {{ label }}
  </Link>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link } from '@inertiajs/vue3'
defineProps<{ href: string; label: string }>()
const hovered = ref(false)
</script>
