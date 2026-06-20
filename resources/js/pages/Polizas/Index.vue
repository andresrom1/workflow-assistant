<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto">

      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Pólizas
        </h1>
        <div class="flex items-center gap-3">
          <span class="text-xs font-medium" style="color: var(--text-3);">
            Total: <span class="font-semibold" style="color: var(--text-2);">{{ polizas.total }}</span>
          </span>
          <Link href="/polizas/create" class="btn btn-primary text-sm">Nueva póliza</Link>
        </div>
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
              placeholder="Buscar por número, patente o cliente..."
              class="field pl-9" />
          </div>
          <div class="flex gap-2">
            <Select v-model="perPageInput" @update:model-value="buscar">
              <SelectTrigger class="h-[38px]"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem value="15">15 / pág</SelectItem>
                  <SelectItem value="25">25 / pág</SelectItem>
                  <SelectItem value="50">50 / pág</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <button type="submit" class="btn btn-primary">Buscar</button>
          </div>
        </form>
      </div>

      <!-- Vacío -->
      <div v-if="!polizas.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No se encontraron pólizas.
      </div>

      <template v-else>
        <!-- DESKTOP -->
        <div class="hidden md:block rounded-[14px] overflow-hidden" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <table class="min-w-full text-sm">
            <thead>
              <tr style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Póliza</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Cliente</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider w-28" style="color: var(--text-3);">Cobertura</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider w-24" style="color: var(--text-3);">Estado</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider w-28" style="color: var(--text-3);">Vigencia</th>
                <th class="px-5 py-3 w-10"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in polizas.data" :key="p.id"
                class="cursor-pointer transition-colors"
                style="border-bottom: 1px solid var(--border-sub);"
                @mouseenter="$event.currentTarget.style.background = 'var(--border-sub)'"
                @mouseleave="$event.currentTarget.style.background = 'transparent'"
                @click="irA(`/polizas/${p.id}/edit`)">
                <td class="px-5 py-3">
                  <p class="text-sm font-semibold leading-tight" style="color: var(--text-1);">{{ p.numero ?? 'Sin número' }}</p>
                  <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
                    {{ p.patente ?? '—' }}<template v-if="p.company"> · {{ p.company }}</template>
                  </p>
                </td>
                <td class="px-5 py-3 text-sm" style="color: var(--text-2);">{{ p.cliente ?? '—' }}</td>
                <td class="px-5 py-3 text-xs" style="color: var(--text-3);">{{ p.coverage ?? '—' }}</td>
                <td class="px-5 py-3 text-center">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                    :style="estadoStyle(p.estado)">{{ p.estado }}</span>
                </td>
                <td class="px-5 py-3 text-xs font-mono whitespace-nowrap" style="color: var(--text-3);">{{ p.vigencia ?? '—' }}</td>
                <td class="px-5 py-3" @click.stop>
                  <RowActionMin :href="`/polizas/${p.id}/edit`" label="Editar póliza" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MOBILE -->
        <div class="md:hidden space-y-2">
          <Link v-for="p in polizas.data" :key="p.id" :href="`/polizas/${p.id}/edit`"
            class="block rounded-[14px] px-4 py-3 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex items-center justify-between gap-2">
              <div class="min-w-0">
                <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ p.numero ?? 'Sin número' }}</p>
                <p class="text-xs truncate mt-0.5 font-mono" style="color: var(--text-3);">
                  {{ p.patente ?? '—' }}<template v-if="p.cliente"> · {{ p.cliente }}</template>
                </p>
              </div>
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold flex-shrink-0"
                :style="estadoStyle(p.estado)">{{ p.estado }}</span>
            </div>
          </Link>
        </div>
      </template>

      <Pagination v-if="polizas.last_page > 1" :data="polizas" class="mt-4" />

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import RowActionMin from '@/components/UI/RowActionMin.vue'
import Pagination from '@/components/UI/Pagination.vue'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

interface PolizaRow {
  id: number; numero: string | null; company: string | null; coverage: string | null
  patente: string | null; cliente: string | null; estado: string; vigencia: string | null
}

const props = defineProps<{
  polizas: {
    data: PolizaRow[]
    total: number; last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { search: string; per_page: number }
}>()

const searchInput  = ref(props.filters.search ?? '')
const perPageInput = ref(String(props.filters.per_page ?? 25))

const buscar = () => {
  router.get('/polizas', { search: searchInput.value, per_page: perPageInput.value },
    { preserveState: true, replace: true })
}

const irA = (href: string) => router.visit(href)

const estadoStyle = (estado: string): string => {
  if (estado === 'vigente') { return 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);' }
  if (estado === 'emitida') { return 'background: var(--accent-100); color: var(--accent-600);' }
  return 'background: var(--border-sub); color: var(--text-3);'
}
</script>
