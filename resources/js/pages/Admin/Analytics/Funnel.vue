<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto space-y-6">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            📊 Funnel de cotización
          </h1>
          <p class="text-sm" style="color: var(--text-3);">
            Métricas por step — últimos 7 días por defecto.
          </p>
        </div>

        <!-- Date range -->
        <div class="flex items-center gap-2 flex-wrap">
          <input
            type="date"
            :value="from"
            @change="applyRange('from', ($event.target as HTMLInputElement).value)"
            class="rounded-lg px-3 py-1.5 text-[13px]"
            style="background: var(--bg-card); border: 1px solid var(--border); color: var(--text-1);"
          />
          <span class="text-[12px]" style="color: var(--text-3);">–</span>
          <input
            type="date"
            :value="to"
            @change="applyRange('to', ($event.target as HTMLInputElement).value)"
            class="rounded-lg px-3 py-1.5 text-[13px]"
            style="background: var(--bg-card); border: 1px solid var(--border); color: var(--text-1);"
          />
        </div>
      </div>

      <!-- Funnel visual + metrics -->
      <div class="space-y-3">
        <div
          v-for="step in steps"
          :key="step.step"
          class="rounded-[14px] overflow-hidden"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
        >
          <!-- Bar track -->
          <div class="relative h-2" style="background: var(--bg-raised);">
            <div
              class="absolute left-0 top-0 h-2 rounded-r transition-all duration-500"
              :style="{
                width: barWidth(step.entered) + '%',
                background: barColor(step.step),
              }"
            ></div>
          </div>

          <!-- Content -->
          <div class="px-4 py-3 sm:px-5">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
              <!-- Left: label + abandonment -->
              <div class="flex items-center gap-3">
                <span class="w-6 h-6 rounded-full flex items-center justify-center text-[11px] font-bold shrink-0"
                  :style="`background: ${barColor(step.step)}22; color: ${barColor(step.step)};`">
                  {{ step.step }}
                </span>
                <div>
                  <p class="text-[13px] font-semibold" style="color: var(--text-1);">{{ step.label }}</p>
                  <p class="text-[11px]" style="color: var(--text-3);">
                    <code class="font-mono">{{ step.key }}</code>
                  </p>
                </div>
              </div>

              <!-- Right: metrics grid -->
              <div class="flex flex-wrap gap-x-5 gap-y-1 text-[12px]">
                <MetricCell label="Entraron" :value="step.entered.toString()" />
                <MetricCell label="Completaron" :value="step.completed.toString()" />
                <MetricCell
                  label="Abandono"
                  :value="abandonmentLabel(step.abandonment_rate)"
                  :danger="step.abandonment_rate > 0.5"
                />
                <MetricCell label="Turns promedio" :value="step.avg_turns.toFixed(1)" />
                <MetricCell
                  label="Tiempo promedio"
                  :value="step.avg_time_seconds !== null ? formatSeconds(step.avg_time_seconds) : '—'"
                />
                <MetricCell
                  label="👎 Anotaciones"
                  :value="step.negative_annotations.toString()"
                  :danger="step.negative_annotations > 0"
                />
              </div>
            </div>

            <!-- Drill-down link + toggle de versiones -->
            <div class="mt-2 flex items-center justify-end gap-3">
              <button
                v-if="(promptBreakdown[step.step]?.length ?? 0) > 0"
                type="button"
                @click="toggleVersions(step.step)"
                class="text-[11px] transition-colors"
                style="color: var(--text-3);"
              >
                {{ expandedStep === step.step ? '▾' : '▸' }} Por versión de prompt
              </button>
              <Link
                :href="`/admin/conversations?step=${step.step}`"
                class="text-[11px] transition-colors"
                style="color: var(--text-3);"
              >
                Ver conversaciones de este step →
              </Link>
            </div>

            <!-- Desglose por versión de prompt -->
            <div v-if="expandedStep === step.step" class="mt-2 rounded-[10px] overflow-hidden"
              style="border: 1px solid var(--border-sub);">
              <table class="w-full text-[11px]">
                <thead>
                  <tr style="background: var(--bg-raised);">
                    <th class="text-left px-3 py-1.5 font-semibold" style="color: var(--text-3);">Versión</th>
                    <th class="text-left px-3 py-1.5 font-semibold" style="color: var(--text-3);">Notas</th>
                    <th class="text-right px-3 py-1.5 font-semibold" style="color: var(--text-3);">Entraron</th>
                    <th class="text-right px-3 py-1.5 font-semibold" style="color: var(--text-3);">Completaron</th>
                    <th class="text-right px-3 py-1.5 font-semibold" style="color: var(--text-3);">Conversión</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in promptBreakdown[step.step]" :key="row.agent_prompt_id ?? 'none'"
                    style="border-top: 1px solid var(--border-sub);">
                    <td class="px-3 py-1.5" style="color: var(--text-1);">
                      {{ row.version !== null ? `v${row.version}` : 'sin versión' }}
                    </td>
                    <td class="px-3 py-1.5 truncate max-w-[220px]" style="color: var(--text-2);">{{ row.notes ?? '—' }}</td>
                    <td class="px-3 py-1.5 text-right" style="color: var(--text-2);">{{ row.entered }}</td>
                    <td class="px-3 py-1.5 text-right" style="color: var(--text-2);">{{ row.completed }}</td>
                    <td class="px-3 py-1.5 text-right font-semibold" style="color: var(--text-1);">
                      {{ Math.round(row.conversion * 100) }}%
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div
        v-if="steps.every(s => s.entered === 0)"
        class="text-center py-12"
        style="color: var(--text-3);"
      >
        <p class="text-[14px]">Sin datos para el rango seleccionado.</p>
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'

interface StepMetric {
  step: number
  key: string
  label: string
  entered: number
  completed: number
  abandonment_rate: number
  avg_turns: number
  avg_time_seconds: number | null
  negative_annotations: number
}

interface PromptVersionMetric {
  agent_prompt_id: number | null
  version: number | null
  notes: string | null
  entered: number
  completed: number
  conversion: number
}

interface Props {
  steps: StepMetric[]
  promptBreakdown: Record<number, PromptVersionMetric[]>
  from: string
  to: string
}

const props = defineProps<Props>()

const expandedStep = ref<number | null>(null)
const toggleVersions = (step: number) => {
  expandedStep.value = expandedStep.value === step ? null : step
}

const maxEntered = Math.max(...props.steps.map(s => s.entered), 1)

const barWidth = (entered: number): number =>
  Math.max(entered > 0 ? Math.round((entered / maxEntered) * 100) : 0, 1)

const BAR_COLORS = ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981']
const barColor = (step: number): string => BAR_COLORS[(step - 1) % BAR_COLORS.length]

const abandonmentLabel = (rate: number): string =>
  `${Math.round(rate * 100)}%`

const formatSeconds = (secs: number): string => {
  if (secs < 60) return `${Math.round(secs)}s`
  if (secs < 3600) return `${Math.round(secs / 60)}m`
  return `${(secs / 3600).toFixed(1)}h`
}

const applyRange = (field: 'from' | 'to', value: string) => {
  router.get('/admin/analytics/funnel', {
    from: field === 'from' ? value : props.from,
    to: field === 'to' ? value : props.to,
  }, { preserveState: true, preserveScroll: true })
}
</script>

<script lang="ts">
// Inline sub-component for metric cells — keeps the template clean.
import { defineComponent, h } from 'vue'

const MetricCell = defineComponent({
  name: 'MetricCell',
  props: {
    label: String,
    value: String,
    danger: { type: Boolean, default: false },
  },
  setup(props) {
    return () => h('div', { class: 'text-right' }, [
      h('p', {
        class: 'text-[10px] uppercase tracking-wider',
        style: 'color: var(--text-3);',
      }, props.label),
      h('p', {
        class: 'font-semibold',
        style: props.danger ? 'color: var(--badge-danger-txt, #dc2626);' : 'color: var(--text-1);',
      }, props.value),
    ])
  },
})

export default { components: { MetricCell } }
</script>
