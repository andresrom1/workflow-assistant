<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <BackLink href="/policy-documents" label="Documentos de Póliza" class="mb-4" />

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            {{ poliza.numero ?? 'Póliza sin número' }}
          </h1>
          <p class="text-sm mt-1 font-mono" style="color: var(--text-3);">
            {{ poliza.patente ?? '—' }} · {{ poliza.label }}
            <span v-if="poliza.cliente"> · {{ poliza.cliente }}</span>
          </p>
        </div>
        <div class="flex items-center gap-2 self-start">
          <span v-if="poliza.estado" class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold"
            :style="poliza.estado === 'vigente'
              ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
              : 'background: var(--border-sub); color: var(--text-3);'">
            {{ poliza.estado === 'vigente' ? 'Vigente' : 'Histórica' }}
          </span>
          <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold"
            style="background: var(--accent-100); color: var(--accent-600);">
            {{ poliza.company ?? '—' }}
          </span>
        </div>
      </div>

      <!-- Upload form -->
      <div class="rounded-[14px] p-5 mb-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-sm font-semibold mb-4" style="color: var(--text-1);">Subir documento</h2>
        <form @submit.prevent="submitUpload" enctype="multipart/form-data">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="field-label">Tipo de documento *</label>
              <Select v-model="uploadForm.kind">
                <SelectTrigger class="w-full h-[38px]" :aria-invalid="!!uploadForm.errors.kind || undefined">
                  <SelectValue placeholder="Seleccionar..." />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
              <p v-if="uploadForm.errors.kind" class="field-error-text">{{ uploadForm.errors.kind }}</p>
            </div>
            <div>
              <label class="field-label">Archivo (PDF o imagen) *</label>
              <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="onFileSelect" class="field text-sm"
                :class="{ 'field-error': uploadForm.errors.file }" />
              <p v-if="uploadForm.errors.file" class="field-error-text">{{ uploadForm.errors.file }}</p>
            </div>
            <div class="sm:col-span-2">
              <label class="field-label">Etiqueta (opcional)</label>
              <input v-model="uploadForm.label" type="text" class="field"
                :class="{ 'field-error': uploadForm.errors.label }"
                placeholder="Ej: Endoso cambio de uso — junio 2026" />
              <p v-if="uploadForm.errors.label" class="field-error-text">{{ uploadForm.errors.label }}</p>
            </div>
          </div>

          <p class="text-xs mt-4" style="color: var(--text-3);">
            Los documentos de la póliza vigente se entregan automáticamente al cliente en la app.
          </p>

          <div class="flex justify-end mt-4">
            <button type="submit" class="btn btn-primary text-sm" :disabled="uploadForm.processing">
              {{ uploadForm.processing ? 'Subiendo...' : 'Subir documento' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Checklist de completitud -->
      <div class="rounded-[14px] p-5 mb-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-sm font-semibold" style="color: var(--text-1);">Documentación esperada</h2>
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
            :style="completos === checklist.length
              ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
              : 'background: var(--accent-100); color: var(--accent-600);'">
            {{ completos }}/{{ checklist.length }}
          </span>
        </div>
        <ul class="space-y-2">
          <li v-for="item in checklist" :key="item.kind" class="flex items-center gap-2.5 text-sm">
            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[11px] font-bold flex-shrink-0"
              :style="item.presente
                ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                : 'background: var(--border-sub); color: var(--text-3);'">
              {{ item.presente ? '✓' : '–' }}
            </span>
            <span :style="item.presente ? 'color: var(--text-1);' : 'color: var(--text-3);'">{{ item.label }}</span>
            <span v-if="!item.presente" class="ml-auto text-[11px] font-semibold" style="color: var(--accent-600);">Falta</span>
          </li>
        </ul>
      </div>

      <!-- Documents list -->
      <h2 class="text-sm font-semibold mb-3" style="color: var(--text-1);">
        Documentos ({{ documents.length }})
      </h2>

      <div v-if="!documents.length"
        class="rounded-[14px] p-10 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        Esta póliza todavía no tiene documentos cargados.
      </div>

      <div v-else class="space-y-2">
        <div v-for="doc in documents" :key="doc.id"
          class="rounded-[14px] p-4 flex flex-col sm:flex-row sm:items-center gap-3"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                style="background: var(--accent-100); color: var(--accent-600);">
                {{ doc.kind_label }}
              </span>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                :style="doc.source === 'admin_upload'
                  ? 'background: #e0e7ff; color: #3730a3;'
                  : 'background: var(--border-sub); color: var(--text-2);'">
                {{ doc.source_label }}
              </span>
            </div>
            <p class="text-sm mt-1.5 truncate" style="color: var(--text-1);">
              {{ doc.label ?? doc.original_filename ?? doc.kind_label }}
            </p>
            <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
              {{ formatDate(doc.captured_at) }}
            </p>
          </div>

          <div class="flex items-center gap-2 flex-shrink-0">
            <a :href="doc.preview_url" target="_blank" rel="noopener"
              class="btn btn-secondary text-xs py-1.5 px-3">
              Ver
            </a>
            <button @click="confirmDelete(doc)" class="btn btn-danger text-xs py-1.5 px-3">
              Eliminar
            </button>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Modal confirmar eliminación -->
  <Transition name="fade">
    <div v-if="docToDelete" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="docToDelete = null" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">
          ¿Eliminar este documento?
        </h3>
        <p class="text-sm font-medium mb-3" style="color: var(--text-2);">
          {{ docToDelete.label ?? docToDelete.original_filename ?? docToDelete.kind_label }}
        </p>
        <ul class="text-sm space-y-1.5 mb-5">
          <li style="color: var(--badge-danger-txt);">✗ Se borra el archivo de R2</li>
          <li style="color: var(--badge-danger-txt);">✗ Deja de estar disponible para el cliente</li>
          <li style="color: var(--badge-danger-txt);">✗ Esta acción no se puede revertir</li>
        </ul>
        <div class="flex justify-end gap-2">
          <button @click="docToDelete = null"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);">
            Cancelar
          </button>
          <button @click="submitDelete"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);">
            Eliminar
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import BackLink from '@/components/UI/BackLink.vue'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

interface DocumentItem {
  id: number
  kind: string
  kind_label: string
  source: string
  source_label: string
  original_filename: string | null
  label: string | null
  visible_to_client: boolean
  preview_url: string
  captured_at: string | null
}

interface Poliza {
  id: number
  numero: string | null
  company: string | null
  coverage: string | null
  patente: string | null
  label: string
  cliente: string | null
  estado: string
}

const props = defineProps<{
  poliza: Poliza
  documents: DocumentItem[]
  checklist: Array<{ kind: string; label: string; presente: boolean }>
  kinds: Array<{ value: string; label: string }>
}>()

const completos = computed(() => props.checklist.filter((i) => i.presente).length)

const uploadForm = useForm({
  kind: '',
  file: null as File | null,
  label: '',
})

const onFileSelect = (e: Event) => {
  const input = e.target as HTMLInputElement
  uploadForm.file = input.files?.[0] ?? null
}

const submitUpload = () => {
  uploadForm.post(`/policy-documents/${props.poliza.id}/documents`, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => uploadForm.reset(),
  })
}

const docToDelete = ref<DocumentItem | null>(null)

const confirmDelete = (doc: DocumentItem) => {
  docToDelete.value = doc
}

const submitDelete = () => {
  const doc = docToDelete.value
  if (!doc) { return }
  docToDelete.value = null
  router.delete(`/policy-documents/documents/${doc.id}`, { preserveScroll: true })
}

const formatDate = (iso: string | null): string => {
  if (!iso) { return '—' }
  return new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
