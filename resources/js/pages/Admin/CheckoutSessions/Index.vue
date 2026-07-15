<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-6xl mx-auto">

      <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mb-6" style="color: var(--text-1);">
        Auditoría de Checkout
      </h1>

      <DataTable
        :columns="columns"
        :data="sessions.data"
        :sort="currentSort"
        :direction="currentDirection"
        compact
        empty-message="No hay sesiones de checkout para procesar."
        @sort="handleSort"
        @row-click="(item) => irA(`/admin/checkout-sessions/${item.id}`)"
      >
        <template #cell-id="{ item }">
          <span class="text-[11px] font-mono text-muted-foreground">{{ item.id }}</span>
        </template>

        <template #cell-status="{ item }">
          <Badge variant="outline" :class="statusBadgeClass(item.status)">
            <span class="size-1.5 rounded-full flex-shrink-0" :class="statusDotClass(item.status)" />
            {{ statusLabel(item.status) }}
          </Badge>
        </template>

        <template #cell-cliente="{ item }">
          <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ item.nombre }}</p>
          <p class="text-[11px] text-muted-foreground truncate">{{ item.email }}</p>
        </template>

        <template #cell-plan="{ item }">
          <p class="text-sm font-medium" style="color: var(--text-1);">{{ item.aseguradora ?? '—' }}</p>
          <p class="text-[11px] text-muted-foreground truncate">{{ item.titulo ?? '—' }}</p>
          <p class="text-xs font-medium text-foreground">{{ formatPrice(item.precio) }}</p>
        </template>

        <template #cell-submitted_at="{ item }">
          <span class="text-[11px] whitespace-nowrap text-muted-foreground">
            {{ formatDate(item.submitted_at) }}
          </span>
        </template>

        <template #cell-action="{ item }">
          <div @click.stop>
            <Button variant="ghost" size="icon" class="h-7 w-7" as-child>
              <Link :href="`/admin/checkout-sessions/${item.id}`" title="Ver sesión">
                <ChevronRight class="size-4" />
              </Link>
            </Button>
          </div>
        </template>

        <template #mobile-row="{ item }">
          <Link :href="`/admin/checkout-sessions/${item.id}`" class="block">
            <Card class="p-4 transition-all hover:bg-muted/50">
              <CardContent class="p-0">
                <div class="flex items-start justify-between gap-2">
                  <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1.5">
                      <Badge variant="outline" :class="statusBadgeClass(item.status)">
                        {{ statusLabel(item.status) }}
                      </Badge>
                    </div>
                    <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ item.nombre }}</p>
                    <p class="text-xs truncate mt-0.5 text-muted-foreground">{{ item.email }}</p>
                    <p class="text-xs mt-1 text-foreground">
                      {{ item.aseguradora }} · {{ item.titulo }}
                    </p>
                    <p class="text-xs mt-0.5 font-medium text-foreground">
                      {{ formatPrice(item.precio) }}
                    </p>
                  </div>
                  <ChevronRight class="size-4 text-muted-foreground flex-shrink-0" />
                </div>
                <p class="text-[11px] mt-2 text-muted-foreground">{{ formatDate(item.submitted_at) }}</p>
              </CardContent>
            </Card>
          </Link>
        </template>
      </DataTable>

      <AppPagination v-if="sessions.last_page > 1" :data="sessions" class="mt-4" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { Badge } from '@/components/UI/badge'
import { Button } from '@/components/UI/button'
import { Card, CardContent } from '@/components/UI/card'
import { ChevronRight } from '@lucide/vue'
import AppPagination from '@/components/App/Pagination.vue'
import DataTable, { type SortDirection } from '@/components/App/DataTable.vue'

const props = defineProps<{
  sessions: {
    data: Array<{
      id: number
      status: string
      nombre: string
      email: string
      submitted_at: string | null
      quote_id: number
      aseguradora: string | null
      titulo: string | null
      precio: number | null
    }>
    from: number
    to: number
    total: number
    current_page: number
    last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
}>()

const page = usePage()
const queryParams = computed(() => new URLSearchParams(page.url.split('?')[1] ?? ''))
const currentSort = computed(() => queryParams.value.get('sort') || null)
const currentDirection = computed<SortDirection>(() => (queryParams.value.get('direction') as SortDirection) || 'asc')

const columns = [
  { key: 'id', label: '#', sortable: false, class: 'w-[60px]' },
  { key: 'status', label: 'Estado', sortable: true, class: 'w-[120px]' },
  { key: 'cliente', label: 'Cliente', sortable: true, class: 'min-w-[180px]' },
  { key: 'plan', label: 'Plan / Cotización', sortable: false, class: 'min-w-[200px]' },
  { key: 'submitted_at', label: 'Recibido', sortable: true, class: 'w-[140px]' },
  { key: 'action', label: '', sortable: false, align: 'center' as const, class: 'w-[60px]' },
]

const irA = (href: string) => router.visit(href)

const handleSort = (column: string, direction: SortDirection) => {
  router.get('/admin/checkout-sessions', { sort: column, direction }, { preserveState: true, preserveScroll: true, replace: true })
}

const statusLabel = (s: string) => ({
  pending: 'Pendiente',
  submitted: 'Por procesar',
  processed: 'Procesado',
  expired: 'Expirado',
}[s] ?? s)

const statusBadgeClass = (s: string) => {
  const map: Record<string, string> = {
    pending: 'bg-muted text-foreground border-border',
    submitted: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    processed: 'bg-green-100 text-green-700 border-green-200',
    expired: 'bg-destructive/10 text-destructive border-destructive/20',
  }
  return map[s] ?? 'bg-muted text-muted-foreground border-border'
}

const statusDotClass = (s: string) => {
  const map: Record<string, string> = {
    pending: 'bg-muted-foreground',
    submitted: 'bg-yellow-500',
    processed: 'bg-green-500',
    expired: 'bg-destructive',
  }
  return map[s] ?? 'bg-muted-foreground'
}

const formatDate = (iso: string | null) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
}

const formatPrice = (value: number | null) => {
  if (value == null) return '—'
  return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(value)
}
</script>
