<template>
  <div class="min-h-screen bg-gray-100 py-8 px-4">
    <div class="max-w-3xl mx-auto space-y-6">

      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <a href="/admin/checkout-sessions" class="text-sm text-gray-500 hover:text-gray-700">← Volver al listado</a>
          <h1 class="text-2xl font-bold text-gray-800 mt-1">Checkout #{{ session.id }}</h1>
        </div>
        <span :class="statusBadgeClass(session.status)" class="px-3 py-1 rounded-full text-sm font-semibold">
          {{ statusLabel(session.status) }}
        </span>
      </div>

      <!-- Flash message -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm">
        {{ $page.props.flash.success }}
      </div>

      <!-- Cobertura -->
      <div v-if="session.alternative" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Cobertura contratada</h2>
        <p class="text-lg font-bold text-gray-800">{{ session.alternative.aseguradora }} — {{ session.alternative.titulo }}</p>
        <p class="text-2xl font-bold text-gray-900 mt-1">$ {{ formatPrice(session.alternative.precio) }} <span class="text-sm font-normal text-gray-400">/mes</span></p>
        <p v-if="session.risk" class="text-sm text-gray-500 mt-1">{{ session.risk.marca }} {{ session.risk.modelo }} {{ session.risk.year }}</p>
      </div>

      <!-- Datos personales -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Datos del tomador</h2>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
          <div><dt class="text-gray-400">Nombre</dt><dd class="font-medium text-gray-800">{{ session.nombre }}</dd></div>
          <div><dt class="text-gray-400">DNI</dt><dd class="font-medium text-gray-800">{{ session.dni }}</dd></div>
          <div class="col-span-2"><dt class="text-gray-400">Domicilio</dt><dd class="font-medium text-gray-800">{{ session.domicilio }}</dd></div>
          <div><dt class="text-gray-400">Email</dt><dd class="font-medium text-gray-800">{{ session.email }}</dd></div>
          <div><dt class="text-gray-400">Teléfono</dt><dd class="font-medium text-gray-800">{{ session.telefono }}</dd></div>
        </dl>
      </div>

      <!-- Datos de tarjeta -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide">Datos de tarjeta</h2>
          <span v-if="session.cc_cleared" class="text-xs text-gray-400 bg-gray-100 px-2 py-0.5 rounded-full">Datos eliminados</span>
        </div>

        <div v-if="!session.cc_cleared">
          <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
              <dt class="text-gray-400">Marca</dt>
              <dd class="font-bold uppercase tracking-wide" :class="brandColor(session.cc_brand)">{{ session.cc_brand }}</dd>
            </div>
            <div>
              <dt class="text-gray-400">Número</dt>
              <dd class="font-mono font-medium text-gray-800 tracking-widest">{{ formatPan(session.cc_pan) }}</dd>
            </div>
            <div>
              <dt class="text-gray-400">Vencimiento</dt>
              <dd class="font-mono font-medium text-gray-800">{{ session.cc_expiry }}</dd>
            </div>
            <div>
              <dt class="text-gray-400">Titular</dt>
              <dd class="font-medium text-gray-800">{{ session.cc_holder_name }}</dd>
            </div>
            <div>
              <dt class="text-gray-400">DNI titular</dt>
              <dd class="font-medium text-gray-800">{{ session.cc_holder_dni }}</dd>
            </div>
          </dl>

          <!-- Advertencia de seguridad -->
          <div class="mt-4 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-xs text-amber-700">
            Datos sensibles en pantalla. Procesá el pago y luego eliminá estos datos.
          </div>
        </div>

        <div v-else class="text-sm text-gray-400 italic">
          Los datos de tarjeta fueron eliminados el {{ formatDate(session.cc_cleared_at) }} tras el procesamiento.
        </div>

        <!-- Info de procesamiento -->
        <div v-if="session.cc_processed_at" class="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400">
          Procesado el {{ formatDate(session.cc_processed_at) }}
          <span v-if="session.cc_processed_by"> por {{ session.cc_processed_by }}</span>
        </div>
      </div>

      <!-- Fotos de inspección -->
      <div v-if="session.photo_paths?.length" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Fotos de inspección ({{ session.photo_paths.length }})</h2>
        <div class="grid grid-cols-3 gap-3">
          <div
            v-for="(path, i) in session.photo_paths"
            :key="i"
            class="bg-gray-100 rounded-lg aspect-square flex items-center justify-center overflow-hidden"
          >
            <!-- En producción, usar la URL de Cloudinary para mostrar el thumbnail -->
            <div class="text-xs text-gray-400 text-center p-2 break-all">{{ path }}</div>
          </div>
        </div>
      </div>

      <!-- Acciones -->
      <div v-if="session.status === 'submitted' || session.status === 'processed'" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">Acciones</h2>
        <div class="flex gap-3 flex-wrap">

          <!-- Marcar como procesado -->
          <form
            v-if="session.status === 'submitted'"
            :action="`/admin/checkout-sessions/${session.id}/mark-processed`"
            method="POST"
            @submit.prevent="confirmAndSubmit($event, '¿Confirmar que el pago fue procesado?')"
          >
            <input type="hidden" name="_token" :value="csrfToken" />
            <button
              type="submit"
              class="bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700 transition-colors"
            >
              Marcar como procesado
            </button>
          </form>

          <!-- Eliminar datos de tarjeta -->
          <form
            v-if="session.status === 'processed' && !session.cc_cleared"
            :action="`/admin/checkout-sessions/${session.id}/clear-card-data`"
            method="POST"
            @submit.prevent="confirmAndSubmit($event, '¿Eliminar los datos de tarjeta? Esta acción no se puede deshacer.')"
          >
            <input type="hidden" name="_token" :value="csrfToken" />
            <button
              type="submit"
              class="bg-red-600 text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-red-700 transition-colors"
            >
              Eliminar datos de tarjeta
            </button>
          </form>

        </div>
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'

const $page = usePage()

const props = defineProps<{
  session: {
    id: number
    status: string
    nombre: string
    dni: string
    domicilio: string
    email: string
    telefono: string
    cc_brand: string
    cc_pan: string | null
    cc_expiry: string | null
    cc_holder_name: string | null
    cc_holder_dni: string | null
    cc_cleared: boolean
    cc_cleared_at: string | null
    cc_processed_at: string | null
    cc_processed_by: string | null
    photo_paths: string[]
    quote_id: number
    alternative: {
      aseguradora: string
      titulo: string
      precio: number
      normalized_grade: string
    } | null
    risk: {
      marca: string
      modelo: string
      year: number
    } | null
  }
}>()

const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''

const statusLabel = (s: string) => ({
  pending: 'Pendiente',
  submitted: 'Por procesar',
  processed: 'Procesado',
  expired: 'Expirado',
}[s] ?? s)

const statusBadgeClass = (s: string) => ({
  submitted: 'bg-yellow-100 text-yellow-700',
  processed: 'bg-green-100 text-green-700',
  expired:   'bg-red-100 text-red-600',
  pending:   'bg-gray-100 text-gray-600',
}[s] ?? 'bg-gray-100 text-gray-500')

const brandColor = (b: string) => ({
  visa:       'text-blue-700',
  mastercard: 'text-red-600',
  amex:       'text-indigo-700',
  naranja:    'text-orange-600',
  cabal:      'text-green-700',
  maestro:    'text-teal-700',
}[b] ?? 'text-gray-700')

const formatPan = (pan: string | null) => {
  if (!pan) return '—'
  return pan.replace(/(.{4})/g, '$1 ').trim()
}

const formatDate = (iso: string | null) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('es-AR', { dateStyle: 'medium', timeStyle: 'short' })
}

const formatPrice = (n: number) =>
  new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0 }).format(n)

const confirmAndSubmit = (e: Event, message: string) => {
  if (confirm(message)) {
    (e.target as HTMLFormElement).submit()
  }
}
</script>
