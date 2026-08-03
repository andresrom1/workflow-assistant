<template>
  <Head :title="`${vista.cobertura.label} — ${vista.vehiculo.descripcion}`" />

  <MangoLayout hide-header>
    <ComparadorMobile v-if="esMovil" :vista="vista" />
    <ComparadorDesktop v-else :vista="vista" />
  </MangoLayout>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import MangoLayout from '@/layouts/MangoLayout.vue'
import ComparadorDesktop from '@/components/Cotizaciones/ComparadorDesktop.vue'
import ComparadorMobile from '@/components/Cotizaciones/ComparadorMobile.vue'
import type { Vista } from '@/components/Cotizaciones/comparador'

const vista = defineProps<Vista>()

// El corte es de layout, no de dispositivo: abajo de 900px la vista de dos columnas no entra.
// Con matchMedia también acompaña el giro de pantalla y el redimensionado en escritorio.
const consulta = '(max-width: 899px)'
const esMovil = ref(typeof window === 'undefined' ? true : window.matchMedia(consulta).matches)

let mql: MediaQueryList | null = null

function onCambio(e: MediaQueryListEvent): void {
  esMovil.value = e.matches
}

onMounted(() => {
  mql = window.matchMedia(consulta)
  esMovil.value = mql.matches
  mql.addEventListener('change', onCambio)
})

onUnmounted(() => mql?.removeEventListener('change', onCambio))
</script>
