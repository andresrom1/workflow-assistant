<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-7xl mx-auto">
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Cotizaciones
        </h1>
        <span class="text-xs font-medium" style="color: var(--text-3);">
          Total: <span class="font-semibold" style="color: var(--text-2);">{{ quotes.total }}</span>
        </span>
      </div>

      <!-- Buscador -->
      <Card class="p-4 mb-5">
        <form @submit.prevent="buscar" class="flex flex-col sm:flex-row gap-2.5">
          <div class="flex-1 relative">
            <SearchIcon class="absolute left-3 top-1/2 -translate-y-1/2 size-3.5" style="color: var(--text-3);" />
            <Input v-model="searchInput" type="text" placeholder="Buscar por cliente, DNI, marca, modelo..." class="pl-9" />
          </div>
          <div class="flex gap-2">
            <Select v-model="perPageInput" @update:model-value="buscar">
              <SelectTrigger class="h-10"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem value="10">10 / pág</SelectItem>
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
        :data="quotes.data"
        :sort="filters.sort"
        :direction="filters.direction"
        empty-message="No hay cotizaciones."
        @sort="handleSort"
        @row-click="(item) => irA(`/quotes/${item.id}`)"
      >
        <template #cell-id="{ item }">
          <p class="font-semibold font-mono text-sm" style="color: var(--text-1);">#{{ item.id }}</p>
          <p class="text-[11px] mt-0.5" style="color: var(--text-3);">{{ formatDate(item.created_at) }}</p>
        </template>

        <template #cell-status="{ item }">
          <Badge class="gap-1.5 px-2.5 py-1 rounded-full text-[11px]" :style="statusStyle(item.status)">
            <span class="size-1.5 rounded-full flex-shrink-0" :style="dotStyle(item.status)" />
            {{ statusLabel(item.status) }}
          </Badge>
        </template>

        <template #cell-vehiculo="{ item }">
          <p class="text-sm font-medium" style="color: var(--text-1);">{{ item.marca }} {{ item.modelo }}</p>
          <p class="text-[11px] font-mono mt-0.5" style="color: var(--text-3);">{{ item.year }} · CP {{ item.codigo_postal }}</p>
        </template>

        <template #cell-customer_name="{ item }">
          <p class="text-sm" style="color: var(--text-1);">{{ item.customer_name ?? 'Sin Nombre' }}</p>
          <a v-if="item.customer_phone"
            :href="`https://wa.me/${item.customer_phone.replace(/\D/g, '')}`"
            target="_blank" rel="noopener"
            class="text-[11px] font-mono mt-0.5 block hover:underline"
            style="color: var(--text-3);"
            @click.stop>{{ item.customer_identifier }}</a>
          <p v-else class="text-[11px] font-mono mt-0.5" style="color: var(--text-3);">{{ item.customer_identifier ?? '—' }}</p>
        </template>

        <template #cell-alternatives_count="{ item }">
          <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
            style="background: var(--border-sub); color: var(--text-2);">
            {{ item.alternatives_count }}
          </span>
        </template>

        <template #cell-action="{ item }">
          <div @click.stop>
            <Button variant="ghost" size="icon" as-child>
              <Link :href="`/quotes/${item.id}`" title="Ver cotización">
                <ChevronRightIcon class="size-3.5" />
              </Link>
            </Button>
          </div>
        </template>

        <template #mobile-row="{ item }">
          <Link :href="`/quotes/${item.id}`"
            class="block rounded-[14px] p-4 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex items-start justify-between gap-2">
              <div>
                <div class="flex items-center gap-2 mb-1.5">
                  <span class="font-semibold font-mono text-sm" style="color: var(--text-1);">#{{ item.id }}</span>
                  <Badge class="gap-1.5 px-2 py-0.5 rounded-full text-[10px]" :style="statusStyle(item.status)">
                    {{ statusLabel(item.status) }}
                  </Badge>
                </div>
                <p class="text-sm font-medium" style="color: var(--text-1);">{{ item.marca }} {{ item.modelo }} {{ item.year }}</p>
                <p class="text-xs mt-0.5" style="color: var(--text-3);">{{ item.customer_name ?? 'Sin Nombre' }} · {{ item.alternatives_count }} alternativas</p>
              </div>
              <ChevronRightIcon class="size-4" style="color: var(--text-3);" />
            </div>
            <p class="text-[11px] mt-2" style="color: var(--text-3);">{{ formatDate(item.created_at) }}</p>
          </Link>
        </template>
      </DataTable>

      <AppPagination v-if="quotes.last_page > 1" :data="quotes" class="mt-4" />
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

interface QuoteRow {
  id: number
  status: string
  created_at: string
  marca: string
  modelo: string
  year: number
  codigo_postal: string
  customer_name: string | null
  customer_phone: string | null
  customer_identifier: string | null
  dni: string | null
  alternatives_count: number
}

const props = defineProps<{
  quotes: {
    data: QuoteRow[]
    total: number
    from: number
    to: number
    last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { search?: string; per_page: number; sort?: string; direction?: SortDirection }
}>()

const columns = [
  { key: 'id', label: 'ID / Fecha', sortable: true },
  { key: 'status', label: 'Estado', sortable: true },
  { key: 'vehiculo', label: 'Vehículo', sortable: true },
  { key: 'customer_name', label: 'Cliente', sortable: true },
  { key: 'alternatives_count', label: 'Alt.', sortable: true, align: 'center' as const, class: 'w-16' },
  { key: 'action', label: '', sortable: false, align: 'center' as const, class: 'w-10' },
]

const searchInput  = ref(props.filters?.search ?? '')
const perPageInput = ref(String(props.filters?.per_page ?? 15))

const buscar = () => {
  router.get('/quotes', {
    search: searchInput.value,
    per_page: perPageInput.value,
    sort: props.filters.sort,
    direction: props.filters.direction,
  }, { preserveState: true, replace: true })
}

const handleSort = (column: string, direction: SortDirection) => {
  router.get('/quotes', {
    search: searchInput.value,
    per_page: perPageInput.value,
    sort: column,
    direction,
  }, { preserveState: true, replace: true })
}

const irA = (href: string) => router.visit(href)

const statusLabel = (s: string) => ({
  pending: 'Pendiente', processed: 'Procesado', failed: 'Fallido',
  checkout_pending: 'Checkout', checkout_submitted: 'Enviado',
}[s] ?? s)

const statusStyle = (s: string) => ({
  pending:            'background:var(--badge-pending-bg); color:var(--badge-pending-txt);',
  processed:          'background:var(--badge-ok-bg);      color:var(--badge-ok-txt);',
  failed:             'background:var(--badge-danger-bg);  color:var(--badge-danger-txt);',
  checkout_pending:   'background:var(--badge-violet-bg);  color:var(--badge-violet-txt);',
  checkout_submitted: 'background:var(--badge-teal-bg);    color:var(--badge-teal-txt);',
}[s] ?? 'background:var(--border-sub); color:var(--text-3);')

const dotStyle = (s: string) => ({
  pending:            'background:var(--dot-pending);',
  processed:          'background:var(--dot-ok);',
  failed:             'background:var(--dot-danger);',
  checkout_pending:   'background:var(--dot-violet);',
  checkout_submitted: 'background:var(--dot-teal);',
}[s] ?? 'background:var(--text-3);')

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
</script>
