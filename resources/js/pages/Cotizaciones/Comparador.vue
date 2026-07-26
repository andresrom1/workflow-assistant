<template>
  <Head :title="`${cobertura.label} — ${vehiculo.descripcion}`" />

  <MangoLayout hide-header>
    <!--
      Andamio temporal: muestra las props que arma PublicQuoteController para poder verificarlas
      contra la maqueta. La UI real se porta en la fase siguiente.
    -->
    <div class="p-6 text-xs" style="font-family: var(--mg-font-mono)">
      <p class="mg-display text-2xl mb-1" style="font-family: var(--mg-font-display)">
        {{ vehiculo.descripcion }} {{ vehiculo.year }}
      </p>
      <p class="mb-4" style="color: var(--mg-fg-dim)">
        {{ cobertura.label }} · {{ totalOpciones }} opciones ·
        {{ vigente ? 'vigente' : 'vencida' }}
      </p>
      <pre class="overflow-x-auto whitespace-pre-wrap">{{ props }}</pre>
    </div>
  </MangoLayout>
</template>

<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import MangoLayout from '@/layouts/MangoLayout.vue'

const props = defineProps<{
  vigente: boolean
  expiresAt: string | null
  cotizadoEl: string | null
  vehiculo: {
    marca: string | null
    modelo: string | null
    version: string | null
    year: number | null
    combustible: string | null
    descripcion: string
  }
  cobertura: { grade: string | null; label: string }
  totalOpciones: number
  glosario: Record<string, { nota: string; esCobertura: boolean }>
  companias: Array<{
    slug: string
    nombre: string
    desde: number
    sumaAsegurada: number | null
    planes: Array<{
      id: number
      aseguradora: string
      companiaSlug: string
      titulo: string
      franquicia: string | null
      precio: number
      sumaAsegurada: number
      sumaAseguradaTexto: string | null
      features: string[]
    }>
  }>
  recomendadas: {
    principal: { planId: number; razon: string | null }
    segunda: { planId: number; razon: string | null }
  } | null
  comparacion: {
    comunes: Array<{ label: string; nota: string; esCobertura: boolean }>
    soloA: Array<{ label: string; nota: string; esCobertura: boolean }>
    soloB: Array<{ label: string; nota: string; esCobertura: boolean }>
    diferenciaPrecio: number
    ahorroAnual: number
  } | null
  whatsappNumber: string | null
}>()
</script>
