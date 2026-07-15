<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-2xl mx-auto">

      <AppBackLink href="/polizas" label="Pólizas" class="mb-4" />

      <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mb-6" style="color: var(--text-1);">
        Nueva póliza
      </h1>

      <!-- Paso 1: elegir cliente -->
      <div v-if="!customer"
        class="rounded-[14px] p-5"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-1);">¿Para qué cliente?</h2>
        <form @submit.prevent="searchCustomer" class="flex gap-2">
          <FormItem class="flex-1">
            <FormControl>
              <Input v-model="customerSearchInput" type="text" placeholder="Buscar por nombre, DNI, email o teléfono..." />
            </FormControl>
          </FormItem>
          <Button type="submit">Buscar</Button>
        </form>

        <div v-if="customerMatches.length" class="mt-4 space-y-2">
          <Link v-for="c in customerMatches" :key="c.id"
            :href="`/polizas/create?customer=${c.id}`"
            class="flex items-center justify-between rounded-[10px] p-3 transition-colors"
            style="border: 1px solid var(--border-sub);">
            <div class="min-w-0">
              <p class="text-sm font-semibold truncate" style="color: var(--text-1);">{{ c.name }}</p>
              <p class="text-xs mt-0.5 font-mono" style="color: var(--text-3);">
                {{ c.dni ?? '—' }}<template v-if="c.email"> · {{ c.email }}</template>
              </p>
            </div>
            <span class="text-xs font-semibold" style="color: var(--accent-600);">Elegir →</span>
          </Link>
        </div>
        <p v-else-if="searched" class="text-sm text-center py-6" style="color: var(--text-3);">
          No se encontraron clientes. <Link href="/conversations/create" class="font-semibold" style="color: var(--accent-600);">Crear uno nuevo</Link>.
        </p>
      </div>

      <!-- Paso 2: vehículo + póliza -->
      <form v-else @submit.prevent="submit" class="space-y-5">

        <!-- Cliente elegido -->
        <div class="rounded-[14px] p-4 flex items-center justify-between"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <div>
            <p class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Cliente</p>
            <p class="text-sm font-semibold mt-0.5" style="color: var(--text-1);">
              {{ customer.name }} <span class="font-mono font-normal" style="color: var(--text-3);">· {{ customer.dni ?? '—' }}</span>
            </p>
          </div>
          <Link href="/polizas/create" class="text-xs font-semibold" style="color: var(--accent-600);">Cambiar</Link>
        </div>

        <!-- Vehículo -->
        <div class="rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-sm font-semibold mb-3" style="color: var(--text-1);">Vehículo</h2>

          <div v-if="customer.risks.length" class="flex gap-4 mb-4 text-sm">
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" value="existing" v-model="riskMode" />
              <span style="color: var(--text-1);">Usar uno existente</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
              <input type="radio" value="new" v-model="riskMode" />
              <span style="color: var(--text-1);">Cargar uno nuevo</span>
            </label>
          </div>

          <!-- existente -->
          <div v-if="riskMode === 'existing'">
            <FormItem :error="form.errors.risk_id">
              <FormLabel>Vehículo del cliente *</FormLabel>
              <FormControl>
                <Select v-model="form.risk_id">
                  <SelectTrigger class="w-full">
                    <SelectValue placeholder="Seleccionar..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem v-for="r in customer.risks" :key="r.id" :value="r.id">
                        {{ r.label }}{{ r.patente ? ` — ${r.patente}` : '' }}
                      </SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
              </FormControl>
              <FormMessage />
            </FormItem>
          </div>

          <!-- nuevo -->
          <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <FormItem :error="form.errors['risk.patente']">
              <FormLabel>Patente</FormLabel>
              <FormControl>
                <Input v-model="form.risk.patente" type="text" class="uppercase" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem :error="form.errors['risk.marca']">
              <FormLabel>Marca</FormLabel>
              <FormControl>
                <Input v-model="form.risk.marca" type="text" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem :error="form.errors['risk.modelo']">
              <FormLabel>Modelo</FormLabel>
              <FormControl>
                <Input v-model="form.risk.modelo" type="text" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem :error="form.errors['risk.version']">
              <FormLabel>Versión</FormLabel>
              <FormControl>
                <Input v-model="form.risk.version" type="text" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem :error="form.errors['risk.year']">
              <FormLabel>Año</FormLabel>
              <FormControl>
                <Input v-model="form.risk.year" type="number" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem :error="form.errors['risk.combustible']">
              <FormLabel>Combustible</FormLabel>
              <FormControl>
                <Input v-model="form.risk.combustible" type="text" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem :error="form.errors['risk.uso']">
              <FormLabel>Uso</FormLabel>
              <FormControl>
                <Input v-model="form.risk.uso" type="text" placeholder="particular / comercial" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem :error="form.errors['risk.codigo_postal']">
              <FormLabel>Código postal</FormLabel>
              <FormControl>
                <Input v-model="form.risk.codigo_postal" type="text" />
              </FormControl>
              <FormMessage />
            </FormItem>
          </div>
        </div>

        <!-- Datos de la póliza -->
        <div class="rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-sm font-semibold mb-4" style="color: var(--text-1);">Datos de la póliza</h2>
          <PolizaFields :form="form" :estados="estados" />
        </div>

        <div class="flex justify-end gap-2">
          <Button variant="secondary" as-child>
            <Link href="/polizas">Cancelar</Link>
          </Button>
          <Button type="submit" :disabled="form.processing">
            {{ form.processing ? 'Guardando...' : 'Crear póliza' }}
          </Button>
        </div>
      </form>

    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import AppBackLink from '@/components/App/BackLink.vue'
import { Button } from '@/components/UI/button'
import { FormControl, FormItem, FormLabel, FormMessage } from '@/components/UI/form'
import { Input } from '@/components/UI/input'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'
import PolizaFields from './PolizaFields.vue'

interface CustomerProp {
  id: number
  name: string
  dni: string | null
  risks: Array<{ id: number; label: string; patente: string | null }>
}

const props = defineProps<{
  customer: CustomerProp | null
  customerMatches: Array<{ id: number; name: string; dni: string | null; email: string | null }>
  customerSearch: string
  estados: Array<{ value: string; label: string }>
}>()

const customerSearchInput = ref(props.customerSearch ?? '')
const searched = ref(props.customerSearch !== '')

const searchCustomer = () => {
  searched.value = true
  router.get('/polizas/create', { customer_search: customerSearchInput.value },
    { preserveState: true, replace: true })
}

const riskMode = ref<'existing' | 'new'>(props.customer?.risks.length ? 'existing' : 'new')

const form = useForm({
  customer_id: props.customer?.id ?? null,
  risk_id: null as number | null,
  risk: {
    patente: '', marca: '', modelo: '', version: '',
    year: '' as string | number, combustible: '', uso: '', codigo_postal: '',
  },
  numero: '', company: '', coverage: '', coverage_detail: '',
  sum_asegurada: '' as string | number, cuota: '' as string | number,
  cuota_due: '', vigencia: '', emitida_en: '', estado: 'vigente',
  periodo_corto: false,
})

const submit = () => {
  form.transform((data) => ({
    ...data,
    risk_id: riskMode.value === 'existing' ? data.risk_id : null,
    risk: riskMode.value === 'new' ? data.risk : null,
  })).post('/polizas')
}
</script>
