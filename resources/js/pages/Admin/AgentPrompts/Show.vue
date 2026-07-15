<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-7xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div class="flex items-center gap-3">
          <AppBackLink href="/admin/agent-prompts" label="Agentes" />
          <div>
            <div class="flex items-center gap-2.5 flex-wrap">
              <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
                {{ agentLabel }}
              </h1>
              <span v-if="type === 'shared'"
                class="text-[10px] px-2 py-0.5 rounded-full font-semibold"
                style="background: var(--bg-raised); color: var(--text-2); border: 1px dashed var(--border);">
                bloque compartido
              </span>
              <span v-if="activeVersion"
                class="text-[11px] px-2.5 py-0.5 rounded-full font-semibold"
                style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);">
                v{{ activeVersion.version }} activa
              </span>
            </div>
            <p class="text-sm mt-0.5" style="color: var(--text-3);">
              Editá el prompt y guardalo como nueva versión. Los cambios tienen efecto inmediato.
            </p>
            <div v-if="type === 'agent' && inheritedBlocks && inheritedBlocks.length"
              class="mt-2 flex items-center gap-2 flex-wrap">
              <span class="text-[10px] uppercase tracking-wider" style="color: var(--text-3);">Hereda</span>
              <span v-for="block in inheritedBlocks" :key="block"
                class="text-[10px] px-2 py-0.5 rounded-full font-mono"
                style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border-sub);">
                {{ block }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Preview del prompt compuesto (solo agentes) -->
      <div v-if="type === 'agent' && composedPreview"
        class="rounded-[14px] overflow-hidden"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <button
          @click="showComposed = !showComposed"
          class="w-full flex items-center justify-between px-5 py-3 text-left transition-colors"
          style="background: var(--bg-raised); border-bottom: 1px solid var(--border);"
        >
          <div class="flex items-center gap-2">
            <span style="color: var(--text-1);" class="text-sm font-semibold">
              Prompt compuesto (lo que recibe el LLM)
            </span>
            <span class="text-[11px]" style="color: var(--text-3);">
              {{ composedPreview.length }} caracteres · {{ composedLines }} líneas
            </span>
          </div>
          <span class="text-[11px]" style="color: var(--text-3);">
            {{ showComposed ? 'Ocultar' : 'Ver' }}
          </span>
        </button>
        <div v-if="showComposed" class="p-5 font-mono text-[12px] leading-relaxed overflow-y-auto"
          style="background: #0d1117; color: #e6edf3; max-height: 400px;">
          <pre class="whitespace-pre-wrap">{{ composedPreview }}</pre>
        </div>
      </div>

<!-- Draft banner -->
      <div class="rounded-[14px] overflow-hidden"
        :style="draftBannerStyle">
        <div class="px-5 py-3 flex items-center justify-between gap-3 flex-wrap">
          <div class="flex items-center gap-2.5">
            <span class="text-sm font-semibold" :style="{ color: draftBannerTextColor }">
              {{ draftBannerTitle }}
            </span>
            <span v-if="draft"
              class="text-[10px] px-2 py-0.5 rounded-full font-semibold"
              :style="draft.is_mine
                ? 'background: var(--accent-100); color: var(--dot-accent);'
                : 'background: #fef3c7; color: #92400e;'">
              {{ draft.is_mine ? 'Tuyo' : `de ${draft.owner?.name ?? 'otro admin'}` }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="!draft" @click="createDraft"
              :disabled="draftLoading"
              class="text-[11px] px-3 py-1.5 rounded-[7px] font-semibold transition-opacity"
              :style="draftLoading
                ? 'background: var(--border-sub); color: var(--text-3); cursor: not-allowed;'
                : 'background: var(--accent-100); color: var(--dot-accent); border: 1px solid var(--border);'">
              ✎ Empezar draft
            </button>
            <template v-else-if="draft.is_mine">
              <button @click="saveDraft"
                :disabled="!draftDirty || draftLoading"
                class="text-[11px] px-3 py-1.5 rounded-[7px] font-semibold transition-opacity"
                :style="(!draftDirty || draftLoading)
                  ? 'background: var(--border-sub); color: var(--text-3); cursor: not-allowed;'
                  : 'background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);'">
                {{ draftLoading ? 'Guardando…' : draftDirty ? 'Guardar draft' : 'Guardado' }}
              </button>
              <button @click="confirmPromote = true"
                :disabled="draftLoading"
                class="text-[11px] px-3 py-1.5 rounded-[7px] font-semibold transition-opacity"
                style="background: var(--badge-ok-bg); color: var(--badge-ok-txt); border: 1px solid transparent;">
                ↑ Promover a activa
              </button>
              <button @click="confirmDiscard = true"
                class="text-[11px] px-3 py-1.5 rounded-[7px] transition-colors"
                style="color: var(--badge-danger-txt); border: 1px solid var(--border);">
                Descartar
              </button>
            </template>
            <template v-else>
              <button @click="confirmTakeControl = true"
                class="text-[11px] px-3 py-1.5 rounded-[7px] font-semibold"
                style="background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;">
                Tomar control
              </button>
            </template>
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
              :readonly="editorReadonly"
              class="flex-1 w-full resize-none font-mono text-[13px] leading-relaxed p-5 outline-none"
              :style="editorReadonly
                ? 'background: #0d1117; color: #9ca3af; tab-size: 2; cursor: not-allowed;'
                : 'background: #0d1117; color: #e6edf3; tab-size: 2;'"
              :placeholder="editorReadonly
                ? 'Tomá el control del draft para poder editarlo.'
                : 'Escribí aquí el prompt del agente...'"
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
                  v-if="v.parent_version_id"
                  @click="toggleDiff(v.id)"
                  class="text-[11px] px-2.5 py-1 rounded-[7px] transition-all"
                  style="color: var(--text-3); border: 1px solid var(--border);"
                  @mouseenter="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-1)'"
                  @mouseleave="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-3)'"
                >
                  {{ diffTargetId === v.id ? 'Ocultar diff' : 'Ver diff' }}
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

            <!-- Acordeón: diff contra la versión padre -->
            <Transition name="accordion">
              <div v-if="diffTargetId === v.id" class="px-5 pb-4">
                <div v-if="diffParts" class="rounded-[10px] p-4 font-mono text-[12px] leading-relaxed overflow-x-auto"
                  style="background: #0d1117; max-height: 400px; overflow-y: auto;">
                  <pre
                    v-for="(part, idx) in diffParts"
                    :key="idx"
                    class="whitespace-pre-wrap m-0"
                    :style="part.added
                      ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                      : part.removed
                        ? 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);'
                        : 'color: #8b949e;'"
                  >{{ part.value }}</pre>
                </div>
                <p v-else class="text-[11px]" style="color: var(--text-3);">
                  Sin versión padre registrada para comparar.
                </p>
              </div>
            </Transition>
          </div>
        </div>

      </div>

    </div>
  </div>

  <!-- Modal confirmar restaurar versión -->
  <Transition name="fade">
    <div v-if="restoreTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="restoreTarget = null" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">
          ¿Restaurar versión {{ restoreTarget.version }}?
        </h3>
        <p class="text-sm mb-2" style="color: var(--text-2);">
          {{ restoreTarget.notes || '(sin descripción)' }}
        </p>
        <ul class="text-sm space-y-1.5 mb-5">
          <li style="color: var(--badge-ok-txt);">✓ La versión seleccionada pasará a ser la activa</li>
          <li style="color: var(--badge-danger-txt);">✗ Los cambios tendrán efecto inmediato en el LLM</li>
        </ul>
        <div class="flex justify-end gap-2">
          <button
            @click="restoreTarget = null"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);"
          >
            Cancelar
          </button>
          <button
            @click="doRestoreVersion"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
          >
            Restaurar
          </button>
        </div>
      </div>
    </div>
  </Transition>

  <!-- Modal confirmar promover draft -->
  <Transition name="fade">
    <div v-if="confirmPromote && props.draft" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="confirmPromote = false" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">
          ¿Promover draft a activa?
        </h3>
        <ul class="text-sm space-y-1.5 mb-5">
          <li style="color: var(--badge-ok-txt);">✓ El draft pasa a ser la versión activa y bumpea el número de versión</li>
          <li style="color: var(--badge-danger-txt);">✗ La versión activa actual queda archivada</li>
          <li style="color: var(--badge-danger-txt);">✗ El LLM empieza a usar el nuevo prompt de inmediato</li>
        </ul>
        <div class="flex justify-end gap-2">
          <button
            @click="confirmPromote = false"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);"
          >
            Cancelar
          </button>
          <button
            @click="doPromoteDraft"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);"
          >
            Promover
          </button>
        </div>
      </div>
    </div>
  </Transition>

  <!-- Modal confirmar descartar draft -->
  <Transition name="fade">
    <div v-if="confirmDiscard && props.draft" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="confirmDiscard = false" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">
          ¿Descartar este draft?
        </h3>
        <p class="text-sm mb-4" style="color: var(--text-2);">
          Se pierde el contenido del draft sin promoverlo. La versión activa sigue intacta.
        </p>
        <div class="flex justify-end gap-2">
          <button
            @click="confirmDiscard = false"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);"
          >
            Cancelar
          </button>
          <button
            @click="doDiscardDraft"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
          >
            Descartar
          </button>
        </div>
      </div>
    </div>
  </Transition>

  <!-- Modal confirmar tomar control del draft -->
  <Transition name="fade">
    <div v-if="confirmTakeControl && props.draft" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="confirmTakeControl = false" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">
          ¿Tomar control del draft?
        </h3>
        <p class="text-sm mb-2" style="color: var(--text-2);">
          Actualmente pertenece a <strong>{{ props.draft?.owner?.name ?? 'otro admin' }}</strong>.
        </p>
        <ul class="text-sm space-y-1.5 mb-5">
          <li style="color: var(--badge-pending-txt);">⚠ Pasás a ser el owner y podés editarlo, promoverlo o descartarlo</li>
          <li style="color: var(--text-3);">· El contenido del draft se preserva</li>
        </ul>
        <div class="flex justify-end gap-2">
          <button
            @click="confirmTakeControl = false"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);"
          >
            Cancelar
          </button>
          <button
            @click="doTakeControl"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;"
          >
            Tomar control
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref, computed, reactive } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { marked } from 'marked'
import { diffLines, type Change } from 'diff'
import AppBackLink from '@/components/App/BackLink.vue'

// ─── Props ────────────────────────────────────────────────────────────────────

interface VersionEntry {
  id: number
  version: number
  is_active: boolean
  status: 'active' | 'draft' | 'archived'
  notes: string | null
  content: string
  created_at: string
  owner: { id: number; name: string } | null
  is_mine: boolean
  parent_version_id: number | null
}

const props = defineProps<{
  agentKey: string
  agentLabel: string
  type?: 'agent' | 'shared'
  activeVersion: VersionEntry | null
  draft: VersionEntry | null
  versions: VersionEntry[]
  composedPreview?: string
  inheritedBlocks?: string[]
}>()

const $page = usePage()
const showComposed = ref(false)
const composedLines = computed(() => (props.composedPreview ?? '').split('\n').length)

// ─── Editor state ────────────────────────────────────────────────────────────

// Si hay draft (mío o ajeno), el editor arranca mostrando el draft.
// Si no hay draft, arranca con la versión activa.
const initialContent = props.draft?.content ?? props.activeVersion?.content ?? ''
const draft = ref(initialContent)
const notes = ref(props.draft?.notes ?? '')
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

// ─── Draft flow ──────────────────────────────────────────────────────────────

const draftLoading = ref(false)
const confirmPromote = ref(false)
const confirmDiscard = ref(false)
const confirmTakeControl = ref(false)

const draftDirty = computed(() => {
  if (!props.draft || !props.draft.is_mine) return false
  return draft.value !== props.draft.content || notes.value !== (props.draft.notes ?? '')
})

// Editor solo es editable si: no hay draft, o el draft me pertenece.
const editorReadonly = computed(() => {
  return props.draft !== null && !props.draft.is_mine
})

const draftBannerStyle = computed(() => {
  if (!props.draft) {
    return 'background: var(--bg-card); border: 1px dashed var(--border); box-shadow: var(--shadow-card);'
  }
  if (props.draft.is_mine) {
    return 'background: var(--accent-50, #eef0ff); border: 1px solid var(--border); box-shadow: var(--shadow-card);'
  }
  return 'background: #fffbeb; border: 1px solid #fcd34d; box-shadow: var(--shadow-card);'
})

const draftBannerTitle = computed(() => {
  if (!props.draft) return 'Sin draft en curso'
  if (props.draft.is_mine) return `Editando draft v${props.draft.version}`
  return `Draft v${props.draft.version} en uso por otro admin`
})

const draftBannerTextColor = computed(() => {
  if (!props.draft) return 'var(--text-2)'
  if (props.draft.is_mine) return 'var(--text-1)'
  return '#92400e'
})

const createDraft = () => {
  if (draftLoading.value) return
  draftLoading.value = true
  router.post(`/admin/agent-prompts/${props.agentKey}/drafts`, {}, {
    preserveScroll: true,
    onFinish: () => { draftLoading.value = false },
  })
}

const saveDraft = () => {
  if (!props.draft || !props.draft.is_mine || draftLoading.value || !draftDirty.value) return
  draftLoading.value = true
  router.put(`/admin/agent-prompts/drafts/${props.draft.id}`, {
    content: draft.value,
    notes: notes.value || null,
  }, {
    preserveScroll: true,
    onFinish: () => { draftLoading.value = false },
  })
}

const doPromoteDraft = () => {
  if (!props.draft) return
  confirmPromote.value = false
  draftLoading.value = true
  // Asegurar que el contenido en pantalla esté persistido antes de promover.
  const promote = () => router.post(`/admin/agent-prompts/drafts/${props.draft!.id}/promote`, {}, {
    preserveScroll: true,
    onFinish: () => { draftLoading.value = false },
  })
  if (draftDirty.value) {
    router.put(`/admin/agent-prompts/drafts/${props.draft.id}`, {
      content: draft.value,
      notes: notes.value || null,
    }, {
      preserveScroll: true,
      onSuccess: () => promote(),
      onError: () => { draftLoading.value = false },
    })
  } else {
    promote()
  }
}

const doDiscardDraft = () => {
  if (!props.draft) return
  confirmDiscard.value = false
  draftLoading.value = true
  router.delete(`/admin/agent-prompts/drafts/${props.draft.id}`, {
    preserveScroll: true,
    onFinish: () => { draftLoading.value = false },
  })
}

const doTakeControl = () => {
  if (!props.draft) return
  confirmTakeControl.value = false
  draftLoading.value = true
  router.post(`/admin/agent-prompts/drafts/${props.draft.id}/take-control`, {}, {
    preserveScroll: true,
    onFinish: () => { draftLoading.value = false },
  })
}

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

const restoreTarget = ref<VersionEntry | null>(null)

const restoreVersion = (v: VersionEntry) => {
  restoreTarget.value = v
}

const doRestoreVersion = () => {
  if (!restoreTarget.value) return
  router.post(`/admin/agent-prompts/${restoreTarget.value.id}/activate`, {}, {
    preserveScroll: true,
  })
  restoreTarget.value = null
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

// ─── Diff entre versiones ────────────────────────────────────────────────────

const diffTargetId = ref<number | null>(null)
const versionsById = computed(() => new Map(props.versions.map(v => [v.id, v])))

const toggleDiff = (id: number) => {
  diffTargetId.value = diffTargetId.value === id ? null : id
}

const diffParts = computed<Change[] | null>(() => {
  if (diffTargetId.value === null) return null
  const target = versionsById.value.get(diffTargetId.value)
  if (!target?.parent_version_id) return null
  const parent = versionsById.value.get(target.parent_version_id)
  if (!parent) return null
  return diffLines(parent.content, target.content)
})

// ─── Helpers ─────────────────────────────────────────────────────────────────

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

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
