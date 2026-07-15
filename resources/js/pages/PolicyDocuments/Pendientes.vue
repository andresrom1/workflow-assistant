<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <AppBackLink href="/policy-documents" label="Docs Pólizas" class="mb-4" />

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Documentación pendiente
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">
            Pólizas activas (vigentes y emitidas) a las que les falta documentación.
          </p>
        </div>
        <div class="flex items-center gap-2 self-start">
          <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold font-mono tabular-nums"
            :style="polizas.total > 0
              ? 'background: var(--accent-100); color: var(--accent-600);'
              : 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'">
            {{ polizas.total }} pendiente{{ polizas.total === 1 ? '' : 's' }}
          </span>
          <Select v-model="perPageInput" @update:model-value="recargar">
            <SelectTrigger class="h-[34px]"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectGroup>
                <SelectItem value="15">15 / pág</SelectItem>
                <SelectItem value="25">25 / pág</SelectItem>
                <SelectItem value="50">50 / pág</SelectItem>
              </SelectGroup>
            </SelectContent>
          </Select>
        </div>
      </div>

      <!-- Vacío -->
      <div v-if="!polizas.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        Toda la cartera activa tiene su documentación completa. 🎉
      </div>

      <div v-else class="space-y-2">
        <div v-for="p in polizas.data" :key="p.id"
          class="rounded-[14px] p-4 flex flex-col sm:flex-row sm:items-center gap-3"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <p class="text-sm font-semibold" style="color: var(--text-1);">{{ p.numero ?? 'Sin número' }}</p>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                :style="p.estado === 'vigente'
                  ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                  : 'background: var(--accent-100); color: var(--accent-600);'">
                {{ p.estado === 'vigente' ? 'Vigente' : 'Emitida' }}
              </span>
              <span class="text-[11px] font-mono tabular-nums" style="color: var(--text-3);">
                {{ p.presentes }}/{{ p.esperados }}
              </span>
            </div>
            <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
              {{ p.patente ?? '—' }} · {{ p.label }}<template v-if="p.cliente"> · {{ p.cliente }}</template>
            </p>
            <div class="flex items-center gap-1.5 flex-wrap mt-2">
              <span class="text-[11px] font-semibold" style="color: var(--text-3);">Falta:</span>
              <span v-for="f in p.faltantes" :key="f.kind"
                class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);">
                {{ f.label }}
              </span>
            </div>
          </div>

          <div class="flex-shrink-0">
            <Link :href="`/policy-documents/${p.id}`" class="btn btn-primary text-xs py-1.5 px-3">
              Gestionar documentos
            </Link>
          </div>
        </div>
      </div>

      <AppPagination v-if="polizas.last_page > 1" :data="polizas" class="mt-4" />

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppBackLink from '@/components/App/BackLink.vue'
import AppPagination from '@/components/App/Pagination.vue'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

interface PendienteRow {
  id: number
  numero: string | null
  company: string | null
  estado: string
  patente: string | null
  label: string
  cliente: string | null
  presentes: number
  esperados: number
  faltantes: Array<{ kind: string; label: string }>
}

const props = defineProps<{
  polizas: {
    data: PendienteRow[]
    total: number
    last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { per_page: number }
}>()

const perPageInput = ref(String(props.filters.per_page ?? 25))

const recargar = () => {
  router.get('/documentacion-pendiente', { per_page: perPageInput.value }, { preserveState: true, replace: true })
}
</script>
