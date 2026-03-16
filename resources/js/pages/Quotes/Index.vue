<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-7xl mx-auto">

      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Cotizaciones
        </h1>
        <span class="text-xs font-medium" style="color: var(--text-3);">
          Total: <span class="font-semibold" style="color: var(--text-2);">{{ quotes.total }}</span>
        </span>
      </div>

      <!-- Buscador -->
      <div class="rounded-[14px] p-4 mb-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <form @submit.prevent="buscar" class="flex flex-col sm:flex-row gap-2.5">
          <div class="flex-1 relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5" style="color: var(--text-3);"
              fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input v-model="searchInput" type="text"
              placeholder="Buscar por cliente, DNI, marca, modelo..."
              class="field pl-9" />
          </div>
          <div class="flex gap-2">
            <select v-model="perPageInput" @change="buscar" class="field" style="width: auto;">
              <option value="10">10 / pág</option>
              <option value="15">15 / pág</option>
              <option value="25">25 / pág</option>
              <option value="50">50 / pág</option>
            </select>
            <button type="submit" class="btn btn-primary">Buscar</button>
          </div>
        </form>
      </div>

      <div v-if="!quotes.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No hay cotizaciones.
      </div>

      <template v-else>
        <!-- DESKTOP — tabla ≥ md -->
        <div class="hidden md:block rounded-[14px] overflow-hidden" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <table class="min-w-full text-sm">
            <thead>
              <tr style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">ID / Fecha</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Estado</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Vehículo</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Cliente</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider w-16" style="color: var(--text-3);">Alt.</th>
                <th class="px-5 py-3 w-10"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="q in quotes.data" :key="q.id"
                class="cursor-pointer transition-colors"
                style="border-bottom: 1px solid var(--border-sub);"
                @mouseenter="$event.currentTarget.style.background = 'var(--border-sub)'"
                @mouseleave="$event.currentTarget.style.background = 'transparent'"
                @click="irA(`/quotes/${q.id}`)"
              >
                <td class="px-5 py-3">
                  <p class="font-semibold font-mono text-sm" style="color: var(--text-1);">#{{ q.id }}</p>
                  <p class="text-[11px] mt-0.5" style="color: var(--text-3);">{{ formatDate(q.created_at) }}</p>
                </td>
                <td class="px-5 py-3">
                  <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold"
                    :style="statusStyle(q.status)">
                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :style="dotStyle(q.status)"></span>
                    {{ statusLabel(q.status) }}
                  </span>
                </td>
                <td class="px-5 py-3">
                  <p class="text-sm font-medium" style="color: var(--text-1);">{{ q.marca }} {{ q.modelo }}</p>
                  <p class="text-[11px] font-mono mt-0.5" style="color: var(--text-3);">{{ q.year }} · CP {{ q.codigo_postal }}</p>
                </td>
                <td class="px-5 py-3">
                  <p class="text-sm" style="color: var(--text-1);">{{ q.customer_name ?? 'Anónimo' }}</p>
                  <p class="text-[11px] font-mono mt-0.5" style="color: var(--text-3);">{{ q.dni ?? '—' }}</p>
                </td>
                <td class="px-5 py-3 text-center">
                  <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
                    style="background: var(--border-sub); color: var(--text-2);">
                    {{ q.alternatives_count }}
                  </span>
                </td>
                <td class="px-5 py-3" @click.stop>
                  <RowActionMin :href="`/quotes/${q.id}`" label="Ver cotización" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MOBILE — cards < md -->
        <div class="md:hidden space-y-2">
          <Link v-for="q in quotes.data" :key="q.id"
            :href="`/quotes/${q.id}`"
            class="block rounded-[14px] p-4 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
          >
            <div class="flex items-start justify-between gap-2">
              <div>
                <div class="flex items-center gap-2 mb-1.5">
                  <span class="font-semibold font-mono text-sm" style="color: var(--text-1);">#{{ q.id }}</span>
                  <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold"
                    :style="statusStyle(q.status)">
                    {{ statusLabel(q.status) }}
                  </span>
                </div>
                <p class="text-sm font-medium" style="color: var(--text-1);">{{ q.marca }} {{ q.modelo }} {{ q.year }}</p>
                <p class="text-xs mt-0.5" style="color: var(--text-3);">{{ q.customer_name ?? 'Anónimo' }} · {{ q.alternatives_count }} alternativas</p>
              </div>
              <ChevronRight />
            </div>
            <p class="text-[11px] mt-2" style="color: var(--text-3);">{{ formatDate(q.created_at) }}</p>
          </Link>
        </div>
      </template>

      <Pagination v-if="quotes.last_page > 1" :data="quotes" class="mt-4" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import RowActionMin from '@/components/UI/RowActionMin.vue'
import ChevronRight from '@/components/UI/ChevronRight.vue'
import Pagination from '@/components/UI/Pagination.vue'

const props = defineProps<{
  quotes: {
    data: Array<{
      id: number; status: string; created_at: string
      marca: string; modelo: string; year: number; codigo_postal: string
      customer_name: string | null; dni: string | null; alternatives_count: number
    }>
    total: number; from: number; to: number; last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { search: string; per_page: number }
}>()

const searchInput  = ref(props.filters?.search ?? '')
const perPageInput = ref(String(props.filters?.per_page ?? 15))

const buscar = () => {
  router.get('/quotes', { search: searchInput.value, per_page: perPageInput.value },
    { preserveState: true, replace: true })
}

const irA = (href: string) => router.visit(href)

const statusLabel = (s: string) => ({
  pending: 'Pendiente', processed: 'Procesado', failed: 'Fallido',
  offered_pas: 'En PAS', rejected_pas: 'Rechazado',
  checkout_pending: 'Checkout', checkout_submitted: 'Enviado',
}[s] ?? s)

const statusStyle = (s: string) => ({
  pending:            'background:var(--badge-pending-bg); color:var(--badge-pending-txt);',
  processed:          'background:var(--badge-ok-bg);      color:var(--badge-ok-txt);',
  failed:             'background:var(--badge-danger-bg);  color:var(--badge-danger-txt);',
  offered_pas:        'background:var(--badge-accent-bg);  color:var(--badge-accent-txt);',
  rejected_pas:       'background:var(--badge-orange-bg);  color:var(--badge-orange-txt);',
  checkout_pending:   'background:var(--badge-violet-bg);  color:var(--badge-violet-txt);',
  checkout_submitted: 'background:var(--badge-teal-bg);    color:var(--badge-teal-txt);',
}[s] ?? 'background:var(--border-sub); color:var(--text-3);')

const dotStyle = (s: string) => ({
  pending:            'background:var(--dot-pending);',
  processed:          'background:var(--dot-ok);',
  failed:             'background:var(--dot-danger);',
  offered_pas:        'background:var(--dot-accent);',
  rejected_pas:       'background:var(--dot-orange);',
  checkout_pending:   'background:var(--dot-violet);',
  checkout_submitted: 'background:var(--dot-teal);',
}[s] ?? 'background:var(--text-3);')

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
</script>
