<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto">

      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Documentos de Póliza
        </h1>
      </div>

      <p class="text-sm mb-5" style="color: var(--text-3);">
        Buscá una póliza para gestionar sus documentos (renovaciones, endosos, correcciones).
      </p>

      <!-- Search -->
      <div class="rounded-[14px] p-4 mb-3" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <form @submit.prevent="buscar" class="flex flex-col sm:flex-row gap-2.5">
          <div class="flex-1 relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color: var(--text-3);"
              fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input v-model="searchInput" type="text"
              placeholder="Buscar por número, patente o cliente..."
              class="field pl-9" />
          </div>
          <div class="flex gap-2">
            <Select v-model="perPageInput" @update:model-value="buscar">
              <SelectTrigger class="h-[38px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem value="15">15 / pág</SelectItem>
                  <SelectItem value="25">25 / pág</SelectItem>
                  <SelectItem value="50">50 / pág</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <button type="submit" class="btn btn-primary">Buscar</button>
          </div>
        </form>
      </div>

      <!-- Filtro por estado de documentación -->
      <div class="flex items-center gap-2 mb-5">
        <button v-for="opt in filterOptions" :key="opt.value"
          @click="setFilter(opt.value)"
          class="px-3 py-1.5 rounded-full text-xs font-semibold transition-colors"
          :style="filterInput === opt.value
            ? 'background: var(--accent-100); color: var(--accent-600); border: 1px solid var(--accent-600);'
            : 'background: var(--bg-card); color: var(--text-3); border: 1px solid var(--border);'">
          {{ opt.label }}
        </button>
      </div>

      <!-- Empty state -->
      <div v-if="!polizas.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        {{ emptyMessage }}
      </div>

      <template v-else>
        <!-- DESKTOP table -->
        <div class="hidden md:block rounded-[14px] overflow-hidden"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <table class="min-w-full text-sm">
            <thead>
              <tr style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Póliza</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Cliente</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider w-48" style="color: var(--text-3);">Último documento</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider w-28" style="color: var(--text-3);">Docs</th>
                <th class="px-5 py-3 w-10"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="p in polizas.data" :key="p.id"
                class="cursor-pointer transition-colors"
                style="border-bottom: 1px solid var(--border-sub);"
                @mouseenter="($event.currentTarget as HTMLElement).style.background = 'var(--border-sub)'"
                @mouseleave="($event.currentTarget as HTMLElement).style.background = 'transparent'"
                @click="irA(`/policy-documents/${p.id}`)"
              >
                <td class="px-5 py-3">
                  <div class="flex items-center gap-2">
                    <p class="text-sm font-semibold leading-tight" style="color: var(--text-1);">{{ p.numero ?? '—' }}</p>
                    <span v-if="p.estado" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                      :style="p.estado === 'vigente'
                        ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                        : 'background: var(--border-sub); color: var(--text-3);'">
                      {{ estadoLabel(p.estado) }}
                    </span>
                  </div>
                  <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
                    {{ p.patente ?? '—' }} · {{ p.label }}
                  </p>
                </td>
                <td class="px-5 py-3">
                  <p class="text-sm" style="color: var(--text-2);">{{ p.cliente ?? '—' }}</p>
                </td>
                <td class="px-5 py-3">
                  <template v-if="p.last_kind">
                    <p class="text-sm" style="color: var(--text-2);">{{ p.last_kind }}</p>
                    <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">{{ formatDate(p.last_document_at) }}</p>
                  </template>
                  <span v-else class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                    style="background: var(--border-sub); color: var(--text-3);">Sin documentos</span>
                </td>
                <td class="px-5 py-3 text-center">
                  <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
                    :style="p.documents_count > 0
                      ? 'background: var(--accent-100); color: var(--accent-600);'
                      : 'background: var(--border-sub); color: var(--text-3);'">
                    {{ p.documents_count }}
                  </span>
                  <p class="text-[10px] mt-1 font-mono tabular-nums"
                    :style="p.doc_presentes === p.doc_esperados ? 'color: var(--badge-ok-txt);' : 'color: var(--text-3);'">
                    {{ p.doc_presentes }}/{{ p.doc_esperados }} esperados
                  </p>
                </td>
                <td class="px-5 py-3" @click.stop>
                  <RowActionMin :href="`/policy-documents/${p.id}`" label="Gestionar documentos" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MOBILE cards -->
        <div class="md:hidden space-y-2">
          <Link v-for="p in polizas.data" :key="p.id"
            :href="`/policy-documents/${p.id}`"
            class="flex items-center gap-3 rounded-[14px] px-4 py-3 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ p.numero ?? '—' }}</p>
                <span v-if="p.estado" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold flex-shrink-0"
                  :style="p.estado === 'vigente'
                    ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                    : 'background: var(--border-sub); color: var(--text-3);'">
                  {{ estadoLabel(p.estado) }}
                </span>
              </div>
              <p class="text-xs truncate mt-0.5 font-mono" style="color: var(--text-3);">
                {{ p.patente ?? '—' }} · {{ p.cliente ?? '—' }}
              </p>
              <p class="text-xs truncate mt-1" style="color: var(--text-3);">
                <template v-if="p.last_kind">{{ p.last_kind }} · {{ formatDate(p.last_document_at) }}</template>
                <template v-else>Sin documentos</template>
              </p>
            </div>
            <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
              :style="p.documents_count > 0
                ? 'background: var(--accent-100); color: var(--accent-600);'
                : 'background: var(--border-sub); color: var(--text-3);'">
              {{ p.documents_count }}
            </span>
            <ChevronRight />
          </Link>
        </div>
      </template>

      <Pagination v-if="polizas.last_page > 1" :data="polizas" class="mt-4" />

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import RowActionMin from '@/components/UI/RowActionMin.vue'
import ChevronRight from '@/components/UI/ChevronRight.vue'
import Pagination from '@/components/UI/Pagination.vue'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

type DocFilter = 'all' | 'with' | 'without'

interface PolizaItem {
  id: number
  numero: string | null
  company: string | null
  estado: string | null
  patente: string | null
  label: string
  cliente: string | null
  documents_count: number
  visible_count: number
  doc_presentes: number
  doc_esperados: number
  last_kind: string | null
  last_document_at: string | null
}

const props = defineProps<{
  polizas: {
    data: PolizaItem[]
    total: number
    from: number
    to: number
    last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { search: string; per_page: number; filter: DocFilter }
}>()

const searchInput = ref(props.filters.search ?? '')
const perPageInput = ref(String(props.filters.per_page ?? 25))
const filterInput = ref<DocFilter>(props.filters.filter ?? 'all')

const filterOptions: Array<{ value: DocFilter; label: string }> = [
  { value: 'all', label: 'Todas' },
  { value: 'with', label: 'Con documentos' },
  { value: 'without', label: 'Sin documentos' },
]

const emptyMessage = computed(() => {
  if (filterInput.value === 'with') { return 'No hay pólizas con documentos cargados.' }
  if (filterInput.value === 'without') { return 'Todas las pólizas tienen al menos un documento.' }
  return 'No se encontraron pólizas.'
})

const buscar = () => {
  router.get('/policy-documents',
    { search: searchInput.value, per_page: perPageInput.value, filter: filterInput.value },
    { preserveState: true, replace: true })
}

const setFilter = (value: DocFilter) => {
  filterInput.value = value
  buscar()
}

const irA = (href: string) => router.visit(href)

const formatDate = (iso: string | null): string => {
  if (!iso) { return '—' }
  return new Date(iso).toLocaleDateString('es-AR', { dateStyle: 'medium' })
}

const estadoLabel = (estado: string): string => {
  if (estado === 'vigente') { return 'Vigente' }
  return estado.charAt(0).toUpperCase() + estado.slice(1)
}
</script>
