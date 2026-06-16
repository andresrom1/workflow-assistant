<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto">

      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Documentos de Póliza
        </h1>
      </div>

      <p class="text-sm mb-5" style="color: var(--text-3);">
        Buscá una póliza para gestionar sus documentos (renovaciones, endosos, correcciones).
      </p>

      <!-- Search -->
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
            <select v-model="perPageInput" @change="buscar" class="field" style="width: auto;">
              <option value="15">15 / pág</option>
              <option value="25">25 / pág</option>
              <option value="50">50 / pág</option>
            </select>
            <button type="submit" class="btn btn-primary">Buscar</button>
          </div>
        </form>
      </div>

      <!-- Empty state -->
      <div v-if="!polizas.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No se encontraron pólizas.
      </div>

      <template v-else>
        <!-- DESKTOP table -->
        <div class="hidden md:block rounded-[14px] overflow-hidden"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <table class="min-w-full text-sm">
            <thead>
              <tr style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Póliza</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Cliente</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Compañía</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider w-24" style="color: var(--text-3);">Docs</th>
                <th class="px-5 py-3 w-10"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="p in polizas.data" :key="p.id"
                class="cursor-pointer transition-colors"
                style="border-bottom: 1px solid var(--border-sub);"
                @mouseenter="($event.currentTarget as HTMLElement).style.background = 'var(--border-sub)'"
                @mouseleave="($event.currentTarget as HTMLElement).style.background = 'transparent'"
                @click="irA(`/policy-documents/${p.id}`)"
              >
                <td class="px-5 py-3">
                  <p class="text-sm font-semibold leading-tight" style="color: var(--text-1);">{{ p.numero ?? '—' }}</p>
                  <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
                    {{ p.patente ?? '—' }} · {{ p.label }}
                  </p>
                </td>
                <td class="px-5 py-3">
                  <p class="text-sm" style="color: var(--text-2);">{{ p.cliente ?? '—' }}</p>
                </td>
                <td class="px-5 py-3">
                  <p class="text-sm" style="color: var(--text-2);">{{ p.company ?? '—' }}</p>
                </td>
                <td class="px-5 py-3 text-center">
                  <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
                    style="background: var(--accent-100); color: var(--accent-600);">
                    {{ p.documents_count }}
                  </span>
                </td>
                <td class="px-5 py-3" @click.stop>
                  <RowActionMin :href="`/policy-documents/${p.id}`" label="Gestionar documentos" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MOBILE cards -->
        <div class="md:hidden space-y-2">
          <Link v-for="p in polizas.data" :key="p.id"
            :href="`/policy-documents/${p.id}`"
            class="flex items-center gap-3 rounded-[14px] px-4 py-3 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ p.numero ?? '—' }}</p>
              <p class="text-xs truncate mt-0.5 font-mono" style="color: var(--text-3);">
                {{ p.patente ?? '—' }} · {{ p.cliente ?? '—' }}
              </p>
            </div>
            <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
              style="background: var(--accent-100); color: var(--accent-600);">
              {{ p.documents_count }}
            </span>
            <ChevronRight />
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
import ChevronRight from '@/components/UI/ChevronRight.vue'
import Pagination from '@/components/UI/Pagination.vue'

interface PolizaItem {
  id: number
  numero: string | null
  company: string | null
  patente: string | null
  label: string
  cliente: string | null
  estado: string
  documents_count: number
}

const props = defineProps<{
  polizas: {
    data: PolizaItem[]
    total: number
    from: number
    to: number
    last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { search: string; per_page: number }
}>()

const searchInput = ref(props.filters.search ?? '')
const perPageInput = ref(String(props.filters.per_page ?? 25))

const buscar = () => {
  router.get('/policy-documents', { search: searchInput.value, per_page: perPageInput.value },
    { preserveState: true, replace: true })
}

const irA = (href: string) => router.visit(href)
</script>
