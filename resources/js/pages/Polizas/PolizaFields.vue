<template>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <FormItem :error="form.errors.numero">
      <FormLabel>Número de póliza</FormLabel>
      <FormControl>
        <Input v-model="form.numero" type="text" />
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem :error="form.errors.company">
      <FormLabel>Compañía</FormLabel>
      <FormControl>
        <Input v-model="form.company" type="text" placeholder="Ej: La Caja Seguros" />
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem :error="form.errors.coverage">
      <FormLabel>Cobertura</FormLabel>
      <FormControl>
        <Input v-model="form.coverage" type="text" placeholder="Ej: Todo riesgo C" />
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem :error="form.errors.coverage_detail">
      <FormLabel>Detalle de cobertura</FormLabel>
      <FormControl>
        <Input v-model="form.coverage_detail" type="text" />
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem :error="form.errors.sum_asegurada">
      <FormLabel>Suma asegurada</FormLabel>
      <FormControl>
        <Input v-model="form.sum_asegurada" type="number" step="0.01" />
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem :error="form.errors.cuota">
      <FormLabel>Cuota</FormLabel>
      <FormControl>
        <Input v-model="form.cuota" type="number" step="0.01" />
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem :error="form.errors.cuota_due">
      <FormLabel>Vencimiento de cuota</FormLabel>
      <FormControl>
        <Input v-model="form.cuota_due" type="date" />
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem :error="form.errors.vigencia">
      <FormLabel>Vigencia (vencimiento)</FormLabel>
      <FormControl>
        <Input v-model="form.vigencia" type="date" />
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem :error="form.errors.emitida_en">
      <FormLabel>Emitida en</FormLabel>
      <FormControl>
        <Input v-model="form.emitida_en" type="date" />
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem v-if="showEstado" :error="form.errors.estado">
      <FormLabel>Estado *</FormLabel>
      <FormControl>
        <Select v-model="form.estado">
          <SelectTrigger class="w-full">
            <SelectValue placeholder="Seleccionar..." />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup>
              <SelectItem v-for="e in estados" :key="e.value" :value="e.value">{{ e.label }}</SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>
      </FormControl>
      <FormMessage />
    </FormItem>

    <FormItem class="sm:col-span-2 flex flex-row items-start gap-3 space-y-0 mt-1" :error="form.errors.periodo_corto">
      <FormControl>
        <Checkbox v-model="form.periodo_corto" />
      </FormControl>
      <div class="space-y-1 leading-none">
        <FormLabel>Período corto (no se renueva)</FormLabel>
        <FormDescription>
          Marca la excepción que no entra en la cola de renovación (p. ej. AP por días).
        </FormDescription>
      </div>
      <FormMessage />
    </FormItem>
  </div>
</template>

<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3'
import { Checkbox } from '@/components/UI/checkbox'
import { FormControl, FormDescription, FormItem, FormLabel, FormMessage } from '@/components/UI/form'
import { Input } from '@/components/UI/input'
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
