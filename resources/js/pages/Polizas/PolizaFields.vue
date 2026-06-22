<template>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <div>
      <label class="field-label">Número de póliza</label>
      <input v-model="form.numero" type="text" class="field" :class="{ 'field-error': form.errors.numero }" />
      <p v-if="form.errors.numero" class="field-error-text">{{ form.errors.numero }}</p>
    </div>
    <div>
      <label class="field-label">Compañía</label>
      <input v-model="form.company" type="text" class="field" :class="{ 'field-error': form.errors.company }"
        placeholder="Ej: La Caja Seguros" />
      <p v-if="form.errors.company" class="field-error-text">{{ form.errors.company }}</p>
    </div>
    <div>
      <label class="field-label">Cobertura</label>
      <input v-model="form.coverage" type="text" class="field" placeholder="Ej: Todo riesgo C" />
    </div>
    <div>
      <label class="field-label">Detalle de cobertura</label>
      <input v-model="form.coverage_detail" type="text" class="field" />
    </div>
    <div>
      <label class="field-label">Suma asegurada</label>
      <input v-model="form.sum_asegurada" type="number" step="0.01" class="field"
        :class="{ 'field-error': form.errors.sum_asegurada }" />
      <p v-if="form.errors.sum_asegurada" class="field-error-text">{{ form.errors.sum_asegurada }}</p>
    </div>
    <div>
      <label class="field-label">Cuota</label>
      <input v-model="form.cuota" type="number" step="0.01" class="field"
        :class="{ 'field-error': form.errors.cuota }" />
      <p v-if="form.errors.cuota" class="field-error-text">{{ form.errors.cuota }}</p>
    </div>
    <div>
      <label class="field-label">Vencimiento de cuota</label>
      <input v-model="form.cuota_due" type="date" class="field" />
    </div>
    <div>
      <label class="field-label">Vigencia (vencimiento)</label>
      <input v-model="form.vigencia" type="date" class="field" :class="{ 'field-error': form.errors.vigencia }" />
      <p v-if="form.errors.vigencia" class="field-error-text">{{ form.errors.vigencia }}</p>
    </div>
    <div>
      <label class="field-label">Emitida en</label>
      <input v-model="form.emitida_en" type="date" class="field" />
    </div>
    <div v-if="showEstado">
      <label class="field-label">Estado *</label>
      <Select v-model="form.estado">
        <SelectTrigger class="w-full h-[38px]" :aria-invalid="!!form.errors.estado || undefined">
          <SelectValue placeholder="Seleccionar..." />
        </SelectTrigger>
        <SelectContent>
          <SelectGroup>
            <SelectItem v-for="e in estados" :key="e.value" :value="e.value">{{ e.label }}</SelectItem>
          </SelectGroup>
        </SelectContent>
      </Select>
      <p v-if="form.errors.estado" class="field-error-text">{{ form.errors.estado }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

withDefaults(defineProps<{
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  form: InertiaForm<any>
  estados?: Array<{ value: string; label: string }>
  showEstado?: boolean
}>(), {
  estados: () => [],
  showEstado: true,
})
</script>
