<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-6xl mx-auto">

      <!-- Header -->
      <div class="flex items-center gap-3 mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Conversaciones
        </h1>
        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"
          style="background: #fff3cd; color: #92400e; border: 1px solid #fcd34d;">
          DEV TOOL
        </span>
      </div>

<!-- Filtros por salud -->
      <div class="mb-5">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">
            Filtrar:
          </span>
          <button
            v-for="flag in flagDefs"
            :key="flag.key"
            @click="toggleFlag(flag.key)"
            type="button"
            class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold transition-colors"
            :style="chipStyle(flag.key)"
          >
            <span>{{ flag.emoji }}</span>
            <span>{{ flag.label }}</span>
            <span class="px-1 rounded text-[10px]"
              :style="isFlagActive(flag.key)
                ? 'background:rgba(255,255,255,0.25); color:inherit;'
                : 'background:var(--border); color:var(--text-3);'">
              {{ flagCounts[flag.key] ?? 0 }}
            </span>
          </button>
          <button
            v-if="filters.flags.length"
            @click="clearFlags"
            type="button"
            class="ml-1 text-[11px] underline"
            style="color: var(--text-3);"
          >
            Limpiar
          </button>
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="!conversations.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No hay conversaciones registradas.
      </div>

      <template v-else>
        <!-- DESKTOP — tabla ≥ md -->
        <div class="hidden md:block rounded-[14px] overflow-hidden"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <table class="min-w-full text-sm">
            <thead>
              <tr style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Canal</th>
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Cliente</th>
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Estado del flujo</th>
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Mensajes</th>
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Última actividad</th>
                <th class="px-4 py-3 w-36"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="c in conversations.data" :key="c.id"
                style="border-bottom: 1px solid var(--border-sub);"
              >
                <!-- Canal -->
                <td class="px-4 py-3">
                  <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold"
                    :style="channelStyle(c.channel)">
                    {{ channelLabel(c.channel) }}
                  </span>
                </td>

                <!-- Cliente -->
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2 mb-0.5">
                    <p class="text-sm font-semibold" style="color: var(--text-1);">
                      {{ c.customer?.name ?? c.ext_username ?? 'Anónimo' }}
                    </p>
                    <span v-if="c.status === 'archived'"
                      class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold"
                      style="background: var(--border-sub); color: var(--text-3);">
                      Archivada
                    </span>
                  </div>
                  <p class="text-[11px] font-mono" style="color: var(--text-3);">
                    {{ c.customer?.phone ?? c.ext_user_id ?? '—' }}
                  </p>
                  <p class="text-[10px] font-mono mt-0.5" style="color: var(--text-3); opacity: 0.6;">#{{ c.id }}</p>
                </td>

                <!-- Estado del flujo AI -->
                <td class="px-4 py-3">
                  <div class="flex flex-wrap gap-1">
                    <span v-for="(done, flag) in c.ai_state" :key="flag"
                      class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
                      :style="done
                        ? 'background:var(--badge-ok-bg); color:var(--badge-ok-txt);'
                        : 'background:var(--border-sub); color:var(--text-3);'">
                      <span class="w-1 h-1 rounded-full flex-shrink-0"
                        :style="done ? 'background:var(--dot-ok);' : 'background:var(--text-3);'"></span>
                      {{ flagLabel(flag) }}
                    </span>
                  </div>
                </td>

                <!-- Mensajes -->
                <td class="px-4 py-3 text-sm text-center" style="color: var(--text-2);">
                  {{ c.messages_count }}
                </td>

                <!-- Última actividad -->
                <td class="px-4 py-3 text-[11px] whitespace-nowrap" style="color: var(--text-3);">
                  {{ formatDate(c.last_message_at ?? c.created_at) }}
                </td>

                <!-- Acciones -->
                <td class="px-4 py-3">
                  <div class="flex items-center justify-end gap-2">
                    <a
                      :href="`/admin/conversations/${c.id}`"
                      class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap transition-colors"
                      style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);"
                    >
                      Ver →
                    </a>
                    <form
                      v-if="c.status !== 'archived'"
                      :action="`/admin/conversations/${c.id}/reset`"
                      method="POST"
                      @submit.prevent="confirmReset($event, c)"
                    >
                      <input type="hidden" name="_token" :value="csrfToken" />
                      <button
                        type="submit"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap transition-colors"
                        style="background: var(--badge-danger-bg); color: var(--badge-danger-txt); border: 1px solid transparent;"
                        @mouseenter="$event.currentTarget.style.opacity = '0.8'"
                        @mouseleave="$event.currentTarget.style.opacity = '1'"
                      >
                        ↺ Archivar
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MOBILE — cards < md -->
        <div class="md:hidden space-y-2">
          <div v-for="c in conversations.data" :key="c.id"
            class="rounded-[14px] p-4"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

            <div class="flex items-start justify-between gap-2 mb-2">
              <div class="min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold"
                    :style="channelStyle(c.channel)">
                    {{ channelLabel(c.channel) }}
                  </span>
                  <span class="text-[10px]" style="color: var(--text-3);">{{ c.messages_count }} msgs</span>
                </div>
                <div class="flex items-center gap-2">
                  <p class="text-sm font-semibold truncate" style="color: var(--text-1);">
                    {{ c.customer?.name ?? c.ext_username ?? 'Anónimo' }}
                  </p>
                  <span v-if="c.status === 'archived'"
                    class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold flex-shrink-0"
                    style="background: var(--border-sub); color: var(--text-3);">
                    Archivada
                  </span>
                </div>
                <p class="text-[11px] font-mono mt-0.5" style="color: var(--text-3);">
                  {{ c.customer?.phone ?? c.ext_user_id ?? '—' }}
                </p>
                <p class="text-[10px] font-mono mt-0.5" style="color: var(--text-3); opacity: 0.6;">#{{ c.id }}</p>
              </div>
            </div>

            <!-- AI state mini-flags -->
            <div class="flex flex-wrap gap-1 mb-3">
              <span v-for="(done, flag) in c.ai_state" :key="flag"
                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
                :style="done
                  ? 'background:var(--badge-ok-bg); color:var(--badge-ok-txt);'
                  : 'background:var(--border-sub); color:var(--text-3);'">
                {{ flagLabel(flag) }}
              </span>
            </div>

            <div class="flex items-center justify-between gap-2">
              <span class="text-[11px]" style="color: var(--text-3);">
                {{ formatDate(c.last_message_at ?? c.created_at) }}
              </span>
              <div class="flex items-center gap-2">
                <a
                  :href="`/admin/conversations/${c.id}`"
                  class="inline-flex items-center px-2.5 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap"
                  style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);"
                >
                  Ver →
                </a>
                <form
                  v-if="c.status !== 'archived'"
                  :action="`/admin/conversations/${c.id}/reset`"
                  method="POST"
                  @submit.prevent="confirmReset($event, c)"
                >
                  <input type="hidden" name="_token" :value="csrfToken" />
                  <button
                    type="submit"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-[11px] font-semibold whitespace-nowrap"
                    style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
                  >
                    ↺ Archivar
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- Pagination -->
        <div v-if="conversations.last_page > 1" class="mt-4 flex items-center justify-center gap-2 text-sm">
          <a
            v-if="conversations.prev_page_url"
            :href="conversations.prev_page_url"
            class="px-3 py-1.5 rounded-lg text-[12px]"
            style="background: var(--bg-card); border: 1px solid var(--border); color: var(--text-2);"
          >← Anterior</a>
          <span class="text-[12px]" style="color: var(--text-3);">
            Pág. {{ conversations.current_page }} / {{ conversations.last_page }}
          </span>
          <a
            v-if="conversations.next_page_url"
            :href="conversations.next_page_url"
            class="px-3 py-1.5 rounded-lg text-[12px]"
            style="background: var(--bg-card); border: 1px solid var(--border); color: var(--text-2);"
          >Siguiente →</a>
        </div>
      </template>

    </div>
  </div>

  <!-- Modal de confirmación de archivado -->
  <Transition name="fade">
    <div v-if="archiveTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="archiveTarget = null" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">
          ¿Archivar conversación?
        </h3>
        <p class="text-sm font-medium mb-3" style="color: var(--text-2);">
          {{ archiveTarget.identifier }}
        </p>
        <ul class="text-sm space-y-1.5 mb-5">
          <li style="color: var(--badge-ok-txt);">✓ Mensajes, cotizaciones y contexto del LLM conservados</li>
          <li style="color: var(--badge-ok-txt);">✓ Conversación accesible para auditoría</li>
          <li style="color: var(--badge-danger-txt);">✗ El próximo mensaje inicia el flujo desde cero</li>
        </ul>
        <div class="flex justify-end gap-2">
          <button
            @click="archiveTarget = null"
            class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);"
          >
            Cancelar
          </button>
          <button
            @click="submitArchive"
            class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors"
            style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
          >
            Archivar
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

type Tier1Flag = 'loops' | 'stuck' | 'tool_errors' | 'abandoned' | 'long'
type Tier2Flag = 'user_frustrated' | 'agent_confused' | 'semantic_loop' | 'context_loss' | 'hallucination' | 'incorrect_answer'
type HealthFlag = Tier1Flag | Tier2Flag

const props = defineProps<{
  conversations: {
    data: Array<{
      id: number
      external_id: string
      ext_user_id: string | null
      ext_username: string | null
      customer: { id: number; name: string | null; phone: string | null } | null
      channel: string
      status: string
      ai_state: Record<string, boolean>
      flags: Partial<Record<HealthFlag, boolean>>
      messages_count: number
      last_message_at: string | null
      created_at: string
    }>
    current_page: number
    last_page: number
    prev_page_url: string | null
    next_page_url: string | null
  }
  filters: { flags: HealthFlag[] }
  flag_counts: Record<HealthFlag, number>
}>()

const flagCounts = props.flag_counts

type FlagDef = { key: HealthFlag; label: string; emoji: string; tier: 1 | 2 }

const flagDefs: FlagDef[] = [
  { key: 'loops',             label: 'Loops',        emoji: '🔁', tier: 1 },
  { key: 'stuck',             label: 'Estancadas',   emoji: '⏳', tier: 1 },
  { key: 'tool_errors',       label: 'Tool errors',  emoji: '⚠️', tier: 1 },
  { key: 'abandoned',         label: 'Abandonadas',  emoji: '🕒', tier: 1 },
  { key: 'long',              label: 'Largas',       emoji: '📜', tier: 1 },
  { key: 'user_frustrated',   label: 'Frustrado',    emoji: '😤', tier: 2 },
  { key: 'agent_confused',    label: 'Confundido',   emoji: '🤔', tier: 2 },
  { key: 'semantic_loop',     label: 'Loop semántico', emoji: '🔂', tier: 2 },
  { key: 'context_loss',      label: 'Perdió contexto', emoji: '🧠', tier: 2 },
  { key: 'hallucination',     label: 'Alucinación',  emoji: '👻', tier: 2 },
  { key: 'incorrect_answer',  label: 'Resp. incorrecta', emoji: '❌', tier: 2 },
]

const isFlagActive = (flag: HealthFlag) => props.filters.flags.includes(flag)

const chipStyle = (flag: HealthFlag) => {
  const def = flagDefs.find((f) => f.key === flag)
  const active = isFlagActive(flag)
  if (active) {
    return 'background:var(--badge-danger-bg); color:var(--badge-danger-txt); border:1px solid var(--badge-danger-txt);'
  }
  // Tier 2 = outline (bg transparente) para distinguir de Tier 1 (filled)
  if (def?.tier === 2) {
    return 'background:transparent; color:var(--text-2); border:1px dashed var(--border);'
  }
  return 'background:var(--bg-card); color:var(--text-2); border:1px solid var(--border);'
}

const toggleFlag = (flag: HealthFlag) => {
  const next = isFlagActive(flag)
    ? props.filters.flags.filter((f) => f !== flag)
    : [...props.filters.flags, flag]
  router.get('', { flags: next }, { preserveState: true, preserveScroll: true, replace: true })
}

const clearFlags = () => {
  router.get('', {}, { preserveState: true, preserveScroll: true, replace: true })
}

const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''

interface ArchiveTarget {
  form: HTMLFormElement
  identifier: string
}

const archiveTarget = ref<ArchiveTarget | null>(null)

const confirmReset = (e: Event, c: { ext_user_id: string | null; ext_username: string | null; customer: { name: string | null } | null }) => {
  archiveTarget.value = {
    form: e.target as HTMLFormElement,
    identifier: c.customer?.name ?? c.ext_username ?? c.ext_user_id ?? 'este usuario',
  }
}

const submitArchive = () => {
  archiveTarget.value?.form.submit()
  archiveTarget.value = null
}

const channelLabel = (ch: string) => ({
  whatsapp: 'WhatsApp',
  web: 'Web',
  telegram: 'Telegram',
}[ch] ?? ch)

const channelStyle = (ch: string) => ({
  whatsapp: 'background:#dcfce7; color:#16a34a;',
  web:      'background:#dbeafe; color:#1d4ed8;',
  telegram: 'background:#e0f2fe; color:#0369a1;',
}[ch] ?? 'background:var(--border-sub); color:var(--text-3);')

const flagLabel = (flag: string) => ({
  customer_identified: 'cliente',
  vehicle_identified:  'vehículo',
  coverage_set:        'cobertura',
  quote_ready:         'cotización',
  checkout_done:       'checkout',
}[flag] ?? flag)

const formatDate = (iso: string | null) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
