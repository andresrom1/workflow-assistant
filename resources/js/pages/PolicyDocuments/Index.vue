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
      <Card
        class="p-4 mb-3"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
      >
        <form @submit.prevent="buscar" class="flex flex-col sm:flex-row gap-2.5">
          <div class="flex-1 relative">
            <Search class="absolute left-3 top-1/2 -translate-y-1/2 size-3.5" style="color: var(--text-3);" />
            <Input
              v-model="searchInput"
              type="text"
              placeholder="Buscar por número, patente o cliente..."
              class="pl-9"
            />
          </div>
          <div class="flex gap-2">
            <Select v-model="perPageInput" @update:model-value="buscar">
              <SelectTrigger class="h-10"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem value="15">15 / pag</SelectItem>
                  <SelectItem value="25">25 / pag</SelectItem>
                  <SelectItem value="50">50 / pag</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <Button type="submit" size="sm">Buscar</Button>
          </div>
        </form>
      </Card>

      <!-- Filtro por estado de documentacion -->
      <div class="flex items-center gap-2 mb-5">
        <Button
          v-for="opt in filterOptions"
          :key="opt.value"
          type="button"
          size="xs"
          variant="outline"
          :style="filterInput === opt.value
            ? 'background: var(--accent-100); color: var(--accent-600); border-color: var(--accent-600);'
            : 'background: var(--bg-card); color: var(--text-3); border-color: var(--border);'"
          @click="setFilter(opt.value)"
        >
          {{ opt.label }}
        </Button>
      </div>

      <!-- Empty state -->
      <div
        v-if="!polizas.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);"
      >
        {{ emptyMessage }}
      </div>

      <template v-else>
        <DataTable
          :columns="columns"
          :data="polizas.data"
          :sort="filters.sort"
          :direction="filters.direction"
          @sort="handleSort"
          @row-click="(p) => irA(`/policy-documents/${p.id}`)"
        >
          <template #cell-numero="{ item }">
            <div>
              <div class="flex items-center gap-2">
                <p class="text-sm font-semibold leading-tight" style="color: var(--text-1);">{{ item.numero ?? '—' }}</p>
                <Badge
                  v-if="item.estado"
                  class="text-[10px] px-2 py-0.5 rounded-full"
                  :style="item.estado === 'vigente'
                    ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                    : 'background: var(--border-sub); color: var(--text-3);'"
                >
                  {{ estadoLabel(item.estado) }}
                </Badge>
              </div>
              <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
                {{ item.patente ?? '—' }} · {{ item.label }}
              </p>
            </div>
          </template>

          <template #cell-cliente="{ item }">
            <p class="text-sm" style="color: var(--text-2);">{{ item.cliente ?? '—' }}</p>
          </template>

          <template #cell-last_document_at="{ item }">
            <div>
              <template v-if="item.last_kind">
                <p class="text-sm" style="color: var(--text-2);">{{ item.last_kind }}</p>
                <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">{{ formatDate(item.last_document_at) }}</p>
              </template>
              <Badge v-else class="text-[11px] px-2 py-0.5 rounded-full" style="background: var(--border-sub); color: var(--text-3);">
                Sin documentos
              </Badge>
            </div>
          </template>

          <template #cell-documents_count="{ item }">
            <div class="text-center">
              <Badge
                class="h-[22px] min-w-[22px] px-1.5 justify-center rounded-full text-[11px] font-bold font-mono tabular-nums"
                :style="item.documents_count > 0
                  ? 'background: var(--accent-100); color: var(--accent-600);'
                  : 'background: var(--border-sub); color: var(--text-3);'"
              >
                {{ item.documents_count }}
              </Badge>
              <p
                class="text-[10px] mt-1 font-mono tabular-nums"
                :style="item.doc_presentes === item.doc_esperados ? 'color: var(--badge-ok-txt);' : 'color: var(--text-3);'"
              >
                {{ item.doc_presentes }}/{{ item.doc_esperados }} esperados
              </p>
            </div>
          </template>

          <template #cell-actions="{ item }">
            <div @click.stop>
              <Button variant="ghost" size="icon" as-child>
                <Link :href="`/policy-documents/${item.id}`" title="Gestionar documentos">
                  <ChevronRight class="size-4" />
                </Link>
              </Button>
            </div>
          </template>

          <template #mobile-row="{ item }">
            <Link :href="`/policy-documents/${item.id}`" class="block">
              <Card
                size="sm"
                class="overflow-hidden"
                style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
              >
                <CardContent class="p-4 flex items-center gap-3">
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                      <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ item.numero ?? '—' }}</p>
                      <Badge
                        v-if="item.estado"
                        class="text-[10px] px-2 py-0.5 rounded-full flex-shrink-0"
                        :style="item.estado === 'vigente'
                          ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                          : 'background: var(--border-sub); color: var(--text-3);'"
                      >
                        {{ estadoLabel(item.estado) }}
                      </Badge>
                    </div>
                    <p class="text-xs truncate mt-0.5 font-mono" style="color: var(--text-3);">
                      {{ item.patente ?? '—' }} · {{ item.cliente ?? '—' }}
                    </p>
                    <p class="text-xs truncate mt-1" style="color: var(--text-3);">
                      <template v-if="item.last_kind">{{ item.last_kind }} · {{ formatDate(item.last_document_at) }}</template>
                      <template v-else>Sin documentos</template>
                    </p>
                  </div>
                  <Badge
                    class="h-[22px] min-w-[22px] px-1.5 justify-center rounded-full text-[10px] font-bold font-mono tabular-nums"
                    :style="item.documents_count > 0
                      ? 'background: var(--accent-100); color: var(--accent-600);'
                      : 'background: var(--border-sub); color: var(--text-3);'"
                  >
                    {{ item.documents_count }}
                  </Badge>
                  <ChevronRight class="size-4" style="color: var(--text-3);" />
                </CardContent>
              </Card>
            </Link>
          </template>
        </DataTable>
      </template>

      <AppPagination v-if="polizas.last_page > 1" :data="polizas" class="mt-4" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Button } from '@/components/UI/button'
import { Input } from '@/components/UI/input'
import { Card, CardContent } from '@/components/UI/card'
import { Badge } from '@/components/UI/badge'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'
import { Search, ChevronRight } from '@lucide/vue'
import DataTable, { type SortDirection } from '@/components/App/DataTable.vue'
import AppPagination from '@/components/App/Pagination.vue'

type DocFilter = 'all' | 'with' | 'without'

interface PolizaItem {
  id: number
  numero: string | null
  company: string | null
  estado: string
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
  filters: { search: string; per_page: number; filter: DocFilter; sort?: string; direction?: SortDirection }
}>()

const columns = [
  { key: 'numero', label: 'Póliza', sortable: true },
  { key: 'cliente', label: 'Cliente', sortable: true },
  { key: 'last_document_at', label: 'Último documento', sortable: true, class: 'w-48' },
  { key: 'documents_count', label: 'Docs', sortable: true, align: 'center' as const, class: 'w-28' },
  { key: 'actions', label: '', sortable: false, align: 'center' as const, class: 'w-10' },
]

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
  router.get('/policy-documents', {
    search: searchInput.value,
    per_page: perPageInput.value,
    filter: filterInput.value,
    sort: props.filters.sort,
    direction: props.filters.direction,
  }, { preserveState: true, replace: true })
}

const setFilter = (value: DocFilter) => {
  filterInput.value = value
  buscar()
}

const handleSort = (column: string, direction: SortDirection) => {
  router.get('/policy-documents', {
    search: searchInput.value,
    per_page: perPageInput.value,
    filter: filterInput.value,
    sort: column,
    direction,
  }, { preserveState: true, replace: true })
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
