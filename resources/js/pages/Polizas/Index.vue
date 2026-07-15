<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Pólizas
        </h1>
        <div class="flex items-center gap-3">
          <span class="text-xs font-medium" style="color: var(--text-3);">
            Total: <span class="font-semibold" style="color: var(--text-2);">{{ polizas.total }}</span>
          </span>
          <Button variant="outline" as-child>
            <Link href="/polizas/vencimientos">Vencimientos</Link>
          </Button>
          <Button as-child>
            <Link href="/polizas/create">Nueva póliza</Link>
          </Button>
        </div>
      </div>

      <!-- Buscador -->
      <Card class="p-4 mb-5">
        <form @submit.prevent="buscar" class="flex flex-col sm:flex-row gap-2.5">
          <div class="flex-1 relative">
            <SearchIcon class="absolute left-3 top-1/2 -translate-y-1/2 size-3.5" style="color: var(--text-3);" />
            <Input v-model="searchInput" type="text" placeholder="Buscar por número, patente o cliente..." class="pl-9" />
          </div>
          <div class="flex gap-2">
            <Select v-model="perPageInput" @update:model-value="buscar">
              <SelectTrigger class="h-10"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem value="15">15 / pág</SelectItem>
                  <SelectItem value="25">25 / pág</SelectItem>
                  <SelectItem value="50">50 / pág</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <Button type="submit">Buscar</Button>
          </div>
        </form>
      </Card>

      <DataTable
        :columns="columns"
        :data="polizas.data"
        :sort="filters.sort"
        :direction="filters.direction"
        empty-message="No se encontraron pólizas."
        @sort="handleSort"
        @row-click="(item) => irA(`/polizas/${item.id}/edit`)"
      >
        <template #cell-numero="{ item }">
          <p class="text-sm font-semibold leading-tight" style="color: var(--text-1);">{{ item.numero ?? 'Sin número' }}</p>
          <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
            {{ item.patente ?? '—' }}<template v-if="item.company"> · {{ item.company }}</template>
          </p>
        </template>

        <template #cell-cliente="{ item }">
          <span class="text-sm" style="color: var(--text-2);">{{ item.cliente ?? '—' }}</span>
        </template>

        <template #cell-coverage="{ item }">
          <span class="text-xs" style="color: var(--text-3);">{{ item.coverage ?? '—' }}</span>
        </template>

        <template #cell-estado="{ item }">
          <Badge class="text-[10px] px-2 py-0.5 rounded-full" :style="estadoStyle(item.estado)">
            {{ item.estado }}
          </Badge>
        </template>

        <template #cell-vigencia="{ item }">
          <span class="text-xs font-mono whitespace-nowrap" style="color: var(--text-3);">{{ item.vigencia ?? '—' }}</span>
        </template>

        <template #cell-action="{ item }">
          <div @click.stop>
            <Button variant="ghost" size="icon" as-child>
              <Link :href="`/polizas/${item.id}/edit`" title="Editar póliza">
                <ChevronRightIcon class="size-3.5" />
              </Link>
            </Button>
          </div>
        </template>

        <template #mobile-row="{ item }">
          <Link :href="`/polizas/${item.id}/edit`"
            class="block rounded-[14px] px-4 py-3 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex items-center justify-between gap-2">
              <div class="min-w-0">
                <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ item.numero ?? 'Sin número' }}</p>
                <p class="text-xs truncate mt-0.5 font-mono" style="color: var(--text-3);">
                  {{ item.patente ?? '—' }}<template v-if="item.cliente"> · {{ item.cliente }}</template>
                </p>
              </div>
              <Badge class="text-[10px] px-2 py-0.5 rounded-full flex-shrink-0" :style="estadoStyle(item.estado)">
                {{ item.estado }}
              </Badge>
            </div>
          </Link>
        </template>
      </DataTable>

      <AppPagination v-if="polizas.last_page > 1" :data="polizas" class="mt-4" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Button } from '@/components/UI/button'
import { Input } from '@/components/UI/input'
import { Card } from '@/components/UI/card'
import { Badge } from '@/components/UI/badge'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'
import { Search as SearchIcon, ChevronRight as ChevronRightIcon } from '@lucide/vue'
import DataTable, { type SortDirection } from '@/components/App/DataTable.vue'
import AppPagination from '@/components/App/Pagination.vue'

interface PolizaRow {
  id: number
  numero: string | null
  company: string | null
  coverage: string | null
  patente: string | null
  cliente: string | null
  estado: string
  vigencia: string | null
}

const props = defineProps<{
  polizas: {
    data: PolizaRow[]
    total: number
    last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { search: string; per_page: number; sort?: string; direction?: SortDirection }
}>()

const columns = [
  { key: 'numero', label: 'Póliza', sortable: true },
  { key: 'cliente', label: 'Cliente', sortable: true },
  { key: 'coverage', label: 'Cobertura', sortable: true, class: 'w-28' },
  { key: 'estado', label: 'Estado', sortable: true, align: 'center' as const, class: 'w-24' },
  { key: 'vigencia', label: 'Vigencia', sortable: true, class: 'w-28' },
  { key: 'action', label: '', sortable: false, align: 'center' as const, class: 'w-10' },
]

const searchInput  = ref(props.filters.search ?? '')
const perPageInput = ref(String(props.filters.per_page ?? 25))

const buscar = () => {
  router.get('/polizas', {
    search: searchInput.value,
    per_page: perPageInput.value,
    sort: props.filters.sort,
    direction: props.filters.direction,
  }, { preserveState: true, replace: true })
}

const handleSort = (column: string, direction: SortDirection) => {
  router.get('/polizas', {
    search: searchInput.value,
    per_page: perPageInput.value,
    sort: column,
    direction,
  }, { preserveState: true, replace: true })
}

const irA = (href: string) => router.visit(href)

const estadoStyle = (estado: string): string => {
  if (estado === 'vigente') { return 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);' }
  if (estado === 'emitida') { return 'background: var(--accent-100); color: var(--accent-600);' }
  return 'background: var(--border-sub); color: var(--text-3);'
}
</script>
