<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <!-- Header -->
      <div class="mb-6 flex items-start justify-between gap-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Facturación de comisiones
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">
            Emisión de Facturas C contra AFIP · Punto de venta {{ puntoVenta }}.
          </p>
        </div>
        <a href="/admin/facturacion/configuracion" class="btn text-sm py-1.5 px-3 whitespace-nowrap" style="background: var(--bg-subtle); color: var(--text-2);">
          ⚙ Configuración
        </a>
      </div>

      <!-- Lote en proceso: progreso en vivo -->
      <div v-if="batchEnProceso"
        class="rounded-[14px] p-4 mb-6"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <div class="flex items-center justify-between mb-3">
          <div>
            <p class="text-sm font-semibold" style="color: var(--text-1);">
              Lote {{ batchEnProceso.codigo }} — emitiendo…
            </p>
            <p class="text-[11px]" style="color: var(--text-3);">{{ batchEnProceso.concepto }}</p>
          </div>
          <span class="inline-flex items-center gap-2 text-xs" style="color: var(--text-3);">
            <span class="w-2 h-2 rounded-full animate-pulse" style="background: var(--accent-600);"></span>
            Procesando contra AFIP
          </span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-xs" style="color: var(--text-2);">
            <thead>
              <tr style="color: var(--text-3);" class="text-left">
                <th class="py-1.5 pr-3 font-semibold">Compañía</th>
                <th class="py-1.5 pr-3 font-semibold right">Importe</th>
                <th class="py-1.5 pr-3 font-semibold">N° comp.</th>
                <th class="py-1.5 pr-3 font-semibold">Estado</th>
                <th class="py-1.5 font-semibold">Observaciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in batchEnProceso.invoices" :key="i.id" style="border-top: 1px solid var(--border);">
                <td class="py-1.5 pr-3">{{ i.company }}</td>
                <td class="py-1.5 pr-3 font-mono tabular-nums text-right">{{ money(i.importe) }}</td>
                <td class="py-1.5 pr-3 font-mono">{{ i.numero_comprobante ?? '—' }}</td>
                <td class="py-1.5 pr-3">
                  <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold" :style="estadoStyle(i.estado)">
                    {{ i.estado_label }}
                  </span>
                </td>
                <td class="py-1.5" style="color: var(--badge-danger-txt);">{{ i.observaciones || '' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Formulario de nuevo lote (solo si no hay uno en proceso) -->
      <form v-else
        class="rounded-[14px] p-4 mb-6"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
        @submit.prevent="abrirConfirmacion">

        <!-- Datos comunes -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Código</span>
            <input v-model="form.codigo" type="text" placeholder="0006" class="field mt-1" />
            <span v-if="form.errors.codigo" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ form.errors.codigo }}</span>
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Producto / Servicio</span>
            <input v-model="form.concepto" type="text" placeholder="Comisiones correspondientes a Junio 2026" class="field mt-1" />
            <span v-if="form.errors.concepto" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ form.errors.concepto }}</span>
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Servicios desde</span>
            <input v-model="form.fecha_servicio_desde" type="date" class="field mt-1" />
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Servicios hasta</span>
            <input v-model="form.fecha_servicio_hasta" type="date" class="field mt-1" />
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Vencimiento de pago</span>
            <input v-model="form.fecha_vto_pago" type="date" class="field mt-1" />
          </label>
        </div>

        <!-- Listado de compañías -->
        <div class="rounded-[10px] overflow-hidden" style="border: 1px solid var(--border);">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs" style="color: var(--text-3); background: var(--bg-subtle);">
                <th class="py-2 px-3 font-semibold w-10"></th>
                <th class="py-2 px-3 font-semibold">Compañía</th>
                <th class="py-2 px-3 font-semibold text-right">Importe</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.id" style="border-top: 1px solid var(--border);"
                :style="row.checked ? '' : 'opacity: 0.5;'">
                <td class="py-1.5 px-3">
                  <input type="checkbox" v-model="row.checked" />
                </td>
                <td class="py-1.5 px-3" style="color: var(--text-1);">
                  {{ row.razon_social }}
                  <span v-if="yaFacturada(row.id)" class="ml-1 text-[11px]" style="color: var(--badge-warn-txt, #b45309);">
                    · ya facturada con código {{ form.codigo }}
                  </span>
                </td>
                <td class="py-1.5 px-3">
                  <input
                    type="text" inputmode="decimal" placeholder="0,00"
                    class="field text-right font-mono tabular-nums"
                    :disabled="!row.checked"
                    v-model="row.importeRaw"
                    @blur="reformatRow(row)"
                    :style="rowInvalid(row) ? 'border-color: var(--badge-danger-txt);' : ''" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Total + acción -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-4">
          <p class="text-sm" style="color: var(--text-2);">
            Seleccionadas: <strong>{{ seleccionadas.length }}</strong> ·
            Total: <strong class="font-mono tabular-nums">{{ money(totalLote) }}</strong>
          </p>
          <button class="btn btn-primary text-sm py-1.5 px-4" type="submit" :disabled="!puedeEmitir || form.processing">
            {{ form.processing ? 'Emitiendo…' : 'Facturar seleccionadas' }}
          </button>
        </div>
        <p v-if="hayFilasInvalidas" class="text-[11px] mt-2 text-right" style="color: var(--badge-danger-txt);">
          Hay compañías tildadas sin importe. Cargá el monto o destildalas.
        </p>
      </form>

      <!-- Lotes recientes -->
      <div v-if="recientes.length">
        <h2 class="text-sm font-semibold mb-2" style="color: var(--text-2);">Lotes recientes</h2>
        <div class="space-y-2">
          <div v-for="b in recientes" :key="b.id"
            class="rounded-[10px] p-3 flex items-center justify-between gap-3 text-xs"
            style="background: var(--bg-card); border: 1px solid var(--border);">
            <div class="min-w-0">
              <p class="font-semibold truncate" style="color: var(--text-1);">{{ b.codigo }} · {{ b.concepto }}</p>
              <p class="truncate" style="color: var(--text-3);">{{ b.finished_at }}</p>
            </div>
            <div class="flex items-center gap-3 whitespace-nowrap">
              <span v-if="b.summary" class="font-mono tabular-nums" style="color: var(--text-3);">
                {{ b.summary.autorizadas }} ok · {{ b.summary.rechazadas }} rech.
              </span>
              <a v-if="b.summary && b.summary.autorizadas > 0"
                :href="`/admin/facturacion/batches/${b.id}/download`"
                class="btn btn-primary text-xs py-1 px-3">ZIP</a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal confirmar -->
    <Transition name="fade">
      <div v-if="confirmando" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
        @click.self="confirmando = false">
        <div class="w-full max-w-sm rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-base font-semibold mb-1" style="color: var(--text-1);">Confirmar facturación</h2>
          <div class="text-sm mb-4 space-y-1" style="color: var(--text-3);">
            <p>Código: <strong>{{ form.codigo }}</strong></p>
            <p>{{ form.concepto }}</p>
            <p>Período: {{ form.fecha_servicio_desde }} al {{ form.fecha_servicio_hasta }}</p>
            <p>Compañías: <strong>{{ seleccionadas.length }}</strong> · Total: <strong class="font-mono">{{ money(totalLote) }}</strong></p>
          </div>
          <div class="flex justify-end gap-2">
            <button class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);"
              @click="confirmando = false">Cancelar</button>
            <button class="btn btn-primary text-sm py-1.5 px-3" :disabled="form.processing" @click="emitir">
              {{ form.processing ? 'Emitiendo…' : 'Emitir' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useForm, usePoll } from '@inertiajs/vue3'

interface Company { id: number; razon_social: string; cuit: string; condicion_iva: string }
interface BatchInvoice {
  id: number; company: string | null; importe: string
  numero_comprobante: number | null; cae: string | null
  estado: string; estado_label: string; observaciones: string | null
}
interface BatchEnProceso {
  id: number; codigo: string; concepto: string; estado: string
  finished_at: string | null; summary: Record<string, number> | null; invoices: BatchInvoice[]
}
interface Summary { autorizadas: number; rechazadas: number; total: number }
interface Reciente { id: number; codigo: string; concepto: string; summary: Summary | null; finished_at: string | null }

const props = defineProps<{
  companies: Company[]
  puntoVenta: number
  batchEnProceso: BatchEnProceso | null
  recientes: Reciente[]
  facturadasPorCodigo: Record<string, number[]>
}>()

// ─── Formato de moneda es-AR ───────────────────────────────────────────────────
const money = (v: number | string): string =>
  new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(Number(v) || 0)

const parseMoney = (raw: string): number => {
  const cleaned = (raw || '').replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '')
  return parseFloat(cleaned) || 0
}
const formatMoney = (n: number): string =>
  n > 0 ? new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n) : ''

// ─── Filas de compañías ────────────────────────────────────────────────────────
interface Row { id: number; razon_social: string; checked: boolean; importeRaw: string }

const rows = reactive<Row[]>(
  props.companies.map((c): Row => ({ id: c.id, razon_social: c.razon_social, checked: true, importeRaw: '' })),
)

const reformatRow = (row: Row): void => {
  const n = parseMoney(row.importeRaw)
  row.importeRaw = formatMoney(n)
}

// `form` se declara ANTES de yaFacturada/watch: el getter del watch corre en el setup
// y accedería a `form` en zona muerta temporal si estuviera declarado después.
const form = useForm<{
  codigo: string; concepto: string
  fecha_servicio_desde: string; fecha_servicio_hasta: string; fecha_vto_pago: string
  empresas: Array<{ id: number; importe: number }>
}>({
  codigo: '', concepto: '',
  fecha_servicio_desde: '', fecha_servicio_hasta: '', fecha_vto_pago: '',
  empresas: [],
})

const yaFacturada = (companyId: number): boolean =>
  (props.facturadasPorCodigo[form.codigo] ?? []).includes(companyId)

// Deschequear por defecto las ya facturadas cuando cambia el código.
watch(() => form.codigo, () => {
  for (const row of rows) {
    if (yaFacturada(row.id)) { row.checked = false }
  }
})

const seleccionadas = computed(() => rows.filter((r) => r.checked && parseMoney(r.importeRaw) > 0))
const totalLote = computed(() => seleccionadas.value.reduce((sum, r) => sum + parseMoney(r.importeRaw), 0))
const rowInvalid = (row: Row): boolean => row.checked && parseMoney(row.importeRaw) <= 0
const hayFilasInvalidas = computed(() => rows.some(rowInvalid))
const puedeEmitir = computed(() => seleccionadas.value.length > 0 && !hayFilasInvalidas.value)

// ─── Submit ──────────────────────────────────────────────────────────────────
const confirmando = ref(false)

const abrirConfirmacion = (): void => {
  if (!puedeEmitir.value) return
  confirmando.value = true
}

const emitir = (): void => {
  form.empresas = seleccionadas.value.map((r) => ({ id: r.id, importe: parseMoney(r.importeRaw) }))
  form.post('/admin/facturacion', {
    preserveScroll: true,
    onSuccess: () => { confirmando.value = false },
  })
}

// ─── Polling mientras haya un lote en proceso ──────────────────────────────────
const { start, stop } = usePoll(3000, { only: ['batchEnProceso', 'recientes'] }, { autoStart: false })
watch(
  () => !!props.batchEnProceso && !props.batchEnProceso.finished_at,
  (activo) => { activo ? start() : stop() },
  { immediate: true },
)

const estadoStyle = (estado: string): string => {
  switch (estado) {
    case 'authorized': return 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
    case 'rejected': return 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);'
    default: return 'background: var(--bg-subtle); color: var(--text-3);'
  }
}
</script>
