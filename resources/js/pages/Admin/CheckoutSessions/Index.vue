<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-6xl mx-auto">

      <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mb-6" style="color: var(--text-1);">
        Auditoría de Checkout
      </h1>

      <div v-if="!sessions.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No hay sesiones de checkout para procesar.
      </div>

      <template v-else>
        <!-- DESKTOP — tabla ≥ md -->
        <div class="hidden md:block rounded-[14px] overflow-hidden"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <table class="min-w-full text-sm">
            <thead>
              <tr style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">#</th>
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Tomador</th>
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Cobertura</th>
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Tarjeta</th>
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Estado</th>
                <th class="px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Recibido</th>
                <th class="px-4 py-3 w-10"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="s in sessions.data" :key="s.id"
                class="cursor-pointer transition-colors"
                style="border-bottom: 1px solid var(--border-sub);"
                @mouseenter="$event.currentTarget.style.background = 'var(--border-sub)'"
                @mouseleave="$event.currentTarget.style.background = 'transparent'"
                @click="irA(`/admin/checkout-sessions/${s.id}`)"
              >
                <td class="px-4 py-3 text-[11px] font-mono" style="color: var(--text-3);">{{ s.id }}</td>
                <td class="px-4 py-3">
                  <p class="text-sm font-semibold" style="color: var(--text-1);">{{ s.nombre }}</p>
                  <p class="text-[11px] mt-0.5" style="color: var(--text-3);">{{ s.email }}</p>
                </td>
                <td class="px-4 py-3">
                  <p class="text-sm font-medium" style="color: var(--text-1);">{{ s.aseguradora }}</p>
                  <p class="text-[11px] mt-0.5" style="color: var(--text-3);">{{ s.titulo }}</p>
                </td>
                <td class="px-4 py-3">
                  <span class="text-[11px] font-bold uppercase tracking-wide" :style="brandStyle(s.cc_brand)">
                    {{ s.cc_brand }}
                  </span>
                  <span v-if="s.cc_cleared" class="ml-1 text-[11px]" style="color: var(--text-3);">(eliminados)</span>
                </td>
                <td class="px-4 py-3">
                  <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold"
                    :style="statusStyle(s.status)">
                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :style="dotStyle(s.status)"></span>
                    {{ statusLabel(s.status) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-[11px] whitespace-nowrap" style="color: var(--text-3);">
                  {{ formatDate(s.submitted_at) }}
                </td>
                <td class="px-4 py-3" @click.stop>
                  <RowActionMin :href="`/admin/checkout-sessions/${s.id}`" label="Ver sesión" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MOBILE — cards < md -->
        <div class="md:hidden space-y-2">
          <Link v-for="s in sessions.data" :key="s.id"
            :href="`/admin/checkout-sessions/${s.id}`"
            class="block rounded-[14px] p-4 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <div class="flex items-center gap-2 mb-1.5">
                  <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold"
                    :style="statusStyle(s.status)">
                    {{ statusLabel(s.status) }}
                  </span>
                  <span class="text-[10px] font-bold uppercase tracking-wide" :style="brandStyle(s.cc_brand)">
                    {{ s.cc_brand }}
                  </span>
                </div>
                <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ s.nombre }}</p>
                <p class="text-xs truncate mt-0.5" style="color: var(--text-3);">{{ s.email }}</p>
                <p class="text-xs mt-1" style="color: var(--text-2);">
                  {{ s.aseguradora }} · {{ s.titulo }}
                </p>
              </div>
              <ChevronRight />
            </div>
            <p class="text-[11px] mt-2" style="color: var(--text-3);">{{ formatDate(s.submitted_at) }}</p>
          </Link>
        </div>
      </template>

    </div>
  </div>
</template>

<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import RowActionMin from '@/components/UI/RowActionMin.vue'
import ChevronRight from '@/components/UI/ChevronRight.vue'

defineProps<{
  sessions: {
    data: Array<{
      id: number; status: string; nombre: string; email: string
      cc_brand: string; cc_cleared: boolean
      submitted_at: string | null; quote_id: number
      aseguradora: string | null; titulo: string | null; precio: number | null
    }>
  }
}>()

const irA = (href: string) => router.visit(href)

const statusLabel = (s: string) => ({
  pending: 'Pendiente', submitted: 'Por procesar',
  processed: 'Procesado', expired: 'Expirado',
}[s] ?? s)

const statusStyle = (s: string) => ({
  pending:   'background:var(--border-sub);         color:var(--text-2);',
  submitted: 'background:var(--badge-pending-bg);   color:var(--badge-pending-txt);',
  processed: 'background:var(--badge-ok-bg);         color:var(--badge-ok-txt);',
  expired:   'background:var(--badge-danger-bg);     color:var(--badge-danger-txt);',
}[s] ?? 'background:var(--border-sub); color:var(--text-3);')

const dotStyle = (s: string) => ({
  pending:   'background:var(--text-3);',
  submitted: 'background:var(--dot-pending);',
  processed: 'background:var(--dot-ok);',
  expired:   'background:var(--dot-danger);',
}[s] ?? 'background:var(--text-3);')

const brandStyle = (b: string) => ({
  visa:       'color:#1a56db;',
  mastercard: 'color:#dc2626;',
  amex:       'color:#4338ca;',
  naranja:    'color:#ea580c;',
  cabal:      'color:#16a349;',
  maestro:    'color:#0d9488;',
}[b] ?? 'color:var(--text-2);')

const formatDate = (iso: string | null) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
}
</script>
