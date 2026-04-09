<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-3xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3">
          <BackLink href="/admin/checkout-sessions" label="Auditoría" />
          <div class="w-px h-4" style="background: var(--border);"></div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Checkout #{{ session.id }}
          </h1>
        </div>
        <span
          class="self-start sm:self-auto inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold"
          :style="statusStyle(session.status)"
        >
          <span class="w-1.5 h-1.5 rounded-full" :style="dotStyle(session.status)"></span>
          {{ statusLabel(session.status) }}
        </span>
      </div>

<!-- Cobertura -->
      <div v-if="session.alternative"
        class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); border-left: 4px solid #5b5ef6; box-shadow: var(--shadow-card);">
        <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-3" style="color: var(--text-3);">
          Cobertura contratada
        </h2>
        <p class="text-base font-semibold" style="color: var(--text-1);">
          {{ session.alternative.aseguradora }} — {{ session.alternative.titulo }}
        </p>
        <p class="text-2xl font-bold tracking-tight mt-1" style="color: var(--text-1);">
          ${{ formatPrice(session.alternative.precio) }}
          <span class="text-sm font-normal" style="color: var(--text-3);">/mes</span>
        </p>
        <p v-if="session.risk" class="text-sm mt-1.5" style="color: var(--text-2);">
          {{ session.risk.marca }} {{ session.risk.modelo }} {{ session.risk.year }}
        </p>
      </div>

      <!-- Datos del tomador -->
      <div class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
          Datos del tomador
        </h2>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
          <div>
            <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Nombre</dt>
            <dd class="text-sm font-medium mt-0.5" style="color: var(--text-1);">{{ session.nombre }}</dd>
          </div>
          <div>
            <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">DNI</dt>
            <dd class="text-sm font-mono mt-0.5" style="color: var(--text-1);">{{ session.dni }}</dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Domicilio</dt>
            <dd class="text-sm font-medium mt-0.5" style="color: var(--text-1);">{{ session.domicilio }}</dd>
          </div>
          <div>
            <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Email</dt>
            <dd class="text-sm font-medium mt-0.5 break-all" style="color: var(--text-1);">{{ session.email }}</dd>
          </div>
          <div>
            <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Teléfono</dt>
            <dd class="text-sm font-medium mt-0.5" style="color: var(--text-1);">{{ session.telefono }}</dd>
          </div>
        </dl>
      </div>

      <!-- Datos de tarjeta -->
      <div class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">
            Datos de tarjeta
          </h2>
          <span v-if="session.cc_cleared"
            class="text-[11px] px-2 py-0.5 rounded-full"
            style="background: var(--border-sub); color: var(--text-3);">
            Datos eliminados
          </span>
        </div>

        <div v-if="!session.cc_cleared">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 mb-4">
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Marca</dt>
              <dd class="text-sm font-bold uppercase tracking-wide mt-0.5" :style="brandStyle(session.cc_brand)">
                {{ session.cc_brand }}
              </dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Número</dt>
              <dd class="text-sm font-mono tracking-widest mt-0.5" style="color: var(--text-1);">
                {{ formatPan(session.cc_pan) }}
              </dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Vencimiento</dt>
              <dd class="text-sm font-mono mt-0.5" style="color: var(--text-1);">{{ session.cc_expiry }}</dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Titular</dt>
              <dd class="text-sm font-medium mt-0.5" style="color: var(--text-1);">{{ session.cc_holder_name }}</dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">DNI titular</dt>
              <dd class="text-sm font-mono mt-0.5" style="color: var(--text-1);">{{ session.cc_holder_dni }}</dd>
            </div>
          </dl>

          <!-- Alerta sensible -->
          <div class="flex items-start gap-2.5 px-4 py-3 rounded-[10px] text-xs"
            style="background:#fef3c7; border-left: 3px solid #d97706; color:#92400e;">
            <span class="font-bold flex-shrink-0">⚠</span>
            <span>Datos sensibles en pantalla. Procesá el pago y luego eliminá estos datos.</span>
          </div>
        </div>

        <p v-else class="text-sm italic" style="color: var(--text-3);">
          Datos eliminados el {{ formatDate(session.cc_cleared_at) }}.
        </p>

        <div v-if="session.cc_processed_at"
          class="mt-4 pt-3 text-xs"
          style="border-top: 1px solid var(--border-sub); color: var(--text-3);">
          Procesado el {{ formatDate(session.cc_processed_at) }}
          <span v-if="session.cc_processed_by"> por {{ session.cc_processed_by }}</span>
        </div>
      </div>

      <!-- Fotos de inspección -->
      <div v-if="session.photo_paths && Object.keys(session.photo_paths).length"
        class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
          Fotos de inspección ({{ Object.keys(session.photo_paths).length }})
        </h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
          <div v-for="(path, key) in session.photo_paths" :key="key"
            class="rounded-[10px] aspect-square overflow-hidden relative"
            style="background: var(--border-sub);">
            <img :src="path" :alt="photoLabel(key as string)"
              class="w-full h-full object-cover" />
            <span class="absolute bottom-0 left-0 right-0 text-white text-[10px] text-center py-1.5 font-medium"
              style="background: rgba(0,0,0,.5);">
              {{ photoLabel(key as string) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Acciones -->
      <div v-if="session.status === 'submitted' || session.status === 'processed'"
        class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
          Acciones
        </h2>
        <div class="flex flex-wrap gap-3">
          <form
            v-if="session.status === 'submitted'"
            :action="`/admin/checkout-sessions/${session.id}/mark-processed`"
            method="POST"
            @submit.prevent="confirmAndSubmit($event, '¿Confirmar que el pago fue procesado?')"
          >
            <input type="hidden" name="_token" :value="csrfToken" />
            <button type="submit" class="btn btn-primary">
              Marcar como procesado
            </button>
          </form>

          <form
            v-if="session.status === 'processed' && !session.cc_cleared"
            :action="`/admin/checkout-sessions/${session.id}/clear-card-data`"
            method="POST"
            @submit.prevent="confirmAndSubmit($event, '¿Eliminar los datos de tarjeta? Esta acción no se puede deshacer.')"
          >
            <input type="hidden" name="_token" :value="csrfToken" />
            <button type="submit"
              class="btn inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-[10px]
                     bg-red-50 text-red-700 hover:bg-red-100 transition-colors"
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
import BackLink from '@/components/UI/BackLink.vue'

const $page = usePage()

defineProps<{
  session: {
    id: number; status: string; nombre: string; dni: string; domicilio: string
    email: string; telefono: string; cc_brand: string
    cc_pan: string | null; cc_expiry: string | null
    cc_holder_name: string | null; cc_holder_dni: string | null
    cc_cleared: boolean; cc_cleared_at: string | null
    cc_processed_at: string | null; cc_processed_by: string | null
    photo_paths: Record<string, string>; quote_id: number
    alternative: { aseguradora: string; titulo: string; precio: number; normalized_grade: string } | null
    risk: { marca: string; modelo: string; year: number } | null
  }
}>()

const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''

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
  visa: 'color:#1a56db;', mastercard: 'color:#dc2626;',
  amex: 'color:#4338ca;', naranja: 'color:#ea580c;',
  cabal: 'color:#16a349;', maestro: 'color:#0d9488;',
}[b] ?? 'color:var(--text-2);')

const formatPan = (pan: string | null) =>
  pan ? pan.replace(/(.{4})/g, '$1 ').trim() : '—'

const formatDate = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('es-AR', { dateStyle: 'medium', timeStyle: 'short' }) : '—'

const formatPrice = (n: number) =>
  new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0 }).format(n)

const confirmAndSubmit = (e: Event, msg: string) => {
  if (confirm(msg)) (e.target as HTMLFormElement).submit()
}

const photoLabel = (key: string) => ({
  tarjeta_verde: 'Tarjeta Verde', frente: 'Frente', atras: 'Atrás',
  lateral_i: 'Lateral izq.', lateral_d: 'Lateral der.',
  auxilio: 'Auxilio', parabrisas: 'Parabrisas',
}[key] ?? key)
</script>
