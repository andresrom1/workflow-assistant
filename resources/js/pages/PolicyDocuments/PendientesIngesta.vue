<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <AppBackLink href="/policy-documents" label="Docs Pólizas" class="mb-4" />

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Ingesta — Pendientes de confirmación
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">
            Altas que subió el ingestor local. Revisá y confirmá el contrato para materializar la póliza.
          </p>
        </div>
        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold font-mono tabular-nums self-start"
          :style="totalDocs > 0
            ? 'background: var(--accent-100); color: var(--accent-600);'
            : 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'">
          {{ totalDocs }} pendiente{{ totalDocs === 1 ? '' : 's' }}
        </span>
      </div>

      <!-- Vacío -->
      <div v-if="!grupos.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No hay altas pendientes del ingestor. 🎉
      </div>

      <!-- Grupos (contratos) -->
      <div v-else class="space-y-4">
        <div v-for="g in grupos" :key="g.key"
          class="rounded-[14px] p-4"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

          <!-- Cabecera del contrato -->
          <div class="flex items-start gap-2 flex-wrap mb-3">
            <div class="flex items-center gap-2 flex-wrap flex-1 min-w-0">
              <p class="text-sm font-semibold" style="color: var(--text-1);">
                {{ g.numero_poliza ?? 'Sin número' }}
              </p>
              <span v-if="g.compania" class="text-xs" style="color: var(--text-3);">· {{ g.compania }}</span>
              <span v-if="g.contrato_anterior_sugerido"
                class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                style="background: var(--accent-100); color: var(--accent-600);">
                Posible renovación de #{{ g.contrato_anterior_sugerido.numero ?? g.contrato_anterior_sugerido.id }}
              </span>
            </div>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold tabular-nums self-start"
              :style="g.faltantes_count > 0
                ? 'background: var(--badge-warn-bg, var(--accent-100)); color: var(--badge-warn-txt, var(--accent-600));'
                : 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'">
              {{ g.faltantes_count > 0 ? `${g.faltantes_count} faltante${g.faltantes_count === 1 ? '' : 's'}` : 'Completo' }}
            </span>
          </div>

          <!-- Resumen del contrato (solo campos con dato) -->
          <div v-if="hasResumen(g.resumen)" class="flex flex-wrap gap-x-4 gap-y-1 mb-3 text-xs" style="color: var(--text-2);">
            <span v-if="g.resumen.tomador"><span style="color: var(--text-3);">Tomador:</span> {{ g.resumen.tomador }}</span>
            <span v-if="g.resumen.documento_numero"><span style="color: var(--text-3);">DNI/CUIT:</span> {{ g.resumen.documento_numero }}</span>
            <span v-if="g.resumen.patente"><span style="color: var(--text-3);">Dominio:</span> {{ g.resumen.patente }}</span>
            <span v-if="g.resumen.vehiculo"><span style="color: var(--text-3);">Vehículo:</span> {{ g.resumen.vehiculo }}</span>
            <span v-if="g.resumen.vigencia_desde || g.resumen.vigencia_hasta">
              <span style="color: var(--text-3);">Vigencia:</span>
              {{ g.resumen.vigencia_desde ?? '—' }} → {{ g.resumen.vigencia_hasta ?? '—' }}
            </span>
          </div>

          <!-- Documentos del contrato -->
          <div class="space-y-2">
            <div v-for="doc in g.documentos" :key="doc.id"
              class="rounded-[10px] p-3 flex flex-col sm:flex-row sm:items-center gap-3"
              style="background: var(--bg-subtle); border: 1px solid var(--border);">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                    style="background: var(--bg-card); color: var(--text-2); border: 1px solid var(--border);">
                    {{ doc.kind_label }}
                  </span>
                  <span v-if="doc.campos_no_extraidos.length"
                    class="text-[11px]" style="color: var(--badge-danger-txt);">
                    faltan: {{ doc.campos_no_extraidos.join(', ') }}
                  </span>
                </div>
                <p class="text-[11px] mt-1 font-mono truncate" style="color: var(--text-3);">
                  {{ doc.original_filename }}
                </p>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <a :href="doc.preview_url" target="_blank" rel="noopener"
                  class="text-xs underline" style="color: var(--accent-600);">Ver PDF</a>
                <button class="text-xs" style="color: var(--badge-danger-txt);" @click="pedirDescarteDoc(doc)">
                  Quitar
                </button>
              </div>
            </div>
          </div>

          <!-- Acciones del contrato -->
          <div class="flex items-center justify-end gap-2 mt-4">
            <button class="btn text-xs py-1.5 px-3"
              style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
              @click="pedirDescarteContrato(g)">
              Descartar contrato
            </button>
            <button class="btn btn-primary text-xs py-1.5 px-3" @click="abrirContrato(g)">
              Confirmar contrato
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal de confirmación: dos paneles (PDF + form) -->
    <Transition name="fade">
      <div v-if="contrato" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-3 sm:p-4"
        @click.self="cerrar">
        <div class="w-full max-w-6xl h-[90vh] rounded-[14px] overflow-hidden flex flex-col"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

          <!-- Cabecera del modal -->
          <div class="flex items-center justify-between px-5 py-3 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
            <div>
              <h2 class="text-base font-semibold" style="color: var(--text-1);">Confirmar contrato</h2>
              <p class="text-xs" style="color: var(--text-3);">
                {{ contrato.numero_poliza ?? 'Sin número' }}<span v-if="contrato.compania"> · {{ contrato.compania }}</span>
                — {{ modalDocs.length }} documento{{ modalDocs.length === 1 ? '' : 's' }}
              </p>
            </div>
            <button class="text-sm" style="color: var(--text-3);" @click="cerrar">✕</button>
          </div>

          <div class="grid md:grid-cols-2 flex-1 min-h-0">
            <!-- Panel izquierdo: visor de PDF con tabs -->
            <div class="flex flex-col min-h-0" style="border-right: 1px solid var(--border);">
              <div class="flex items-center gap-2 px-3 py-2 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                <div class="flex gap-1 overflow-x-auto flex-1 min-w-0">
                  <button v-for="doc in modalDocs" :key="doc.id"
                    class="text-xs px-2.5 py-1 rounded-full whitespace-nowrap"
                    :style="doc.id === activeDocId
                      ? 'background: var(--accent-600); color: white;'
                      : 'background: var(--bg-subtle); color: var(--text-2); border: 1px solid var(--border);'"
                    @click="activeDocId = doc.id">
                    {{ doc.kind_label }}
                  </button>
                </div>
                <button v-if="activeDoc" class="text-xs whitespace-nowrap flex-shrink-0"
                  style="color: var(--badge-danger-txt);" @click="descartarDocActivo">
                  Descartar documento
                </button>
              </div>
              <iframe v-if="activeDoc" :src="activeDoc.preview_url" class="flex-1 w-full min-h-0"
                style="border: 0; background: var(--bg-subtle);" title="Documento"></iframe>
            </div>

            <!-- Panel derecho: form del contrato -->
            <div class="overflow-y-auto p-5">
              <p class="text-xs mb-4" style="color: var(--text-3);">
                Revisá los datos antes de materializar. Lo que corrijas acá pisa lo extraído.
                <span v-if="contrato.campos_faltantes.length" style="color: var(--badge-danger-txt);">
                  Campos resaltados: no se pudieron extraer.
                </span>
              </p>

              <div class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                  <label class="block">
                    <span class="text-xs font-semibold" :style="labelStyle('documento_numero')">Documento (DNI/CUIT)</span>
                    <input ref="firstField" v-model="form.documento_numero" class="field mt-1" :style="fieldStyle('documento_numero')" @input="buscarCliente" />
                  </label>
                  <label class="block">
                    <span class="text-xs font-semibold" :style="labelStyle('numero_poliza')">N° de póliza</span>
                    <input v-model="form.numero_poliza" class="field mt-1" :style="fieldStyle('numero_poliza')" />
                  </label>

                  <!-- Resultado del lookup de cliente (bajo el campo Documento) -->
                  <div v-if="clienteLookup" class="col-span-2 text-xs px-2.5 py-1.5 rounded-[8px] flex items-center gap-1.5"
                    :style="clienteLookup.existe
                      ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                      : 'background: var(--bg-subtle); color: var(--text-2);'">
                    <template v-if="clienteLookup.existe">
                      ✓ Cliente existente: <strong>{{ clienteMatchNombre }}</strong> — se adjunta al cliente actual
                    </template>
                    <template v-else>
                      + Cliente nuevo — se creará al confirmar
                    </template>
                  </div>
                  <label class="block">
                    <span class="text-xs font-semibold" style="color: var(--text-2);">Tipo de documento</span>
                    <select v-model="form.document_type" class="field mt-1" @change="buscarCliente">
                      <option value="dni">DNI</option>
                      <option value="cuit">CUIT</option>
                      <option value="cuil">CUIL</option>
                    </select>
                  </label>
                  <label class="block">
                    <span class="text-xs font-semibold" style="color: var(--text-2);">Tipo de persona</span>
                    <select v-model="form.person_type" class="field mt-1" @change="buscarCliente">
                      <option value="fisica">Persona física</option>
                      <option value="juridica">Persona jurídica</option>
                    </select>
                  </label>
                  <label class="block">
                    <span class="text-xs font-semibold" :style="labelStyle('nombre_tomador')">Nombre</span>
                    <input v-model="form.first_name" class="field mt-1" :style="fieldStyle('nombre_tomador')" />
                  </label>
                  <label class="block">
                    <span class="text-xs font-semibold" :style="labelStyle('nombre_tomador')">Apellido</span>
                    <input v-model="form.last_name" class="field mt-1" :style="fieldStyle('nombre_tomador')" />
                  </label>
                  <label class="block">
                    <span class="text-xs font-semibold" style="color: var(--text-2);">Compañía</span>
                    <input v-model="form.company" class="field mt-1" />
                  </label>
                  <label class="block">
                    <span class="text-xs font-semibold" :style="labelStyle('patente')">Patente</span>
                    <input v-model="form.patente" class="field mt-1" :style="fieldStyle('patente')" />
                  </label>
                  <label class="block">
                    <span class="text-xs font-semibold" :style="labelStyle('vigencia_desde')">Vigencia desde</span>
                    <input type="date" v-model="form.vigencia_desde" class="field mt-1" :style="fieldStyle('vigencia_desde')" />
                  </label>
                  <label class="block">
                    <span class="text-xs font-semibold" :style="labelStyle('vigencia_hasta')">Vigencia hasta</span>
                    <input type="date" v-model="form.vigencia_hasta" class="field mt-1" :style="fieldStyle('vigencia_hasta')" />
                  </label>
                </div>

                <label class="block">
                  <span class="text-xs font-semibold" style="color: var(--text-2);">Estado de la póliza</span>
                  <select v-model="form.estado" class="field mt-1">
                    <option value="">Inferir de las fechas</option>
                    <option v-for="e in estados" :key="e.value" :value="e.value">{{ e.label }}</option>
                  </select>
                </label>

                <label v-if="contrato.contrato_anterior_sugerido" class="flex items-start gap-2 text-xs p-2 rounded-[8px]"
                  style="background: var(--bg-subtle);">
                  <input type="checkbox" v-model="vincularRenovacion" class="mt-0.5" />
                  <span style="color: var(--text-2);">
                    Vincular como renovación de la póliza
                    <strong>#{{ contrato.contrato_anterior_sugerido.numero ?? contrato.contrato_anterior_sugerido.id }}</strong>
                    <template v-if="contrato.contrato_anterior_sugerido.vigencia"> (vencía {{ contrato.contrato_anterior_sugerido.vigencia }})</template>
                  </span>
                </label>
              </div>

              <p v-if="errorMsg" class="text-xs mt-3" style="color: var(--badge-danger-txt);">{{ errorMsg }}</p>

              <div class="flex justify-end gap-2 mt-5">
                <button class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);"
                  @click="cerrar">Cancelar</button>
                <button class="btn btn-primary text-sm py-1.5 px-3" :disabled="enviando" @click="enviarConfirmacion">
                  {{ enviando ? 'Materializando…' : 'Confirmar contrato' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal de descarte (doc suelto o contrato) -->
    <Transition name="fade">
      <div v-if="descarte" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
        @click.self="descarte = null">
        <div class="w-full max-w-sm rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-base font-semibold mb-1" style="color: var(--text-1);">
            {{ descarte.tipo === 'contrato' ? 'Descartar contrato' : 'Quitar documento' }}
          </h2>
          <p class="text-sm mb-4" style="color: var(--text-3);">
            {{ descarte.tipo === 'contrato'
              ? 'Se descartan todos los documentos de este contrato. No se creará ninguna póliza.'
              : 'Se quita este documento del contrato. No se creará ninguna póliza a partir de él.' }}
          </p>
          <div class="flex justify-end gap-2">
            <button class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);"
              @click="descarte = null">Cancelar</button>
            <button class="btn text-sm py-1.5 px-3"
              style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
              @click="enviarDescarte">{{ descarte.tipo === 'contrato' ? 'Descartar' : 'Quitar' }}</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppBackLink from '@/components/App/BackLink.vue'

interface IngestaDoc {
  id: number
  kind: string
  kind_label: string
  compania: string | null
  numero_poliza: string | null
  documento_numero: string | null
  patente: string | null
  tomador: string | null
  vigencia_desde: string | null
  vigencia_hasta: string | null
  campos_no_extraidos: string[]
  original_filename: string | null
  preview_url: string
}

interface Sugerencia { id: number; numero: string | null; vigencia: string | null }

interface Resumen {
  tomador: string | null
  documento_numero: string | null
  patente: string | null
  vehiculo: string | null
  vigencia_desde: string | null
  vigencia_hasta: string | null
}

interface Prefill {
  documento_numero: string | null
  document_type: string | null
  person_type: string | null
  first_name: string | null
  last_name: string | null
  numero_poliza: string | null
  company: string | null
  patente: string | null
  vigencia_desde: string | null
  vigencia_hasta: string | null
}

interface ClienteLookup {
  existe: boolean
  cliente: { first_name: string | null; last_name: string | null; name: string | null; email: string | null } | null
}

interface Grupo {
  key: string
  numero_poliza: string | null
  compania: string | null
  patente: string | null
  resumen: Resumen
  prefill: Prefill
  campos_faltantes: string[]
  faltantes_count: number
  contrato_anterior_sugerido: Sugerencia | null
  documentos: IngestaDoc[]
}

const props = defineProps<{
  grupos: Grupo[]
  estados: Array<{ value: string; label: string }>
}>()

const totalDocs = computed(() => props.grupos.reduce((n, g) => n + g.documentos.length, 0))

const hasResumen = (r: Resumen): boolean =>
  Boolean(r.tomador || r.documento_numero || r.patente || r.vehiculo || r.vigencia_desde || r.vigencia_hasta)

// ─── Confirmación por contrato ──────────────────────────────────────────────
const contrato = ref<Grupo | null>(null)
const modalDocs = ref<IngestaDoc[]>([])
const activeDocId = ref<number | null>(null)
const vincularRenovacion = ref(false)
const enviando = ref(false)
const errorMsg = ref('')
const firstField = ref<HTMLInputElement | null>(null)

const form = reactive({
  documento_numero: '',
  document_type: '',
  person_type: '',
  first_name: '',
  last_name: '',
  numero_poliza: '',
  company: '',
  patente: '',
  vigencia_desde: '',
  vigencia_hasta: '',
  estado: '',
})

const activeDoc = computed(() => modalDocs.value.find((d) => d.id === activeDocId.value) ?? null)

const faltaCampo = (campo: string): boolean => contrato.value?.campos_faltantes.includes(campo) ?? false
const labelStyle = (campo: string): string => faltaCampo(campo) ? 'color: var(--badge-danger-txt);' : 'color: var(--text-2);'
const fieldStyle = (campo: string): string => faltaCampo(campo) ? 'border-color: var(--badge-danger-txt);' : ''

const abrirContrato = (g: Grupo) => {
  errorMsg.value = ''
  clienteLookup.value = null
  form.documento_numero = g.prefill.documento_numero ?? ''
  form.document_type = g.prefill.document_type ?? 'dni'
  form.person_type = g.prefill.person_type ?? 'fisica'
  form.first_name = g.prefill.first_name ?? ''
  form.last_name = g.prefill.last_name ?? ''
  form.numero_poliza = g.prefill.numero_poliza ?? ''
  form.company = g.prefill.company ?? ''
  form.patente = g.prefill.patente ?? ''
  form.vigencia_desde = g.prefill.vigencia_desde ?? ''
  form.vigencia_hasta = g.prefill.vigencia_hasta ?? ''
  form.estado = ''
  vincularRenovacion.value = false
  modalDocs.value = [...g.documentos]
  activeDocId.value = modalDocs.value[0]?.id ?? null
  contrato.value = g
  nextTick(() => firstField.value?.focus())
  buscarCliente()
}

const descartarDocActivo = () => {
  if (activeDoc.value) pedirDescarteDoc(activeDoc.value)
}

// ─── Lookup de cliente por identidad (existe / nuevo) ───────────────────────
const clienteLookup = ref<ClienteLookup | null>(null)
let lookupTimer: ReturnType<typeof setTimeout> | null = null

const buscarCliente = () => {
  if (lookupTimer) clearTimeout(lookupTimer)
  const documento = form.documento_numero.trim()
  if (!documento) { clienteLookup.value = null; return }

  lookupTimer = setTimeout(async () => {
    const params = new URLSearchParams({
      documento,
      document_type: form.document_type,
      person_type: form.person_type,
    })
    try {
      const res = await fetch(`/ingesta-pendientes/buscar-cliente?${params.toString()}`, {
        headers: { Accept: 'application/json' },
      })
      if (!res.ok) { clienteLookup.value = null; return }
      const data: ClienteLookup = await res.json()
      clienteLookup.value = data
      // Autocompleta nombre/apellido solo si están vacíos (no pisa lo tipeado/extraído).
      if (data.existe && data.cliente) {
        if (!form.first_name && data.cliente.first_name) form.first_name = data.cliente.first_name
        if (!form.last_name && data.cliente.last_name) form.last_name = data.cliente.last_name
      }
    } catch {
      clienteLookup.value = null
    }
  }, 400)
}

const clienteMatchNombre = computed(() => {
  const c = clienteLookup.value?.cliente
  if (!c) return ''
  return c.name || [c.first_name, c.last_name].filter(Boolean).join(' ') || c.email || 'sin nombre'
})

const cerrar = () => { contrato.value = null }

const enviarConfirmacion = () => {
  if (!contrato.value) return
  enviando.value = true
  errorMsg.value = ''
  const payload: Record<string, unknown> = {
    ids: modalDocs.value.map((d) => d.id),
    ...form,
  }
  if (vincularRenovacion.value && contrato.value.contrato_anterior_sugerido) {
    payload.contrato_anterior_id = contrato.value.contrato_anterior_sugerido.id
  }
  router.post('/ingesta-pendientes/confirmar-contrato', payload, {
    preserveScroll: true,
    onSuccess: () => { contrato.value = null },
    onError: (errors) => { errorMsg.value = Object.values(errors)[0] ?? 'No se pudo confirmar.' },
    onFinish: () => { enviando.value = false },
  })
}

// ─── Descarte (doc suelto o contrato) ───────────────────────────────────────
const descarte = ref<{ tipo: 'doc'; id: number } | { tipo: 'contrato'; ids: number[] } | null>(null)

const pedirDescarteDoc = (doc: IngestaDoc) => { descarte.value = { tipo: 'doc', id: doc.id } }
const pedirDescarteContrato = (g: Grupo) => { descarte.value = { tipo: 'contrato', ids: g.documentos.map((d) => d.id) } }

const enviarDescarte = () => {
  const d = descarte.value
  if (!d) return
  if (d.tipo === 'doc') {
    const id = d.id
    router.delete(`/ingesta-pendientes/${id}`, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        descarte.value = null
        // Si el modal de confirmar está abierto, sacar el doc de la lista local y
        // cerrar si era el último; reapuntar la pestaña activa si hacía falta.
        if (!contrato.value) return
        modalDocs.value = modalDocs.value.filter((x) => x.id !== id)
        if (modalDocs.value.length === 0) {
          contrato.value = null
        } else if (activeDocId.value === id) {
          activeDocId.value = modalDocs.value[0]?.id ?? null
        }
      },
    })
  } else {
    router.post('/ingesta-pendientes/descartar-contrato', { ids: d.ids }, {
      preserveScroll: true,
      onSuccess: () => { descarte.value = null; contrato.value = null },
    })
  }
}
</script>
