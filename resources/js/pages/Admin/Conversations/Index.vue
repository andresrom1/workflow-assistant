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

      <!-- Flash message -->
      <div v-if="$page.props.flash?.success"
        class="flex items-center gap-2.5 px-4 py-3 rounded-[10px] text-sm mb-6"
        style="background:#dcfce7; border-left: 3px solid #16a349; color:#15803d;">
        <span class="font-semibold">✓</span>
        {{ $page.props.flash.success }}
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
                <th class="px-4 py-3 w-28"></th>
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
                  <p class="text-sm font-semibold" style="color: var(--text-1);">
                    {{ c.customer?.name ?? c.ext_username ?? 'Anónimo' }}
                  </p>
                  <p class="text-[11px] mt-0.5 font-mono" style="color: var(--text-3);">
                    {{ c.customer?.phone ?? c.ext_user_id ?? '—' }}
                  </p>
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

                <!-- Acción: Resetear -->
                <td class="px-4 py-3 text-right">
                  <form
                    :action="`/admin/conversations/${c.id}/reset`"
                    method="POST"
                    @submit.prevent="confirmReset($event, c)"
                  >
                    <input type="hidden" name="_token" :value="csrfToken" />
                    <button
                      type="submit"
                      class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[11px] font-semibold transition-colors"
                      style="background: var(--badge-danger-bg); color: var(--badge-danger-txt); border: 1px solid transparent;"
                      @mouseenter="$event.currentTarget.style.opacity = '0.8'"
                      @mouseleave="$event.currentTarget.style.opacity = '1'"
                    >
                      ↺ Resetear
                    </button>
                  </form>
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
                <p class="text-sm font-semibold truncate" style="color: var(--text-1);">
                  {{ c.customer?.name ?? c.ext_username ?? 'Anónimo' }}
                </p>
                <p class="text-[11px] font-mono mt-0.5" style="color: var(--text-3);">
                  {{ c.customer?.phone ?? c.ext_user_id ?? '—' }}
                </p>
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

            <div class="flex items-center justify-between">
              <span class="text-[11px]" style="color: var(--text-3);">
                {{ formatDate(c.last_message_at ?? c.created_at) }}
              </span>
              <form
                :action="`/admin/conversations/${c.id}/reset`"
                method="POST"
                @submit.prevent="confirmReset($event, c)"
              >
                <input type="hidden" name="_token" :value="csrfToken" />
                <button
                  type="submit"
                  class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-[11px] font-semibold"
                  style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
                >
                  ↺ Resetear
                </button>
              </form>
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
</template>

<script setup lang="ts">
defineProps<{
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
      messages_count: number
      last_message_at: string | null
      created_at: string
    }>
    current_page: number
    last_page: number
    prev_page_url: string | null
    next_page_url: string | null
  }
}>()

const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''

const confirmReset = (e: Event, c: { ext_user_id: string | null; ext_username: string | null; customer: { name: string | null } | null }) => {
  const identifier = c.customer?.name ?? c.ext_username ?? c.ext_user_id ?? 'este usuario'
  if (confirm(`¿Resetear la conversación de ${identifier}?\n\nEsto borrará todos los mensajes, cotizaciones y la memoria del agente IA. El próximo mensaje iniciará el flujo desde cero.`)) {
    (e.target as HTMLFormElement).submit()
  }
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
