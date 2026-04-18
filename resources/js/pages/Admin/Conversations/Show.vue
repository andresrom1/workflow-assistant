<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3">
          <BackLink href="/admin/conversations" label="Conversaciones" />
          <div class="w-px h-4" style="background: var(--border);"></div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Conversación #{{ conversation.id }}
          </h1>
          <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold"
            :style="channelStyle(conversation.channel)">
            {{ channelLabel(conversation.channel) }}
          </span>
        </div>
        <span class="self-start sm:self-auto inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold"
          :style="statusStyle(conversation.status)">
          <span class="w-1.5 h-1.5 rounded-full" :style="statusDot(conversation.status)"></span>
          {{ conversation.status }}
        </span>
      </div>

      <!-- Identidad + pipeline -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <!-- Identidad -->
        <div class="rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">Identidad</h2>
          <dl class="space-y-3">
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Cliente</dt>
              <dd class="text-sm font-semibold mt-0.5" style="color: var(--text-1);">
                {{ conversation.customer?.name ?? conversation.ext_username ?? 'Anónimo' }}
              </dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Teléfono / ID</dt>
              <dd class="text-xs font-mono mt-0.5" style="color: var(--text-2);">
                {{ conversation.customer?.phone ?? conversation.ext_user_id ?? '—' }}
              </dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">External ID</dt>
              <dd class="text-xs font-mono mt-0.5" style="color: var(--text-2);">{{ conversation.external_id }}</dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Iniciada</dt>
              <dd class="text-xs mt-0.5" style="color: var(--text-2);">{{ formatDate(conversation.created_at) }}</dd>
            </div>
          </dl>
        </div>

        <!-- Pipeline + stats -->
        <div class="rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">Pipeline</h2>
          <AgentFlowGraph :ai-state="conversation.ai_state" :large="true" />
          <div class="grid grid-cols-2 gap-3 mt-5">
            <div class="text-center">
              <div class="text-xl font-bold tracking-tight" style="color: var(--text-1);">{{ stats.total_invocations }}</div>
              <div class="text-[11px] mt-0.5" style="color: var(--text-3);">pasos</div>
            </div>
            <div class="text-center">
              <div class="text-xl font-bold tracking-tight" style="color: var(--text-1);">{{ formatDuration(stats.total_duration_ms) }}</div>
              <div class="text-[11px] mt-0.5" style="color: var(--text-3);">duración total</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Chat + flujo lateral -->
      <div ref="gridContainer" class="grid grid-cols-1 lg:grid-cols-[1fr_200px] gap-4 items-start">

        <!-- Panel central: mensajes -->
        <div class="rounded-[14px] overflow-hidden"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <div class="px-5 py-3 border-b" style="background: var(--bg-raised); border-color: var(--border);">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">
              Mensajes ({{ messages.length }})
            </h2>
          </div>

          <div v-if="!messages.length" class="p-8 text-center text-sm" style="color: var(--text-3);">
            Sin mensajes en esta conversación.
          </div>

          <div v-else ref="messagesContent" class="p-4 space-y-2">
            <div v-for="msg in messages" :key="`msg-${msg.id}`"
              :data-msg-id="msg.id"
              :class="[
                msg.type === 'message_inbound' ? 'flex justify-start' : 'flex justify-end',
                messageToExecMap.has(msg.id) ? 'cursor-pointer' : '',
              ]"
              @click="handleMessageClick(msg)">

              <!-- Inbound -->
              <div v-if="msg.type === 'message_inbound'"
                class="max-w-[80%] rounded-[12px] rounded-tl-[4px] px-3.5 py-2.5"
                :class="{
                  'msg-highlight': highlightedInboundIds.has(msg.id),
                  'msg-hover': !highlightedInboundIds.has(msg.id) && hoveredMsgIds.has(msg.id),
                }"
                style="background: var(--bg-raised); border: 1px solid var(--border);">
                <p v-if="msg.sender_name" class="text-[10px] font-semibold mb-1" style="color: var(--text-3);">
                  {{ msg.sender_name }}
                </p>

                <!-- Audio inbound -->
                <div v-if="msg.message_type === 'audio'" class="space-y-1.5">
                  <div class="flex items-center gap-2 px-2 py-1.5 rounded-lg"
                       style="background: var(--bg-card); border: 1px solid var(--border);">
                    <button @click.stop="toggleAudio(msg.id, msg.attachment?.storage_url ?? null)"
                            class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0"
                            style="background: var(--accent-100); color: var(--dot-accent);"
                            :disabled="!msg.attachment?.storage_url">
                      <span class="text-[10px]">{{ playingAudioId === msg.id ? '⏸' : '▶' }}</span>
                    </button>
                    <div class="flex-1 h-1 rounded-full" style="background: var(--border);">
                      <div class="h-full rounded-full transition-all"
                           :style="{ width: (audioProgress[msg.id] ?? 0) + '%', background: 'var(--dot-accent)' }"></div>
                    </div>
                    <span class="text-[10px] font-mono flex-shrink-0" style="color: var(--text-3);">
                      {{ msg.attachment?.duration_seconds != null ? formatAudioDuration(msg.attachment.duration_seconds) : '—' }}
                    </span>
                  </div>
                  <div v-if="msg.attachment?.transcription">
                    <button @click.stop="toggleTranscription(msg.id)"
                      class="text-[10px] flex items-center gap-1" style="color: var(--text-3);">
                      {{ expandedTranscriptions.has(msg.id) ? '▾' : '▸' }} Transcripción
                    </button>
                    <p v-if="expandedTranscriptions.has(msg.id)"
                      class="text-xs mt-1 pl-2 whitespace-pre-wrap leading-relaxed"
                      style="color: var(--text-2); border-left: 2px solid var(--border);">
                      {{ msg.attachment.transcription }}
                    </p>
                  </div>
                </div>

                <!-- Text inbound -->
                <p v-else class="text-sm whitespace-pre-wrap" style="color: var(--text-1);">{{ msg.content }}</p>

                <div class="flex items-center gap-2 mt-1">
                  <span class="text-[10px]" style="color: var(--text-3);">{{ formatTime(msg.created_at) }}</span>
                  <span class="text-[9px] font-mono" style="color: var(--text-3); opacity: 0.5;">#{{ msg.id }}</span>
                </div>
              </div>

              <!-- Outbound -->
              <div v-else
                class="max-w-[80%] rounded-[12px] rounded-tr-[4px] px-3.5 py-2.5"
                :class="{
                  'msg-highlight': highlightedOutboundId === msg.id,
                  'msg-hover': highlightedOutboundId !== msg.id && hoveredMsgIds.has(msg.id),
                }"
                style="background: var(--accent-100); border: 1px solid var(--accent-200, var(--border));">
                <div v-if="msg.agent_name" class="flex items-center gap-1.5 mb-1">
                  <span class="w-2 h-2 rounded-full flex-shrink-0" :style="{ background: agentColor(msg.agent_name) }"></span>
                  <p class="text-[10px] font-semibold" :style="{ color: agentColor(msg.agent_name) }">
                    {{ agentShortName(msg.agent_name) }}
                  </p>
                  <span v-if="msg.ai_provider"
                    class="text-[9px] font-mono px-1 py-px rounded"
                    style="background: var(--bg-raised); color: var(--text-3); border: 1px solid var(--border);">
                    {{ msg.ai_provider }}
                  </span>
                </div>
                <p class="text-sm whitespace-pre-wrap" style="color: var(--text-1);">{{ msg.content }}</p>
                <div class="flex items-center gap-2 mt-1">
                  <span class="text-[10px]" style="color: var(--text-3);">{{ formatTime(msg.created_at) }}</span>
                  <span class="text-[9px] font-mono" style="color: var(--text-3); opacity: 0.5;">#{{ msg.id }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Panel lateral: flujo de agentes -->
        <div>
          <!-- Header -->
          <div class="rounded-[14px] px-4 py-3 mb-3"
               style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">
              Flujo de agentes
            </h2>
          </div>

          <div v-if="!executions.length" class="rounded-[14px] p-6 text-center text-xs"
               style="background: var(--bg-card); border: 1px solid var(--border); color: var(--text-3);">
            Sin registros.<br>
            <span class="text-[10px]">Los logs se generan a partir de nuevas conversaciones.</span>
          </div>

          <!-- Execution nodes (scroll-synced) — timeline continuo -->
          <div v-else ref="execPanel" class="relative ml-[5px]">
            <!-- Línea continua vertical -->
            <div class="absolute left-0 top-1 bottom-1 w-0.5 rounded-full" style="background: var(--border);"></div>

            <div v-for="(group, idx) in executionGroups" :key="group.parent.id"
                 :data-exec-id="group.parent.id"
                 class="relative cursor-pointer transition-opacity"
                 :style="{ marginTop: (execMargins[group.parent.id] ?? 0) + 'px' }"
                 :class="{
                   'opacity-40': hoveredExecId && hoveredExecId !== group.parent.id && selectedExecId !== group.parent.id,
                 }"
                 @mouseenter="hoveredExecId = group.parent.id"
                 @mouseleave="hoveredExecId = null"
                 @click="highlightExecution(group.parent)">

              <!-- Main step -->
              <div class="relative flex items-start gap-3 py-1.5">
                <!-- Dot -->
                <div class="absolute left-0 top-2 w-2.5 h-2.5 rounded-full -translate-x-[calc(50%-1px)] border-2 flex-shrink-0"
                     :style="{
                       background: agentColor(group.parent.agent_name),
                       borderColor: selectedExecId === group.parent.id ? 'var(--accent-100)' : 'var(--bg-card)',
                     }"></div>

                <!-- Content -->
                <div class="min-w-0 pl-4">
                  <div class="flex items-center gap-1 flex-wrap">
                    <span class="text-[11px] font-semibold"
                          :style="{ color: agentColor(group.parent.agent_name) }">
                      {{ agentShortName(group.parent.agent_name) }}
                    </span>
                    <span class="text-[10px]" style="color: var(--text-3);">·</span>
                    <span class="text-[10px] font-mono" style="color: var(--text-2);">
                      {{ formatDuration(group.parent.duration_ms) }}
                    </span>
                  </div>

                  <!-- State changes -->
                  <div v-if="Object.keys(group.parent.state_changes ?? {}).length" class="flex flex-wrap gap-1 mt-0.5">
                    <span v-for="(val, key) in group.parent.state_changes" :key="key"
                      class="inline-flex items-center gap-0.5 px-1 py-0 rounded-[3px] text-[9px] font-medium"
                      style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);">
                      ✓ {{ flagLabel(String(key)) }}
                    </span>
                  </div>

                  <!-- Tool calls -->
                  <div v-if="group.parent.tool_calls?.length" class="flex flex-wrap gap-1 mt-0.5">
                    <span v-for="tc in group.parent.tool_calls" :key="tc.name"
                      class="inline-flex items-center gap-0.5 px-1 py-0 rounded-[3px] text-[9px] font-medium"
                      style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);">
                      🔧 {{ toolShortName(tc.name) }}
                    </span>
                  </div>

                  <!-- Error -->
                  <p v-if="group.parent.status === 'error'" class="text-[10px] mt-0.5 leading-tight"
                     style="color: var(--badge-danger-txt, #dc2626);">
                    {{ group.parent.error_message }}
                  </p>

                  <!-- Chained indicator -->
                  <p v-if="group.child" class="text-[10px] mt-0.5" style="color: var(--text-3);">
                    ↳ encadenó a {{ agentShortName(group.child.agent_name) }}
                  </p>

                  <!-- Footer: time + ID (only when no child) -->
                  <div v-if="!group.child" class="flex items-center gap-2 mt-0.5">
                    <span class="text-[10px]" style="color: var(--text-3);">{{ formatTime(group.parent.created_at) }}</span>
                    <span class="text-[9px] font-mono" style="color: var(--text-3); opacity: 0.5;">#{{ group.parent.id }}</span>
                  </div>
                </div>
              </div>

              <!-- Chained sub-node -->
              <div v-if="group.child" class="relative flex items-start gap-3 py-1 ml-2">
                <!-- Smaller dot -->
                <div class="absolute left-0 top-1.5 w-1.5 h-1.5 rounded-full -translate-x-[calc(50%-1px+4px)] flex-shrink-0"
                     :style="{ background: agentColor(group.child.agent_name) }"></div>

                <div class="min-w-0 pl-3">
                  <div class="flex items-center gap-1 flex-wrap">
                    <span class="text-[10px] font-semibold"
                          :style="{ color: agentColor(group.child.agent_name) }">
                      ↳ {{ agentShortName(group.child.agent_name) }}
                    </span>
                    <span class="text-[10px]" style="color: var(--text-3);">·</span>
                    <span class="text-[10px] font-mono" style="color: var(--text-2);">
                      {{ formatDuration(group.child.duration_ms) }}
                    </span>
                  </div>

                  <div v-if="Object.keys(group.child.state_changes ?? {}).length" class="flex flex-wrap gap-1 mt-0.5">
                    <span v-for="(val, key) in group.child.state_changes" :key="key"
                      class="inline-flex items-center gap-0.5 px-1 py-0 rounded-[3px] text-[9px] font-medium"
                      style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);">
                      ✓ {{ flagLabel(String(key)) }}
                    </span>
                  </div>

                  <!-- Tool calls (chained child) -->
                  <div v-if="group.child.tool_calls?.length" class="flex flex-wrap gap-1 mt-0.5">
                    <span v-for="tc in group.child.tool_calls" :key="tc.name"
                      class="inline-flex items-center gap-0.5 px-1 py-0 rounded-[3px] text-[9px] font-medium"
                      style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);">
                      🔧 {{ toolShortName(tc.name) }}
                    </span>
                  </div>

                  <p v-if="group.child.status === 'error'" class="text-[10px] mt-0.5 leading-tight"
                     style="color: var(--badge-danger-txt, #dc2626);">
                    {{ group.child.error_message }}
                  </p>

                  <div class="flex items-center gap-2 mt-0.5">
                    <span class="text-[10px]" style="color: var(--text-3);">{{ formatTime(group.child.created_at) }}</span>
                    <span class="text-[9px] font-mono" style="color: var(--text-3); opacity: 0.5;">#{{ group.child.id }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import AgentFlowGraph from '@/components/AgentFlowGraph.vue'
import BackLink from '@/components/UI/BackLink.vue'

// ── Types ──

interface MessageItem {
  type: 'message_inbound' | 'message_outbound'
  id: number
  message_type: string
  content: string | null
  sender_name: string | null
  agent_name: string | null
  attachment: {
    duration_seconds: number | null
    storage_url: string | null
    transcription: string | null
  } | null
  created_at: string
}

interface ToolCallItem {
  name: string
  arguments: Record<string, unknown>
}

interface ExecutionItem {
  id: number
  agent_name: string
  step: number
  state_changes: Record<string, boolean> | null
  chained: boolean
  status: string
  error_message: string | null
  duration_ms: number
  input_tokens: number | null
  output_tokens: number | null
  inbound_message_ids: number[] | null
  outbound_message_id: number | null
  tool_calls: ToolCallItem[]
  created_at: string
}

interface ExecutionGroup {
  parent: ExecutionItem
  child: ExecutionItem | null
}

const props = defineProps<{
  conversation: {
    id: number
    external_id: string
    ext_user_id: string | null
    ext_username: string | null
    customer: { id: number; name: string | null; phone: string | null } | null
    channel: string
    status: string
    ai_state: Record<string, boolean>
    created_at: string
    last_message_at: string
  }
  messages: MessageItem[]
  executions: ExecutionItem[]
  stats: {
    total_invocations: number
    total_duration_ms: number
    total_input_tokens: number | null
    total_output_tokens: number | null
  }
}>()

// ── Template refs ──

const gridContainer = ref<HTMLElement>()
const messagesContent = ref<HTMLElement>()
const execPanel = ref<HTMLElement>()

// ── State ──

const hoveredExecId = ref<number | null>(null)
const selectedExecId = ref<number | null>(null)
const highlightedInboundIds = ref<Set<number>>(new Set())
const highlightedOutboundId = ref<number | null>(null)
const execMargins = ref<Record<number, number>>({})

// Audio
const audioElements = ref<Map<number, HTMLAudioElement>>(new Map())
const audioProgress = ref<Record<number, number>>({})
const playingAudioId = ref<number | null>(null)
const expandedTranscriptions = ref<Set<number>>(new Set())

// ── Execution grouping (chained sub-nodes) ──

const executionGroups = computed<ExecutionGroup[]>(() => {
  const groups: ExecutionGroup[] = []
  let i = 0
  while (i < props.executions.length) {
    if (props.executions[i].chained && i + 1 < props.executions.length) {
      groups.push({ parent: props.executions[i], child: props.executions[i + 1] })
      i += 2
    } else {
      groups.push({ parent: props.executions[i], child: null })
      i += 1
    }
  }
  return groups
})

// ── Bidirectional message ↔ execution map ──

const messageToExecMap = computed(() => {
  const map = new Map<number, number>()
  for (const exec of props.executions) {
    if (exec.inbound_message_ids) {
      for (const id of exec.inbound_message_ids) {
        map.set(id, exec.id)
      }
    }
    if (exec.outbound_message_id) {
      map.set(exec.outbound_message_id, exec.id)
    }
  }
  return map
})

// ── Hover → soft highlight on related messages ──

const hoveredMsgIds = computed<Set<number>>(() => {
  if (!hoveredExecId.value) return new Set()
  const exec = props.executions.find(e => e.id === hoveredExecId.value)
  if (!exec) return new Set()
  const ids = new Set<number>(exec.inbound_message_ids ?? [])
  if (exec.outbound_message_id) ids.add(exec.outbound_message_id)
  return ids
})

// ── Scroll-sync positioning ──

const getAnchorMessageId = (group: ExecutionGroup): number | null => {
  // Prefer first inbound message as anchor
  if (group.parent.inbound_message_ids?.length) {
    return group.parent.inbound_message_ids[0]
  }
  // Fall back to outbound message
  if (group.parent.outbound_message_id) {
    return group.parent.outbound_message_id
  }
  // Check child's outbound for chained groups
  if (group.child?.outbound_message_id) {
    return group.child.outbound_message_id
  }
  return null
}

const recalculatePositions = () => {
  if (!gridContainer.value || !execPanel.value || !executionGroups.value.length) return

  // Skip sync on mobile (single column)
  if (window.innerWidth < 1024) {
    const uniform: Record<number, number> = {}
    executionGroups.value.forEach((g, i) => { uniform[g.parent.id] = i === 0 ? 0 : 8 })
    execMargins.value = uniform
    return
  }

  const gridRect = gridContainer.value.getBoundingClientRect()
  const execPanelRect = execPanel.value.getBoundingClientRect()
  const margins: Record<number, number> = {}

  let currentBottom = execPanelRect.top - gridRect.top

  for (const group of executionGroups.value) {
    const anchorId = getAnchorMessageId(group)
    const msgEl = anchorId ? document.querySelector(`[data-msg-id="${anchorId}"]`) : null

    if (msgEl) {
      const msgRect = msgEl.getBoundingClientRect()
      const msgTop = msgRect.top - gridRect.top
      const gap = msgTop - currentBottom
      margins[group.parent.id] = Math.max(0, gap)
    } else {
      margins[group.parent.id] = currentBottom === (execPanelRect.top - gridRect.top) ? 0 : 4
    }

    const nodeEl = document.querySelector(`[data-exec-id="${group.parent.id}"]`)
    const nodeHeight = nodeEl?.getBoundingClientRect().height ?? 50
    currentBottom += margins[group.parent.id] + nodeHeight
  }

  execMargins.value = margins
}

// Two-pass: first render with zero margins, then calculate and apply
onMounted(() => {
  nextTick(() => {
    // First pass: nodes rendered at zero margin, measure positions
    nextTick(() => {
      recalculatePositions()
    })
  })
})

// Recalculate when transcriptions toggle (changes message heights)
watch(expandedTranscriptions, () => {
  nextTick(() => recalculatePositions())
}, { deep: true })

// Recalculate on resize
if (typeof window !== 'undefined') {
  let resizeTimer: ReturnType<typeof setTimeout>
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer)
    resizeTimer = setTimeout(recalculatePositions, 150)
  })
}

// ── Highlighting ──

const highlightExecution = (exec: ExecutionItem) => {
  if (selectedExecId.value === exec.id) {
    selectedExecId.value = null
    highlightedInboundIds.value = new Set()
    highlightedOutboundId.value = null
    return
  }
  selectedExecId.value = exec.id
  highlightedInboundIds.value = new Set(exec.inbound_message_ids ?? [])
  highlightedOutboundId.value = exec.outbound_message_id

  // Scroll to first highlighted message
  const firstId = exec.inbound_message_ids?.[0] ?? exec.outbound_message_id
  if (firstId) {
    nextTick(() => {
      document.querySelector(`[data-msg-id="${firstId}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
    })
  }
}

const handleMessageClick = (msg: MessageItem) => {
  const execId = messageToExecMap.value.get(msg.id)
  if (!execId) return

  const exec = props.executions.find(e => e.id === execId)
  if (exec) highlightExecution(exec)
}

// ── Audio ──

const toggleAudio = (msgId: number, storageUrl: string | null) => {
  if (!storageUrl) return
  if (playingAudioId.value && playingAudioId.value !== msgId) {
    audioElements.value.get(playingAudioId.value)?.pause()
  }
  let audio = audioElements.value.get(msgId)
  if (!audio) {
    audio = new Audio(storageUrl)
    audio.addEventListener('timeupdate', () => {
      if (audio!.duration) { audioProgress.value[msgId] = (audio!.currentTime / audio!.duration) * 100 }
    })
    audio.addEventListener('ended', () => { playingAudioId.value = null; audioProgress.value[msgId] = 0 })
    audioElements.value.set(msgId, audio)
  }
  if (playingAudioId.value === msgId) { audio.pause(); playingAudioId.value = null }
  else { audio.play(); playingAudioId.value = msgId }
}

const toggleTranscription = (msgId: number) => {
  const set = new Set(expandedTranscriptions.value)
  if (set.has(msgId)) { set.delete(msgId) } else { set.add(msgId) }
  expandedTranscriptions.value = set
}

// ── Agent mappings ──

const AGENT_COLORS: Record<string, string> = {
  CustomerIdentifierAgent:  '#f59e0b',
  VehicleIdentifierAgent:   '#3b82f6',
  CoveragePreferenceAgent:  '#8b5cf6',
  QuoteAgent:               '#10b981',
  CheckoutAgent:            '#6366f1',
}
const agentColor = (name: string): string => AGENT_COLORS[name] ?? 'var(--text-3)'

const AGENT_SHORT: Record<string, string> = {
  CustomerIdentifierAgent: 'Cliente', VehicleIdentifierAgent: 'Vehículo',
  CoveragePreferenceAgent: 'Cobertura', QuoteAgent: 'Cotización', CheckoutAgent: 'Checkout',
}
const agentShortName = (name: string): string => AGENT_SHORT[name] ?? name

const flagLabel = (flag: string): string => ({
  customer_identified: 'cliente', vehicle_identified: 'vehículo', coverage_set: 'cobertura',
  quote_ready: 'cotización', checkout_done: 'checkout',
}[flag] ?? flag)

// ── Style helpers ──

const channelLabel = (ch: string): string => ({ whatsapp: 'WhatsApp', web: 'Web', telegram: 'Telegram' }[ch] ?? ch)
const channelStyle = (ch: string): string => ({
  whatsapp: 'background:#dcfce7; color:#16a34a;', web: 'background:#dbeafe; color:#1d4ed8;',
  telegram: 'background:#e0f2fe; color:#0369a1;',
}[ch] ?? 'background:var(--border-sub); color:var(--text-3);')

const statusStyle = (s: string): string => ({
  active: 'background:#dcfce7; color:#15803d;', completed: 'background:#dbeafe; color:#1d4ed8;',
  abandoned: 'background:#fee2e2; color:#991b1b;', anonymous: 'background:var(--border-sub); color:var(--text-3);',
  identified: 'background:#fef3c7; color:#92400e;',
}[s] ?? 'background:var(--border-sub); color:var(--text-3);')

const statusDot = (s: string): string => ({
  active: 'background:#16a349;', completed: 'background:#3b82f6;', abandoned: 'background:#dc2626;',
  anonymous: 'background:var(--text-3);', identified: 'background:#d97706;',
}[s] ?? 'background:var(--text-3);')

// ── Formatters ──

const formatDate = (iso: string | null): string => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-AR', { dateStyle: 'medium', timeStyle: 'short' })
}

const formatTime = (iso: string): string =>
  new Date(iso).toLocaleString('es-AR', { hour: '2-digit', minute: '2-digit', hour12: false })

const formatDuration = (ms: number): string => {
  if (!ms) return '—'
  return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`
}

const formatAudioDuration = (s: number): string => {
  const m = Math.floor(s / 60)
  const sec = s % 60
  return m + ':' + String(sec).padStart(2, '0')
}

const toolShortName = (name: string): string => ({
  CheckCoverageRuleTool:  'cobertura?',
  CheckoutTool:           'checkout',
  GetQuoteTool:           'cotización',
  IdentifyCustomerTool:   'cliente',
  IdentifyVehicleTool:    'vehículo',
  CoveragePreferenceTool: 'cobertura',
} as Record<string, string>)[name] ?? name
</script>
