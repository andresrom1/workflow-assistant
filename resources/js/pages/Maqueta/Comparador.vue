<template>
  <Head title="Maqueta — Comparador de coberturas" />

  <MangoLayout hide-header>
    <ComparadorMobile v-if="esMovil" />
    <ComparadorDesktop v-else />
  </MangoLayout>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import MangoLayout from '@/layouts/MangoLayout.vue'
import ComparadorDesktop from './ComparadorDesktop.vue'
import ComparadorMobile from './ComparadorMobile.vue'

// El corte es de layout, no de dispositivo: abajo de 900px la vista de dos
// columnas no entra. Con matchMedia también acompaña el giro de pantalla y el
// redimensionado de la ventana en escritorio.
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
