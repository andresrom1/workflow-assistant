<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Conversaciones
        </h1>
        <div class="flex items-center gap-3">
          <span class="text-xs font-medium" style="color: var(--text-3);">
            Total: <span class="font-semibold" style="color: var(--text-2);">{{ customers.total }}</span>
          </span>
          <Button as-child>
            <Link href="/conversations/create">Nuevo cliente</Link>
          </Button>
        </div>
      </div>

      <!-- Buscador -->
      <Card class="p-4 mb-5">
        <form @submit.prevent="buscar" class="flex flex-col sm:flex-row gap-2.5">
          <div class="flex-1 relative">
            <SearchIcon class="absolute left-3 top-1/2 -translate-y-1/2 size-3.5" style="color: var(--text-3);" />
            <Input v-model="searchInput" type="text" placeholder="Buscar por DNI, nombre, email o teléfono..." class="pl-9" />
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
        :data="customers.data"
        :sort="filters.sort"
        :direction="filters.direction"
        empty-message="No se encontraron clientes."
        @sort="handleSort"
        @row-click="(item) => irA(`/conversations/${item.id}`)"
      >
        <template #cell-name="{ item }">
          <div class="flex items-center gap-3">
            <Avatar :name="item.name" />
            <div class="min-w-0">
              <p class="text-sm font-semibold leading-tight" style="color: var(--text-1);">{{ item.name }}</p>
              <p class="text-xs mt-0.5 truncate font-mono" style="color: var(--text-3);">
                {{ item.dni }}<template v-if="item.email"> · {{ item.email }}</template><template v-if="item.phone"> · {{ item.phone }}</template>
              </p>
            </div>
          </div>
        </template>

        <template #cell-vehicles_count="{ item }">
          <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
            style="background: var(--accent-100); color: var(--accent-600);">
            {{ item.vehicles_count }}
          </span>
        </template>

        <template #cell-conversations_count="{ item }">
          <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
            style="background: var(--border-sub); color: var(--text-2);">
            {{ item.conversations_count }}
          </span>
        </template>

        <template #cell-created_at="{ item }">
          <span class="text-xs font-mono whitespace-nowrap" style="color: var(--text-3);">{{ formatDate(item.created_at) }}</span>
        </template>

        <template #cell-action="{ item }">
          <div @click.stop>
            <Button variant="ghost" size="icon" as-child>
              <Link :href="`/conversations/${item.id}`" title="Ver cliente">
                <ChevronRightIcon class="size-3.5" />
              </Link>
            </Button>
          </div>
        </template>

        <template #mobile-row="{ item }">
          <Link :href="`/conversations/${item.id}`"
            class="flex items-center gap-3 rounded-[14px] px-4 py-3 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <Avatar :name="item.name" />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ item.name }}</p>
              <p class="text-xs truncate mt-0.5 font-mono" style="color: var(--text-3);">
                {{ item.dni }}<template v-if="item.email"> · {{ item.email }}</template>
              </p>
              <div class="flex gap-3 mt-1.5">
                <span class="inline-flex items-center gap-1 text-xs" style="color: var(--text-3);">
                  <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold font-mono"
                    style="background: var(--accent-100); color: var(--accent-600);">{{ item.vehicles_count }}</span>
                  veh.
                </span>
                <span class="inline-flex items-center gap-1 text-xs" style="color: var(--text-3);">
                  <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold font-mono"
                    style="background: var(--border-sub); color: var(--text-2);">{{ item.conversations_count }}</span>
                  conv.
                </span>
              </div>
            </div>
            <ChevronRightIcon class="size-4" style="color: var(--text-3);" />
          </Link>
        </template>
      </DataTable>

      <AppPagination v-if="customers.last_page > 1" :data="customers" class="mt-4" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Button } from '@/components/UI/button'
import { Input } from '@/components/UI/input'
import { Card } from '@/components/UI/card'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'
import { Search as SearchIcon, ChevronRight as ChevronRightIcon } from '@lucide/vue'
import Avatar from '@/components/UI/Avatar.vue'
import DataTable, { type SortDirection } from '@/components/App/DataTable.vue'
import AppPagination from '@/components/App/Pagination.vue'

const props = defineProps<{
  customers: {
    data: Array<{
      id: number; name: string; dni: string
      email: string | null; phone: string | null
      vehicles_count: number; conversations_count: number
      created_at: string
    }>
    total: number; from: number; to: number; last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { search: string; per_page: number; sort?: string; direction?: SortDirection }
}>()

const columns = [
  { key: 'name', label: 'Cliente', sortable: true },
  { key: 'vehicles_count', label: 'Vehículos', sortable: true, align: 'center' as const },
  { key: 'conversations_count', label: 'Convs.', sortable: true, align: 'center' as const },
  { key: 'created_at', label: 'Registro', sortable: true },
  { key: 'action', label: '', sortable: false, align: 'center' as const },
]

const searchInput  = ref(props.filters.search ?? '')
const perPageInput = ref(String(props.filters.per_page ?? 15))

const buscar = () => {
  router.get('/conversations', {
    search: searchInput.value,
    per_page: perPageInput.value,
    sort: props.filters.sort,
    direction: props.filters.direction,
  }, { preserveState: true, replace: true })
}

const handleSort = (column: string, direction: SortDirection) => {
  router.get('/conversations', {
    search: searchInput.value,
    per_page: perPageInput.value,
    sort: column,
    direction,
  }, { preserveState: true, replace: true })
}

const irA = (href: string) => router.visit(href)

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('es-AR', { dateStyle: 'short' })
</script>
