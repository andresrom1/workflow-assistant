<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-2xl mx-auto">

      <AppBackLink :href="`/polizas/${anterior.id}/edit`" label="Volver a la póliza" class="mb-4" />

      <!-- Header -->
      <div class="mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Renovar póliza
        </h1>
        <p class="text-sm mt-1 font-mono" style="color: var(--text-3);">
          {{ vehicle.patente ?? vehicle.label }}
          <span v-if="vehicle.cliente"> · {{ vehicle.cliente }}</span>
        </p>
      </div>

      <!-- Anterior read-only -->
      <div class="rounded-[14px] p-4 mb-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <p class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Renueva la póliza</p>
        <p class="text-sm font-semibold mt-0.5" style="color: var(--text-1);">
          {{ anterior.numero ?? 'sin número' }} · {{ anterior.company ?? '—' }}
        </p>
        <p class="text-xs mt-1" style="color: var(--text-3);">
          Al guardar, esta póliza queda marcada como <strong>vencida</strong> y la nueva como vigente.
        </p>
      </div>

      <!-- Form -->
      <form @submit.prevent="submit"
        class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-sm font-semibold mb-4" style="color: var(--text-1);">Datos de la póliza nueva</h2>
        <PolizaFields :form="form" :show-estado="false" />
        <div class="flex justify-end gap-2 mt-5">
          <Button variant="secondary" as-child>
            <Link :href="`/polizas/${anterior.id}/edit`">Cancelar</Link>
          </Button>
          <Button type="submit" :disabled="form.processing">
            {{ form.processing ? 'Renovando...' : 'Renovar' }}
          </Button>
        </div>
      </form>

    </div>
  </div>
</template>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import AppBackLink from '@/components/App/BackLink.vue'
import { Button } from '@/components/UI/button'
import PolizaFields from './PolizaFields.vue'

interface Anterior {
  id: number; numero: string | null; company: string | null
  coverage: string | null; coverage_detail: string | null
  sum_asegurada: string | null; cuota: string | null
  vigencia: string | null; estado: string
}

const props = defineProps<{
  anterior: Anterior
  vehicle: { label: string; patente: string | null; cliente: string | null }
}>()

// La renovada arranca con número/vigencia en blanco (datos nuevos) y arrastra
// compañía/cobertura/suma/cuota de la anterior como punto de partida editable.
const form = useForm({
  numero: '',
  company: props.anterior.company ?? '',
  coverage: props.anterior.coverage ?? '',
  coverage_detail: props.anterior.coverage_detail ?? '',
  sum_asegurada: props.anterior.sum_asegurada ?? '',
  cuota: props.anterior.cuota ?? '',
  cuota_due: '',
  vigencia: '',
  emitida_en: '',
})

const submit = () => {
  form.post(`/polizas/${props.anterior.id}/renovar`, { preserveScroll: true })
}
</script>
