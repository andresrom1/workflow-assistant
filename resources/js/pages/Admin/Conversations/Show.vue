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
        <div class="flex items-center gap-2">
          <span v-if="conversation.ai_paused"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold"
            style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);">
            ⏸ IA pausada
          </span>
          <span class="self-start sm:self-auto inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold"
            :style="statusStyle(conversation.status)">
            <span class="w-1.5 h-1.5 rounded-full" :style="statusDot(conversation.status)"></span>
            {{ conversation.status }}
          </span>
          <button type="button" @click="takeoverModalOpen = true"
            class="px-3 py-1.5 rounded-full text-xs font-semibold transition-colors"
            :style="conversation.ai_paused
              ? 'background: var(--accent-100); color: var(--dot-accent);'
              : 'background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);'">
            {{ conversation.ai_paused ? 'Devolver a la IA' : 'Tomar control' }}
          </button>
        </div>
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

      <!-- Análisis semántico (Tier 2) -->
      <div v-if="semantic_analysis_enabled || conversation.semantic_analysis"
        class="rounded-[14px] overflow-hidden"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <button type="button" @click="semanticOpen = !semanticOpen"
          class="w-full flex items-center justify-between px-5 py-3 border-b"
          :style="`background: var(--bg-raised); border-color: var(--border); ${semanticOpen ? '' : 'border-bottom-color: transparent;'}`">
          <div class="flex items-center gap-2">
            <span class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">
              🤖 Análisis IA
            </span>
            <span v-if="positiveSemanticFlags.length"
              class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold"
              style="background: #fee2e2; color: #991b1b;">
              {{ positiveSemanticFlags.length }} detectado{{ positiveSemanticFlags.length === 1 ? '' : 's' }}
            </span>
            <span v-else-if="conversation.semantic_analysis"
              class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold"
              style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);">
              Sin hallazgos
            </span>
            <span v-else class="text-[11px]" style="color: var(--text-3);">Sin análisis aún</span>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="conversation.last_semantic_analysis_at" class="text-[10px]" style="color: var(--text-3);">
              {{ formatDate(conversation.last_semantic_analysis_at) }}
            </span>
            <span class="text-[10px]" style="color: var(--text-3);">{{ semanticOpen ? '▾' : '▸' }}</span>
          </div>
        </button>

        <div v-if="semanticOpen" class="px-5 py-4 space-y-3">
          <div v-if="!conversation.semantic_analysis" class="text-sm" style="color: var(--text-3);">
            Esta conversación todavía no fue analizada. Usá el botón de abajo para disparar un análisis manual.
          </div>

          <div v-else>
            <!-- Flags detectados con reasoning -->
            <div v-if="positiveSemanticFlags.length" class="space-y-2 mb-3">
              <div v-for="flag in positiveSemanticFlags" :key="flag"
                class="rounded-[10px] p-3"
                style="background: var(--bg-raised); border: 1px solid var(--border);">
                <div class="flex items-center gap-2 mb-1">
                  <span>{{ semanticFlagEmoji(flag) }}</span>
                  <span class="text-[12px] font-semibold" style="color: var(--text-1);">
                    {{ semanticFlagLabel(flag) }}
                  </span>
                </div>
                <p v-if="conversation.semantic_analysis.reasoning[flag]"
                  class="text-[12px] leading-relaxed pl-6" style="color: var(--text-2);">
                  {{ conversation.semantic_analysis.reasoning[flag] }}
                </p>
              </div>
            </div>

            <div v-else class="text-sm mb-3" style="color: var(--text-2);">
              El análisis no detectó ningún problema semántico en los últimos
              {{ conversation.semantic_analysis.window_turns }} turnos.
            </div>

            <div class="text-[10px]" style="color: var(--text-3);">
              Ventana: {{ conversation.semantic_analysis.window_turns }} turnos ·
              Mensajes analizados: {{ conversation.semantic_analysis.messages_analyzed }}
            </div>
          </div>

          <div v-if="semantic_analysis_enabled" class="pt-2">
            <button type="button" @click="reanalyzeSemantics"
              :disabled="reanalyzing"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold transition-opacity"
              :style="reanalyzing
                ? 'background: var(--border-sub); color: var(--text-3); cursor: not-allowed;'
                : 'background: var(--accent-100); color: var(--dot-accent); border: 1px solid var(--border);'">
              {{ reanalyzing ? 'Encolando…' : '↻ Re-analizar ahora' }}
            </button>
            <span class="text-[10px] ml-2" style="color: var(--text-3);">
              Bypasa el throttle · puede tardar unos segundos en aparecer tras refresh
            </span>
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
                :style="msg.agent_name === 'human'
                  ? 'background: var(--bg-card); border: 1.5px solid var(--accent-600);'
                  : 'background: var(--accent-100); border: 1px solid var(--accent-200, var(--border));'">
                <div v-if="msg.agent_name === 'human'" class="flex items-center gap-1.5 mb-1">
                  <span class="w-2 h-2 rounded-full flex-shrink-0" style="background: var(--accent-600);"></span>
                  <p class="text-[10px] font-semibold" style="color: var(--accent-600);">Asesor</p>
                </div>
                <div v-else-if="msg.agent_name" class="flex items-center gap-1.5 mb-1">
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

          <!-- Composer manual — solo con IA pausada -->
          <div v-if="conversation.ai_paused" class="p-3 border-t" style="border-color: var(--border); background: var(--bg-raised);">
            <form @submit.prevent="sendManualMessage" class="flex items-end gap-2">
              <textarea v-model="manualMessageForm.text" rows="2" placeholder="Escribí como asesor…"
                class="flex-1 text-sm px-3 py-2 rounded-lg outline-none resize-none"
                style="background: var(--bg-card); color: var(--text-1); border: 1px solid var(--border);"></textarea>
              <button type="submit" :disabled="manualMessageForm.processing || !manualMessageForm.text.trim()"
                class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors disabled:opacity-40"
                style="background: var(--accent-600); color: white;">
                Enviar
              </button>
            </form>
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
                    <button
                      @click.stop="viewPrompt(group.parent)"
                      class="ml-1 text-[10px] px-1 py-0 rounded-[3px] transition-colors inline-flex items-center gap-0.5"
                      :title="group.parent.agent_prompt_id ? 'Ver el prompt que corrió en este turn' : 'Sin FK — muestra la versión activa actual'"
                      style="color: var(--text-3); border: 1px solid var(--border);"
                      @mouseenter="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-1)'"
                      @mouseleave="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-3)'"
                    >
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M9 8h6m-7 12h8a2 2 0 002-2V6a2 2 0 00-2-2H8a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                      </svg>
                      <span v-if="!group.parent.agent_prompt_id">⚠</span>
                    </button>
                    <Link
                      v-if="canReevaluate(group.parent.agent_name)"
                      :href="`/admin/studio/reevaluate/${group.parent.id}`"
                      class="text-[10px] px-1 py-0 rounded-[3px] transition-colors inline-flex items-center gap-0.5"
                      title="Reevaluar este turn con un prompt editado"
                      style="color: var(--text-3); border: 1px solid var(--border);"
                    >
                      🧪 reevaluar
                    </Link>
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

                  <!-- Annotation controls (parent) -->
                  <div class="mt-1 flex items-center gap-1" @click.stop>
                    <button type="button"
                      @click="submitAnnotation(group.parent, true)"
                      :title="myAnnotation(group.parent)?.verdict === true ? 'Quitar 👍' : 'Marcar 👍'"
                      class="w-5 h-5 rounded flex items-center justify-center transition-opacity"
                      :class="myAnnotation(group.parent)?.verdict === true ? '' : 'opacity-40 hover:opacity-100'"
                      :style="myAnnotation(group.parent)?.verdict === true
                        ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                        : 'color: var(--text-3);'">
                      <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke="currentColor" class="size-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.633 10.25c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 0 1 2.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 0 0 .322-1.672V2.75a.75.75 0 0 1 .75-.75 2.25 2.25 0 0 1 2.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282m0 0h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 0 1-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 0 0-1.423-.23H5.904m10.598-9.75H14.25M5.904 18.5c.083.205.173.405.27.602.197.4-.078.898-.523.898h-.908c-.889 0-1.713-.518-1.972-1.368a12 12 0 0 1-.521-3.507c0-1.553.295-3.036.831-4.398C3.387 9.953 4.167 9.5 5 9.5h1.053c.472 0 .745.556.5.96a8.958 8.958 0 0 0-1.302 4.665c0 1.194.232 2.333.654 3.375Z" />
                      </svg>
                    </button>
                    <button type="button"
                      @click="submitAnnotation(group.parent, false)"
                      :title="myAnnotation(group.parent)?.verdict === false ? 'Editar 👎' : 'Marcar 👎'"
                      class="w-5 h-5 rounded flex items-center justify-center transition-opacity"
                      :class="myAnnotation(group.parent)?.verdict === false ? '' : 'opacity-40 hover:opacity-100'"
                      :style="myAnnotation(group.parent)?.verdict === false
                        ? 'background: #fee2e2; color: #991b1b;'
                        : 'color: var(--text-3);'">
                      <!-- <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg> -->
                      <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke="currentColor" class="size-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.498 15.25H4.372c-1.026 0-1.945-.694-2.054-1.715a12.137 12.137 0 0 1-.068-1.285c0-2.848.992-5.464 2.649-7.521C5.287 4.247 5.886 4 6.504 4h4.016a4.5 4.5 0 0 1 1.423.23l3.114 1.04a4.5 4.5 0 0 0 1.423.23h1.294M7.498 15.25c.618 0 .991.724.725 1.282A7.471 7.471 0 0 0 7.5 19.75 2.25 2.25 0 0 0 9.75 22a.75.75 0 0 0 .75-.75v-.633c0-.573.11-1.14.322-1.672.304-.76.93-1.33 1.653-1.715a9.04 9.04 0 0 0 2.86-2.4c.498-.634 1.226-1.08 2.032-1.08h.384m-10.253 1.5H9.7m8.075-9.75c.01.05.027.1.05.148.593 1.2.925 2.55.925 3.977 0 1.487-.36 2.89-.999 4.125m.023-8.25c-.076-.365.183-.75.575-.75h.908c.889 0 1.713.518 1.972 1.368.339 1.11.521 2.287.521 3.507 0 1.553-.295 3.036-.831 4.398-.306.774-1.086 1.227-1.918 1.227h-1.053c-.472 0-.745-.556-.5-.96a8.95 8.95 0 0 0 .303-.54" />
                      </svg>

                    </button>
                    <button v-if="myAnnotation(group.parent)" type="button"
                      @click="clearAnnotation(group.parent)"
                      title="Quitar anotación"
                      class="text-[9px] opacity-40 hover:opacity-100 ml-1"
                      style="color: var(--text-3);">limpiar
                    </button>
                    <span v-for="a in otherAnnotations(group.parent)" :key="a.id"
                      :title="`${a.user_name ?? 'otro admin'}${a.note ? ': ' + a.note : ''}`"
                      class="text-[10px] ml-0.5"
                      :style="a.verdict
                        ? 'color: var(--badge-ok-txt);'
                        : 'color: #991b1b;'">
                      {{ a.verdict ? '👍' : '👎' }}
                    </span>
                  </div>

                  <!-- Negative annotation popover (parent) -->
                  <div v-if="openAnnotationId === group.parent.id" @click.stop
                    class="mt-1.5 p-2 rounded-[8px]"
                    style="background: var(--bg-raised); border: 1px solid var(--border);">
                    <textarea v-model="annotationNote"
                      placeholder="¿Qué estuvo mal en este turn?"
                      rows="2"
                      class="w-full text-[11px] px-1.5 py-1 rounded outline-none"
                      style="background: var(--bg-card); color: var(--text-1); border: 1px solid var(--border);"></textarea>
                    <div class="flex gap-1.5 mt-1.5">
                      <button type="button"
                        @click="saveNegativeAnnotation(group.parent)"
                        class="text-[10px] px-2 py-0.5 rounded font-medium"
                        style="background: var(--accent-100); color: var(--dot-accent);">Guardar</button>
                      <button type="button"
                        @click="openAnnotationId = null"
                        class="text-[10px] px-2 py-0.5 rounded"
                        style="color: var(--text-3);">Cancelar</button>
                    </div>
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
                    <button
                      @click.stop="viewPrompt(group.child)"
                      class="ml-1 text-[10px] px-1 py-0 rounded-[3px] transition-colors inline-flex items-center gap-0.5"
                      :title="group.child.agent_prompt_id ? 'Ver el prompt que corrió en este turn' : 'Sin FK — muestra la versión activa actual'"
                      style="color: var(--text-3); border: 1px solid var(--border);"
                      @mouseenter="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-1)'"
                      @mouseleave="e => (e.currentTarget as HTMLElement).style.color = 'var(--text-3)'"
                    >
                      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M9 8h6m-7 12h8a2 2 0 002-2V6a2 2 0 00-2-2H8a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                      </svg>
                      <span v-if="!group.child.agent_prompt_id">⚠</span>
                    </button>
                    <Link
                      v-if="canReevaluate(group.child.agent_name)"
                      :href="`/admin/studio/reevaluate/${group.child.id}`"
                      class="text-[10px] px-1 py-0 rounded-[3px] transition-colors inline-flex items-center gap-0.5"
                      title="Reevaluar este turn con un prompt editado"
                      style="color: var(--text-3); border: 1px solid var(--border);"
                    >
                      🧪 reevaluar
                    </Link>
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

                  <!-- Annotation controls (child) -->
                  <div class="mt-1 flex items-center gap-1" @click.stop>
                    <button type="button"
                      @click="submitAnnotation(group.child, true)"
                      :title="myAnnotation(group.child)?.verdict === true ? 'Quitar 👍' : 'Marcar 👍'"
                      class="w-5 h-5 rounded flex items-center justify-center transition-opacity"
                      :class="myAnnotation(group.child)?.verdict === true ? '' : 'opacity-40 hover:opacity-100'"
                      :style="myAnnotation(group.child)?.verdict === true
                        ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
                        : 'color: var(--text-3);'">
                      <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </button>
                    <button type="button"
                      @click="submitAnnotation(group.child, false)"
                      :title="myAnnotation(group.child)?.verdict === false ? 'Editar 👎' : 'Marcar 👎'"
                      class="w-5 h-5 rounded flex items-center justify-center transition-opacity"
                      :class="myAnnotation(group.child)?.verdict === false ? '' : 'opacity-40 hover:opacity-100'"
                      :style="myAnnotation(group.child)?.verdict === false
                        ? 'background: #fee2e2; color: #991b1b;'
                        : 'color: var(--text-3);'">
                      <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                    <button v-if="myAnnotation(group.child)" type="button"
                      @click="clearAnnotation(group.child)"
                      title="Quitar anotación"
                      class="text-[9px] opacity-40 hover:opacity-100 ml-1"
                      style="color: var(--text-3);">limpiar</button>
                    <span v-for="a in otherAnnotations(group.child)" :key="a.id"
                      :title="`${a.user_name ?? 'otro admin'}${a.note ? ': ' + a.note : ''}`"
                      class="text-[10px] ml-0.5"
                      :style="a.verdict
                        ? 'color: var(--badge-ok-txt);'
                        : 'color: #991b1b;'">
                      {{ a.verdict ? '👍' : '👎' }}
                    </span>
                  </div>

                  <!-- Negative annotation popover (child) -->
                  <div v-if="openAnnotationId === group.child.id" @click.stop
                    class="mt-1.5 p-2 rounded-[8px]"
                    style="background: var(--bg-raised); border: 1px solid var(--border);">
                    <textarea v-model="annotationNote"
                      placeholder="¿Qué estuvo mal en este turn?"
                      rows="2"
                      class="w-full text-[11px] px-1.5 py-1 rounded outline-none"
                      style="background: var(--bg-card); color: var(--text-1); border: 1px solid var(--border);"></textarea>
                    <div class="flex gap-1.5 mt-1.5">
                      <button type="button"
                        @click="saveNegativeAnnotation(group.child)"
                        class="text-[10px] px-2 py-0.5 rounded font-medium"
                        style="background: var(--accent-100); color: var(--dot-accent);">Guardar</button>
                      <button type="button"
                        @click="openAnnotationId = null"
                        class="text-[10px] px-2 py-0.5 rounded"
                        style="color: var(--text-3);">Cancelar</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal de confirmación de takeover -->
  <Transition name="fade">
    <div v-if="takeoverModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="takeoverModalOpen = false" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">
          {{ conversation.ai_paused ? '¿Devolver el control a la IA?' : '¿Tomar control de la conversación?' }}
        </h3>
        <p class="text-sm mb-5" style="color: var(--text-2);">
          <template v-if="conversation.ai_paused">
            La IA vuelve a responder los próximos mensajes del cliente. Va a recibir un resumen de lo que se conversó durante la pausa.
          </template>
          <template v-else>
            La IA deja de responder al cliente hasta que reactives el flujo. Vas a poder escribirle vos directamente desde este panel.
          </template>
        </p>
        <div class="flex justify-end gap-2">
          <button
            @click="takeoverModalOpen = false"
            class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);"
          >
            Cancelar
          </button>
          <button
            @click="confirmToggleTakeover"
            class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors"
            :style="conversation.ai_paused
              ? 'background: var(--accent-100); color: var(--dot-accent);'
              : 'background: var(--badge-danger-bg); color: var(--badge-danger-txt);'"
          >
            {{ conversation.ai_paused ? 'Devolver a la IA' : 'Tomar control' }}
          </button>
        </div>
      </div>
    </div>
  </Transition>

  <PromptSlideOver
    :open="promptSlideOverOpen"
    :prompt-id="promptSlideOverId"
    :fallback-warning="promptSlideOverFallback"
    @close="closePromptSlideOver"
  />
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { Link, router, useForm, usePoll } from '@inertiajs/vue3'
import AgentFlowGraph from '@/components/AgentFlowGraph.vue'
import BackLink from '@/components/UI/BackLink.vue'
import PromptSlideOver from '@/components/PromptSlideOver.vue'

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

interface AnnotationItem {
  id: number
  verdict: boolean
  note: string | null
  user_id: number
  user_name: string | null
  is_mine: boolean
  updated_at: string
}

interface ExecutionItem {
  id: number
  agent_name: string
  agent_prompt_id: number | null
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
  annotations: AnnotationItem[]
}

interface ExecutionGroup {
  parent: ExecutionItem
  child: ExecutionItem | null
}

type SemanticFlag = 'user_frustrated' | 'agent_confused' | 'semantic_loop' | 'context_loss' | 'hallucination' | 'incorrect_answer'

interface SemanticAnalysis {
  flags: Partial<Record<SemanticFlag, boolean>>
  reasoning: Partial<Record<SemanticFlag, string>>
  analyzed_at: string
  window_turns: number
  messages_analyzed: number
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
    flags: Partial<Record<string, boolean>>
    semantic_analysis: SemanticAnalysis | null
    last_semantic_analysis_at: string | null
    created_at: string
    last_message_at: string
    ai_paused: boolean
  }
  semantic_analysis_enabled: boolean
  active_prompt_ids_by_agent: Record<string, number>
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

// ── Annotations ──

const openAnnotationId = ref<number | null>(null)
// ── Prompt slide-over ──
const promptSlideOverOpen = ref(false)
const promptSlideOverId = ref<number | null>(null)
const promptSlideOverFallback = ref(false)

const viewPrompt = (exec: ExecutionItem) => {
  if (exec.agent_prompt_id) {
    promptSlideOverId.value = exec.agent_prompt_id
    promptSlideOverFallback.value = false
  } else {
    // Log previo a Fase 5 — sin FK. Fallback a la activa actual del agente.
    const fallbackId = props.active_prompt_ids_by_agent[exec.agent_name]
    if (!fallbackId) return
    promptSlideOverId.value = fallbackId
    promptSlideOverFallback.value = true
  }
  promptSlideOverOpen.value = true
}

const closePromptSlideOver = () => {
  promptSlideOverOpen.value = false
}

const REEVALUABLE_AGENTS = new Set([
  'CustomerIdentifierAgent',
  'VehicleIdentifierAgent',
  'CoveragePreferenceAgent',
  'QuoteAgent',
  'CheckoutAgent',
])
const canReevaluate = (agentName: string): boolean => REEVALUABLE_AGENTS.has(agentName)

const annotationNote = ref('')

const myAnnotation = (exec: ExecutionItem): AnnotationItem | null =>
  exec.annotations?.find(a => a.is_mine) ?? null

const otherAnnotations = (exec: ExecutionItem): AnnotationItem[] =>
  exec.annotations?.filter(a => !a.is_mine) ?? []

const submitAnnotation = (exec: ExecutionItem, verdict: boolean) => {
  const mine = myAnnotation(exec)

  if (verdict === true) {
    // 👍 — upsert sin popover
    router.post(`/admin/execution-logs/${exec.id}/annotations`,
      { verdict: true, note: mine?.note ?? null },
      { preserveScroll: true, preserveState: true, only: ['executions'] })
    openAnnotationId.value = null
    return
  }

  // 👎 — abrir popover para escribir nota
  if (openAnnotationId.value === exec.id) {
    openAnnotationId.value = null
    return
  }
  annotationNote.value = mine?.verdict === false ? (mine.note ?? '') : ''
  openAnnotationId.value = exec.id
}

const saveNegativeAnnotation = (exec: ExecutionItem) => {
  router.post(`/admin/execution-logs/${exec.id}/annotations`,
    { verdict: false, note: annotationNote.value.trim() || null },
    { preserveScroll: true, preserveState: true, only: ['executions'] })
  openAnnotationId.value = null
}

const clearAnnotation = (exec: ExecutionItem) => {
  router.delete(`/admin/execution-logs/${exec.id}/annotations`,
    { preserveScroll: true, preserveState: true, only: ['executions'] })
  openAnnotationId.value = null
}

// ── Semantic analysis panel (Tier 2) ──

const SEMANTIC_FLAGS: SemanticFlag[] = [
  'user_frustrated', 'agent_confused', 'semantic_loop',
  'context_loss', 'hallucination', 'incorrect_answer',
]

const SEMANTIC_META: Record<SemanticFlag, { label: string; emoji: string }> = {
  user_frustrated:   { label: 'Usuario frustrado',        emoji: '😤' },
  agent_confused:    { label: 'Agente confundido',        emoji: '🤔' },
  semantic_loop:     { label: 'Loop semántico',           emoji: '🔂' },
  context_loss:      { label: 'Pérdida de contexto',      emoji: '🧠' },
  hallucination:     { label: 'Alucinación',              emoji: '👻' },
  incorrect_answer:  { label: 'Respuesta incorrecta',     emoji: '❌' },
}

const semanticFlagLabel = (f: SemanticFlag): string => SEMANTIC_META[f].label
const semanticFlagEmoji = (f: SemanticFlag): string => SEMANTIC_META[f].emoji

const positiveSemanticFlags = computed<SemanticFlag[]>(() => {
  const sa = props.conversation.semantic_analysis
  if (!sa) return []
  return SEMANTIC_FLAGS.filter((f) => sa.flags[f] === true)
})

const semanticOpen = ref<boolean>(!!props.conversation.semantic_analysis)
const reanalyzing = ref(false)

const reanalyzeSemantics = () => {
  reanalyzing.value = true
  router.post(
    `/admin/conversations/${props.conversation.id}/analyze-semantics`,
    {},
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => { reanalyzing.value = false },
    },
  )
}

// ── Takeover humano ──

const takeoverModalOpen = ref(false)

const confirmToggleTakeover = () => {
  const action = props.conversation.ai_paused ? 'resume-ai' : 'pause-ai'
  router.post(`/admin/conversations/${props.conversation.id}/${action}`, {}, {
    preserveScroll: true,
    preserveState: true,
    only: ['conversation'],
  })
  takeoverModalOpen.value = false
}

const manualMessageForm = useForm({ text: '' })

const sendManualMessage = () => {
  manualMessageForm.post(`/admin/conversations/${props.conversation.id}/send-message`, {
    preserveScroll: true,
    only: ['messages', 'conversation'],
    onSuccess: () => { manualMessageForm.reset('text') },
  })
}

// ── Refresh automático mientras la conversación está viva ──

const { start: startPoll, stop: stopPoll } = usePoll(
  5000,
  { only: ['messages', 'executions', 'conversation'] },
  { autoStart: false },
)

watch(
  () => props.conversation.status !== 'completed' && props.conversation.status !== 'archived',
  (isLive) => { isLive ? startPoll() : stopPoll() },
  { immediate: true },
)
</script>
