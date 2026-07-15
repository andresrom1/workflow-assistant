<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-2xl mx-auto">

      <AppBackLink href="/polizas" label="Pólizas" class="mb-4" />

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            {{ poliza.numero ?? 'Póliza sin número' }}
          </h1>
          <p class="text-sm mt-1 font-mono" style="color: var(--text-3);">
            {{ vehicle.patente ?? vehicle.label }}
            <span v-if="vehicle.cliente"> · {{ vehicle.cliente }}</span>
          </p>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
          <Button v-if="poliza.es_renovable" variant="secondary" as-child>
            <Link :href="`/polizas/${poliza.id}/renovar`">Renovar</Link>
          </Button>
          <Button variant="secondary" as-child>
            <Link :href="`/policy-documents/${poliza.id}`">Gestionar documentos →</Link>
          </Button>
          <Button variant="destructive" @click="showDelete = true">Eliminar</Button>
        </div>
      </div>

      <!-- Vehículo read-only -->
      <div class="rounded-[14px] p-4 mb-5 flex items-center justify-between"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <div>
          <p class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Vehículo</p>
          <p class="text-sm font-semibold mt-0.5" style="color: var(--text-1);">{{ vehicle.label }}</p>
        </div>
        <Link :href="`/conversations/${vehicle.customer_id}`" class="text-xs font-semibold" style="color: var(--accent-600);">
          Ver cliente →
        </Link>
      </div>

      <!-- Marcada como no renovable -->
      <div v-if="poliza.no_renovar_at"
        class="rounded-[14px] p-4 mb-5 flex items-center justify-between gap-3"
        style="background: var(--badge-danger-bg); border: 1px solid var(--border);">
        <p class="text-sm font-medium" style="color: var(--badge-danger-txt);">
          Marcada como no renovable — no figura en la cola de mantenimiento.
        </p>
        <Button variant="secondary" size="sm" @click="reactivar">Reactivar</Button>
      </div>

      <!-- Form -->
      <form @submit.prevent="submit"
        class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <PolizaFields :form="form" :estados="estados" />
        <div class="flex justify-end gap-2 mt-5">
          <Button variant="secondary" as-child>
            <Link href="/polizas">Cancelar</Link>
          </Button>
          <Button type="submit" :disabled="form.processing">
            {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
          </Button>
        </div>
      </form>

    </div>
  </div>

  <!-- Modal eliminar -->
  <Dialog v-model:open="showDelete">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>¿Eliminar esta póliza?</DialogTitle>
        <DialogDescription>
          La póliza se archiva (soft-delete). Los documentos cargados no se borran.
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="secondary" @click="showDelete = false">Cancelar</Button>
        <Button variant="destructive" @click="submitDelete">Eliminar</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppBackLink from '@/components/App/BackLink.vue'
import { Button } from '@/components/UI/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/UI/dialog'
import PolizaFields from './PolizaFields.vue'

interface Poliza {
  id: number; numero: string | null; company: string | null
  coverage: string | null; coverage_detail: string | null
  sum_asegurada: string | null; cuota: string | null
  cuota_due: string | null; vigencia: string | null; emitida_en: string | null
  estado: string
  periodo_corto: boolean; no_renovar_at: string | null; es_renovable: boolean
}

const props = defineProps<{
  poliza: Poliza
  vehicle: { label: string; patente: string | null; cliente: string | null; customer_id: number }
  estados: Array<{ value: string; label: string }>
}>()

const form = useForm({
  numero: props.poliza.numero ?? '',
  company: props.poliza.company ?? '',
  coverage: props.poliza.coverage ?? '',
  coverage_detail: props.poliza.coverage_detail ?? '',
  sum_asegurada: props.poliza.sum_asegurada ?? '',
  cuota: props.poliza.cuota ?? '',
  cuota_due: props.poliza.cuota_due ?? '',
  vigencia: props.poliza.vigencia ?? '',
  emitida_en: props.poliza.emitida_en ?? '',
  estado: props.poliza.estado,
  periodo_corto: props.poliza.periodo_corto,
})

const submit = () => {
  form.put(`/polizas/${props.poliza.id}`, { preserveScroll: true })
}

const showDelete = ref(false)

const submitDelete = () => {
  showDelete.value = false
  router.delete(`/polizas/${props.poliza.id}`)
}

const reactivar = () => {
  router.delete(`/polizas/${props.poliza.id}/descartar-renovacion`, { preserveScroll: true })
}
</script>
