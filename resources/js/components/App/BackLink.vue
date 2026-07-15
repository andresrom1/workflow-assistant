<script setup lang="ts">
import { ChevronLeft } from '@lucide/vue'
import { Link } from '@inertiajs/vue3'
import { Button } from '@/components/UI/button'

defineProps<{
  href: string
  label: string
}>()
</script>

<template>
  <Button variant="ghost" size="sm" as-child>
    <Link :href="href">
      <ChevronLeft data-icon="inline-start" />
      {{ label }}
    </Link>
  </Button>
</template>
