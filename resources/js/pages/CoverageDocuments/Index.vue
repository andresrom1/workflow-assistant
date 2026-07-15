<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Documentacion de Coberturas
        </h1>
        <Button size="sm" @click="showUpload = !showUpload">
          <Plus class="size-4" />
          Subir documento
        </Button>
      </div>

      <!-- Upload form -->
      <Transition name="slide-down">
        <Card
          v-if="showUpload"
          class="p-5 mb-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
        >
          <h2 class="text-sm font-semibold mb-4" style="color: var(--text-1);">Subir nuevo documento</h2>
          <form @submit.prevent="submitUpload" enctype="multipart/form-data">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <Label class="mb-1.5 block">Compania *</Label>
                <Input
                  v-model="uploadForm.company_name"
                  type="text"
                  placeholder="Ej: San Cristobal Seguros"
                  :aria-invalid="!!uploadForm.errors.company_name || undefined"
                />
                <p v-if="uploadForm.errors.company_name" class="text-xs mt-1" style="color: var(--badge-danger-txt);">
                  {{ uploadForm.errors.company_name }}
                </p>
              </div>
              <div>
                <Label class="mb-1.5 block">Tipo de documento *</Label>
                <Select v-model="uploadForm.document_type">
                  <SelectTrigger class="w-full" :aria-invalid="!!uploadForm.errors.document_type || undefined">
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
                <p v-if="uploadForm.errors.document_type" class="text-xs mt-1" style="color: var(--badge-danger-txt);">
                  {{ uploadForm.errors.document_type }}
                </p>
              </div>
              <div>
                <Label class="mb-1.5 block">Archivo PDF *</Label>
                <Input
                  type="file"
                  accept=".pdf"
                  @change="onFileSelect"
                  :aria-invalid="!!uploadForm.errors.file || undefined"
                />
                <p v-if="uploadForm.errors.file" class="text-xs mt-1" style="color: var(--badge-danger-txt);">
                  {{ uploadForm.errors.file }}
                </p>
              </div>
              <div>
                <Label class="mb-1.5 block">Version</Label>
                <Input v-model="uploadForm.version" type="text" placeholder="Ej: 2026-06" />
              </div>
              <div>
                <Label class="mb-1.5 block">Modo de extraccion *</Label>
                <Select v-model="uploadForm.extraction_mode">
                  <SelectTrigger class="w-full" :aria-invalid="!!uploadForm.errors.extraction_mode || undefined">
                    <SelectValue placeholder="Seleccionar..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem value="ai">Procesar con IA</SelectItem>
                      <SelectItem value="manual">Ingresar manualmente</SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
                <p v-if="uploadForm.errors.extraction_mode" class="text-xs mt-1" style="color: var(--badge-danger-txt);">
                  {{ uploadForm.errors.extraction_mode }}
                </p>
              </div>
              <div v-if="uploadForm.extraction_mode === 'ai'">
                <Label class="mb-1.5 block">Proveedor IA</Label>
                <Select v-model="uploadForm.extraction_provider">
                  <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
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
              <Button type="button" variant="outline" size="sm" @click="showUpload = false">Cancelar</Button>
              <Button type="submit" size="sm" :disabled="uploadForm.processing">
                {{ uploadForm.processing ? 'Subiendo...' : 'Subir documento' }}
              </Button>
            </div>
          </form>
        </Card>
      </Transition>

      <!-- Search -->
      <Card
        class="p-4 mb-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
      >
        <form @submit.prevent="buscar" class="flex flex-col sm:flex-row gap-2.5">
          <div class="flex-1 relative">
            <Search class="absolute left-3 top-1/2 -translate-y-1/2 size-3.5" style="color: var(--text-3);" />
            <Input
              v-model="searchInput"
              type="text"
              placeholder="Buscar por compania o archivo..."
              class="pl-9"
            />
          </div>
          <div class="flex gap-2">
            <Select v-model="perPageInput" @update:model-value="buscar">
              <SelectTrigger class="h-10"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem value="15">15 / pag</SelectItem>
                  <SelectItem value="25">25 / pag</SelectItem>
                  <SelectItem value="50">50 / pag</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <Button type="submit" size="sm">Buscar</Button>
          </div>
        </form>
      </Card>

      <!-- Empty state -->
      <div
        v-if="!documents.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);"
      >
        No se encontraron documentos de cobertura.
      </div>

      <template v-else>
        <DataTable
          :columns="columns"
          :data="documents.data"
          :sort="filters.sort"
          :direction="filters.direction"
          @sort="handleSort"
          @row-click="(doc) => irA(`/coverage-documents/${doc.id}`)"
        >
          <template #cell-company_name="{ item }">
            <div>
              <p class="text-sm font-semibold leading-tight" style="color: var(--text-1);">{{ item.company_name }}</p>
              <p v-if="item.version" class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">v{{ item.version }}</p>
            </div>
          </template>

          <template #cell-document_type="{ item }">
            <Badge class="text-[11px] px-2 py-0.5 rounded-full" :style="typeBadgeStyle(item.document_type)">
              {{ typeLabel(item.document_type) }}
            </Badge>
          </template>

          <template #cell-original_filename="{ item }">
            <p class="text-xs font-mono truncate max-w-[200px]" style="color: var(--text-2);">
              {{ item.original_filename }}
            </p>
          </template>

          <template #cell-extraction_status="{ item }">
            <Badge class="text-[11px] px-2 py-0.5 rounded-full" :style="statusBadgeStyle(item)">
              {{ statusLabel(item) }}
            </Badge>
          </template>

          <template #cell-chunks_count="{ item }">
            <Badge
              class="h-[22px] min-w-[22px] px-1.5 justify-center rounded-full text-[11px] font-bold font-mono tabular-nums"
              :style="item.chunks_count > 0
                ? 'background: var(--accent-100); color: var(--accent-600);'
                : 'background: var(--border-sub); color: var(--text-3);'"
            >
              {{ item.chunks_count }}
            </Badge>
          </template>

          <template #cell-actions="{ item }">
            <div @click.stop>
              <Button variant="ghost" size="icon" as-child>
                <Link :href="`/coverage-documents/${item.id}`" title="Ver documento">
                  <ChevronRight class="size-4" />
                </Link>
              </Button>
            </div>
          </template>

          <template #mobile-row="{ item }">
            <Link :href="`/coverage-documents/${item.id}`" class="block">
              <Card
                size="sm"
                class="overflow-hidden"
                style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
              >
                <CardContent class="p-4 flex items-center gap-3">
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ item.company_name }}</p>
                    <p class="text-xs truncate mt-0.5 font-mono" style="color: var(--text-3);">
                      {{ item.original_filename }}
                    </p>
                    <div class="flex gap-2 mt-1.5 flex-wrap">
                      <Badge class="text-[10px] px-2 py-0.5 rounded-full" :style="typeBadgeStyle(item.document_type)">
                        {{ typeLabel(item.document_type) }}
                      </Badge>
                      <Badge class="text-[10px] px-2 py-0.5 rounded-full" :style="statusBadgeStyle(item)">
                        {{ statusLabel(item) }}
                      </Badge>
                      <span v-if="item.chunks_count > 0" class="inline-flex items-center gap-1 text-[10px]" style="color: var(--text-3);">
                        {{ item.chunks_count }} chunks
                      </span>
                    </div>
                  </div>
                  <ChevronRight class="size-4" style="color: var(--text-3);" />
                </CardContent>
              </Card>
            </Link>
          </template>
        </DataTable>
      </template>

      <AppPagination v-if="documents.last_page > 1" :data="documents" class="mt-4" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { Button } from '@/components/UI/button'
import { Input } from '@/components/UI/input'
import { Card, CardContent } from '@/components/UI/card'
import { Badge } from '@/components/UI/badge'
import { Label } from '@/components/UI/label'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'
import { Plus, Search, ChevronRight } from '@lucide/vue'
import DataTable, { type SortDirection } from '@/components/App/DataTable.vue'
import AppPagination from '@/components/App/Pagination.vue'

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
  filters: { search: string; per_page: number; sort?: string; direction?: SortDirection }
}>()

const columns = [
  { key: 'company_name', label: 'Compania', sortable: true },
  { key: 'document_type', label: 'Tipo', sortable: true },
  { key: 'original_filename', label: 'Archivo', sortable: true },
  { key: 'extraction_status', label: 'Estado', sortable: true, align: 'center' as const, class: 'w-24' },
  { key: 'chunks_count', label: 'Chunks', sortable: true, align: 'center' as const, class: 'w-20' },
  { key: 'actions', label: '', sortable: false, align: 'center' as const, class: 'w-10' },
]

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
  router.get('/coverage-documents', {
    search: searchInput.value,
    per_page: perPageInput.value,
    sort: props.filters.sort,
    direction: props.filters.direction,
  }, { preserveState: true, replace: true })
}

const handleSort = (column: string, direction: SortDirection) => {
  router.get('/coverage-documents', {
    search: searchInput.value,
    per_page: perPageInput.value,
    sort: column,
    direction,
  }, { preserveState: true, replace: true })
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
