<template>
  <Teleport to="body">
    <Transition name="backdrop">
      <div v-if="open" class="fixed inset-0 bg-black/40 z-40" @click="$emit('close')" />
    </Transition>
    <Transition name="slide">
      <aside
        v-if="open"
        class="fixed inset-y-0 right-0 z-50 w-full sm:w-[480px] flex flex-col"
        style="background: var(--bg-card); border-left: 1px solid var(--border); box-shadow: var(--shadow-card);"
      >
        <!-- Header -->
        <header class="px-5 py-4 flex items-start justify-between gap-3"
          style="border-bottom: 1px solid var(--border); background: var(--bg-raised);">
          <div class="min-w-0">
            <h2 class="text-sm font-semibold truncate" style="color: var(--text-1);">
              {{ data?.agent_label ?? 'Prompt' }}
              <span v-if="data" class="ml-1 text-[11px] font-mono" style="color: var(--text-3);">
                v{{ data.version }}
              </span>
            </h2>
            <p class="text-[11px] mt-0.5" style="color: var(--text-3);">
              <span v-if="data?.is_active">Versión activa · usada en este turn</span>
              <span v-else-if="data">Versión archivada · histórica</span>
              <span v-else>&nbsp;</span>
            </p>
            <p v-if="fallbackWarning" class="text-[11px] mt-1 px-2 py-1 rounded"
               style="background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;">
              ⚠ No se pudo identificar la versión exacta que corrió. Mostrando la activa actual.
            </p>
          </div>
          <div class="flex items-center gap-1.5 flex-shrink-0">
            <a v-if="data" :href="`/admin/agent-prompts/${data.agent_key}`"
               class="text-[11px] px-2.5 py-1 rounded-[7px] font-medium transition-colors"
               style="color: var(--text-2); border: 1px solid var(--border);"
               @mouseenter="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-1)'"
               @mouseleave="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-2)'">
              Abrir editor →
            </a>
            <button @click="$emit('close')"
              class="w-7 h-7 rounded-[7px] flex items-center justify-center transition-colors"
              style="color: var(--text-3);"
              @mouseenter="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-1)'"
              @mouseleave="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-3)'"
              aria-label="Cerrar">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto">
          <div v-if="loading" class="p-5 text-sm" style="color: var(--text-3);">
            Cargando prompt...
          </div>
          <div v-else-if="error" class="p-5 text-sm" style="color: var(--badge-danger-txt);">
            {{ error }}
          </div>
          <div v-else-if="data" class="markdown-preview p-5" v-html="renderedContent" />
        </div>
      </aside>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { marked } from 'marked'

interface PromptView {
  id: number
  agent_key: string
  agent_label: string
  version: number
  status: string
  is_active: boolean
  notes: string | null
  content: string
  created_at: string
}

const props = defineProps<{
  open: boolean
  promptId: number | null
  /** Si true, el slide-over avisa que no hay FK — está mostrando la activa actual. */
  fallbackWarning?: boolean
}>()

defineEmits<{ (e: 'close'): void }>()

const loading = ref(false)
const error = ref<string | null>(null)
const data = ref<PromptView | null>(null)

const renderedContent = computed(() => {
  if (!data.value) return ''
  return marked.parse(data.value.content) as string
})

watch(() => [props.open, props.promptId] as const, async ([isOpen, id]) => {
  if (!isOpen || !id) {
    data.value = null
    error.value = null
    return
  }
  loading.value = true
  error.value = null
  try {
    const res = await fetch(`/admin/agent-prompts/view/${id}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    })
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`)
    }
    data.value = await res.json()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Error cargando prompt'
  } finally {
    loading.value = false
  }
}, { immediate: true })
</script>

<style scoped>
.backdrop-enter-active, .backdrop-leave-active { transition: opacity 0.2s ease; }
.backdrop-enter-from, .backdrop-leave-to { opacity: 0; }

.slide-enter-active, .slide-leave-active { transition: transform 0.25s ease; }
.slide-enter-from, .slide-leave-to { transform: translateX(100%); }

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
:deep(.markdown-preview ol) { padding-left: 1.25em; margin-bottom: 0.75em; }
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
:deep(.markdown-preview pre code) { background: transparent; color: inherit; padding: 0; }
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
</style>
