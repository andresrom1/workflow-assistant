<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <!-- Header -->
      <div class="mb-6 flex items-start justify-between gap-3">
        <div>
          <Link href="/admin/facturacion" class="text-xs hover:underline" style="color: var(--text-3);">← Volver a facturación</Link>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mt-1" style="color: var(--text-1);">
            Lote {{ batch.codigo }}
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">{{ batch.concepto }}</p>
        </div>
        <a v-if="puedeDescargarZip"
          :href="`/admin/facturacion/batches/${batch.id}/download`"
          class="btn btn-primary text-sm py-1.5 px-4 whitespace-nowrap">
          Descargar todo (ZIP)
        </a>
      </div>

      <!-- Datos del lote -->
      <div class="rounded-[14px] p-4 mb-6 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
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
      </div>

      <!-- Resumen -->
      <div v-if="batch.summary" class="flex flex-wrap gap-2 mb-4">
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold tabular-nums"
          style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);">
          Autorizadas: {{ batch.summary.autorizadas }}
        </span>
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold tabular-nums"
          style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);">
          Rechazadas: {{ batch.summary.rechazadas }}
        </span>
        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold tabular-nums"
          style="background: var(--bg-subtle); color: var(--text-2);">
          Total: {{ money(totalAutorizado) }}
        </span>
      </div>

      <!-- Facturas -->
      <div class="rounded-[14px] p-4" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <div class="overflow-x-auto">
          <table class="w-full text-sm" style="color: var(--text-2);">
            <thead>
              <tr class="text-left text-xs" style="color: var(--text-3);">
                <th class="py-2 pr-3 font-semibold">Compañía</th>
                <th class="py-2 pr-3 font-semibold text-right">Importe</th>
                <th class="py-2 pr-3 font-semibold">N° comprobante</th>
                <th class="py-2 pr-3 font-semibold">CAE</th>
                <th class="py-2 pr-3 font-semibold">Vto. CAE</th>
                <th class="py-2 pr-3 font-semibold">Estado</th>
                <th class="py-2 font-semibold text-right">PDF</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in batch.invoices" :key="i.id" style="border-top: 1px solid var(--border);">
                <td class="py-2 pr-3" style="color: var(--text-1);">{{ i.company }}</td>
                <td class="py-2 pr-3 font-mono tabular-nums text-right">{{ money(i.importe) }}</td>
                <td class="py-2 pr-3 font-mono">{{ i.numero_comprobante ?? '—' }}</td>
                <td class="py-2 pr-3 font-mono">{{ i.cae ?? '—' }}</td>
                <td class="py-2 pr-3">{{ i.cae_vencimiento ? fmtDate(i.cae_vencimiento) : '—' }}</td>
                <td class="py-2 pr-3">
                  <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold" :style="estadoStyle(i.estado)">
                    {{ i.estado_label }}
                  </span>
                  <span v-if="i.observaciones" class="block text-[11px] mt-0.5" style="color: var(--badge-danger-txt);">{{ i.observaciones }}</span>
                </td>
                <td class="py-2 text-right">
                  <a v-if="i.estado === 'authorized'" :href="`/admin/facturacion/invoices/${i.id}/pdf`"
                    class="text-xs underline" style="color: var(--accent-600);">Descargar</a>
                  <span v-else class="text-xs" style="color: var(--text-3);">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

interface BatchInvoice {
  id: number; company: string | null; importe: string
  numero_comprobante: number | null; cae: string | null; cae_vencimiento: string | null
  estado: string; estado_label: string; observaciones: string | null
}
interface Summary { autorizadas: number; rechazadas: number; pendientes?: number; total: number }
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

// Se deriva de las facturas y NO de `summary`: el summary lo escribe el cierre del lote, así que
// en un lote que se cayó a mitad es null — justo cuando más hace falta bajar lo ya emitido.
const puedeDescargarZip = computed(() => props.batch.invoices.some((i) => i.estado === 'authorized'))

const estadoStyle = (estado: string): string => {
  switch (estado) {
    case 'authorized': return 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
    case 'rejected': return 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);'
    default: return 'background: var(--bg-subtle); color: var(--text-3);'
  }
}
</script>
