<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <BackLink href="/polizas" label="Pólizas" class="mb-4" />

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Vencimientos
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">
            Pólizas vigentes que vencen pronto — cargá la renovación antes del corte.
          </p>
        </div>
        <Select v-model="diasInput" @update:model-value="recargar">
          <SelectTrigger class="h-[38px] w-44"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <SelectItem value="15">Próximos 15 días</SelectItem>
              <SelectItem value="30">Próximos 30 días</SelectItem>
              <SelectItem value="60">Próximos 60 días</SelectItem>
              <SelectItem value="90">Próximos 90 días</SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>
      </div>

      <!-- Vacío -->
      <div v-if="!polizas.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No hay pólizas que venzan en los próximos {{ dias }} días.
      </div>

      <div v-else class="space-y-2">
        <div v-for="p in polizas" :key="p.id"
          class="rounded-[14px] p-4 flex flex-col sm:flex-row sm:items-center gap-3"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <p class="text-sm font-semibold" style="color: var(--text-1);">{{ p.numero ?? 'Sin número' }}</p>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                :style="urgenciaStyle(p.vigencia)">
                {{ urgenciaLabel(p.vigencia) }}
              </span>
            </div>
            <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
              {{ p.patente ?? '—' }} · {{ p.label }}<template v-if="p.cliente"> · {{ p.cliente }}</template>
            </p>
            <p class="text-xs mt-1" style="color: var(--text-3);">
              {{ p.company ?? '—' }} · vence el <span class="font-mono">{{ formatDate(p.vigencia) }}</span>
            </p>
          </div>

          <div class="flex items-center gap-2 flex-shrink-0">
            <Link :href="`/policy-documents/${p.id}`" class="btn btn-secondary text-xs py-1.5 px-3">Documentos</Link>
            <Link :href="`/polizas/${p.id}/renovar`" class="btn btn-primary text-xs py-1.5 px-3">Renovar</Link>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import BackLink from '@/components/UI/BackLink.vue'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

interface VencimientoRow {
  id: number
  numero: string | null
  company: string | null
  patente: string | null
  label: string
  cliente: string | null
  vigencia: string | null
}

const props = defineProps<{
  polizas: VencimientoRow[]
  dias: number
}>()

const diasInput = ref(String(props.dias))

const recargar = () => {
  router.get('/polizas/vencimientos', { dias: diasInput.value }, { preserveState: true, replace: true })
}

const diasRestantes = (vigencia: string | null): number | null => {
  if (!vigencia) { return null }
  const hoy = new Date()
  hoy.setHours(0, 0, 0, 0)
  return Math.ceil((new Date(vigencia).getTime() - hoy.getTime()) / 86_400_000)
}

const urgenciaLabel = (vigencia: string | null): string => {
  const d = diasRestantes(vigencia)
  if (d === null) { return '—' }
  if (d < 0) { return `Vencida hace ${Math.abs(d)} d` }
  if (d === 0) { return 'Vence hoy' }
  return `En ${d} día${d === 1 ? '' : 's'}`
}

const urgenciaStyle = (vigencia: string | null): string => {
  const d = diasRestantes(vigencia)
  if (d !== null && d <= 7) { return 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);' }
  if (d !== null && d <= 30) { return 'background: var(--accent-100); color: var(--accent-600);' }
  return 'background: var(--border-sub); color: var(--text-3);'
}

const formatDate = (iso: string | null): string => {
  if (!iso) { return '—' }
  return new Date(iso).toLocaleDateString('es-AR', { dateStyle: 'medium' })
}
</script>
