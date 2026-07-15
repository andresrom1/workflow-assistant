<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <!-- Header -->
      <div class="mb-6 flex items-start justify-between gap-3">
        <div>
          <Button as-child variant="link" size="sm" class="px-0 h-auto">
            <Link href="/admin/facturacion">
              <ArrowLeftIcon class="size-3.5" />
              Volver a facturación
            </Link>
          </Button>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-1);">
            Lote {{ batch.codigo }}
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">{{ batch.concepto }}</p>
        </div>
        <Button v-if="puedeDescargarZip" as-child size="sm">
          <a :href="`/admin/facturacion/batches/${batch.id}/download`">
            <DownloadIcon class="size-3.5" />
            Descargar todo (ZIP)
          </a>
        </Button>
      </div>

      <!-- Datos del lote -->
      <Card class="mb-6">
        <CardContent class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
          <div>
            <p class="text-[11px] font-semibold" style="color: var(--text-3);">Punto de venta</p>
            <p class="font-mono" style="color: var(--text-1);">{{ batch.punto_venta }}</p>
          </div>
          <div>
            <p class="text-[11px] font-semibold" style="color: var(--text-3);">Fecha comprobante</p>
            <p style="color: var(--text-1);">{{ fmtDate(batch.fecha_comprobante) }}</p>
          </div>
          <div>
            <p class="text-[11px] font-semibold" style="color: var(--text-3);">Período facturado</p>
            <p style="color: var(--text-1);">{{ fmtDate(batch.fecha_servicio_desde) }} al {{ fmtDate(batch.fecha_servicio_hasta) }}</p>
          </div>
          <div>
            <p class="text-[11px] font-semibold" style="color: var(--text-3);">Vto. de pago</p>
            <p style="color: var(--text-1);">{{ fmtDate(batch.fecha_vto_pago) }}</p>
          </div>
        </CardContent>
      </Card>

      <!-- Resumen -->
      <div v-if="batch.summary" class="flex flex-wrap gap-2 mb-4">
        <Badge variant="default" class="tabular-nums">
          Autorizadas: {{ batch.summary.autorizadas }}
        </Badge>
        <Badge variant="destructive" class="tabular-nums">
          Rechazadas: {{ batch.summary.rechazadas }}
        </Badge>
        <Badge variant="secondary" class="tabular-nums">
          Total: {{ money(totalAutorizado) }}
        </Badge>
      </div>

      <!-- Facturas -->
      <Card>
        <CardHeader>
          <CardTitle>Facturas</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable
            :columns="columns"
            :data="batch.invoices"
            empty-message="Este lote no tiene facturas."
          >
            <template #cell-importe="{ item }">
              <span class="font-mono tabular-nums">{{ money(item.importe) }}</span>
            </template>

            <template #cell-cae="{ item }">
              <span class="font-mono">{{ item.cae ?? '—' }}</span>
            </template>

            <template #cell-cae_vencimiento="{ item }">
              {{ item.cae_vencimiento ? fmtDate(item.cae_vencimiento) : '—' }}
            </template>

            <template #cell-estado="{ item }">
              <div class="space-y-0.5">
                <Badge :variant="estadoBadgeVariant(item.estado)">{{ item.estado_label }}</Badge>
                <p v-if="item.observaciones" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ item.observaciones }}</p>
              </div>
            </template>

            <template #cell-pdf="{ item }">
              <Button v-if="item.estado === 'authorized'" as-child variant="link" size="sm" class="px-0 h-auto">
                <a :href="`/admin/facturacion/invoices/${item.id}/pdf`">Descargar</a>
              </Button>
              <span v-else class="text-xs" style="color: var(--text-3);">—</span>
            </template>

            <template #mobile-row="{ item }">
              <div
                class="rounded-[14px] p-3 text-xs"
                style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
              >
                <div class="flex items-center justify-between mb-1">
                  <span class="font-semibold text-sm" style="color: var(--text-1);">{{ item.company }}</span>
                  <Badge :variant="estadoBadgeVariant(item.estado)">{{ item.estado_label }}</Badge>
                </div>
                <div class="grid grid-cols-2 gap-2" style="color: var(--text-2);">
                  <span>Importe: <span class="font-mono tabular-nums">{{ money(item.importe) }}</span></span>
                  <span>N°: <span class="font-mono">{{ item.numero_comprobante ?? '—' }}</span></span>
                  <span>CAE: <span class="font-mono">{{ item.cae ?? '—' }}</span></span>
                  <span>Vto: {{ item.cae_vencimiento ? fmtDate(item.cae_vencimiento) : '—' }}</span>
                </div>
                <p v-if="item.observaciones" class="mt-1" style="color: var(--badge-danger-txt);">{{ item.observaciones }}</p>
                <Button v-if="item.estado === 'authorized'" as-child variant="link" size="sm" class="px-0 h-auto mt-2">
                  <a :href="`/admin/facturacion/invoices/${item.id}/pdf`">Descargar PDF</a>
                </Button>
              </div>
            </template>
          </DataTable>
        </CardContent>
      </Card>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Button } from '@/components/UI/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/UI/card'
import { Badge } from '@/components/UI/badge'
import { ArrowLeft as ArrowLeftIcon, Download as DownloadIcon } from '@lucide/vue'
import DataTable from '@/components/App/DataTable.vue'

interface BatchInvoice {
  id: number; company: string | null; importe: string
  numero_comprobante: number | null; cae: string | null; cae_vencimiento: string | null
  estado: string; estado_label: string; observaciones: string | null
}
interface Summary { autorizadas: number; rechazadas: number; total: number }
interface Batch {
  id: number; codigo: string; concepto: string; punto_venta: number
  fecha_comprobante: string; fecha_servicio_desde: string; fecha_servicio_hasta: string; fecha_vto_pago: string
  estado: string; finished_at: string | null; summary: Summary | null; invoices: BatchInvoice[]
}

const props = defineProps<{ batch: Batch }>()

const money = (v: number | string): string =>
  new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(Number(v) || 0)

const fmtDate = (iso: string): string => {
  const [y, m, d] = iso.split('-')
  return `${d}/${m}/${y}`
}

const totalAutorizado = computed(() =>
  props.batch.invoices
    .filter((i) => i.estado === 'authorized')
    .reduce((sum, i) => sum + Number(i.importe), 0),
)

const puedeDescargarZip = computed(() => (props.batch.summary?.autorizadas ?? 0) > 0)

const columns = [
  { key: 'company', label: 'Compañía', sortable: false },
  { key: 'importe', label: 'Importe', sortable: false, align: 'right' as const },
  { key: 'numero_comprobante', label: 'N° comprobante', sortable: false },
  { key: 'cae', label: 'CAE', sortable: false },
  { key: 'cae_vencimiento', label: 'Vto. CAE', sortable: false },
  { key: 'estado', label: 'Estado', sortable: false },
  { key: 'pdf', label: 'PDF', sortable: false, align: 'right' as const },
]

const estadoBadgeVariant = (estado: string): 'default' | 'secondary' | 'destructive' => {
  switch (estado) {
    case 'authorized': return 'default'
    case 'rejected': return 'destructive'
    default: return 'secondary'
  }
}
</script>
