<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-4xl mx-auto">

      <!-- Back link -->
      <BackLink href="/coverage-documents" label="Documentacion" class="mb-4" />

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            {{ document.company_name }}
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">
            {{ document.original_filename }}
            <span v-if="document.version" class="ml-2 font-mono">v{{ document.version }}</span>
          </p>
        </div>
        <div class="flex gap-2">
          <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold"
            :style="statusBadgeStyle">
            {{ statusLabel }}
          </span>
          <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold"
            :style="typeBadgeStyle">
            {{ typeLabel }}
          </span>
        </div>
      </div>

      <!-- Info card -->
      <div class="rounded-[14px] p-5 mb-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-wider mb-1" style="color: var(--text-3);">Modo</p>
            <p style="color: var(--text-1);">{{ document.extraction_mode === 'ai' ? 'IA' : 'Manual' }}</p>
          </div>
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-wider mb-1" style="color: var(--text-3);">Proveedor</p>
            <p style="color: var(--text-1);">{{ document.extraction_provider }}</p>
          </div>
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-wider mb-1" style="color: var(--text-3);">Chunks</p>
            <p style="color: var(--text-1);">{{ document.chunks_count }}</p>
          </div>
          <div>
            <p class="text-[11px] font-semibold uppercase tracking-wider mb-1" style="color: var(--text-3);">Creado</p>
            <p class="font-mono text-xs" style="color: var(--text-1);">{{ formatDate(document.created_at) }}</p>
          </div>
        </div>
      </div>

      <!-- Extracted content editor -->
      <div class="rounded-[14px] p-5 mb-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <div class="flex items-center justify-between mb-3">
          <h2 class="text-sm font-semibold" style="color: var(--text-1);">Contenido extraido</h2>
          <span v-if="contentForm.isDirty" class="text-[11px] px-2 py-0.5 rounded-full font-semibold"
            style="background: #fef3c7; color: #92400e;">
            Sin guardar
          </span>
        </div>

        <p v-if="!document.extracted_content && document.extraction_status === 'pending'"
          class="text-sm py-8 text-center" style="color: var(--text-3);">
          Extraccion en progreso. Recarga la pagina para ver el resultado.
        </p>

        <p v-else-if="!document.extracted_content && document.extraction_status === 'failed'"
          class="text-sm py-8 text-center" style="color: var(--badge-danger-txt);">
          La extraccion fallo. Podes ingresar el contenido manualmente.
        </p>

        <form @submit.prevent="saveContent">
          <textarea
            v-model="contentForm.extracted_content"
            rows="20"
            class="field font-mono text-xs leading-relaxed w-full"
            :class="{ 'field-error': contentForm.errors.extracted_content }"
            placeholder="Pega o escribe el contenido extraido del documento..."
            :disabled="!document.is_active"
          />
          <p v-if="contentForm.errors.extracted_content" class="field-error-text">
            {{ contentForm.errors.extracted_content }}
          </p>
          <div class="flex justify-end gap-2 mt-3">
            <button v-if="document.is_active" type="submit" class="btn btn-primary text-sm"
              :disabled="contentForm.processing || !contentForm.isDirty">
              {{ contentForm.processing ? 'Guardando...' : 'Guardar contenido' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Actions -->
      <div v-if="document.is_active"
        class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-1);">Acciones</h2>
        <div class="flex gap-3">
          <button @click="confirmDeprecate" class="btn btn-danger text-sm"
            :disabled="deprecateForm.processing">
            Deprecar documento
          </button>
        </div>
        <p class="text-xs mt-2" style="color: var(--text-3);">
          Deprecar desactiva el documento y lo excluye de las busquedas RAG.
        </p>
      </div>

      <!-- Deprecated notice -->
      <div v-else
        class="rounded-[14px] p-5"
        style="background: var(--badge-danger-bg); border: 1px solid var(--badge-danger-txt);">
        <p class="text-sm font-semibold" style="color: var(--badge-danger-txt);">
          Este documento fue deprecado el {{ formatDate(document.deprecated_at) }}.
        </p>
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import BackLink from '@/components/UI/BackLink.vue'

interface Document {
  id: number
  company_name: string
  company_slug: string
  document_type: string
  original_filename: string
  storage_path: string
  mime_type: string
  extracted_content: string | null
  extraction_status: string
  extraction_mode: string
  extraction_provider: string
  is_active: boolean
  version: string | null
  chunks_count: number
  deprecated_at: string | null
  created_at: string | null
  updated_at: string | null
}

const props = defineProps<{ document: Document }>()

const contentForm = useForm({
  extracted_content: props.document.extracted_content ?? '',
})

const deprecateForm = useForm({})

const saveContent = () => {
  contentForm.put(`/coverage-documents/${props.document.id}`, {
    preserveScroll: true,
  })
}

const confirmDeprecate = () => {
  if (confirm('Estas seguro de que queres deprecar este documento? No podra usarse en busquedas RAG.')) {
    deprecateForm.delete(`/coverage-documents/${props.document.id}`)
  }
}

const formatDate = (iso: string | null): string => {
  if (!iso) { return '-' }
  return new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
}

const typeLabels: Record<string, string> = {
  insert: 'Insert',
  asistencia: 'Asistencia',
  manual: 'Manual',
  general: 'General',
}

const typeLabel = typeLabels[props.document.document_type] ?? props.document.document_type

const typeBadgeStyles: Record<string, string> = {
  insert: 'background: var(--accent-100); color: var(--accent-600);',
  asistencia: 'background: #dbeafe; color: #1d4ed8;',
  manual: 'background: #fef3c7; color: #92400e;',
  general: 'background: var(--border-sub); color: var(--text-2);',
}
const typeBadgeStyle = typeBadgeStyles[props.document.document_type] ?? typeBadgeStyles.general

const statusLabels: Record<string, string> = {
  pending: 'Pendiente',
  completed: 'Completado',
  failed: 'Error',
  manual: 'Manual',
}
const statusLabel = !props.document.is_active
  ? 'Deprecado'
  : (statusLabels[props.document.extraction_status] ?? props.document.extraction_status)

const statusStyles: Record<string, string> = {
  pending: 'background: #fef3c7; color: #92400e;',
  completed: 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);',
  failed: 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);',
  manual: 'background: #e0e7ff; color: #3730a3;',
}
const statusBadgeStyle = !props.document.is_active
  ? 'background: var(--border-sub); color: var(--text-3);'
  : (statusStyles[props.document.extraction_status] ?? '')
</script>
