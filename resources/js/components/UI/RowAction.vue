<template>
  <!--
    RowAction — CTA uniforme para todas las filas/cards de la app.
    Siempre el mismo aspecto: pill azul con ícono de flecha.
  -->
  <Link
    :href="href"
    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 text-blue-700 border border-blue-200
           rounded-full text-xs font-semibold hover:bg-blue-600 hover:text-white hover:border-blue-600
           transition-all whitespace-nowrap"
  >
    {{ label }}
    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
    </svg>
  </Link>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
defineProps<{ href: string; label: string }>()
</script>
