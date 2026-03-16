<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-7xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div class="flex items-center gap-3">
          <BackLink href="/quotes" label="Cotizaciones" />
          <div class="w-px h-4" style="background: var(--border);"></div>
          <div>
            <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
              Cotización #{{ quote.id }}
            </h1>
            <p class="text-[11px] font-mono mt-0.5" style="color: var(--text-3);">
              Ref: {{ quote.external_ref_id ?? 'N/A' }}
            </p>
          </div>
        </div>
        <span class="self-start sm:self-auto inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold"
          :style="statusStyle(quote.status)">
          <span class="w-1.5 h-1.5 rounded-full" :style="dotStyle(quote.status)"></span>
          {{ statusLabel(quote.status) }}
        </span>
      </div>

      <!-- Snapshot -->
      <div class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); border-left: 4px solid #5b5ef6; box-shadow: var(--shadow-card);">
        <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
          Snapshot de Riesgo
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
          <div>
            <p class="text-[11px] uppercase tracking-wide mb-1" style="color: var(--text-3);">Vehículo</p>
            <p class="text-sm font-semibold" style="color: var(--text-1);">{{ quote.marca }} {{ quote.modelo }}</p>
            <p class="text-xs mt-0.5" style="color: var(--text-2);">{{ quote.version }}</p>
            <div class="flex flex-wrap gap-1.5 mt-2">
              <span class="text-[11px] px-2 py-0.5 rounded-[6px] font-mono"
                style="background: var(--border-sub); color: var(--text-2);">{{ quote.year }}</span>
              <span class="text-[11px] px-2 py-0.5 rounded-[6px] font-mono"
                style="background: var(--border-sub); color: var(--text-2);">CP {{ quote.codigo_postal }}</span>
            </div>
          </div>
          <div>
            <p class="text-[11px] uppercase tracking-wide mb-2" style="color: var(--text-3);">Factores</p>
            <dl class="space-y-1.5">
              <div class="flex gap-2 text-xs">
                <dt class="w-20" style="color: var(--text-3);">Combustible</dt>
                <dd class="font-semibold uppercase"
                  :style="quote.combustible?.toLowerCase() === 'gnc' ? 'color:#dc2626' : 'color:#16a349'">
                  {{ quote.combustible ?? '—' }}
                </dd>
              </div>
              <div class="flex gap-2 text-xs">
                <dt class="w-20" style="color: var(--text-3);">Uso</dt>
                <dd class="font-medium capitalize" style="color: var(--text-1);">{{ quote.uso ?? '—' }}</dd>
              </div>
              <div class="flex gap-2 text-xs">
                <dt class="w-20" style="color: var(--text-3);">Conductor</dt>
                <dd class="font-medium" style="color: var(--text-1);">{{ quote.edad_conductor ?? '—' }}</dd>
              </div>
            </dl>
          </div>
          <div>
            <p class="text-[11px] uppercase tracking-wide mb-2" style="color: var(--text-3);">Cliente</p>
            <p class="text-sm font-mono" style="color: var(--text-1);">DNI {{ quote.dni ?? '—' }}</p>
            <div v-if="quote.coverage_preference" class="mt-2.5 p-2.5 rounded-[10px]"
              style="background: var(--accent-50); border: 1px solid var(--accent-200);">
              <p class="text-[9px] font-semibold uppercase tracking-wider" style="color: var(--accent-600);">Preferencia</p>
              <p class="text-sm font-bold mt-0.5" style="color: var(--accent-600);">{{ quote.coverage_preference }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Alternativas -->
      <div>
        <h2 class="text-sm font-semibold mb-4" style="color: var(--text-1);">
          Alternativas
          <span class="font-normal ml-1" style="color: var(--text-3);">({{ quote.alternatives.length }})</span>
        </h2>

        <div v-if="!quote.alternatives.length"
          class="rounded-[14px] p-10 text-center text-sm"
          style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
          <p v-if="quote.status === 'pending'" style="color: var(--accent-600);" class="animate-pulse">
            Consultando proveedores...
          </p>
          <p v-else>No hay alternativas disponibles.</p>
        </div>

        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div v-for="alt in quote.alternatives" :key="alt.id"
            class="rounded-[14px] flex flex-col overflow-hidden transition-all hover:border-[#9b9dfb]"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

            <!-- Card header -->
            <div class="px-4 py-3 flex items-start justify-between gap-2"
              style="background: var(--bg-raised); border-bottom: 1px solid var(--border);">
              <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-wider" style="color: var(--accent-600);">
                  {{ alt.aseguradora }}
                </p>
                <p class="text-sm font-semibold truncate mt-0.5" style="color: var(--text-1);">{{ alt.titulo }}</p>
              </div>
              <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold flex-shrink-0"
                :style="gradeStyle(alt.normalized_grade)">
                {{ gradeLabel(alt.normalized_grade) }}
              </span>
            </div>

            <!-- Card body -->
            <div class="p-4 flex-1">
              <p class="text-xs mb-3 line-clamp-2" style="color: var(--text-3);">{{ alt.descripcion }}</p>
              <div class="flex flex-wrap gap-1">
                <span v-for="tag in alt.features_tags?.slice(0, 3)" :key="tag"
                  class="text-[10px] px-2 py-0.5 rounded-full font-medium"
                  style="background: var(--border-sub); color: var(--text-2); border: 1px solid var(--border);">
                  {{ tag }}
                </span>
                <span v-if="(alt.features_tags?.length ?? 0) > 3"
                  class="text-[10px]" style="color: var(--text-3);">
                  +{{ (alt.features_tags?.length ?? 0) - 3 }}
                </span>
              </div>
            </div>

            <!-- Card footer -->
            <div class="px-4 py-3" style="border-top: 1px solid var(--border-sub);">
              <p class="text-xl font-bold tracking-tight" style="color: var(--text-1);">
                <span class="text-sm font-normal" style="color: var(--text-3);">$</span>
                {{ formatPrice(alt.precio) }}
                <span class="text-xs font-normal" style="color: var(--text-3);">/mes</span>
              </p>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import BackLink from '@/components/UI/BackLink.vue'

defineProps<{
  quote: {
    id: number; status: string; external_ref_id: string | null
    marca: string; modelo: string; version: string
    year: number; codigo_postal: string; combustible: string | null
    uso: string | null; edad_conductor: string | null
    dni: string | null; coverage_preference: string | null
    alternatives: Array<{
      id: number; aseguradora: string; titulo: string; descripcion: string
      normalized_grade: string; precio: number; features_tags: string[] | null
    }>
  }
}>()

const statusLabel = (s: string) => ({
  pending: 'Pendiente', processed: 'Procesado', failed: 'Fallido',
  offered_pas: 'En PAS', checkout_submitted: 'Enviado',
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

const gradeStyle = (g: string) => ({
  liability:            'background:var(--border-sub); color:var(--text-2);',
  basic:                'background:#ffedd5; color:#9a3412;',
  third_party_complete: 'background:var(--accent-100); color:var(--accent-600);',
  all_risk:             'background:#f5f3ff; color:#6d28d9;',
}[g] ?? 'background:var(--border-sub); color:var(--text-2);')

const gradeLabel = (g: string) => ({
  liability: 'RC', basic: 'Básico',
  third_party_complete: 'Terceros', all_risk: 'Todo Riesgo',
}[g] ?? g)

const formatPrice = (n: number) =>
  new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0 }).format(n)
</script>
