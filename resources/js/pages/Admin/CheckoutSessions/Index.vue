<template>
  <div class="min-h-screen bg-gray-100 py-8 px-4">
    <div class="max-w-6xl mx-auto">
      <h1 class="text-2xl font-bold text-gray-800 mb-6">Auditoría de Checkout</h1>

      <div v-if="!sessions.data.length" class="bg-white rounded-xl border border-dashed border-gray-300 p-12 text-center text-gray-400">
        No hay sesiones de checkout para procesar.
      </div>

      <div v-else class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">#</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Tomador</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Cobertura</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Tarjeta</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Estado</th>
              <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Recibido</th>
              <th class="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="s in sessions.data" :key="s.id" class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3 text-gray-400 font-mono">{{ s.id }}</td>
              <td class="px-4 py-3">
                <p class="font-medium text-gray-800">{{ s.nombre }}</p>
                <p class="text-gray-400 text-xs">{{ s.email }}</p>
              </td>
              <td class="px-4 py-3">
                <p class="font-medium text-gray-700">{{ s.aseguradora }}</p>
                <p class="text-gray-400 text-xs">{{ s.titulo }}</p>
              </td>
              <td class="px-4 py-3">
                <span class="uppercase text-xs font-bold tracking-wide" :class="brandColor(s.cc_brand)">{{ s.cc_brand }}</span>
                <span v-if="s.cc_cleared" class="ml-1 text-xs text-gray-400">(datos eliminados)</span>
              </td>
              <td class="px-4 py-3">
                <span :class="statusBadgeClass(s.status)" class="px-2 py-0.5 rounded-full text-xs font-semibold">
                  {{ statusLabel(s.status) }}
                </span>
              </td>
              <td class="px-4 py-3 text-gray-400 text-xs">{{ formatDate(s.submitted_at) }}</td>
              <td class="px-4 py-3 text-right">
                <a :href="`/admin/checkout-sessions/${s.id}`" class="text-blue-600 hover:underline text-xs font-semibold">
                  Ver detalle →
                </a>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{
  sessions: {
    data: Array<{
      id: number
      status: string
      nombre: string
      email: string
      cc_brand: string
      cc_cleared: boolean
      submitted_at: string | null
      quote_id: number
      aseguradora: string | null
      titulo: string | null
      precio: number | null
    }>
  }
}>()

const statusLabel = (s: string) => ({
  pending: 'Pendiente',
  submitted: 'Por procesar',
  processed: 'Procesado',
  expired: 'Expirado',
}[s] ?? s)

const statusBadgeClass = (s: string) => ({
  pending:   'bg-gray-100 text-gray-600',
  submitted: 'bg-yellow-100 text-yellow-700',
  processed: 'bg-green-100 text-green-700',
  expired:   'bg-red-100 text-red-600',
}[s] ?? 'bg-gray-100 text-gray-500')

const brandColor = (b: string) => ({
  visa:       'text-blue-700',
  mastercard: 'text-red-600',
  amex:       'text-indigo-700',
  naranja:    'text-orange-600',
  cabal:      'text-green-700',
  maestro:    'text-teal-700',
}[b] ?? 'text-gray-700')

const formatDate = (iso: string | null) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
}
</script>
