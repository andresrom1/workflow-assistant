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
        <Button as-child variant="secondary" size="sm">
          <Link href="/admin/facturacion/configuracion">
            <SettingsIcon class="size-3.5" />
            Configuración
          </Link>
        </Button>
      </div>

      <!-- Lote en proceso: progreso en vivo -->
      <Card v-if="batchEnProceso" class="mb-6">
        <CardHeader class="pb-2">
          <div class="flex items-center justify-between">
            <div>
              <CardTitle>Lote {{ batchEnProceso.codigo }} — emitiendo…</CardTitle>
              <CardDescription>{{ batchEnProceso.concepto }}</CardDescription>
            </div>
            <span class="inline-flex items-center gap-2 text-xs" style="color: var(--text-3);">
              <span class="w-2 h-2 rounded-full animate-pulse" style="background: var(--accent-600);"></span>
              Procesando contra AFIP
            </span>
          </div>
        </CardHeader>
        <CardContent>
          <DataTable
            :columns="batchColumns"
            :data="batchEnProceso.invoices"
            empty-message="Sin facturas en este lote."
          >
            <template #cell-importe="{ item }">
              <span class="font-mono tabular-nums">{{ money(item.importe) }}</span>
            </template>

            <template #cell-numero_comprobante="{ item }">
              <span class="font-mono">{{ item.numero_comprobante ?? '—' }}</span>
            </template>

            <template #cell-estado="{ item }">
              <Badge :variant="estadoBadgeVariant(item.estado)">{{ item.estado_label }}</Badge>
            </template>

            <template #cell-observaciones="{ item }">
              <span style="color: var(--badge-danger-txt);">{{ item.observaciones || '' }}</span>
            </template>

            <template #mobile-row="{ item }">
              <div
                class="rounded-[14px] p-3 text-xs"
                style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
              >
                <div class="flex items-center justify-between mb-1">
                  <span class="font-semibold" style="color: var(--text-1);">{{ item.company }}</span>
                  <Badge :variant="estadoBadgeVariant(item.estado)">{{ item.estado_label }}</Badge>
                </div>
                <div class="flex items-center justify-between" style="color: var(--text-2);">
                  <span class="font-mono tabular-nums">{{ money(item.importe) }}</span>
                  <span class="font-mono">N° {{ item.numero_comprobante ?? '—' }}</span>
                </div>
                <p v-if="item.observaciones" class="mt-1" style="color: var(--badge-danger-txt);">{{ item.observaciones }}</p>
              </div>
            </template>
          </DataTable>
        </CardContent>
      </Card>

      <!-- Formulario de nuevo lote (solo si no hay uno en proceso) -->
      <Card v-else class="mb-6">
        <CardContent>
          <form @submit.prevent="abrirConfirmacion">
            <!-- Datos comunes -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Código</span>
                <Input v-model="form.codigo" type="text" placeholder="0006" class="mt-1" />
                <span v-if="form.errors.codigo" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ form.errors.codigo }}</span>
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Producto / Servicio</span>
                <Input v-model="form.concepto" type="text" placeholder="Comisiones correspondientes a Junio 2026" class="mt-1" />
                <span v-if="form.errors.concepto" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ form.errors.concepto }}</span>
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Servicios desde</span>
                <Input v-model="form.fecha_servicio_desde" type="date" class="mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Servicios hasta</span>
                <Input v-model="form.fecha_servicio_hasta" type="date" class="mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Vencimiento de pago</span>
                <Input v-model="form.fecha_vto_pago" type="date" class="mt-1" />
              </label>
            </div>

            <!-- Listado de compañías -->
            <DataTable
              :columns="companyColumns"
              :data="rows"
              empty-message="No hay compañías configuradas."
            >
              <template #cell-checkbox="{ item }">
                <Checkbox v-model:checked="item.checked" />
              </template>

              <template #cell-razon_social="{ item }">
                <span style="color: var(--text-1);">
                  {{ item.razon_social }}
                  <span v-if="yaFacturada(item.id)" class="ml-1 text-[11px]" style="color: var(--badge-warn-txt, #b45309);">
                    · ya facturada con código {{ form.codigo }}
                  </span>
                </span>
              </template>

              <template #cell-importe="{ item }">
                <Input
                  v-model="item.importeRaw"
                  type="text"
                  inputmode="decimal"
                  placeholder="0,00"
                  :disabled="!item.checked"
                  class="text-right font-mono tabular-nums"
                  :class="rowInvalid(item) ? 'border-destructive' : ''"
                  @blur="reformatRow(item)"
                />
              </template>

              <template #mobile-row="{ item }">
                <div
                  class="rounded-[14px] p-3 text-sm"
                  style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
                  :style="item.checked ? '' : 'opacity: 0.5;'"
                >
                  <div class="flex items-center justify-between gap-3">
                    <label class="flex items-center gap-2 min-w-0">
                      <Checkbox v-model:checked="item.checked" />
                      <span class="truncate" style="color: var(--text-1);">{{ item.razon_social }}</span>
                    </label>
                    <Input
                      v-model="item.importeRaw"
                      type="text"
                      inputmode="decimal"
                      placeholder="0,00"
                      :disabled="!item.checked"
                      class="w-28 text-right font-mono tabular-nums"
                      :class="rowInvalid(item) ? 'border-destructive' : ''"
                      @blur="reformatRow(item)"
                    />
                  </div>
                  <p v-if="yaFacturada(item.id)" class="text-[11px] mt-1" style="color: var(--badge-warn-txt, #b45309);">
                    Ya facturada con código {{ form.codigo }}
                  </p>
                </div>
              </template>
            </DataTable>

            <!-- Total + acción -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-4">
              <p class="text-sm" style="color: var(--text-2);">
                Seleccionadas: <strong>{{ seleccionadas.length }}</strong> ·
                Total: <strong class="font-mono tabular-nums">{{ money(totalLote) }}</strong>
              </p>
              <Button type="submit" :disabled="!puedeEmitir || form.processing">
                {{ form.processing ? 'Emitiendo…' : 'Facturar seleccionadas' }}
              </Button>
            </div>
            <p v-if="hayFilasInvalidas" class="text-[11px] mt-2 text-right" style="color: var(--badge-danger-txt);">
              Hay compañías tildadas sin importe. Cargá el monto o destildalas.
            </p>
          </form>
        </CardContent>
      </Card>

      <!-- Lotes recientes -->
      <div v-if="recientes.length">
        <h2 class="text-sm font-semibold mb-2" style="color: var(--text-2);">Lotes recientes</h2>
        <div class="space-y-2">
          <Card v-for="b in recientes" :key="b.id" size="sm">
            <CardContent class="flex items-center justify-between gap-3 py-3">
              <Link :href="`/admin/facturacion/batches/${b.id}`" class="min-w-0 hover:underline">
                <p class="font-semibold truncate text-sm" style="color: var(--text-1);">{{ b.codigo }} · {{ b.concepto }}</p>
                <p class="truncate text-xs" style="color: var(--text-3);">{{ b.finished_at }}</p>
              </Link>
              <div class="flex items-center gap-2 whitespace-nowrap">
                <span v-if="b.summary" class="font-mono tabular-nums text-xs" style="color: var(--text-3);">
                  {{ b.summary.autorizadas }} ok · {{ b.summary.rechazadas }} rech.
                </span>
                <Button as-child variant="secondary" size="xs">
                  <Link :href="`/admin/facturacion/batches/${b.id}`">Detalle</Link>
                </Button>
                <Button v-if="b.summary && b.summary.autorizadas > 0" as-child size="xs">
                  <a :href="`/admin/facturacion/batches/${b.id}/download`">ZIP</a>
                </Button>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>

    <!-- Modal confirmar -->
    <Dialog v-model:open="confirmando">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Confirmar facturación</DialogTitle>
          <DialogDescription as="div">
            <div class="text-sm space-y-1 mt-1">
              <p>Código: <strong>{{ form.codigo }}</strong></p>
              <p>{{ form.concepto }}</p>
              <p>Período: {{ form.fecha_servicio_desde }} al {{ form.fecha_servicio_hasta }}</p>
              <p>Compañías: <strong>{{ seleccionadas.length }}</strong> · Total: <strong class="font-mono">{{ money(totalLote) }}</strong></p>
            </div>
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="secondary" @click="confirmando = false">Cancelar</Button>
          <Button :disabled="form.processing" @click="emitir">
            {{ form.processing ? 'Emitiendo…' : 'Emitir' }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { Link, useForm, usePoll } from '@inertiajs/vue3'
import { Button } from '@/components/UI/button'
import { Input } from '@/components/UI/input'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/UI/card'
import { Badge } from '@/components/UI/badge'
import { Checkbox } from '@/components/UI/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/UI/dialog'
import { Settings as SettingsIcon } from '@lucide/vue'
import DataTable from '@/components/App/DataTable.vue'

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

const opacityClass = (row: Row): string => row.checked ? '' : 'opacity-50'

const companyColumns = [
  { key: 'checkbox', label: '', sortable: false, align: 'center' as const, class: 'w-10', cellClass: opacityClass },
  { key: 'razon_social', label: 'Compañía', sortable: false, cellClass: opacityClass },
  { key: 'importe', label: 'Importe', sortable: false, align: 'right' as const, cellClass: opacityClass },
]

const batchColumns = [
  { key: 'company', label: 'Compañía', sortable: false },
  { key: 'importe', label: 'Importe', sortable: false, align: 'right' as const },
  { key: 'numero_comprobante', label: 'N° comp.', sortable: false },
  { key: 'estado', label: 'Estado', sortable: false },
  { key: 'observaciones', label: 'Observaciones', sortable: false, cellClass: 'text-destructive' },
]

const estadoBadgeVariant = (estado: string): 'default' | 'secondary' | 'destructive' => {
  switch (estado) {
    case 'authorized': return 'default'
    case 'rejected': return 'destructive'
    default: return 'secondary'
  }
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
</script>
