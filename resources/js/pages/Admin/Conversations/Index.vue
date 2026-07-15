<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-6xl mx-auto">

      <!-- Header -->
      <div class="flex items-center gap-3 mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Conversaciones
        </h1>
        <Badge class="bg-yellow-100 text-yellow-800 border-yellow-200 hover:bg-yellow-100">
          DEV TOOL
        </Badge>
      </div>

      <!-- Filtros por salud -->
      <Card class="p-4 mb-5">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            Filtrar:
          </span>
          <Button
            v-for="flag in flagDefs"
            :key="flag.key"
            type="button"
            size="xs"
            :variant="isFlagActive(flag.key) ? 'destructive' : 'outline'"
            :class="[!isFlagActive(flag.key) && flag.tier === 2 && 'border-dashed']"
            @click="toggleFlag(flag.key)"
          >
            <span>{{ flag.emoji }}</span>
            <span>{{ flag.label }}</span>
            <span
              class="rounded px-1 text-[10px]"
              :class="isFlagActive(flag.key) ? 'bg-white/25' : 'bg-muted text-muted-foreground'"
            >
              {{ flagCounts[flag.key] ?? 0 }}
            </span>
          </Button>
          <Button
            v-if="filters.flags.length"
            type="button"
            variant="ghost"
            size="xs"
            @click="clearFlags"
          >
            Limpiar
          </Button>
        </div>
      </Card>

      <DataTable
        :columns="columns"
        :data="conversations.data"
        :sort="currentSort"
        :direction="currentDirection"
        empty-message="No hay conversaciones registradas."
        @sort="handleSort"
      >
        <template #cell-customer_name="{ item }">
          <p class="text-sm font-semibold" style="color: var(--text-1);">
            {{ item.customer?.name ?? item.ext_username ?? 'Anónimo' }}
          </p>
          <p class="text-[11px] font-mono text-muted-foreground">
            {{ item.customer?.phone ?? item.ext_user_id ?? '—' }}
          </p>
          <div class="flex items-center gap-1.5 mt-0.5">
            <p class="text-[10px] font-mono text-muted-foreground/60">#{{ item.id }}</p>
            <Badge v-if="item.status === 'archived'" variant="secondary" class="text-[9px] h-3 px-1 py-0 leading-none">
              Archivada
            </Badge>
          </div>
        </template>

        <template #cell-status="{ item }">
          <div class="flex flex-wrap gap-1 max-h-[44px] overflow-hidden">
            <template v-for="(done, flag) in item.ai_state" :key="flag">
              <span
                v-if="done"
                class="inline-flex items-center gap-1 px-1.5 py-0 rounded text-[10px] font-medium bg-green-100 text-green-700"
              >
                <span class="size-1 rounded-full flex-shrink-0 bg-green-500" />
                {{ flagLabel(flag) }}
              </span>
            </template>
            <span
              v-if="Object.values(item.ai_state).filter(Boolean).length === 0"
              class="text-[10px] text-muted-foreground"
            >
              —
            </span>
          </div>
        </template>

        <template #cell-messages_count="{ item }">
          <div class="flex flex-col items-center gap-0.5">
            <span class="text-sm text-foreground">{{ item.messages_count }}</span>
            <Badge variant="outline" class="text-[9px] h-4 px-1 py-0" :class="channelBadgeClass(item.channel)">
              {{ channelLabel(item.channel) }}
            </Badge>
          </div>
        </template>

        <template #cell-updated_at="{ item }">
          <span class="text-[11px] whitespace-nowrap text-muted-foreground">
            {{ formatDate(item.last_message_at ?? item.created_at) }}
          </span>
        </template>

        <template #cell-created_at="{ item }">
          <span class="text-[11px] whitespace-nowrap text-muted-foreground">
            {{ formatDate(item.created_at) }}
          </span>
        </template>

        <template #cell-action="{ item }">
          <div class="flex items-center justify-end gap-1" @click.stop>
            <Button variant="ghost" size="icon" class="h-7 w-7" as-child>
              <Link :href="`/admin/conversations/${item.id}`">
                <Eye class="size-4" />
              </Link>
            </Button>
            <Button
              v-if="item.status !== 'archived'"
              variant="ghost"
              size="icon"
              class="h-7 w-7 text-destructive hover:text-destructive"
              @click="openArchive(item)"
            >
              <Archive class="size-4" />
            </Button>
          </div>
        </template>

        <template #mobile-row="{ item }">
          <Card class="p-4">
            <CardContent class="p-0 space-y-3">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <Badge variant="outline" :class="channelBadgeClass(item.channel)">
                      {{ channelLabel(item.channel) }}
                    </Badge>
                    <span class="text-[10px] text-muted-foreground">{{ item.messages_count }} msgs</span>
                  </div>
                  <div class="flex items-center gap-2">
                    <p class="text-sm font-semibold truncate" style="color: var(--text-1);">
                      {{ item.customer?.name ?? item.ext_username ?? 'Anónimo' }}
                    </p>
                    <Badge v-if="item.status === 'archived'" variant="secondary" class="text-[10px] h-4 px-1 flex-shrink-0">
                      Archivada
                    </Badge>
                  </div>
                  <p class="text-[11px] font-mono text-muted-foreground">
                    {{ item.customer?.phone ?? item.ext_user_id ?? '—' }}
                  </p>
                  <p class="text-[10px] font-mono text-muted-foreground/60">#{{ item.id }}</p>
                </div>
              </div>

              <div class="flex flex-wrap gap-1">
                <span
                  v-for="(done, flag) in item.ai_state"
                  :key="flag"
                  class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
                  :class="done ? 'bg-green-100 text-green-700' : 'bg-muted text-muted-foreground'"
                >
                  {{ flagLabel(flag) }}
                </span>
              </div>

              <div class="flex items-center justify-between gap-2">
                <span class="text-[11px] text-muted-foreground">
                  {{ formatDate(item.last_message_at ?? item.created_at) }}
                </span>
                <div class="flex items-center gap-2">
                  <Button variant="outline" size="xs" as-child>
                    <Link :href="`/admin/conversations/${item.id}`">
                      Ver
                    </Link>
                  </Button>
                  <Button
                    v-if="item.status !== 'archived'"
                    variant="destructive"
                    size="xs"
                    @click="openArchive(item)"
                  >
                    Archivar
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>
        </template>
      </DataTable>

      <AppPagination v-if="conversations.last_page > 1" :data="conversations" class="mt-4" />
    </div>
  </div>

  <!-- Modal de confirmación de archivado -->
  <Dialog :open="!!archiveTarget" @update:open="(open) => { if (!open) archiveTarget = null }">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>¿Archivar conversación?</DialogTitle>
        <DialogDescription>
          {{ archiveTarget?.identifier }}
        </DialogDescription>
      </DialogHeader>

      <ul class="text-sm space-y-2">
        <li class="flex items-center gap-2" style="color: var(--badge-ok-txt);">
          <Check class="size-4" />
          Mensajes, cotizaciones y contexto del LLM conservados
        </li>
        <li class="flex items-center gap-2" style="color: var(--badge-ok-txt);">
          <Check class="size-4" />
          Conversación accesible para auditoría
        </li>
        <li class="flex items-center gap-2" style="color: var(--badge-danger-txt);">
          <X class="size-4" />
          El próximo mensaje inicia el flujo desde cero
        </li>
      </ul>

      <DialogFooter>
        <Button variant="outline" @click="archiveTarget = null">
          Cancelar
        </Button>
        <Button variant="destructive" @click="submitArchive">
          Archivar
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { Badge } from '@/components/UI/badge'
import { Button } from '@/components/UI/button'
import { Card, CardContent } from '@/components/UI/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/UI/dialog'
import { Archive, Check, Eye, X } from '@lucide/vue'
import AppPagination from '@/components/App/Pagination.vue'
import DataTable, { type SortDirection } from '@/components/App/DataTable.vue'

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
    from: number
    to: number
    total: number
    current_page: number
    last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { flags: HealthFlag[] }
  flag_counts: Record<HealthFlag, number>
}>()

const flagCounts = props.flag_counts

const page = usePage()
const queryParams = computed(() => new URLSearchParams(page.url.split('?')[1] ?? ''))
const currentSort = computed(() => queryParams.value.get('sort') || null)
const currentDirection = computed<SortDirection>(() => (queryParams.value.get('direction') as SortDirection) || 'asc')

const columns = [
  { key: 'customer_name', label: 'Cliente', sortable: true, class: 'w-[220px]' },
  { key: 'status', label: 'Estado', sortable: true, class: 'w-[260px]' },
  { key: 'messages_count', label: 'Mensajes', sortable: true, align: 'center' as const, class: 'w-[90px]' },
  { key: 'updated_at', label: 'Última actividad', sortable: true, class: 'w-[140px]' },
  { key: 'created_at', label: 'Creado', sortable: true, class: 'w-[140px]' },
  { key: 'action', label: '', sortable: false, align: 'center' as const, class: 'w-[80px]' },
]

type FlagDef = { key: HealthFlag; label: string; emoji: string; tier: 1 | 2 }

const flagDefs: FlagDef[] = [
  { key: 'loops', label: 'Loops', emoji: '🔁', tier: 1 },
  { key: 'stuck', label: 'Estancadas', emoji: '⏳', tier: 1 },
  { key: 'tool_errors', label: 'Tool errors', emoji: '⚠️', tier: 1 },
  { key: 'abandoned', label: 'Abandonadas', emoji: '🕒', tier: 1 },
  { key: 'long', label: 'Largas', emoji: '📜', tier: 1 },
  { key: 'user_frustrated', label: 'Frustrado', emoji: '😤', tier: 2 },
  { key: 'agent_confused', label: 'Confundido', emoji: '🤔', tier: 2 },
  { key: 'semantic_loop', label: 'Loop semántico', emoji: '🔂', tier: 2 },
  { key: 'context_loss', label: 'Perdió contexto', emoji: '🧠', tier: 2 },
  { key: 'hallucination', label: 'Alucinación', emoji: '👻', tier: 2 },
  { key: 'incorrect_answer', label: 'Resp. incorrecta', emoji: '❌', tier: 2 },
]

const isFlagActive = (flag: HealthFlag) => props.filters.flags.includes(flag)

const buildFilterParams = () => {
  const params: Record<string, any> = {}
  if (currentSort.value) {
    params.sort = currentSort.value
    params.direction = currentDirection.value
  }
  return params
}

const toggleFlag = (flag: HealthFlag) => {
  const next = isFlagActive(flag)
    ? props.filters.flags.filter((f) => f !== flag)
    : [...props.filters.flags, flag]
  router.get('/admin/conversations', { flags: next, ...buildFilterParams() }, { preserveState: true, preserveScroll: true, replace: true })
}

const clearFlags = () => {
  router.get('/admin/conversations', buildFilterParams(), { preserveState: true, preserveScroll: true, replace: true })
}

const handleSort = (column: string, direction: SortDirection) => {
  router.get('/admin/conversations', {
    flags: props.filters.flags,
    sort: column,
    direction,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

interface ArchiveTarget {
  id: number
  identifier: string
}

const archiveTarget = ref<ArchiveTarget | null>(null)

const openArchive = (c: {
  id: number
  ext_user_id: string | null
  ext_username: string | null
  customer: { name: string | null } | null
}) => {
  archiveTarget.value = {
    id: c.id,
    identifier: c.customer?.name ?? c.ext_username ?? c.ext_user_id ?? 'este usuario',
  }
}

const submitArchive = () => {
  if (!archiveTarget.value) {
    return
  }
  router.post(`/admin/conversations/${archiveTarget.value.id}/reset`, {}, {
    preserveScroll: true,
    onFinish: () => { archiveTarget.value = null },
  })
}

const channelLabel = (ch: string) => ({
  whatsapp: 'WhatsApp',
  web: 'Web',
  telegram: 'Telegram',
}[ch] ?? ch)

const channelBadgeClass = (ch: string) => {
  const map: Record<string, string> = {
    whatsapp: 'bg-green-100 text-green-700 border-green-200',
    web: 'bg-blue-100 text-blue-700 border-blue-200',
    telegram: 'bg-sky-100 text-sky-700 border-sky-200',
  }
  return map[ch] ?? 'bg-muted text-muted-foreground'
}

const flagLabel = (flag: string) => ({
  customer_identified: 'cliente',
  vehicle_identified: 'vehículo',
  coverage_set: 'cobertura',
  quote_ready: 'cotización',
  checkout_done: 'checkout',
}[flag] ?? flag)

const formatDate = (iso: string | null) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
}
</script>
