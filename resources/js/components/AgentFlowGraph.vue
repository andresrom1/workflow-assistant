<template>
  <div class="flex items-center">
    <template v-for="(step, idx) in STEPS" :key="step.key">
      <!-- Nodo -->
      <span :title="step.label">
        <div
          class="rounded-full flex items-center justify-center font-bold flex-shrink-0 transition-all"
          :class="[
            large ? 'w-6 h-6 text-[10px]' : 'w-5 h-5 text-[9px]',
            { 'node-active': stepStatus(step.key, idx) === 'active' }
          ]"
          :style="nodeStyle(step.key, idx)"
        >
          <span v-if="stepStatus(step.key, idx) === 'done'">✓</span>
          <span v-else>{{ step.short }}</span>
        </div>
      </span>

      <!-- Conector entre nodos -->
      <div
        v-if="idx < STEPS.length - 1"
        class="h-[2px] flex-shrink-0"
        :class="large ? 'w-4' : 'w-3'"
        :style="{ background: aiState[step.key] ? 'var(--dot-ok, #16a349)' : 'var(--border)' }"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{
  aiState: Record<string, boolean>
  large?: boolean
}>()

const STEPS = [
  { key: 'customer_identified', short: 'C', label: 'Cliente' },
  { key: 'vehicle_identified',  short: 'V', label: 'Vehículo' },
  { key: 'coverage_set',        short: 'K', label: 'Cobertura' },
  { key: 'quote_ready',         short: 'Q', label: 'Cotización' },
  { key: 'checkout_done',       short: 'P', label: 'Checkout' },
]

const stepStatus = (key: string, idx: number): 'done' | 'active' | 'pending' => {
  if (props.aiState[key]) { return 'done' }
  const firstFalse = STEPS.findIndex(s => !props.aiState[s.key])
  if (idx === firstFalse) { return 'active' }
  return 'pending'
}

const nodeStyle = (key: string, idx: number): string => {
  const status = stepStatus(key, idx)
  if (status === 'done') {
    return 'background: var(--badge-ok-bg, #dcfce7); color: var(--dot-ok, #16a349); border: 1.5px solid var(--dot-ok, #16a349);'
  }
  if (status === 'active') {
    return 'background: #dbeafe; color: #1d4ed8; border: 1.5px solid #3b82f6;'
  }
  return 'background: var(--border-sub); color: var(--text-3); border: 1.5px solid var(--border);'
}
</script>

<style scoped>
@keyframes ring-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, .5); }
  50%       { box-shadow: 0 0 0 4px rgba(59, 130, 246, 0); }
}

.node-active {
  animation: ring-pulse 2s ease-in-out infinite;
}
</style>
