<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto space-y-6">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight flex items-center gap-2" style="color: var(--text-1);">
            <BarChart3Icon class="size-6" />
            Funnel de cotización
          </h1>
          <p class="text-sm" style="color: var(--text-3);">
            Métricas por step — últimos 7 días por defecto.
          </p>
        </div>

        <!-- Date range -->
        <div class="flex items-center gap-2 flex-wrap">
          <Input
            type="date"
            :value="from"
            class="w-auto"
            @change="applyRange('from', ($event.target as HTMLInputElement).value)"
          />
          <span class="text-[12px]" style="color: var(--text-3);">–</span>
          <Input
            type="date"
            :value="to"
            class="w-auto"
            @change="applyRange('to', ($event.target as HTMLInputElement).value)"
          />
        </div>
      </div>

      <!-- Funnel visual + metrics -->
      <div class="space-y-3">
        <Card
          v-for="step in steps"
          :key="step.step"
          class="overflow-hidden"
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

          <CardContent class="pt-4">
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
                  label="Anotaciones"
                  :value="step.negative_annotations.toString()"
                  :danger="step.negative_annotations > 0"
                />
              </div>
            </div>

            <!-- Drill-down link + toggle de versiones -->
            <div class="mt-2 flex items-center justify-end gap-3">
              <Button
                v-if="(promptBreakdown[step.step]?.length ?? 0) > 0"
                type="button"
                variant="ghost"
                size="sm"
                class="px-0 h-auto text-[11px]"
                style="color: var(--text-3);"
                @click="toggleVersions(step.step)"
              >
                <ChevronDownIcon v-if="expandedStep === step.step" class="size-3" />
                <ChevronRightIcon v-else class="size-3" />
                Por versión de prompt
              </Button>
              <Button as-child variant="ghost" size="sm" class="px-0 h-auto text-[11px]" style="color: var(--text-3);">
                <Link :href="`/admin/conversations?step=${step.step}`">
                  Ver conversaciones de este step
                  <ArrowRightIcon class="size-3" />
                </Link>
              </Button>
            </div>

            <!-- Desglose por versión de prompt -->
            <div v-if="expandedStep === step.step" class="mt-2 rounded-[10px] overflow-hidden"
              style="border: 1px solid var(--border-sub);">
              <DataTable
                :columns="breakdownColumns"
                :data="promptBreakdown[step.step]"
                empty-message="Sin desglose para este step."
              >
                <template #cell-version="{ item }">
                  {{ item.version !== null ? `v${item.version}` : 'sin versión' }}
                </template>

                <template #cell-conversion="{ item }">
                  <span class="font-semibold">{{ Math.round(item.conversion * 100) }}%</span>
                </template>

                <template #mobile-row="{ item }">
                  <div
                    class="rounded-[10px] p-3 text-[11px]"
                    style="background: var(--bg-card); border: 1px solid var(--border-sub);"
                  >
                    <div class="flex items-center justify-between mb-1">
                      <span class="font-semibold">{{ item.version !== null ? `v${item.version}` : 'sin versión' }}</span>
                      <span class="font-semibold">{{ Math.round(item.conversion * 100) }}%</span>
                    </div>
                    <p class="truncate" style="color: var(--text-2);">{{ item.notes ?? '—' }}</p>
                    <div class="flex gap-3 mt-1" style="color: var(--text-3);">
                      <span>Entraron: {{ item.entered }}</span>
                      <span>Completaron: {{ item.completed }}</span>
                    </div>
                  </div>
                </template>
              </DataTable>
            </div>
          </CardContent>
        </Card>
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
import { Button } from '@/components/UI/button'
import { Input } from '@/components/UI/input'
import { Card, CardContent } from '@/components/UI/card'
import { BarChart3 as BarChart3Icon, ChevronDown as ChevronDownIcon, ChevronRight as ChevronRightIcon, ArrowRight as ArrowRightIcon } from '@lucide/vue'
import DataTable from '@/components/App/DataTable.vue'

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

const breakdownColumns = [
  { key: 'version', label: 'Versión', sortable: false },
  { key: 'notes', label: 'Notas', sortable: false },
  { key: 'entered', label: 'Entraron', sortable: false, align: 'right' as const },
  { key: 'completed', label: 'Completaron', sortable: false, align: 'right' as const },
  { key: 'conversion', label: 'Conversión', sortable: false, align: 'right' as const },
]

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
