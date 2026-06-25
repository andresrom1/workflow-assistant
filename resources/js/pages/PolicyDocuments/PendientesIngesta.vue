<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <BackLink href="/policy-documents" label="Docs Pólizas" class="mb-4" />

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Ingesta — Pendientes de confirmación
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">
            Altas que subió el ingestor local. Revisá y confirmá para materializar la póliza.
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
        <div v-for="(g, gi) in grupos" :key="gi"
          class="rounded-[14px] p-4"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

          <!-- Cabecera del contrato -->
          <div class="flex items-center gap-2 flex-wrap mb-3">
            <p class="text-sm font-semibold" style="color: var(--text-1);">
              {{ g.numero_poliza ?? 'Sin número' }}
            </p>
            <span v-if="g.compania" class="text-xs" style="color: var(--text-3);">· {{ g.compania }}</span>
            <span v-if="g.patente" class="text-[11px] font-mono px-2 py-0.5 rounded-full"
              style="background: var(--bg-subtle); color: var(--text-2);">{{ g.patente }}</span>
            <span v-if="g.contrato_anterior_sugerido"
              class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
              style="background: var(--accent-100); color: var(--accent-600);">
              Posible renovación de #{{ g.contrato_anterior_sugerido.numero ?? g.contrato_anterior_sugerido.id }}
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
                  <span class="text-xs" style="color: var(--text-2);">{{ doc.tomador || '—' }}</span>
                  <span v-if="doc.documento_numero" class="text-[11px] font-mono" style="color: var(--text-3);">
                    {{ doc.documento_numero }}
                  </span>
                </div>
                <p class="text-[11px] mt-1 font-mono truncate" style="color: var(--text-3);">
                  {{ doc.original_filename }}
                  <span v-if="doc.vigencia_hasta"> · vence {{ doc.vigencia_hasta }}</span>
                </p>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <a :href="doc.preview_url" target="_blank" rel="noopener"
                  class="text-xs underline" style="color: var(--accent-600);">Ver PDF</a>
                <button class="btn btn-primary text-xs py-1.5 px-3" @click="abrirConfirmacion(doc, g)">
                  Confirmar
                </button>
                <button class="btn text-xs py-1.5 px-3"
                  style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
                  @click="pedirDescarte(doc)">
                  Descartar
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal de confirmación -->
    <Transition name="fade">
      <div v-if="confirmando" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
        @click.self="confirmando = null">
        <div class="w-full max-w-md rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-base font-semibold mb-1" style="color: var(--text-1);">Confirmar alta</h2>
          <p class="text-xs mb-4" style="color: var(--text-3);">
            Revisá los datos antes de materializar. Lo que corrijas acá pisa lo extraído.
          </p>

          <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Documento (DNI/CUIT)</span>
                <input v-model="form.documento_numero" class="field mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">N° de póliza</span>
                <input v-model="form.numero_poliza" class="field mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Nombre</span>
                <input v-model="form.first_name" class="field mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Apellido</span>
                <input v-model="form.last_name" class="field mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Compañía</span>
                <input v-model="form.company" class="field mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Patente</span>
                <input v-model="form.patente" class="field mt-1" />
              </label>
            </div>

            <label class="block">
              <span class="text-xs font-semibold" style="color: var(--text-2);">Estado de la póliza</span>
              <select v-model="form.estado" class="field mt-1">
                <option value="">Inferir de las fechas</option>
                <option v-for="e in estados" :key="e.value" :value="e.value">{{ e.label }}</option>
              </select>
            </label>

            <label v-if="sugerencia" class="flex items-start gap-2 text-xs p-2 rounded-[8px]"
              style="background: var(--bg-subtle);">
              <input type="checkbox" v-model="vincularRenovacion" class="mt-0.5" />
              <span style="color: var(--text-2);">
                Vincular como renovación de la póliza
                <strong>#{{ sugerencia.numero ?? sugerencia.id }}</strong>
                <template v-if="sugerencia.vigencia"> (vencía {{ sugerencia.vigencia }})</template>
              </span>
            </label>
          </div>

          <p v-if="errorMsg" class="text-xs mt-3" style="color: var(--badge-danger-txt);">{{ errorMsg }}</p>

          <div class="flex justify-end gap-2 mt-5">
            <button class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);"
              @click="confirmando = null">Cancelar</button>
            <button class="btn btn-primary text-sm py-1.5 px-3" :disabled="enviando" @click="enviarConfirmacion">
              {{ enviando ? 'Materializando…' : 'Confirmar alta' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal de descarte -->
    <Transition name="fade">
      <div v-if="descartando" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4"
        @click.self="descartando = null">
        <div class="w-full max-w-sm rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-base font-semibold mb-1" style="color: var(--text-1);">Descartar documento</h2>
          <p class="text-sm mb-4" style="color: var(--text-3);">
            No se creará ninguna póliza. Esta acción no se puede deshacer desde acá.
          </p>
          <div class="flex justify-end gap-2">
            <button class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);"
              @click="descartando = null">Cancelar</button>
            <button class="btn text-sm py-1.5 px-3"
              style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
              @click="enviarDescarte">Descartar</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import BackLink from '@/components/UI/BackLink.vue'

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

interface Grupo {
  numero_poliza: string | null
  compania: string | null
  patente: string | null
  contrato_anterior_sugerido: Sugerencia | null
  documentos: IngestaDoc[]
}

const props = defineProps<{
  grupos: Grupo[]
  estados: Array<{ value: string; label: string }>
}>()

const totalDocs = computed(() => props.grupos.reduce((n, g) => n + g.documentos.length, 0))

const confirmando = ref<IngestaDoc | null>(null)
const sugerencia = ref<Sugerencia | null>(null)
const vincularRenovacion = ref(false)
const enviando = ref(false)
const errorMsg = ref('')

const form = reactive({
  documento_numero: '',
  first_name: '',
  last_name: '',
  numero_poliza: '',
  company: '',
  patente: '',
  estado: '',
})

const abrirConfirmacion = (doc: IngestaDoc, grupo: Grupo) => {
  errorMsg.value = ''
  const [first, ...rest] = (doc.tomador ?? '').split(' ')
  form.documento_numero = doc.documento_numero ?? ''
  form.first_name = first ?? ''
  form.last_name = rest.join(' ')
  form.numero_poliza = doc.numero_poliza ?? ''
  form.company = doc.compania ?? ''
  form.patente = doc.patente ?? ''
  form.estado = ''
  sugerencia.value = grupo.contrato_anterior_sugerido
  vincularRenovacion.value = false
  confirmando.value = doc
}

const enviarConfirmacion = () => {
  if (!confirmando.value) return
  enviando.value = true
  errorMsg.value = ''
  const payload: Record<string, string | number> = { ...form }
  if (vincularRenovacion.value && sugerencia.value) {
    payload.contrato_anterior_id = sugerencia.value.id
  }
  router.post(`/ingesta-pendientes/${confirmando.value.id}/confirmar`, payload, {
    preserveScroll: true,
    onSuccess: () => { confirmando.value = null },
    onError: (errors) => { errorMsg.value = Object.values(errors)[0] ?? 'No se pudo confirmar.' },
    onFinish: () => { enviando.value = false },
  })
}

const descartando = ref<IngestaDoc | null>(null)
const pedirDescarte = (doc: IngestaDoc) => { descartando.value = doc }
const enviarDescarte = () => {
  if (!descartando.value) return
  router.delete(`/ingesta-pendientes/${descartando.value.id}`, {
    preserveScroll: true,
    onSuccess: () => { descartando.value = null },
  })
}
</script>
