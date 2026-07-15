<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div class="flex items-center gap-3">
          <AppBackLink href="/customers" label="Clientes" />
          <div class="w-px h-4" style="background: var(--border);"></div>
          <Avatar :name="customer.name" />
          <div>
            <h1 class="text-xl sm:text-2xl font-semibold tracking-tight flex items-center gap-2" style="color: var(--text-1);">
              {{ customer.name ?? 'Sin nombre' }}
              <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold" :style="estadoBadge(customer.is_anonymous)">
                {{ customer.is_anonymous ? 'Anónimo' : 'Completo' }}
              </span>
            </h1>
            <p class="text-xs font-mono mt-0.5" style="color: var(--text-3);">
              DNI {{ customer.dni ?? '—' }}
              <template v-if="customer.pas"> · PAS: {{ customer.pas.name }}</template>
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
          <Link :href="`/customers/${customer.id}/edit`" class="btn btn-secondary text-sm">Editar</Link>
          <button @click="showDelete = true" class="btn btn-danger text-sm">Eliminar</button>
        </div>
      </div>

      <!-- Resumen de cartera (KPIs) -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div v-for="kpi in kpis" :key="kpi.label" class="rounded-[14px] p-4" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <p class="text-2xl font-bold tracking-tight" :style="`color: ${kpi.color};`">{{ kpi.value }}</p>
          <p class="text-[11px] mt-0.5" style="color: var(--text-3);">{{ kpi.label }}</p>
        </div>
      </div>

      <!-- Vencimientos / alertas -->
      <div v-if="customer.vencimientos.length" class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">Vencimientos y alertas</h2>
        <div class="space-y-2">
          <div v-for="(v, i) in customer.vencimientos" :key="i"
            class="flex items-center justify-between gap-3 rounded-[10px] px-4 py-2.5"
            :style="`border: 1px solid var(--border-sub); ${urgencyBg(v.dias_restantes)}`">
            <div class="min-w-0">
              <p class="text-sm font-semibold" style="color: var(--text-1);">
                {{ v.tipo === 'cuota' ? 'Cuota' : 'Renovación' }}
                <span class="font-normal" style="color: var(--text-3);">· {{ v.numero ?? 'Sin número' }} · {{ v.company ?? '—' }}</span>
              </p>
              <p class="text-xs mt-0.5" style="color: var(--text-3);">{{ formatDate(v.fecha) }}</p>
            </div>
            <span class="text-xs font-semibold flex-shrink-0" :style="urgencyText(v.dias_restantes)">
              {{ v.dias_restantes < 0 ? `Vencido (${-v.dias_restantes}d)` : `${v.dias_restantes}d` }}
            </span>
          </div>
        </div>
      </div>

      <!-- Acciones rápidas -->
      <div class="flex flex-wrap gap-2">
        <a v-if="waLink" :href="waLink" target="_blank" rel="noopener" class="btn btn-secondary text-sm">WhatsApp</a>
        <Link :href="`/polizas/create?customer=${customer.id}`" class="btn btn-secondary text-sm">Agregar póliza</Link>
        <button type="button" class="btn btn-secondary text-sm" @click="copyContact">{{ copied ? 'Copiado ✓' : 'Copiar contacto' }}</button>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <!-- Info -->
        <div class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">Información</h2>
          <dl class="space-y-3">
            <Info label="Email" :value="customer.email" />
            <Info label="Teléfono" :value="customer.phone" />
            <Info label="Nacimiento" :value="customer.birthdate" />
            <Info label="Sexo" :value="customer.sex_id" />
            <Info label="Cond. fiscal" :value="customer.tax_condition_id" />
            <Info label="Domicilio" :value="domicilioStr" />
            <Info label="Registro" :value="formatDate(customer.created_at)" />
          </dl>
          <div v-if="customer.notes" class="mt-4 pt-4" style="border-top: 1px solid var(--border-sub);">
            <p class="text-[11px] uppercase tracking-wide mb-1" style="color: var(--text-3);">Notas</p>
            <p class="text-sm whitespace-pre-wrap" style="color: var(--text-2);">{{ customer.notes }}</p>
          </div>
        </div>

        <!-- Columna principal -->
        <div class="lg:col-span-2 space-y-5">

          <!-- Divergencias -->
          <div v-if="customer.divergencias.length" class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid #f59e0b;">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-3" style="color: #b45309;">
              Divergencias con el checkout ({{ customer.divergencias.length }})
            </h2>
            <p class="text-xs mb-3" style="color: var(--text-3);">El checkout (declaración jurada) trae datos distintos a los del registro. Elegí cuál conservar.</p>
            <div class="space-y-2.5">
              <div v-for="d in customer.divergencias" :key="d.field"
                class="rounded-[10px] p-3" style="border: 1px solid var(--border-sub);">
                <p class="text-xs font-semibold mb-2" style="color: var(--text-1);">{{ d.label }}</p>
                <div class="flex flex-col sm:flex-row gap-2">
                  <div class="flex-1 text-xs rounded-md px-3 py-2" style="background: var(--bg-raised); color: var(--text-2);">
                    <span style="color: var(--text-3);">Registro:</span> {{ d.customer ?? '—' }}
                  </div>
                  <div class="flex-1 text-xs rounded-md px-3 py-2 flex items-center justify-between gap-2" style="background: var(--bg-raised); color: var(--text-2);">
                    <span><span style="color: var(--text-3);">Checkout:</span> {{ d.checkout }}</span>
                    <button class="btn btn-primary text-[11px] py-1 px-2" :disabled="resolving === d.field" @click="resolve(d)">Usar este</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Vehículos -->
          <div class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
              Vehículos ({{ customer.vehicles.length }})
            </h2>
            <div v-if="customer.vehicles.length" class="space-y-2.5">
              <div v-for="v in customer.vehicles" :key="v.id" class="rounded-[10px] p-4" style="border: 1px solid var(--border-sub);">
                <div class="flex items-center gap-2 mb-3">
                  <span class="text-sm font-bold font-mono" style="color: var(--text-1);">{{ v.patente ?? '—' }}</span>
                  <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold"
                    :style="v.is_complete ? 'background: #dcfce7; color: #15803d;' : 'background: #fef3c7; color: #92400e;'">
                    {{ v.is_complete ? 'Completo' : 'Incompleto' }}
                  </span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                  <div v-for="(val, key) in { Marca: v.marca, Modelo: v.modelo, Año: v.year, Uso: v.uso }" :key="key">
                    <p class="text-[10px] uppercase tracking-wide" style="color: var(--text-3);">{{ key }}</p>
                    <p class="text-xs font-semibold mt-0.5 capitalize" style="color: var(--text-1);">{{ val ?? '—' }}</p>
                  </div>
                </div>
              </div>
            </div>
            <p v-else class="text-sm text-center py-6" style="color: var(--text-3);">Sin vehículos registrados.</p>
          </div>

          <!-- Pólizas -->
          <div class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">
                Pólizas ({{ customer.polizas.length }})
              </h2>
              <Link :href="`/polizas/create?customer=${customer.id}`" class="btn btn-primary text-xs py-1.5 px-3">Agregar póliza</Link>
            </div>
            <div v-if="customer.polizas.length" class="space-y-2.5">
              <div v-for="p in customer.polizas" :key="p.id" class="rounded-[10px] p-4" style="border: 1px solid var(--border-sub);">
                <div class="flex items-center justify-between gap-2">
                  <Link :href="`/polizas/${p.id}/edit`" class="min-w-0">
                    <p class="text-sm font-semibold truncate" style="color: var(--text-1);">
                      {{ p.numero ?? 'Sin número' }}
                      <span class="font-normal" style="color: var(--text-3);">· {{ p.company ?? '—' }}</span>
                    </p>
                    <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
                      {{ p.patente ?? p.label }}<template v-if="p.coverage"> · {{ p.coverage }}</template>
                    </p>
                  </Link>
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold flex-shrink-0" :style="estadoStyle(p.estado)">
                    {{ p.estado }}
                  </span>
                </div>
                <div v-if="p.documents.length" class="mt-2.5 pt-2.5 flex flex-wrap gap-2" style="border-top: 1px solid var(--border-sub);">
                  <a v-for="d in p.documents" :key="d.id" :href="d.storage_url ?? '#'" target="_blank" rel="noopener"
                    class="text-[11px] px-2 py-1 rounded-md" style="background: var(--bg-raised); color: var(--accent-600);">
                    📄 {{ d.label ?? d.kind }}
                  </a>
                  <Link :href="`/policy-documents/${p.id}`" class="text-[11px] px-2 py-1 rounded-md" style="background: var(--bg-raised); color: var(--text-3);">Gestionar docs</Link>
                </div>
              </div>
            </div>
            <p v-else class="text-sm text-center py-6" style="color: var(--text-3);">Sin pólizas registradas.</p>
          </div>

          <!-- Cotizaciones -->
          <div class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
              Cotizaciones ({{ customer.cotizaciones.length }})
            </h2>
            <div v-if="customer.cotizaciones.length" class="space-y-2">
              <Link v-for="q in customer.cotizaciones" :key="q.id" :href="`/quotes/${q.id}`"
                class="flex items-center justify-between gap-2 rounded-[10px] px-4 py-2.5" style="border: 1px solid var(--border-sub);">
                <div class="min-w-0">
                  <p class="text-sm font-semibold" style="color: var(--text-1);">Cotización #{{ q.id }}</p>
                  <p class="text-xs mt-0.5" style="color: var(--text-3);">{{ formatDate(q.created_at) }} · {{ q.alternativas_count }} alternativas</p>
                </div>
                <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold flex-shrink-0" style="background: var(--border-sub); color: var(--text-2);">{{ q.status }}</span>
              </Link>
            </div>
            <p v-else class="text-sm text-center py-6" style="color: var(--text-3);">Sin cotizaciones.</p>
          </div>

          <!-- Datos del checkout (read-only) -->
          <div v-if="customer.checkout" class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex items-center justify-between mb-3">
              <h2 class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Última declaración de checkout</h2>
              <span class="text-[11px] px-2 py-0.5 rounded-full font-semibold" style="background: var(--border-sub); color: var(--text-2);">{{ customer.checkout.status }}</span>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Info label="Nombre" :value="customer.checkout.nombre" />
              <Info label="DNI" :value="customer.checkout.dni" />
              <Info label="Email" :value="customer.checkout.email" />
              <Info label="Teléfono" :value="customer.checkout.telefono" />
              <Info label="Domicilio" :value="customer.checkout.domicilio" />
              <Info label="Enviado" :value="customer.checkout.submitted_at ? formatDate(customer.checkout.submitted_at) : null" />
            </dl>
          </div>

          <!-- Conversaciones -->
          <div class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
              Conversaciones ({{ customer.conversations.length }})
            </h2>
            <div v-if="customer.conversations.length" class="space-y-2.5">
              <div v-for="conv in customer.conversations" :key="conv.id" class="rounded-[10px] p-4" style="border: 1px solid var(--border-sub);">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                  <p class="text-[11px] font-mono break-all" style="color: var(--text-3);">{{ conv.external_conversation_id }}</p>
                  <p class="text-[11px] flex-shrink-0" style="color: var(--text-3);">{{ formatDate(conv.created_at) }}</p>
                </div>
                <p class="text-sm mt-1.5" style="color: var(--text-2);">
                  Última actividad: <span class="font-medium" style="color: var(--text-1);">{{ conv.last_message_at ?? '—' }}</span>
                </p>
              </div>
            </div>
            <p v-else class="text-sm text-center py-6" style="color: var(--text-3);">Sin conversaciones registradas.</p>
          </div>

          <!-- Timeline -->
          <div v-if="customer.timeline.length" class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">Actividad</h2>
            <div class="space-y-3">
              <div v-for="(e, i) in customer.timeline" :key="i" class="flex items-start gap-3">
                <span class="mt-1.5 w-2 h-2 rounded-full flex-shrink-0" :style="`background: ${timelineColor(e.tipo)};`"></span>
                <div class="min-w-0">
                  <p class="text-sm" style="color: var(--text-1);">{{ e.label }}</p>
                  <p class="text-[11px]" style="color: var(--text-3);">{{ formatDate(e.fecha) }}</p>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- Modal confirmar eliminación -->
  <Transition name="fade">
    <div v-if="showDelete" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="showDelete = false" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">¿Eliminar a {{ customer.name ?? 'este cliente' }}?</h3>
        <p class="text-sm mb-5" style="color: var(--text-2);">
          El cliente se archiva (soft-delete). Si tiene una póliza vigente, la eliminación se bloquea.
        </p>
        <div class="flex justify-end gap-2">
          <button @click="showDelete = false" class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);">Cancelar</button>
          <button @click="submitDelete" class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);">Eliminar</button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup lang="ts">
import { ref, computed, defineComponent, h } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppBackLink from '@/components/App/BackLink.vue'
import Avatar from '@/components/UI/Avatar.vue'

interface Divergencia { field: string; label: string; customer: string | null; checkout: string }

const props = defineProps<{
  customer: {
    id: number; name: string | null; first_name: string | null; last_name: string | null
    dni: string | null; document_type_id: string | null; person_type_id: string | null
    email: string | null; phone: string | null; birthdate: string | null
    sex_id: string | null; tax_condition_id: string | null
    domicilio: { calle: string | null; numero: string | null; cp: string | null; provincia: string | null; localidad: string | null }
    is_anonymous: boolean; completed_at: string | null; created_at: string; notes: string
    pas: { id: number; name: string } | null
    vehicles: Array<{ id: number; patente: string | null; marca: string | null; modelo: string | null; year: number | null; uso: string | null; is_complete: boolean }>
    polizas: Array<{ id: number; numero: string | null; company: string | null; coverage: string | null; estado: string; patente: string | null; label: string; documents: Array<{ id: number; kind: string; label: string | null; storage_url: string | null; visible_to_client: boolean }> }>
    cotizaciones: Array<{ id: number; status: string; created_at: string; expires_at: string | null; alternativas_count: number }>
    conversations: Array<{ id: number; external_conversation_id: string; last_message_at: string | null; created_at: string }>
    resumen: { polizas_vigentes: number; prima_mensual: number; cotizaciones_abiertas: number; cliente_desde: string }
    vencimientos: Array<{ poliza_id: number; tipo: string; fecha: string; dias_restantes: number; numero: string | null; company: string | null }>
    checkout: { id: number; status: string; submitted_at: string | null; nombre: string; dni: string | null; email: string | null; telefono: string | null; domicilio: string } | null
    divergencias: Divergencia[]
    timeline: Array<{ tipo: string; fecha: string; label: string }>
  }
}>()

const showDelete = ref(false)
const copied = ref(false)
const resolving = ref<string | null>(null)

const Info = defineComponent({
  props: { label: String, value: [String, Number] },
  setup: (p) => () => h('div', [
    h('dt', { class: 'text-[11px] uppercase tracking-wide', style: 'color: var(--text-3);' }, p.label),
    h('dd', { class: 'text-sm font-medium mt-0.5 break-words', style: 'color: var(--text-1);' }, (p.value ?? '—') as string),
  ]),
})

const kpis = computed(() => [
  { label: 'Pólizas vigentes', value: props.customer.resumen.polizas_vigentes, color: 'var(--accent-600)' },
  { label: 'Prima mensual', value: '$' + new Intl.NumberFormat('es-AR').format(props.customer.resumen.prima_mensual), color: 'var(--text-1)' },
  { label: 'Cotiz. abiertas', value: props.customer.resumen.cotizaciones_abiertas, color: 'var(--text-2)' },
  { label: 'Cliente desde', value: new Date(props.customer.resumen.cliente_desde).toLocaleDateString('es-AR', { year: 'numeric', month: 'short' }), color: 'var(--text-2)' },
])

const domicilioStr = computed(() => {
  const d = props.customer.domicilio
  const s = [d.calle, d.numero, d.cp ? `(CP ${d.cp})` : null, d.localidad, d.provincia].filter(Boolean).join(' ')
  return s || null
})

const waLink = computed(() => {
  if (!props.customer.phone) return null
  const digits = props.customer.phone.replace(/\D/g, '')
  return digits ? `https://wa.me/${digits}` : null
})

const copyContact = async () => {
  const c = props.customer
  const text = [c.name, c.dni ? `DNI ${c.dni}` : null, c.email, c.phone].filter(Boolean).join(' · ')
  try {
    await navigator.clipboard.writeText(text)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch { /* clipboard no disponible */ }
}

const resolve = (d: Divergencia) => {
  resolving.value = d.field
  router.post(`/customers/${props.customer.id}/resolve-divergence`,
    { field: d.field, value: d.checkout },
    { preserveScroll: true, onFinish: () => (resolving.value = null) })
}

const submitDelete = () => {
  showDelete.value = false
  router.delete(`/customers/${props.customer.id}`)
}

const estadoBadge = (anon: boolean): string =>
  anon ? 'background: #fef3c7; color: #92400e;' : 'background: #dcfce7; color: #15803d;'

const estadoStyle = (estado: string): string => {
  if (estado === 'vigente') return 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);'
  if (estado === 'emitida') return 'background: var(--accent-100); color: var(--accent-600);'
  return 'background: var(--border-sub); color: var(--text-3);'
}

const urgencyBg = (dias: number): string => {
  if (dias < 0) return 'background: #fef2f2;'
  if (dias <= 30) return 'background: #fffbeb;'
  return ''
}
const urgencyText = (dias: number): string => {
  if (dias < 0) return 'color: #dc2626;'
  if (dias <= 30) return 'color: #b45309;'
  return 'color: var(--text-3);'
}

const timelineColor = (tipo: string): string => ({
  alta: 'var(--accent-600)', cotizacion: '#6366f1', poliza: '#15803d', mensaje: 'var(--text-3)',
}[tipo] ?? 'var(--text-3)')

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
</script>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
