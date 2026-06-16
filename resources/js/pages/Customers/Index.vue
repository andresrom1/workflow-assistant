<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto">

      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Clientes
        </h1>
        <div class="flex items-center gap-3">
          <span class="text-xs font-medium" style="color: var(--text-3);">
            Total: <span class="font-semibold" style="color: var(--text-2);">{{ customers.total }}</span>
          </span>
          <Link href="/customers/create" class="btn btn-primary text-sm">Nuevo cliente</Link>
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
              placeholder="Buscar por DNI, nombre, email o teléfono..."
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

      <!-- Vacío -->
      <div v-if="!customers.data.length"
        class="rounded-[14px] p-12 text-center text-sm"
        style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
        No se encontraron clientes.
      </div>

      <template v-else>
        <!-- DESKTOP — tabla ≥ md -->
        <div class="hidden md:block rounded-[14px] overflow-hidden" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <table class="min-w-full text-sm">
            <thead>
              <tr style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Cliente</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider w-24" style="color: var(--text-3);">Vehículos</th>
                <th class="px-5 py-3 text-center text-[11px] font-semibold uppercase tracking-wider w-24" style="color: var(--text-3);">Convs.</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase tracking-wider w-32" style="color: var(--text-3);">Registro</th>
                <th class="px-5 py-3 w-10"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="c in customers.data" :key="c.id"
                class="cursor-pointer transition-colors"
                style="border-bottom: 1px solid var(--border-sub);"
                @mouseenter="$event.currentTarget.style.background = 'var(--border-sub)'"
                @mouseleave="$event.currentTarget.style.background = 'transparent'"
                @click="irA(`/customers/${c.id}`)"
              >
                <td class="px-5 py-3">
                  <div class="flex items-center gap-3">
                    <Avatar :name="c.name" />
                    <div class="min-w-0">
                      <p class="text-sm font-semibold leading-tight" style="color: var(--text-1);">{{ c.name }}</p>
                      <p class="text-xs mt-0.5 truncate font-mono" style="color: var(--text-3);">
                        {{ c.dni }}<template v-if="c.email"> · {{ c.email }}</template><template v-if="c.phone"> · {{ c.phone }}</template>
                      </p>
                    </div>
                  </div>
                </td>
                <td class="px-5 py-3 text-center" @click.stop @click="irA(`/customers/${c.id}`)">
                  <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
                    style="background: var(--accent-100); color: var(--accent-600);">
                    {{ c.vehicles_count }}
                  </span>
                </td>
                <td class="px-5 py-3 text-center" @click.stop @click="irA(`/customers/${c.id}`)">
                  <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[11px] font-bold font-mono tabular-nums"
                    style="background: var(--border-sub); color: var(--text-2);">
                    {{ c.conversations_count }}
                  </span>
                </td>
                <td class="px-5 py-3 text-xs font-mono whitespace-nowrap" style="color: var(--text-3);">
                  {{ formatDate(c.created_at) }}
                </td>
                <td class="px-5 py-3" @click.stop>
                  <RowActionMin :href="`/customers/${c.id}`" label="Ver cliente" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MOBILE — cards < md -->
        <div class="md:hidden space-y-2">
          <Link v-for="c in customers.data" :key="c.id"
            :href="`/customers/${c.id}`"
            class="flex items-center gap-3 rounded-[14px] px-4 py-3 transition-all"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
          >
            <Avatar :name="c.name" />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ c.name }}</p>
              <p class="text-xs truncate mt-0.5 font-mono" style="color: var(--text-3);">
                {{ c.dni }}<template v-if="c.email"> · {{ c.email }}</template>
              </p>
              <div class="flex gap-3 mt-1.5">
                <span class="inline-flex items-center gap-1 text-xs" style="color: var(--text-3);">
                  <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold font-mono"
                    style="background: var(--accent-100); color: var(--accent-600);">{{ c.vehicles_count }}</span>
                  veh.
                </span>
                <span class="inline-flex items-center gap-1 text-xs" style="color: var(--text-3);">
                  <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold font-mono"
                    style="background: var(--border-sub); color: var(--text-2);">{{ c.conversations_count }}</span>
                  conv.
                </span>
              </div>
            </div>
            <ChevronRight />
          </Link>
        </div>
      </template>

      <Pagination v-if="customers.last_page > 1" :data="customers" class="mt-4" />

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import Avatar from '@/components/UI/Avatar.vue'
import RowActionMin from '@/components/UI/RowActionMin.vue'
import ChevronRight from '@/components/UI/ChevronRight.vue'
import Pagination from '@/components/UI/Pagination.vue'

const props = defineProps<{
  customers: {
    data: Array<{
      id: number; name: string; dni: string
      email: string | null; phone: string | null
      vehicles_count: number; conversations_count: number
      created_at: string
    }>
    total: number; from: number; to: number; last_page: number
    links: Array<{ label: string; url: string | null; active: boolean }>
  }
  filters: { search: string; per_page: number }
}>()

const searchInput  = ref(props.filters.search ?? '')
const perPageInput = ref(String(props.filters.per_page ?? 15))

const buscar = () => {
  router.get('/customers', { search: searchInput.value, per_page: perPageInput.value },
    { preserveState: true, replace: true })
}

const irA = (href: string) => router.visit(href)

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('es-AR', { dateStyle: 'short' })
</script>
