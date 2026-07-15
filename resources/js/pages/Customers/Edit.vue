<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-2xl mx-auto">

      <AppBackLink :href="`/customers/${customer.id}`" label="Cliente" class="mb-4" />

      <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mb-6" style="color: var(--text-1);">
        Editar cliente
      </h1>

      <form @submit.prevent="submit" class="flex flex-col gap-5">

        <!-- Identidad del tomador -->
        <Card>
          <CardContent class="p-5 flex flex-col gap-4">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              Datos del tomador
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormItem name="first_name" :error="form.errors.first_name">
                <FormLabel>Nombre</FormLabel>
                <FormControl>
                  <Input v-model="form.first_name" type="text" />
                </FormControl>
                <FormMessage />
              </FormItem>

              <FormItem name="last_name" :error="form.errors.last_name">
                <FormLabel>Apellido</FormLabel>
                <FormControl>
                  <Input v-model="form.last_name" type="text" />
                </FormControl>
                <FormMessage />
              </FormItem>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <FormItem name="document_type_id" :error="form.errors.document_type_id">
                <FormLabel>Tipo doc.</FormLabel>
                <Select v-model="form.document_type_id">
                  <FormControl>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem value="dni">DNI</SelectItem>
                      <SelectItem value="cuit">CUIT</SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>

              <FormItem class="sm:col-span-2" name="dni" :error="form.errors.dni">
                <FormLabel>Número de documento</FormLabel>
                <FormControl>
                  <Input v-model="form.dni" type="text" />
                </FormControl>
                <FormMessage />
              </FormItem>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <FormItem name="birthdate" :error="form.errors.birthdate">
                <FormLabel>Nacimiento</FormLabel>
                <FormControl>
                  <Input v-model="form.birthdate" type="date" />
                </FormControl>
                <FormMessage />
              </FormItem>

              <FormItem name="sex_id" :error="form.errors.sex_id">
                <FormLabel>Sexo</FormLabel>
                <Select v-model="form.sex_id">
                  <FormControl>
                    <SelectTrigger><SelectValue placeholder="—" /></SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem value="M">Masculino</SelectItem>
                      <SelectItem value="F">Femenino</SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>

              <FormItem name="person_type_id" :error="form.errors.person_type_id">
                <FormLabel>Tipo persona</FormLabel>
                <Select v-model="form.person_type_id">
                  <FormControl>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem value="fisica">Física</SelectItem>
                      <SelectItem value="juridica">Jurídica</SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
                <FormMessage />
              </FormItem>
            </div>

            <FormItem name="tax_condition_id" :error="form.errors.tax_condition_id">
              <FormLabel>Condición fiscal</FormLabel>
              <Select v-model="form.tax_condition_id">
                <FormControl>
                  <SelectTrigger><SelectValue placeholder="Seleccioná" /></SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem v-for="tc in taxConditions" :key="tc.ref" :value="tc.ref">{{ tc.label }}</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
              <FormMessage />
            </FormItem>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <FormItem name="email" :error="form.errors.email">
                <FormLabel>Email</FormLabel>
                <FormControl>
                  <Input v-model="form.email" type="email" />
                </FormControl>
                <FormMessage />
              </FormItem>

              <FormItem name="phone" :error="form.errors.phone">
                <FormLabel>Teléfono</FormLabel>
                <FormControl>
                  <Input v-model="form.phone" type="text" />
                </FormControl>
                <FormMessage />
              </FormItem>
            </div>
          </CardContent>
        </Card>

        <!-- Domicilio del tomador -->
        <Card>
          <CardContent class="p-5 flex flex-col gap-4">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              Domicilio del tomador
            </h2>
            <p class="text-xs text-muted-foreground">
              Domicilio legal/facturación. La ubicación de guarda del vehículo (que tarifa) vive en el riesgo.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <FormItem class="sm:col-span-2" name="domicilio_calle">
                <FormLabel>Calle</FormLabel>
                <FormControl>
                  <Input v-model="form.domicilio_calle" type="text" />
                </FormControl>
              </FormItem>

              <FormItem name="domicilio_numero">
                <FormLabel>Número</FormLabel>
                <FormControl>
                  <Input v-model="form.domicilio_numero" type="text" />
                </FormControl>
              </FormItem>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <FormItem name="domicilio_cp">
                <FormLabel>Código Postal</FormLabel>
                <FormControl>
                  <Input v-model="form.domicilio_cp" type="text" />
                </FormControl>
              </FormItem>

              <FormItem name="domicilio_provincia">
                <FormLabel>Provincia</FormLabel>
                <Select v-model="form.domicilio_provincia">
                  <FormControl>
                    <SelectTrigger><SelectValue placeholder="—" /></SelectTrigger>
                  </FormControl>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem v-for="p in provincias" :key="p" :value="p">{{ p }}</SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
              </FormItem>

              <FormItem name="domicilio_localidad">
                <FormLabel>Localidad</FormLabel>
                <FormControl>
                  <Input v-model="form.domicilio_localidad" type="text" />
                </FormControl>
              </FormItem>
            </div>
          </CardContent>
        </Card>

        <!-- Gestión -->
        <Card>
          <CardContent class="p-5 flex flex-col gap-4">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Gestión</h2>

            <FormItem name="pas_id">
              <FormLabel>PAS asignado</FormLabel>
              <Select v-model="form.pas_id">
                <FormControl>
                  <SelectTrigger><SelectValue placeholder="Sin asignar" /></SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem :value="NONE">Sin asignar</SelectItem>
                    <SelectItem v-for="u in pasUsers" :key="u.id" :value="String(u.id)">{{ u.name }}</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </FormItem>

            <FormItem name="notes">
              <FormLabel>Notas</FormLabel>
              <FormControl>
                <Textarea v-model="form.notes" rows="3" placeholder="Observaciones del productor..." />
              </FormControl>
            </FormItem>
          </CardContent>
        </Card>

        <div class="flex justify-end gap-2">
          <Button type="button" variant="secondary" size="sm" as-child>
            <Link :href="`/customers/${customer.id}`">Cancelar</Link>
          </Button>
          <Button type="submit" size="sm" :disabled="form.processing">
            {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
          </Button>
        </div>
      </form>

    </div>
  </div>
</template>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import AppBackLink from '@/components/App/BackLink.vue'
import { Button } from '@/components/UI/button'
import { Card, CardContent } from '@/components/UI/card'
import { Input } from '@/components/UI/input'
import { Textarea } from '@/components/UI/textarea'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/UI/select'
import { FormControl, FormItem, FormLabel, FormMessage } from '@/components/UI/form'

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
