<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-2xl mx-auto">

      <BackLink :href="`/customers/${customer.id}`" label="Cliente" class="mb-4" />

      <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mb-6" style="color: var(--text-1);">
        Editar cliente
      </h1>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- Identidad del tomador -->
        <div class="rounded-[14px] p-5 space-y-4" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Datos del tomador</h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="field-label">Nombre</label>
              <input v-model="form.first_name" type="text" class="field" :class="{ 'field-error': form.errors.first_name }" />
              <p v-if="form.errors.first_name" class="field-error-text">{{ form.errors.first_name }}</p>
            </div>
            <div>
              <label class="field-label">Apellido</label>
              <input v-model="form.last_name" type="text" class="field" :class="{ 'field-error': form.errors.last_name }" />
              <p v-if="form.errors.last_name" class="field-error-text">{{ form.errors.last_name }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="field-label">Tipo doc.</label>
              <Select v-model="form.document_type_id">
                <SelectTrigger class="h-[38px] w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem value="dni">DNI</SelectItem>
                    <SelectItem value="cuit">CUIT</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </div>
            <div class="sm:col-span-2">
              <label class="field-label">Número de documento</label>
              <input v-model="form.dni" type="text" class="field" :class="{ 'field-error': form.errors.dni }" />
              <p v-if="form.errors.dni" class="field-error-text">{{ form.errors.dni }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="field-label">Nacimiento</label>
              <input v-model="form.birthdate" type="date" class="field" :class="{ 'field-error': form.errors.birthdate }" />
              <p v-if="form.errors.birthdate" class="field-error-text">{{ form.errors.birthdate }}</p>
            </div>
            <div>
              <label class="field-label">Sexo</label>
              <Select v-model="form.sex_id">
                <SelectTrigger class="h-[38px] w-full"><SelectValue placeholder="—" /></SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem value="M">Masculino</SelectItem>
                    <SelectItem value="F">Femenino</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="field-label">Tipo persona</label>
              <Select v-model="form.person_type_id">
                <SelectTrigger class="h-[38px] w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem value="fisica">Física</SelectItem>
                    <SelectItem value="juridica">Jurídica</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div>
            <label class="field-label">Condición fiscal</label>
            <Select v-model="form.tax_condition_id">
              <SelectTrigger class="h-[38px] w-full"><SelectValue placeholder="Seleccioná" /></SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem v-for="tc in taxConditions" :key="tc.ref" :value="tc.ref">{{ tc.label }}</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="field-label">Email</label>
              <input v-model="form.email" type="email" class="field" :class="{ 'field-error': form.errors.email }" />
              <p v-if="form.errors.email" class="field-error-text">{{ form.errors.email }}</p>
            </div>
            <div>
              <label class="field-label">Teléfono</label>
              <input v-model="form.phone" type="text" class="field" :class="{ 'field-error': form.errors.phone }" />
              <p v-if="form.errors.phone" class="field-error-text">{{ form.errors.phone }}</p>
            </div>
          </div>
        </div>

        <!-- Domicilio del tomador -->
        <div class="rounded-[14px] p-5 space-y-4" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Domicilio del tomador</h2>
          <p class="text-xs" style="color: var(--text-3);">Domicilio legal/facturación. La ubicación de guarda del vehículo (que tarifa) vive en el riesgo.</p>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="sm:col-span-2">
              <label class="field-label">Calle</label>
              <input v-model="form.domicilio_calle" type="text" class="field" />
            </div>
            <div>
              <label class="field-label">Número</label>
              <input v-model="form.domicilio_numero" type="text" class="field" />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="field-label">Código Postal</label>
              <input v-model="form.domicilio_cp" type="text" class="field" />
            </div>
            <div>
              <label class="field-label">Provincia</label>
              <Select v-model="form.domicilio_provincia">
                <SelectTrigger class="h-[38px] w-full"><SelectValue placeholder="—" /></SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem v-for="p in provincias" :key="p" :value="p">{{ p }}</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </div>
            <div>
              <label class="field-label">Localidad</label>
              <input v-model="form.domicilio_localidad" type="text" class="field" />
            </div>
          </div>
        </div>

        <!-- Gestión -->
        <div class="rounded-[14px] p-5 space-y-4" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-[11px] font-semibold uppercase tracking-wider" style="color: var(--text-3);">Gestión</h2>

          <div>
            <label class="field-label">PAS asignado</label>
            <Select v-model="form.pas_id">
              <SelectTrigger class="h-[38px] w-full"><SelectValue placeholder="Sin asignar" /></SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem :value="NONE">Sin asignar</SelectItem>
                  <SelectItem v-for="u in pasUsers" :key="u.id" :value="String(u.id)">{{ u.name }}</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
          </div>

          <div>
            <label class="field-label">Notas</label>
            <textarea v-model="form.notes" rows="3" class="field" placeholder="Observaciones del productor..."></textarea>
          </div>
        </div>

        <div class="flex justify-end gap-2">
          <Link :href="`/customers/${customer.id}`" class="btn btn-secondary text-sm">Cancelar</Link>
          <button type="submit" class="btn btn-primary text-sm" :disabled="form.processing">
            {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
          </button>
        </div>
      </form>

    </div>
  </div>
</template>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import BackLink from '@/components/UI/BackLink.vue'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

const NONE = '__none__'

const props = defineProps<{
  customer: {
    id: number
    first_name: string | null; last_name: string | null
    dni: string | null; document_type_id: string | null; person_type_id: string | null
    email: string | null; phone: string | null
    birthdate: string | null; sex_id: string | null; tax_condition_id: string | null
    domicilio_calle: string | null; domicilio_numero: string | null; domicilio_cp: string | null
    domicilio_provincia: string | null; domicilio_localidad: string | null
    pas_id: number | null; notes: string
  }
  pasUsers: Array<{ id: number; name: string }>
  taxConditions: Array<{ ref: string; label: string }>
}>()

const form = useForm({
  first_name: props.customer.first_name ?? '',
  last_name: props.customer.last_name ?? '',
  dni: props.customer.dni ?? '',
  document_type_id: props.customer.document_type_id ?? 'dni',
  person_type_id: props.customer.person_type_id ?? 'fisica',
  email: props.customer.email ?? '',
  phone: props.customer.phone ?? '',
  birthdate: props.customer.birthdate ?? '',
  sex_id: props.customer.sex_id ?? '',
  tax_condition_id: props.customer.tax_condition_id ?? '',
  domicilio_calle: props.customer.domicilio_calle ?? '',
  domicilio_numero: props.customer.domicilio_numero ?? '',
  domicilio_cp: props.customer.domicilio_cp ?? '',
  domicilio_provincia: props.customer.domicilio_provincia ?? '',
  domicilio_localidad: props.customer.domicilio_localidad ?? '',
  pas_id: props.customer.pas_id ? String(props.customer.pas_id) : NONE,
  notes: props.customer.notes ?? '',
})

const provincias = [
  'Buenos Aires', 'CABA', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba',
  'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja',
  'Mendoza', 'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan',
  'San Luis', 'Santa Cruz', 'Santa Fe', 'Santiago del Estero',
  'Tierra del Fuego', 'Tucumán',
]

const submit = () => {
  form
    .transform((data) => ({
      ...data,
      pas_id: data.pas_id === NONE ? null : data.pas_id,
    }))
    .put(`/customers/${props.customer.id}`, { preserveScroll: true })
}
</script>
