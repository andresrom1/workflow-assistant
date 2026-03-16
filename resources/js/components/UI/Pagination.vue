<template>
  <div
    class="rounded-[14px] px-4 py-3 flex flex-col sm:flex-row items-center justify-between gap-3"
    style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
  >
    <span class="text-xs" style="color: var(--text-3);">
      {{ data.from }}–{{ data.to }} de {{ data.total }} resultados
    </span>
    <div class="flex flex-wrap gap-1 justify-center">
      <Link
        v-for="link in data.links"
        :key="link.label"
        :href="link.url ?? '#'"
        :class="[
          'px-3 py-1.5 rounded-[8px] text-xs font-medium transition-all',
          link.active ? 'text-white bg-[#5b5ef6]' : 'hover:bg-[var(--border-sub)]',
          !link.url ? 'opacity-40 pointer-events-none' : '',
        ]"
        :style="!link.active ? 'color: var(--text-2)' : ''"
        v-html="link.label"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
defineProps<{
  data: {
    from: number; to: number; total: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
}>()
</script>
