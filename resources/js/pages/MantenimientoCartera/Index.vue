<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <AppBackLink href="/polizas" label="Pólizas" class="mb-4" />

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Mantenimiento de cartera
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">
            Reporte por póliza: documentación pendiente y renovaciones, en un solo checklist por urgencia.
          </p>
        </div>
        <div class="flex items-center gap-2 self-start">
          <span class="inline-flex items-center whitespace-nowrap px-2.5 py-1 rounded-full text-xs font-bold tabular-nums"
            :style="pendientes > 0
              ? 'background: var(--accent-100); color: var(--accent-600);'
              : 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'">
            {{ pendientes }} pendiente{{ pendientes === 1 ? '' : 's' }}
          </span>
          <Select v-model="diasInput" @update:model-value="recargar">
            <SelectTrigger class="h-[34px] w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectItem value="30">Vence en 30 días</SelectItem>
                <SelectItem value="60">Vence en 60 días</SelectItem>
                <SelectItem value="90">Vence en 90 días</SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
        </div>
      </div>

      <!-- Vacío -->
      <div v-if="!filas.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No hay pendientes de cartera. 🎉
      </div>

      <div v-else class="space-y-2">
        <div v-for="f in filas.data" :key="f.poliza_id"
          class="rounded-[14px] p-4"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

          <!-- Cabecera de la póliza -->
          <div class="flex items-start justify-between gap-3 mb-3">
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <p class="text-sm font-semibold" style="color: var(--text-1);">{{ f.numero ?? 'Sin número' }}</p>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                  :style="nivelStyle(f.urgencia_nivel)">
                  {{ nivelLabel(f.urgencia_nivel) }}
                </span>
              </div>
              <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
                {{ f.patente ?? '—' }} · {{ f.label }}<template v-if="f.cliente"> · {{ f.cliente }}</template>
                <template v-if="f.company"> · {{ f.company }}</template>
              </p>
            </div>
            <span v-if="f.docs" class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold font-mono tabular-nums flex-shrink-0"
              :style="f.docs.completos === f.docs.total
                ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                : 'background: var(--accent-100); color: var(--accent-600);'">
              {{ f.docs.completos }}/{{ f.docs.total }}
            </span>
          </div>

          <!-- Checklist: documentos + renovación -->
          <ul class="space-y-1.5">
            <!-- Documentos esperados -->
            <li v-for="item in f.docs?.items ?? []" :key="item.kind" class="flex items-center gap-2.5 text-sm">
              <span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[11px] font-bold flex-shrink-0"
                :style="item.presente
                  ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                  : 'background: var(--border-sub); color: var(--text-3);'">
                {{ item.presente ? '✓' : '☐' }}
              </span>
              <span :style="item.presente ? 'color: var(--text-1);' : 'color: var(--text-2);'">{{ item.label }}</span>
              <Link v-if="!item.presente" :href="`/policy-documents/${f.poliza_id}?kind=${item.kind}`"
                class="ml-auto text-[11px] font-semibold" style="color: var(--accent-600);">
                Subir →
              </Link>
            </li>

            <!-- Renovación como un ítem más del checklist. Si falta mucho (al_dia, fuera
                 de la ventana) no se muestra: aparece recién al acercarse el vencimiento. -->
            <li v-if="f.renovacion && f.renovacion.nivel !== 'al_dia'" class="flex items-center gap-2.5 text-sm">
              <span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[11px] font-bold flex-shrink-0"
                :style="renovIconStyle(f.renovacion.nivel)">
                {{ renovIcon(f.renovacion.nivel) }}
              </span>
              <span :style="f.renovacion.accionable ? 'color: var(--text-1);' : 'color: var(--text-3);'">
                {{ renovLabel(f.renovacion) }}
              </span>
              <div v-if="f.renovacion.accionable" class="ml-auto flex items-center gap-2">
                <button @click="descartando = f" class="text-[11px] font-semibold" style="color: var(--text-3);">No renovar</button>
                <Link :href="`/polizas/${f.poliza_id}/renovar`" class="text-[11px] font-semibold" style="color: var(--accent-600);">
                  Renovar →
                </Link>
              </div>
            </li>
          </ul>
        </div>
      </div>

      <AppPagination v-if="filas.last_page > 1" :data="filas" class="mt-4" />

    </div>
  </div>

  <!-- Modal: confirmar "No renovar" -->
  <Transition name="fade">
    <div v-if="descartando" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="descartando = null" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">¿Marcar como no renovable?</h3>
        <p class="text-sm mb-5" style="color: var(--text-2);">
          La póliza <strong>{{ descartando.numero ?? 'sin número' }}</strong> sale de la cola sin anularse.
          Podés reactivarla desde la edición de la póliza.
        </p>
        <div class="flex justify-end gap-2">
          <button @click="descartando = null"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);">
            Cancelar
          </button>
          <button @click="confirmarDescarte"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);">
            No renovar
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppBackLink from '@/components/App/BackLink.vue'
import AppPagination from '@/components/App/Pagination.vue'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

interface DocItem { kind: string; label: string; presente: boolean }
interface Renovacion { nivel: string; dias: number | null; vigencia: string | null; accionable: boolean }

interface Fila {
  poliza_id: number
  numero: string | null
  company: string | null
  estado: string
  patente: string | null
  label: string
  cliente: string | null
  urgencia: number
  urgencia_nivel: string
  docs: { items: DocItem[]; completos: number; total: number } | null
  renovacion: Renovacion | null
}

const props = defineProps<{
  filas: {
    data: Fila[]
    total: number
    last_page: number
    from: number
    to: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  pendientes: number
  filters: { per_page: number; dias: number }
}>()

const diasInput = ref(String(props.filters.dias ?? 30))
const descartando = ref<Fila | null>(null)

const recargar = () => {
  router.get('/mantenimiento-cartera', { dias: diasInput.value }, { preserveState: true, replace: true })
}

const confirmarDescarte = () => {
  const fila = descartando.value
  if (!fila) { return }
  descartando.value = null
  router.post(`/polizas/${fila.poliza_id}/descartar-renovacion`, {}, { preserveScroll: true })
}

const nivelLabel = (nivel: string): string => {
  if (nivel === 'vencida') { return 'Vencida' }
  if (nivel === 'critico') { return 'Urgente' }
  if (nivel === 'pronto') { return 'Pronto' }
  return 'Pendiente'
}

const nivelStyle = (nivel: string): string => {
  if (nivel === 'vencida' || nivel === 'critico') { return 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);' }
  if (nivel === 'pronto') { return 'background: var(--accent-100); color: var(--accent-600);' }
  return 'background: var(--border-sub); color: var(--text-3);'
}

const renovIcon = (nivel: string): string => {
  if (nivel === 'al_dia' || nivel === 'renovada') { return '✓' }
  if (nivel === 'vence_pronto') { return '⚠' }
  if (nivel === 'vencida') { return '✗' }
  return '–'
}

const renovIconStyle = (nivel: string): string => {
  if (nivel === 'al_dia' || nivel === 'renovada') { return 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);' }
  if (nivel === 'vence_pronto') { return 'background: var(--accent-100); color: var(--accent-600);' }
  if (nivel === 'vencida') { return 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);' }
  return 'background: var(--border-sub); color: var(--text-3);'
}

const renovLabel = (r: Renovacion): string => {
  switch (r.nivel) {
    case 'al_dia': return `Renovación al día${r.vigencia ? ` — vence ${formatDate(r.vigencia)}` : ''}`
    case 'vence_pronto': return r.dias === 0 ? 'Renovación: vence hoy' : `Renovación: vence en ${r.dias} día${r.dias === 1 ? '' : 's'}`
    case 'vencida': return `Renovación: vencida hace ${Math.abs(r.dias ?? 0)} d`
    case 'renovada': return 'Renovada'
    case 'descartada': return 'Renovación: marcada como no renovable'
    case 'no_renueva': return 'No renueva (período corto)'
    default: return 'Renovación: vigencia sin cargar'
  }
}

const formatDate = (iso: string | null): string => {
  if (!iso) { return '—' }
  return new Date(iso).toLocaleDateString('es-AR', { dateStyle: 'medium' })
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
