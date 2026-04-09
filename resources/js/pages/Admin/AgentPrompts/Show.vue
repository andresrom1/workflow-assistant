<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-7xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div class="flex items-center gap-3">
          <BackLink href="/admin/agent-prompts" label="Agentes" />
          <div>
            <div class="flex items-center gap-2.5">
              <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
                {{ agentLabel }}
              </h1>
              <span v-if="activeVersion"
                class="text-[11px] px-2.5 py-0.5 rounded-full font-semibold"
                style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);">
                v{{ activeVersion.version }} activa
              </span>
            </div>
            <p class="text-sm mt-0.5" style="color: var(--text-3);">
              Editá el prompt y guardalo como nueva versión. Los cambios tienen efecto inmediato.
            </p>
          </div>
        </div>
      </div>

<!-- Editor -->
      <div class="rounded-[14px] overflow-hidden"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

        <!-- Barra superior del editor -->
        <div class="flex items-center justify-between px-4 py-3"
          style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
          <div class="flex items-center gap-2">
            <span class="w-2.5 h-2.5 rounded-full" style="background: #ef4444;"></span>
            <span class="w-2.5 h-2.5 rounded-full" style="background: #f59e0b;"></span>
            <span class="w-2.5 h-2.5 rounded-full" style="background: #22c55e;"></span>
            <span class="ml-3 text-[11px] font-mono" style="color: var(--text-3);">
              prompt.md
            </span>
          </div>
          <div class="flex items-center gap-3">
            <span class="text-[11px]" style="color: var(--text-3);">
              {{ charCount }} caracteres
            </span>
            <div class="flex items-center gap-1 rounded-[8px] p-0.5"
              style="background: var(--bg-app);">
              <button
                @click="viewMode = 'split'"
                :class="['px-2.5 py-1 rounded-[6px] text-[11px] font-medium transition-all', viewMode === 'split' ? 'active-tab' : '']"
                :style="viewMode === 'split' ? 'background:#5b5ef6; color:#fff;' : 'color:var(--text-3);'"
              >Split</button>
              <button
                @click="viewMode = 'editor'"
                :class="['px-2.5 py-1 rounded-[6px] text-[11px] font-medium transition-all', viewMode === 'editor' ? 'active-tab' : '']"
                :style="viewMode === 'editor' ? 'background:#5b5ef6; color:#fff;' : 'color:var(--text-3);'"
              >Editor</button>
              <button
                @click="viewMode = 'preview'"
                :class="['px-2.5 py-1 rounded-[6px] text-[11px] font-medium transition-all', viewMode === 'preview' ? 'active-tab' : '']"
                :style="viewMode === 'preview' ? 'background:#5b5ef6; color:#fff;' : 'color:var(--text-3);'"
              >Preview</button>
            </div>
          </div>
        </div>

        <!-- Split pane -->
        <div class="flex" :style="`height: 520px;`">

          <!-- Columna editor -->
          <div v-show="viewMode !== 'preview'"
            :class="viewMode === 'split' ? 'w-1/2' : 'w-full'"
            class="flex flex-col h-full"
            :style="viewMode === 'split' ? 'border-right: 1px solid var(--border);' : ''">
            <textarea
              v-model="draft"
              spellcheck="false"
              class="flex-1 w-full resize-none font-mono text-[13px] leading-relaxed p-5 outline-none"
              style="background: #0d1117; color: #e6edf3; tab-size: 2;"
              placeholder="Escribí aquí el prompt del agente..."
            />
          </div>

          <!-- Columna preview -->
          <div v-show="viewMode !== 'editor'"
            :class="viewMode === 'split' ? 'w-1/2' : 'w-full'"
            class="h-full overflow-y-auto p-5"
            style="background: var(--bg-card);">
            <div
              class="markdown-preview prose-sm max-w-none"
              v-html="renderedMarkdown"
            />
          </div>

        </div>
      </div>

      <!-- Formulario guardar -->
      <div class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-sm font-semibold mb-4" style="color: var(--text-1);">
          Guardar como nueva versión
        </h3>
        <div class="flex flex-col sm:flex-row gap-3">
          <input
            v-model="notes"
            type="text"
            class="field flex-1"
            placeholder="Descripción del cambio (opcional)..."
            maxlength="255"
          />
          <button
            @click="saveNewVersion"
            :disabled="saving || !draft.trim()"
            class="btn btn-primary flex-shrink-0 flex items-center gap-2"
          >
            <svg v-if="saving" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            {{ saving ? 'Guardando...' : 'Guardar nueva versión' }}
          </button>
        </div>
      </div>

      <!-- Historial de versiones -->
      <div class="rounded-[14px] overflow-hidden"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

        <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
          <h3 class="text-sm font-semibold" style="color: var(--text-1);">
            Historial de versiones
          </h3>
          <p class="text-[11px] mt-0.5" style="color: var(--text-3);">
            {{ versions.length }} versión{{ versions.length !== 1 ? 'es' : '' }} en total
          </p>
        </div>

        <div v-if="!versions.length" class="p-8 text-center text-sm" style="color: var(--text-3);">
          No hay versiones guardadas aún.
        </div>

        <div v-else>
          <div
            v-for="v in versions" :key="v.id"
            style="border-bottom: 1px solid var(--border-sub);"
          >
            <!-- Fila de versión -->
            <div class="px-5 py-3.5 flex items-center gap-3">
              <!-- Badge versión -->
              <span class="text-[11px] font-mono font-bold w-8 flex-shrink-0"
                style="color: var(--text-3);">
                v{{ v.version }}
              </span>

              <!-- Estado -->
              <span v-if="v.is_active"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold flex-shrink-0"
                style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);">
                <span class="w-1.5 h-1.5 rounded-full" style="background: var(--dot-ok);"></span>
                activa
              </span>
              <span v-else
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold flex-shrink-0"
                style="background: var(--border-sub); color: var(--text-3);">
                inactiva
              </span>

              <!-- Notas -->
              <p class="text-[12px] flex-1 truncate" style="color: var(--text-2);">
                {{ v.notes || '(sin descripción)' }}
              </p>

              <!-- Fecha -->
              <p class="text-[11px] flex-shrink-0" style="color: var(--text-3);">
                {{ formatDate(v.created_at) }}
              </p>

              <!-- Acciones -->
              <div class="flex items-center gap-2 flex-shrink-0">
                <button
                  @click="toggleExpanded(v.id)"
                  class="text-[11px] px-2.5 py-1 rounded-[7px] transition-all"
                  style="color: var(--text-3); border: 1px solid var(--border);"
                  @mouseenter="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-1)'"
                  @mouseleave="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-3)'"
                >
                  {{ expanded.has(v.id) ? 'Ocultar' : 'Ver prompt' }}
                </button>

                <button
                  v-if="!v.is_active"
                  @click="restoreVersion(v)"
                  class="text-[11px] px-2.5 py-1 rounded-[7px] font-medium transition-all"
                  style="background: var(--badge-pending-bg); color: var(--badge-pending-txt); border: 1px solid transparent;"
                >
                  Restaurar
                </button>

                <button
                  v-if="!v.is_active"
                  @click="loadIntoEditor(v)"
                  class="text-[11px] px-2.5 py-1 rounded-[7px] transition-all"
                  style="color: #5b5ef6; border: 1px solid #5b5ef6;"
                >
                  Editar
                </button>
              </div>
            </div>

            <!-- Acordeón: contenido -->
            <Transition name="accordion">
              <div v-if="expanded.has(v.id)"
                class="px-5 pb-4">
                <div class="rounded-[10px] p-4 font-mono text-[12px] leading-relaxed overflow-x-auto"
                  style="background: #0d1117; color: #e6edf3; max-height: 300px; overflow-y: auto;">
                  <pre class="whitespace-pre-wrap">{{ v.content }}</pre>
                </div>
              </div>
            </Transition>
          </div>
        </div>

      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, reactive } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { marked } from 'marked'
import BackLink from '@/components/UI/BackLink.vue'

// ─── Props ────────────────────────────────────────────────────────────────────

interface VersionEntry {
  id: number
  version: number
  is_active: boolean
  notes: string | null
  content: string
  created_at: string
}

const props = defineProps<{
  agentKey: string
  agentLabel: string
  activeVersion: VersionEntry | null
  versions: VersionEntry[]
}>()

const $page = usePage()

// ─── Editor state ────────────────────────────────────────────────────────────

const draft = ref(props.activeVersion?.content ?? '')
const notes = ref('')
const saving = ref(false)
const viewMode = ref<'split' | 'editor' | 'preview'>('split')
const expanded = reactive(new Set<number>())

const charCount = computed(() => draft.value.length)

const renderedMarkdown = computed(() => {
  if (!draft.value.trim()) {
    return '<p style="color:var(--text-3); font-style:italic;">Empezá a escribir para ver el preview...</p>'
  }
  return marked.parse(draft.value) as string
})

// ─── Acciones ────────────────────────────────────────────────────────────────

const saveNewVersion = () => {
  if (saving.value || !draft.value.trim()) return
  saving.value = true

  router.post(`/admin/agent-prompts/${props.agentKey}`, {
    content: draft.value,
    notes: notes.value || null,
  }, {
    preserveScroll: true,
    onSuccess: () => { notes.value = '' },
    onFinish: () => { saving.value = false },
  })
}

const restoreVersion = (v: VersionEntry) => {
  if (!confirm(`¿Restaurar versión ${v.version}? Será la versión activa inmediatamente.`)) return

  router.post(`/admin/agent-prompts/${v.id}/activate`, {}, {
    preserveScroll: true,
  })
}

const loadIntoEditor = (v: VersionEntry) => {
  draft.value = v.content
  notes.value = `Basado en v${v.version}`
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

const toggleExpanded = (id: number) => {
  if (expanded.has(id)) {
    expanded.delete(id)
  } else {
    expanded.add(id)
  }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
</script>

<style scoped>
.accordion-enter-active, .accordion-leave-active {
  transition: max-height 0.2s ease, opacity 0.2s ease;
  overflow: hidden;
}
.accordion-enter-from, .accordion-leave-to {
  max-height: 0;
  opacity: 0;
}
.accordion-enter-to, .accordion-leave-from {
  max-height: 400px;
  opacity: 1;
}

/* ── Estilos del markdown preview ──────────────────────────────────────────── */
:deep(.markdown-preview) {
  color: var(--text-1);
  font-size: 13px;
  line-height: 1.7;
}
:deep(.markdown-preview h1),
:deep(.markdown-preview h2),
:deep(.markdown-preview h3) {
  font-weight: 700;
  margin-top: 1.25em;
  margin-bottom: 0.5em;
  color: var(--text-1);
  letter-spacing: -0.01em;
}
:deep(.markdown-preview h1) { font-size: 1.25em; }
:deep(.markdown-preview h2) { font-size: 1.1em; }
:deep(.markdown-preview h3) { font-size: 1em; }
:deep(.markdown-preview p) { margin-bottom: 0.75em; }
:deep(.markdown-preview ul),
:deep(.markdown-preview ol) {
  padding-left: 1.25em;
  margin-bottom: 0.75em;
}
:deep(.markdown-preview li) { margin-bottom: 0.25em; }
:deep(.markdown-preview strong) { font-weight: 700; color: var(--text-1); }
:deep(.markdown-preview em) { font-style: italic; color: var(--text-2); }
:deep(.markdown-preview code) {
  font-family: monospace;
  font-size: 0.9em;
  padding: 0.1em 0.4em;
  border-radius: 4px;
  background: var(--bg-raised);
  color: #7dd3fc;
}
:deep(.markdown-preview pre) {
  background: #0d1117;
  color: #e6edf3;
  border-radius: 8px;
  padding: 14px 16px;
  overflow-x: auto;
  margin-bottom: 1em;
  font-size: 0.85em;
  line-height: 1.6;
}
:deep(.markdown-preview pre code) {
  background: transparent;
  color: inherit;
  padding: 0;
}
:deep(.markdown-preview blockquote) {
  border-left: 3px solid #5b5ef6;
  padding-left: 12px;
  color: var(--text-3);
  font-style: italic;
  margin-bottom: 0.75em;
}
:deep(.markdown-preview hr) {
  border: none;
  border-top: 1px solid var(--border);
  margin: 1.25em 0;
}
:deep(.markdown-preview a) { color: #5b5ef6; text-decoration: underline; }
:deep(.markdown-preview table) {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 1em;
  font-size: 0.9em;
}
:deep(.markdown-preview th),
:deep(.markdown-preview td) {
  padding: 6px 12px;
  text-align: left;
  border: 1px solid var(--border);
}
:deep(.markdown-preview th) {
  background: var(--bg-raised);
  font-weight: 600;
  color: var(--text-2);
  font-size: 0.85em;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
</style>
