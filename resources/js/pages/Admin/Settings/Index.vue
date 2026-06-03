<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Configuración del sistema
          </h1>
          <p class="text-sm mt-0.5" style="color: var(--text-3);">
            Parámetros de operación. Los cambios tienen efecto inmediato.
          </p>
        </div>
        <BackLink href="/admin/checkout-sessions" label="Auditoría" />
      </div>

<!-- Dashboard de estado -->
      <div class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
          Estado actual del sistema
        </h2>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <StatCard
            v-for="stat in stats" :key="stat.key"
            :value="stat.value"
            :label="stat.label"
            :ok="stat.ok"
          />
        </div>

        <!-- Endpoints -->
        <div class="mt-4 pt-4 grid grid-cols-1 md:grid-cols-2 gap-3"
          style="border-top: 1px solid var(--border-sub);">
          <EndpointRow
            label="API de Emisión"
            :url="getSetting('poliza_api.base_url')"
            :has-secret="!!getSetting('poliza_api.key')"
          />
        </div>
      </div>

      <!-- Un card por grupo -->
      <div v-for="group in groups" :key="group.key"
        class="rounded-[14px] overflow-hidden"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

        <!-- Header grupo -->
        <div class="px-5 py-4 flex items-center justify-between"
          style="border-bottom: 1px solid var(--border-sub);">
          <div class="flex items-center gap-3">
            <span class="text-lg">{{ groupIcon(group.key) }}</span>
            <div>
              <h2 class="text-sm font-semibold" style="color: var(--text-1);">{{ group.label }}</h2>
              <p class="text-[11px] mt-0.5" style="color: var(--text-3);">
                {{ group.items.length }} parámetro{{ group.items.length !== 1 ? 's' : '' }}
              </p>
            </div>
          </div>
          <Transition name="fade-badge">
            <span v-if="saved[group.key]"
              class="text-[11px] px-2.5 py-1 rounded-full font-semibold"
              style="background:#dcfce7; color:#15803d; border: 1px solid #bbf7d0;">
              ✓ Guardado
            </span>
          </Transition>
        </div>

        <!-- Campos -->
        <div class="px-5 py-5 space-y-5">
          <div v-for="item in group.items" :key="item.key">
            <div class="flex items-center justify-between mb-1.5">
              <label class="text-sm font-medium" style="color: var(--text-1);">{{ item.label }}</label>
              <span v-if="item.is_secret"
                class="text-[10px] px-2 py-0.5 rounded font-mono font-semibold"
                style="background:#fef3c7; color:#92400e; border: 1px solid #fde68a;">
                secret
              </span>
            </div>

            <div class="relative">
              <!-- Secret -->
              <template v-if="item.type === 'secret'">
                <input
                  :type="revealed[item.key] ? 'text' : 'password'"
                  v-model="drafts[group.key][item.key]"
                  class="field font-mono pr-20"
                  style="background: var(--bg-raised);"
                  :placeholder="item.value ? '(valor configurado)' : 'Sin configurar'"
                />
                <button
                  type="button"
                  @click="revealed[item.key] = !revealed[item.key]"
                  class="absolute right-2.5 top-1/2 -translate-y-1/2 text-[11px] font-medium px-2 py-1 rounded-[6px] transition-all"
                  style="color: var(--text-3);"
                >
                  {{ revealed[item.key] ? 'Ocultar' : 'Mostrar' }}
                </button>
              </template>

              <!-- Integer -->
              <template v-else-if="item.type === 'integer'">
                <input
                  type="number"
                  v-model.number="drafts[group.key][item.key]"
                  min="0"
                  class="field"
                />
              </template>

              <!-- Boolean -->
              <template v-else-if="item.type === 'boolean'">
                <label class="flex items-center gap-3 cursor-pointer">
                  <div class="relative w-9 h-5 rounded-full transition-colors flex-shrink-0"
                    :style="drafts[group.key][item.key]
                      ? 'background:#5b5ef6;'
                      : 'background:var(--border);'">
                    <input type="checkbox" v-model="drafts[group.key][item.key]" class="sr-only" />
                    <div class="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow-sm transition-transform"
                      :class="drafts[group.key][item.key] ? 'translate-x-4 right-0.5' : 'left-0.5'">
                    </div>
                  </div>
                  <span class="text-sm" style="color: var(--text-1);">
                    {{ drafts[group.key][item.key] ? 'Activado' : 'Desactivado' }}
                  </span>
                </label>
              </template>

              <!-- String -->
              <template v-else>
                <input
                  type="text"
                  v-model="drafts[group.key][item.key]"
                  class="field"
                />
              </template>
            </div>

            <p v-if="item.description" class="text-xs mt-1.5 leading-relaxed" style="color: var(--text-3);">
              {{ item.description }}
            </p>
          </div>
        </div>

        <!-- Footer grupo -->
        <div class="px-5 py-4 flex items-center justify-between"
          style="background: var(--bg-raised); border-top: 1px solid var(--border-sub);">
          <p class="text-[11px]" style="color: var(--text-3);">
            Último cambio: {{ latestUpdate(group.items) }}
          </p>
          <button
            type="button"
            @click="saveGroup(group.key)"
            :disabled="saving[group.key]"
            class="btn btn-primary"
          >
            <svg v-if="saving[group.key]"
              class="w-3.5 h-3.5 animate-spin"
              fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
            </svg>
            {{ saving[group.key] ? 'Guardando...' : 'Guardar cambios' }}
          </button>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, defineComponent, h } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import BackLink from '@/components/UI/BackLink.vue'

// ─── Sub-componentes inline con tokens CSS ────────────────────────────────

const StatCard = defineComponent({
  props: { value: String, label: String, ok: Boolean },
  setup(props) {
    return () => h('div', {
      style: 'background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: var(--shadow-card);'
    }, [
      h('p', {
        style: `font-size: 22px; font-weight: 700; letter-spacing: -0.02em; line-height: 1; color: ${props.ok === false ? '#dc2626' : 'var(--text-1)'};`
      }, props.value),
      h('p', {
        style: 'font-size: 11px; color: var(--text-3); margin-top: 4px;'
      }, props.label),
    ])
  }
})

const EndpointRow = defineComponent({
  props: { label: String, url: String, hasSecret: Boolean },
  setup(props) {
    return () => h('div', {
      style: 'display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; border:1px solid var(--border-sub); background:var(--bg-raised);'
    }, [
      h('div', {
        style: `width:8px; height:8px; border-radius:50%; flex-shrink:0; background:${props.url ? '#16a349' : 'var(--border)'};`
      }),
      h('div', { style: 'flex:1; min-width:0;' }, [
        h('p', { style: 'font-size:12px; font-weight:600; color:var(--text-1);' }, props.label),
        h('p', { style: 'font-size:11px; color:var(--text-3); font-family:monospace; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;' },
          props.url || 'Sin configurar'),
      ]),
      props.hasSecret
        ? h('span', {
            style: 'font-size:10px; padding:2px 6px; border-radius:4px; font-family:monospace; background:#fef3c7; color:#92400e; border:1px solid #fde68a; flex-shrink:0;'
          }, '🔑')
        : null,
    ])
  }
})

// ─── Types ────────────────────────────────────────────────────────────────

interface SettingItem {
  key: string; label: string; description: string | null
  type: string; is_secret: boolean; value: string | null; updated_at: string | null
}
interface SettingGroup { key: string; label: string; items: SettingItem[] }

const props = defineProps<{ groups: SettingGroup[] }>()
const $page = usePage()

// ─── Estado ───────────────────────────────────────────────────────────────

const allSettings = Object.fromEntries(
  props.groups.flatMap(g => g.items.map(i => [i.key, i.value]))
)
const getSetting = (key: string): string => allSettings[key] ?? ''

// Stat cards computadas
const stats = computed(() => [
  {
    key: 'photos',
    value: (getSetting('checkout.required_photos') || '—') + ' fotos',
    label: 'Fotos requeridas',
    ok: true,
  },
  {
    key: 'ttl',
    value: (getSetting('checkout.temp_photo_ttl_hours') || '—') + ' hs',
    label: 'TTL fotos temp.',
    ok: true,
  },
  {
    key: 'api',
    value: getSetting('poliza_api.base_url') ? 'Configurada' : 'Sin configurar',
    label: 'API Emisión',
    ok: !!getSetting('poliza_api.base_url'),
  },
])

const drafts = reactive<Record<string, Record<string, any>>>(
  Object.fromEntries(
    props.groups.map(g => [
      g.key,
      Object.fromEntries(g.items.map(i => {
        const val = i.type === 'integer' ? Number(i.value ?? 0)
          : i.type === 'boolean' ? (i.value === '1' || i.value === 'true')
          : (i.value ?? '')
        return [i.key, val]
      }))
    ])
  )
)

const saving  = reactive<Record<string, boolean>>({})
const saved   = reactive<Record<string, boolean>>({})
const revealed = reactive<Record<string, boolean>>({})

// ─── Acciones ─────────────────────────────────────────────────────────────

const saveGroup = (groupKey: string) => {
  saving[groupKey] = true
  saved[groupKey]  = false

  const group = props.groups.find(g => g.key === groupKey)!
  const payload: Record<string, any> = { settings: {} }

  group.items.forEach(item => {
    const draft = drafts[groupKey][item.key]
    if (item.is_secret && (draft === '' || draft === null)) return
    payload.settings[item.key] = draft
  })

  router.post(`/admin/settings/${groupKey}`, payload, {
    preserveScroll: true,
    onSuccess: () => {
      saved[groupKey] = true
      setTimeout(() => { saved[groupKey] = false }, 3000)
    },
    onFinish: () => { saving[groupKey] = false },
  })
}

// ─── Helpers ──────────────────────────────────────────────────────────────

const groupIcon = (key: string) => ({
  pas: '📱', checkout: '🛒', poliza_api: '📄',
}[key] ?? '⚙️')

const latestUpdate = (items: SettingItem[]) => {
  const dates = items.map(i => i.updated_at).filter(Boolean).map(d => new Date(d!).getTime())
  if (!dates.length) return 'nunca'
  return new Date(Math.max(...dates)).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<style scoped>
.fade-badge-enter-active, .fade-badge-leave-active { transition: opacity 0.2s ease; }
.fade-badge-enter-from, .fade-badge-leave-to { opacity: 0; }
</style>
