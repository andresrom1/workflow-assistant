<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto">

      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Documentacion de Coberturas
        </h1>
        <button @click="showUpload = !showUpload" class="btn btn-primary text-sm">
          + Subir documento
        </button>
      </div>

      <!-- Upload form -->
      <Transition name="slide-down">
        <div v-if="showUpload"
          class="rounded-[14px] p-5 mb-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-sm font-semibold mb-4" style="color: var(--text-1);">Subir nuevo documento</h2>
          <form @submit.prevent="submitUpload" enctype="multipart/form-data">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="field-label">Compania *</label>
                <input v-model="uploadForm.company_name" type="text" class="field"
                  :class="{ 'field-error': uploadForm.errors.company_name }"
                  placeholder="Ej: San Cristobal Seguros" />
                <p v-if="uploadForm.errors.company_name" class="field-error-text">{{ uploadForm.errors.company_name }}</p>
              </div>
              <div>
                <label class="field-label">Tipo de documento *</label>
                <Select v-model="uploadForm.document_type">
                  <SelectTrigger class="w-full h-[38px]" :aria-invalid="!!uploadForm.errors.document_type || undefined">
                    <SelectValue placeholder="Seleccionar..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem value="insert">Insert</SelectItem>
                      <SelectItem value="asistencia">Asistencia</SelectItem>
                      <SelectItem value="manual">Manual del asegurado</SelectItem>
                      <SelectItem value="general">General</SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
                <p v-if="uploadForm.errors.document_type" class="field-error-text">{{ uploadForm.errors.document_type }}</p>
              </div>
              <div>
                <label class="field-label">Archivo PDF *</label>
                <input type="file" accept=".pdf" @change="onFileSelect" class="field text-sm"
                  :class="{ 'field-error': uploadForm.errors.file }" />
                <p v-if="uploadForm.errors.file" class="field-error-text">{{ uploadForm.errors.file }}</p>
              </div>
              <div>
                <label class="field-label">Version</label>
                <input v-model="uploadForm.version" type="text" class="field"
                  placeholder="Ej: 2026-06" />
              </div>
              <div>
                <label class="field-label">Modo de extraccion *</label>
                <Select v-model="uploadForm.extraction_mode">
                  <SelectTrigger class="w-full h-[38px]" :aria-invalid="!!uploadForm.errors.extraction_mode || undefined">
                    <SelectValue placeholder="Seleccionar..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem value="ai">Procesar con IA</SelectItem>
                      <SelectItem value="manual">Ingresar manualmente</SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
                <p v-if="uploadForm.errors.extraction_mode" class="field-error-text">{{ uploadForm.errors.extraction_mode }}</p>
              </div>
              <div v-if="uploadForm.extraction_mode === 'ai'">
                <label class="field-label">Proveedor IA</label>
                <Select v-model="uploadForm.extraction_provider">
                  <SelectTrigger class="w-full h-[38px]"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem value="openai">OpenAI</SelectItem>
                      <SelectItem value="anthropic">Anthropic</SelectItem>
                      <SelectItem value="gemini">Gemini</SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div class="flex justify-end gap-2 mt-4">
              <button type="button" @click="showUpload = false" class="btn btn-secondary text-sm">Cancelar</button>
              <button type="submit" class="btn btn-primary text-sm" :disabled="uploadForm.processing">
                {{ uploadForm.processing ? 'Subiendo...' : 'Subir documento' }}
              </button>
            </div>
          </form>
        </div>
      </Transition>

      <!-- Search -->
      <div class="rounded-[14px] p-4 mb-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <form @submit.prevent="buscar" class="flex flex-col sm:flex-row gap-2.5">
          <div class="flex-1 relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color: var(--text-3);"
              fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input v-model="searchInput" type="text"
              placeholder="Buscar por compania o archivo..."
              class="field pl-9" />
          </div>
          <div class="flex gap-2">
            <Select v-model="perPageInput" @update:model-value="buscar">
              <SelectTrigger class="h-[38px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem value="15">15 / pag</SelectItem>
                  <SelectItem value="25">25 / pag</SelectItem>
                  <SelectItem value="50">50 / pag</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <button type="submit" class="btn btn-primary">Buscar</button>
          </div>
        </form>
      </div>

      <!-- Empty state -->
      <div v-if="!documents.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No se encontraron documentos de cobertura.
      </div>

      <template v-else>
        <!-- DESKTOP table -->
        <div class="hidden md:block rounded-[14px] overflow-hidden"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <table class="min-w-full text-sm">
            <thead>
              <tr style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Compania</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Tipo</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Archivo</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider w-24" style="color: var(--text-3);">Estado</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider w-20" style="color: var(--text-3);">Chunks</th>
                <th class="px-5 py-3 w-10"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="doc in documents.data" :key="doc.id"
                class="cursor-pointer transition-colors"
                style="border-bottom: 1px solid var(--border-sub);"
                @mouseenter="($event.currentTarget as HTMLElement).style.background = 'var(--border-sub)'"
                @mouseleave="($event.currentTarget as HTMLElement).style.background = 'transparent'"
                @click="irA(`/coverage-documents/${doc.id}`)"
              >
                <td class="px-5 py-3">
                  <p class="text-sm font-semibold leading-tight" style="color: var(--text-1);">{{ doc.company_name }}</p>
                  <p v-if="doc.version" class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">v{{ doc.version }}</p>
                </td>
                <td class="px-5 py-3">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                    :style="typeBadgeStyle(doc.document_type)">
                    {{ typeLabel(doc.document_type) }}
                  </span>
                </td>
                <td class="px-5 py-3">
                  <p class="text-xs font-mono truncate max-w-[200px]" style="color: var(--text-2);">{{ doc.original_filename }}</p>
                </td>
                <td class="px-5 py-3 text-center">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                    :style="statusBadgeStyle(doc)">
                    {{ statusLabel(doc) }}
                  </span>
                </td>
                <td class="px-5 py-3 text-center">
                  <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
                    style="background: var(--accent-100); color: var(--accent-600);">
                    {{ doc.chunks_count }}
                  </span>
                </td>
                <td class="px-5 py-3" @click.stop>
                  <RowActionMin :href="`/coverage-documents/${doc.id}`" label="Ver documento" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MOBILE cards -->
        <div class="md:hidden space-y-2">
          <Link v-for="doc in documents.data" :key="doc.id"
            :href="`/coverage-documents/${doc.id}`"
            class="flex items-center gap-3 rounded-[14px] px-4 py-3 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ doc.company_name }}</p>
              <p class="text-xs truncate mt-0.5 font-mono" style="color: var(--text-3);">
                {{ doc.original_filename }}
              </p>
              <div class="flex gap-2 mt-1.5 flex-wrap">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                  :style="typeBadgeStyle(doc.document_type)">
                  {{ typeLabel(doc.document_type) }}
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                  :style="statusBadgeStyle(doc)">
                  {{ statusLabel(doc) }}
                </span>
                <span v-if="doc.chunks_count > 0" class="inline-flex items-center gap-1 text-[10px]" style="color: var(--text-3);">
                  {{ doc.chunks_count }} chunks
                </span>
              </div>
            </div>
            <ChevronRight />
          </Link>
        </div>
      </template>

      <Pagination v-if="documents.last_page > 1" :data="documents" class="mt-4" />

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import RowActionMin from '@/components/UI/RowActionMin.vue'
import ChevronRight from '@/components/UI/ChevronRight.vue'
import Pagination from '@/components/UI/Pagination.vue'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

interface DocumentItem {
  id: number
  company_name: string
  company_slug: string
  document_type: string
  original_filename: string
  extraction_status: string
  extraction_mode: string
  is_active: boolean
  version: string | null
  chunks_count: number
  updated_at: string | null
}

const props = defineProps<{
  documents: {
    data: DocumentItem[]
    total: number
    from: number
    to: number
    last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { search: string; per_page: number }
}>()

const searchInput = ref(props.filters.search ?? '')
const perPageInput = ref(String(props.filters.per_page ?? 25))
const showUpload = ref(false)

const uploadForm = useForm({
  company_name: '',
  document_type: '',
  file: null as File | null,
  version: '',
  extraction_mode: 'ai',
  extraction_provider: 'openai',
})

const onFileSelect = (e: Event) => {
  const input = e.target as HTMLInputElement
  uploadForm.file = input.files?.[0] ?? null
}

const submitUpload = () => {
  uploadForm.post('/coverage-documents', {
    forceFormData: true,
    onSuccess: () => {
      showUpload.value = false
      uploadForm.reset()
    },
  })
}

const buscar = () => {
  router.get('/coverage-documents', { search: searchInput.value, per_page: perPageInput.value },
    { preserveState: true, replace: true })
}

const irA = (href: string) => router.visit(href)

const typeLabel = (type: string): string => {
  const map: Record<string, string> = {
    insert: 'Insert',
    asistencia: 'Asistencia',
    manual: 'Manual',
    general: 'General',
  }
  return map[type] ?? type
}

const typeBadgeStyle = (type: string): string => {
  const styles: Record<string, string> = {
    insert: 'background: var(--accent-100); color: var(--accent-600);',
    asistencia: 'background: #dbeafe; color: #1d4ed8;',
    manual: 'background: #fef3c7; color: #92400e;',
    general: 'background: var(--border-sub); color: var(--text-2);',
  }
  return styles[type] ?? styles.general
}

const statusLabel = (doc: DocumentItem): string => {
  if (!doc.is_active) { return 'Deprecado' }
  const map: Record<string, string> = {
    pending: 'Pendiente',
    completed: 'Completado',
    failed: 'Error',
    manual: 'Manual',
  }
  return map[doc.extraction_status] ?? doc.extraction_status
}

const statusBadgeStyle = (doc: DocumentItem): string => {
  if (!doc.is_active) { return 'background: var(--border-sub); color: var(--text-3);' }
  const styles: Record<string, string> = {
    pending: 'background: #fef3c7; color: #92400e;',
    completed: 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);',
    failed: 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);',
    manual: 'background: #e0e7ff; color: #3730a3;',
  }
  return styles[doc.extraction_status] ?? ''
}
</script>

<style scoped>
.slide-down-enter-active, .slide-down-leave-active { transition: all 0.2s ease; }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-8px); }
</style>
