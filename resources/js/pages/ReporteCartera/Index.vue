<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <!-- Header -->
      <div class="mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Reporte de cartera — Import
        </h1>
        <p class="text-sm mt-1" style="color: var(--text-3);">
          Subí el listado de pólizas exportado. Se arma un diff por lote; nada se materializa hasta que confirmes.
        </p>
      </div>

      <!-- Subida -->
      <form
        class="rounded-[14px] p-4 mb-6 flex flex-col sm:flex-row sm:items-end gap-3"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
        @submit.prevent="subir">
        <label class="block sm:w-56">
          <span class="text-xs font-semibold" style="color: var(--text-2);">Origen</span>
          <select v-model="upload.origen" class="field mt-1">
            <option v-for="o in origenes" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </label>
        <label class="block flex-1">
          <span class="text-xs font-semibold" style="color: var(--text-2);">Archivo (.xlsx)</span>
          <input type="file" accept=".xlsx" class="field mt-1" @input="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
        </label>
        <button class="btn btn-primary text-sm py-1.5 px-4" :disabled="upload.processing || !upload.file" type="submit">
          {{ upload.processing ? 'Subiendo…' : 'Subir' }}
        </button>
      </form>
      <p v-if="upload.errors.file" class="text-xs -mt-4 mb-6" style="color: var(--badge-danger-txt);">{{ upload.errors.file }}</p>

      <!-- Lote pendiente de revisión -->
      <div v-if="pendiente"
        class="rounded-[14px] p-4 mb-6"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
          <div>
            <p class="text-sm font-semibold" style="color: var(--text-1);">{{ pendiente.origen }}</p>
            <p class="text-[11px] font-mono" style="color: var(--text-3);">
              {{ pendiente.original_filename }} · {{ pendiente.uploaded_at }}
            </p>
          </div>
          <div class="flex items-center gap-2">
            <button class="btn btn-primary text-xs py-1.5 px-3" @click="confirmar = true">Confirmar lote</button>
            <button class="btn text-xs py-1.5 px-3"
              style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
              @click="descartar = true">Descartar</button>
          </div>
        </div>

        <!-- Resumen -->
        <div class="flex flex-wrap gap-2 mb-4">
          <span v-for="chip in chips" :key="chip.label"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold tabular-nums"
            :style="chip.style">
            {{ chip.label }}: {{ chip.value }}
          </span>
        </div>

        <!-- Filas -->
        <div class="overflow-x-auto">
          <table class="w-full text-xs" style="color: var(--text-2);">
            <thead>
              <tr style="color: var(--text-3);" class="text-left">
                <th class="py-1.5 pr-3 font-semibold">Acción</th>
                <th class="py-1.5 pr-3 font-semibold">Asegurado</th>
                <th class="py-1.5 pr-3 font-semibold">N° / Compañía</th>
                <th class="py-1.5 pr-3 font-semibold">Producto</th>
                <th class="py-1.5 pr-3 font-semibold">Patente</th>
                <th class="py-1.5 pr-3 font-semibold">Estado</th>
                <th class="py-1.5 font-semibold">Nota</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in pendiente.rows" :key="r.id" style="border-top: 1px solid var(--border);">
                <td class="py-1.5 pr-3">
                  <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap"
                    :style="accionStyle(r.accion)">{{ r.accion_label }}</span>
                </td>
                <td class="py-1.5 pr-3">{{ r.asegurado || '—' }}</td>
                <td class="py-1.5 pr-3 whitespace-nowrap">
                  <span class="font-mono">{{ r.numero || '—' }}</span>
                  <span style="color: var(--text-3);"> · {{ r.company || '—' }}</span>
                </td>
                <td class="py-1.5 pr-3">{{ r.producto || '—' }}</td>
                <td class="py-1.5 pr-3 font-mono">{{ r.patente || '—' }}</td>
                <td class="py-1.5 pr-3 whitespace-nowrap">
                  {{ r.estado_mapeado || r.estado_origen || '—' }}
                  <span v-if="r.vigencia" style="color: var(--text-3);">· {{ r.vigencia }}</span>
                </td>
                <td class="py-1.5" style="color: var(--badge-danger-txt);">{{ r.nota || '' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-else
        class="rounded-[14px] p-10 text-center text-sm mb-6"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No hay ningún lote pendiente. Subí un reporte para empezar.
      </div>

      <!-- Recientes -->
      <div v-if="recientes.length">
        <h2 class="text-sm font-semibold mb-2" style="color: var(--text-2);">Lotes recientes</h2>
        <div class="space-y-2">
          <div v-for="b in recientes" :key="b.id"
            class="rounded-[10px] p-3 flex items-center justify-between gap-3 text-xs"
            style="background: var(--bg-card); border: 1px solid var(--border);">
            <div class="min-w-0">
              <p class="font-semibold truncate" style="color: var(--text-1);">{{ b.origen }} · {{ b.status }}</p>
              <p class="font-mono truncate" style="color: var(--text-3);">{{ b.original_filename }} · {{ b.uploaded_at }}</p>
            </div>
            <span v-if="b.summary" class="font-mono tabular-nums whitespace-nowrap" style="color: var(--text-3);">
              +{{ b.summary.create }} altas · ~{{ b.summary.update_estado }} estados · {{ b.summary.exception }} exc.
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal confirmar -->
    <Transition name="fade">
      <div v-if="confirmar" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
        @click.self="confirmar = false">
        <div class="w-full max-w-sm rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-base font-semibold mb-1" style="color: var(--text-1);">Confirmar lote</h2>
          <p class="text-sm mb-4" style="color: var(--text-3);">
            Se materializan las altas y los cambios de estado. Las excepciones se saltan.
          </p>
          <div class="flex justify-end gap-2">
            <button class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);"
              @click="confirmar = false">Cancelar</button>
            <button class="btn btn-primary text-sm py-1.5 px-3" :disabled="enviando" @click="enviarConfirmar">
              {{ enviando ? 'Materializando…' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal descartar -->
    <Transition name="fade">
      <div v-if="descartar" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
        @click.self="descartar = false">
        <div class="w-full max-w-sm rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-base font-semibold mb-1" style="color: var(--text-1);">Descartar lote</h2>
          <p class="text-sm mb-4" style="color: var(--text-3);">No se materializa nada. Podés volver a subir el reporte.</p>
          <div class="flex justify-end gap-2">
            <button class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);"
              @click="descartar = false">Cancelar</button>
            <button class="btn text-sm py-1.5 px-3"
              style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
              @click="enviarDescartar">Descartar</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

interface Row {
  id: number
  asegurado: string | null
  documento: string | null
  numero: string | null
  company: string | null
  producto: string | null
  patente: string | null
  estado_origen: string | null
  estado_mapeado: string | null
  vigencia: string | null
  accion: string
  accion_label: string
  nota: string | null
}

interface Summary {
  create: number
  update_estado: number
  noop: number
  exception: number
  total: number
  nuevos_clientes: number
}

interface Batch {
  id: number
  origen: string
  original_filename: string | null
  uploaded_at: string | null
  summary: Summary | null
  rows: Row[]
}

interface Reciente {
  id: number
  origen: string
  original_filename: string | null
  status: string
  summary: Summary | null
  uploaded_at: string | null
}

const props = defineProps<{
  origenes: Array<{ value: string; label: string }>
  pendiente: Batch | null
  recientes: Reciente[]
}>()

const upload = useForm<{ origen: string; file: File | null }>({
  origen: props.origenes[0]?.value ?? '',
  file: null,
})

const subir = () => {
  upload.post('/reporte-cartera', { preserveScroll: true, forceFormData: true })
}

const chips = computed(() => {
  const s = props.pendiente?.summary
  if (!s) return []
  return [
    { label: 'Altas', value: s.create, style: 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);' },
    { label: 'Clientes nuevos', value: s.nuevos_clientes, style: 'background: var(--accent-100); color: var(--accent-600);' },
    { label: 'Cambios estado', value: s.update_estado, style: 'background: var(--accent-100); color: var(--accent-600);' },
    { label: 'Sin cambios', value: s.noop, style: 'background: var(--bg-subtle); color: var(--text-3);' },
    { label: 'Excepciones', value: s.exception, style: 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);' },
    { label: 'Total', value: s.total, style: 'background: var(--bg-subtle); color: var(--text-2);' },
  ]
})

const accionStyle = (accion: string): string => {
  switch (accion) {
    case 'create': return 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
    case 'update_estado': return 'background: var(--accent-100); color: var(--accent-600);'
    case 'exception': return 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);'
    default: return 'background: var(--bg-subtle); color: var(--text-3);'
  }
}

const confirmar = ref(false)
const descartar = ref(false)
const enviando = ref(false)

const enviarConfirmar = () => {
  if (!props.pendiente) return
  enviando.value = true
  router.post(`/reporte-cartera/${props.pendiente.id}/confirmar`, {}, {
    preserveScroll: true,
    onSuccess: () => { confirmar.value = false },
    onFinish: () => { enviando.value = false },
  })
}

const enviarDescartar = () => {
  if (!props.pendiente) return
  router.delete(`/reporte-cartera/${props.pendiente.id}`, {
    preserveScroll: true,
    onSuccess: () => { descartar.value = false },
  })
}
</script>
