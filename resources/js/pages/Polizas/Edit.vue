<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-2xl mx-auto">

      <BackLink href="/polizas" label="Pólizas" class="mb-4" />

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
          <Link v-if="poliza.estado === 'vigente'" :href="`/polizas/${poliza.id}/renovar`"
            class="btn btn-secondary text-sm">Renovar</Link>
          <Link :href="`/policy-documents/${poliza.id}`" class="btn btn-secondary text-sm">Gestionar documentos →</Link>
          <button @click="showDelete = true" class="btn btn-danger text-sm">Eliminar</button>
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

      <!-- Form -->
      <form @submit.prevent="submit"
        class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <PolizaFields :form="form" :estados="estados" />
        <div class="flex justify-end gap-2 mt-5">
          <Link href="/polizas" class="btn btn-secondary text-sm">Cancelar</Link>
          <button type="submit" class="btn btn-primary text-sm" :disabled="form.processing">
            {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
          </button>
        </div>
      </form>

    </div>
  </div>

  <!-- Modal eliminar -->
  <Transition name="fade">
    <div v-if="showDelete" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="showDelete = false" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">¿Eliminar esta póliza?</h3>
        <p class="text-sm mb-5" style="color: var(--text-2);">
          La póliza se archiva (soft-delete). Los documentos cargados no se borran.
        </p>
        <div class="flex justify-end gap-2">
          <button @click="showDelete = false"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);">
            Cancelar
          </button>
          <button @click="submitDelete"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);">
            Eliminar
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import BackLink from '@/components/UI/BackLink.vue'
import PolizaFields from './PolizaFields.vue'

interface Poliza {
  id: number; numero: string | null; company: string | null
  coverage: string | null; coverage_detail: string | null
  sum_asegurada: string | null; cuota: string | null
  cuota_due: string | null; vigencia: string | null; emitida_en: string | null
  estado: string
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
})

const submit = () => {
  form.put(`/polizas/${props.poliza.id}`, { preserveScroll: true })
}

const showDelete = ref(false)

const submitDelete = () => {
  showDelete.value = false
  router.delete(`/polizas/${props.poliza.id}`)
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
