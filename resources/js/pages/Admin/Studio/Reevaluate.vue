<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-[1400px] mx-auto space-y-4">

      <!-- Header -->
      <div class="flex items-center gap-3">
        <BackLink :href="`/admin/conversations/${conversation.id}`" label="Conversación" />
        <div>
          <h1 class="text-xl font-semibold tracking-tight" style="color: var(--text-1);">
            🧪 Reevaluación de turn #{{ log.id }}
          </h1>
          <p class="text-sm" style="color: var(--text-3);">
            {{ log.agent_name }} · step {{ log.step }} · {{ formatDate(log.created_at) }}
          </p>
        </div>
      </div>

      <div class="rounded-[10px] px-3 py-2 text-[12px]"
        style="background: var(--badge-warning-bg, #fef3c7); color: var(--badge-warning-txt, #92400e); border: 1px solid var(--border);">
        <strong>Zero-writes:</strong> el replay nunca persiste en DB ni llama a adapters; los tools MOCK devuelven respuestas canned.
        Los tools REAL (RAG de coberturas) sí corren contra la base.
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        <!-- LEFT: Context read-only -->
        <div class="rounded-[14px] p-4"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-sm font-semibold mb-2" style="color: var(--text-1);">Contexto histórico</h2>
          <p class="text-[11px] mb-3" style="color: var(--text-3);">
            Mensajes previos al turn. El último inbound se usa como prompt.
          </p>
          <div class="space-y-2 max-h-[500px] overflow-y-auto">
            <div v-for="m in messages" :key="m.id"
              class="rounded-lg px-3 py-2 text-[12px]"
              :style="m.direction === 'inbound'
                ? 'background: var(--bg-raised); color: var(--text-1);'
                : 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'">
              <span class="text-[10px] font-mono uppercase opacity-70">{{ m.direction }}</span>
              <p class="whitespace-pre-wrap">{{ m.content }}</p>
            </div>
          </div>
        </div>

        <!-- RIGHT: Draft + result -->
        <div class="space-y-4">
          <div class="rounded-[14px] p-4"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex items-center justify-between mb-2">
              <h2 class="text-sm font-semibold" style="color: var(--text-1);">Prompt de prueba</h2>
              <span v-if="active_prompt" class="text-[10px] font-mono" style="color: var(--text-3);">
                activa: v{{ active_prompt.version }}
              </span>
            </div>
            <textarea
              v-model="draftInstructions"
              rows="18"
              class="w-full font-mono text-[12px] rounded-lg p-3"
              style="background: var(--bg-raised); color: var(--text-1); border: 1px solid var(--border);"
            ></textarea>

            <div class="mt-4">
              <label class="block text-[11px] font-semibold mb-1" style="color: var(--text-2);">
                Mensaje del cliente (prompt)
              </label>
              <textarea
                v-model="overridePrompt"
                rows="3"
                class="w-full font-mono text-[12px] rounded-lg p-3"
                style="background: var(--bg-raised); color: var(--text-1); border: 1px solid var(--border);"
              ></textarea>
            </div>

            <div class="flex items-center gap-2 mt-3">
              <button
                @click="run"
                :disabled="running || !draftInstructions.trim()"
                class="px-4 py-2 text-[12px] font-semibold rounded-lg transition-colors disabled:opacity-50"
                style="background: var(--accent); color: white;">
                {{ running ? 'Ejecutando…' : '▶ Probar' }}
              </button>
              <button
                @click="resetToActive"
                class="px-3 py-2 text-[12px] rounded-lg transition-colors"
                style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);">
                Restaurar activa
              </button>
            </div>

            <p v-if="error" class="mt-2 text-[12px]"
              style="color: var(--badge-danger-txt, #dc2626);">{{ error }}</p>
          </div>

          <!-- Result -->
          <div v-if="result"
            class="rounded-[14px] p-4"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <h2 class="text-sm font-semibold mb-2" style="color: var(--text-1);">Respuesta</h2>
            <div class="rounded-lg p-3 text-[13px] whitespace-pre-wrap mb-3"
              style="background: var(--bg-raised); color: var(--text-1);">{{ result.response }}</div>

            <div v-if="result.tool_calls?.length" class="space-y-1">
              <p class="text-[11px] uppercase tracking-wider" style="color: var(--text-3);">Tool calls</p>
              <div v-for="(tc, i) in result.tool_calls" :key="i"
                class="rounded px-2 py-1 text-[11px] font-mono"
                style="background: var(--bg-raised); border: 1px solid var(--border);">
                <span class="px-1.5 py-0.5 rounded text-[9px] font-semibold uppercase mr-1"
                  :style="tc.policy === 'real'
                    ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                    : 'background: var(--badge-warning-bg, #fef3c7); color: var(--badge-warning-txt, #92400e);'">
                  {{ tc.policy }}
                </span>
                🔧 {{ tc.name }}({{ argsPreview(tc.arguments) }})
              </div>
            </div>

            <p class="mt-3 text-[10px] font-mono" style="color: var(--text-3);">
              tokens: {{ result.usage.prompt_tokens }} in / {{ result.usage.completion_tokens }} out
            </p>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import BackLink from '@/components/UI/BackLink.vue'

interface Props {
  log: {
    id: number
    agent_name: string
    agent_key: string
    step: number
    status: string
    state_before: Record<string, boolean>
    state_after: Record<string, boolean>
    tool_calls: Array<{ name: string; arguments: unknown }> | null
    created_at: string | null
  }
  conversation: { id: number; external_conversation_id: string }
  messages: Array<{ id: number; direction: string; content: string; created_at: string | null }>
  active_prompt: { id: number; version: number; content: string } | null
  draft_prompt: { id: number; version: number; content: string; is_mine: boolean } | null
  historical_prompt: { id: number; version: number; status: string } | null
}

const props = defineProps<Props>()

const draftInstructions = ref(
  props.draft_prompt?.content ?? props.active_prompt?.content ?? ''
)

const originalPrompt = props.messages.findLast(m => m.direction === 'inbound')?.content ?? ''
const overridePrompt = ref(originalPrompt)

const running = ref(false)
const result = ref<{
  response: string
  tool_calls: Array<{ name: string; arguments: unknown; policy: string }>
  usage: { prompt_tokens: number; completion_tokens: number }
} | null>(null)
const error = ref<string | null>(null)

const run = async () => {
  running.value = true
  error.value = null
  result.value = null
  try {
    const res = await axios.post('/admin/studio/reevaluate', {
      agent_key: props.log.agent_key,
      conversation_id: props.conversation.id,
      agent_execution_log_id: props.log.id,
      draft_instructions: draftInstructions.value,
      override_prompt: overridePrompt.value || undefined,
    })
    result.value = res.data
  } catch (e: unknown) {
    const err = e as { response?: { data?: { error?: string; message?: string } } }
    error.value = err.response?.data?.error
      ?? err.response?.data?.message
      ?? 'Error inesperado'
  } finally {
    running.value = false
  }
}

const resetToActive = () => {
  if (props.active_prompt) draftInstructions.value = props.active_prompt.content
}

const argsPreview = (args: unknown): string => {
  try { return JSON.stringify(args).slice(0, 80) }
  catch { return String(args) }
}

const formatDate = (iso: string | null): string =>
  iso ? new Date(iso).toLocaleString() : ''
</script>
