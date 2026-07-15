<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-3xl mx-auto">

      <!-- Header -->
      <div class="mb-6 flex items-center justify-between gap-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Configuración de facturación
          </h1>
          <p class="text-sm mt-1" style="color: var(--text-3);">
            Tus datos de emisor y las compañías a las que facturás.
          </p>
        </div>
        <Button as-child variant="secondary" size="sm">
          <Link href="/admin/facturacion">
            <ArrowLeftIcon class="size-3.5" />
            Volver
          </Link>
        </Button>
      </div>

      <!-- ─── Datos del emisor ─── -->
      <Card class="mb-8">
        <CardHeader>
          <CardTitle>Tus datos (emisor)</CardTitle>
        </CardHeader>
        <CardContent>
          <form @submit.prevent="guardarEmisor">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <label class="block sm:col-span-2">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Razón social</span>
                <Input v-model="emisorForm.razon_social" type="text" class="mt-1" />
                <span v-if="emisorForm.errors.razon_social" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ emisorForm.errors.razon_social }}</span>
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">CUIT (11 dígitos, sin guiones)</span>
                <Input v-model="emisorForm.cuit" type="text" inputmode="numeric" placeholder="20304050607" class="mt-1 font-mono" />
                <span v-if="emisorForm.errors.cuit" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ emisorForm.errors.cuit }}</span>
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Punto de venta</span>
                <Input v-model="emisorForm.punto_venta" type="number" min="1" class="mt-1 font-mono" />
                <span v-if="emisorForm.errors.punto_venta" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ emisorForm.errors.punto_venta }}</span>
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Condición frente al IVA</span>
                <Input v-model="emisorForm.condicion_iva" type="text" placeholder="Responsable Monotributo" class="mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Subtítulo (opcional)</span>
                <Input v-model="emisorForm.subtitulo" type="text" placeholder="Productor Asesor de Seguros" class="mt-1" />
              </label>
              <label class="block sm:col-span-2">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Domicilio comercial (opcional)</span>
                <Input v-model="emisorForm.domicilio" type="text" class="mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Ingresos Brutos (opcional)</span>
                <Input v-model="emisorForm.ingresos_brutos" type="text" class="mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Inicio de actividades (opcional)</span>
                <Input v-model="emisorForm.inicio_actividades" type="text" placeholder="01/2020" class="mt-1" />
              </label>
            </div>

            <div class="flex items-center justify-between mt-4">
              <p class="text-[11px]" style="color: var(--text-3);">
                El CUIT debe coincidir con el del certificado de AFIP configurado en el servidor.
              </p>
              <Button type="submit" :disabled="emisorForm.processing">
                {{ emisorForm.processing ? 'Guardando…' : 'Guardar datos' }}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <!-- ─── Compañías ─── -->
      <Card>
        <CardHeader>
          <CardTitle>Compañías a facturar</CardTitle>
        </CardHeader>
        <CardContent>
          <!-- Alta -->
          <form class="mb-4" @submit.prevent="agregar">
            <div class="grid grid-cols-1 sm:grid-cols-[1fr_150px_120px] gap-2 mb-2">
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Razón social</span>
                <Input v-model="nueva.razon_social" type="text" class="mt-1" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">CUIT</span>
                <Input v-model="nueva.cuit" type="text" inputmode="numeric" class="mt-1 font-mono" />
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Cond. IVA</span>
                <Select v-model="nueva.condicion_iva">
                  <SelectTrigger class="mt-1 w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem v-for="c in condiciones" :key="c.value" :value="c.value">{{ c.value }}</SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
              </label>
            </div>
            <div class="flex gap-2 items-end">
              <label class="block flex-1">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Domicilio (opcional)</span>
                <Input v-model="nueva.domicilio" type="text" placeholder="Calle 123 - Ciudad, Provincia" class="mt-1" />
              </label>
              <Button type="submit" :disabled="nueva.processing">Agregar</Button>
            </div>
          </form>
          <p v-if="nueva.errors.cuit" class="text-[11px] -mt-3 mb-3" style="color: var(--badge-danger-txt);">{{ nueva.errors.cuit }}</p>
          <p v-if="nueva.errors.razon_social" class="text-[11px] -mt-3 mb-3" style="color: var(--badge-danger-txt);">{{ nueva.errors.razon_social }}</p>

          <!-- Listado -->
          <DataTable
            v-if="companies.length"
            :columns="columns"
            :data="companies"
            empty-message="Todavía no cargaste ninguna compañía."
          >
            <template #cell-estado="{ item }">
              <Badge :variant="item.activo ? 'default' : 'secondary'">
                {{ item.activo ? 'Activa' : 'Inactiva' }}
              </Badge>
            </template>

            <template #cell-acciones="{ item }">
              <div class="flex items-center justify-end gap-3">
                <Button variant="link" size="sm" class="px-0 h-auto" @click="abrirEdicion(item)">
                  Editar
                </Button>
                <Button variant="link" size="sm" class="px-0 h-auto text-destructive" @click="abrirBorrado(item)">
                  Borrar
                </Button>
              </div>
            </template>

            <template #mobile-row="{ item }">
              <div
                class="rounded-[14px] p-3 text-sm"
                style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
                :style="item.activo ? '' : 'opacity: 0.55;'"
              >
                <div class="flex items-center justify-between mb-1">
                  <span class="font-semibold" style="color: var(--text-1);">{{ item.razon_social }}</span>
                  <Badge :variant="item.activo ? 'default' : 'secondary'">
                    {{ item.activo ? 'Activa' : 'Inactiva' }}
                  </Badge>
                </div>
                <div class="text-xs" style="color: var(--text-3);">
                  <span class="font-mono">{{ item.cuit }}</span> · {{ item.condicion_iva }}
                </div>
                <div class="flex items-center gap-3 mt-2">
                  <Button variant="link" size="sm" class="px-0 h-auto" @click="abrirEdicion(item)">Editar</Button>
                  <Button variant="link" size="sm" class="px-0 h-auto text-destructive" @click="abrirBorrado(item)">Borrar</Button>
                </div>
              </div>
            </template>
          </DataTable>
          <p v-else class="text-sm text-center py-6" style="color: var(--text-3);">
            Todavía no cargaste ninguna compañía.
          </p>
        </CardContent>
      </Card>
    </div>

    <!-- Modal editar compañía -->
    <Dialog :open="!!editando" @update:open="(open) => { if (!open) editando = null }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Editar compañía</DialogTitle>
        </DialogHeader>
        <div class="space-y-3">
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Razón social</span>
            <Input v-model="editForm.razon_social" type="text" class="mt-1" />
            <span v-if="editForm.errors.razon_social" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ editForm.errors.razon_social }}</span>
          </label>
          <div class="grid grid-cols-2 gap-3">
            <label class="block">
              <span class="text-xs font-semibold" style="color: var(--text-2);">CUIT</span>
              <Input v-model="editForm.cuit" type="text" inputmode="numeric" class="mt-1 font-mono" />
              <span v-if="editForm.errors.cuit" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ editForm.errors.cuit }}</span>
            </label>
            <label class="block">
              <span class="text-xs font-semibold" style="color: var(--text-2);">Cond. IVA</span>
              <Select v-model="editForm.condicion_iva">
                <SelectTrigger class="mt-1 w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem v-for="c in condiciones" :key="c.value" :value="c.value">{{ c.value }}</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </label>
          </div>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Domicilio (opcional)</span>
            <Input v-model="editForm.domicilio" type="text" placeholder="Calle 123 - Ciudad, Provincia" class="mt-1" />
          </label>
          <label class="flex items-center gap-3">
            <Switch v-model:checked="editForm.activo" />
            <span class="text-sm" style="color: var(--text-2);">Activa (aparece en el formulario de facturación)</span>
          </label>
        </div>
        <DialogFooter>
          <Button variant="secondary" @click="editando = null">Cancelar</Button>
          <Button :disabled="editForm.processing" @click="guardarEdicion">Guardar</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <!-- Modal borrar compañía -->
    <Dialog :open="!!borrando" @update:open="(open) => { if (!open) borrando = null }">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Borrar compañía</DialogTitle>
          <DialogDescription>
            ¿Borrar «{{ borrando?.razon_social }}»? Si ya tiene facturas emitidas, se desactiva en vez de borrarse (el historial es inmutable).
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="secondary" @click="borrando = null">Cancelar</Button>
          <Button variant="destructive" @click="confirmarBorrado">Borrar</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import { Button } from '@/components/UI/button'
import { Input } from '@/components/UI/input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/UI/card'
import { Badge } from '@/components/UI/badge'
import { Switch } from '@/components/UI/switch'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/UI/dialog'
import { ArrowLeft as ArrowLeftIcon } from '@lucide/vue'
import DataTable from '@/components/App/DataTable.vue'

interface Company { id: number; razon_social: string; cuit: string; condicion_iva: string; domicilio: string | null; activo: boolean }

const props = defineProps<{
  emisor: {
    razon_social: string; cuit: string; punto_venta: number; condicion_iva: string
    subtitulo: string; domicilio: string; ingresos_brutos: string; inicio_actividades: string
  }
  companies: Company[]
}>()

const condiciones = [
  { value: 'RI', label: 'Responsable Inscripto' },
  { value: 'MT', label: 'Monotributo' },
  { value: 'EX', label: 'Exento' },
  { value: 'CF', label: 'Consumidor Final' },
]

const columns = [
  { key: 'razon_social', label: 'Razón social', sortable: false },
  { key: 'cuit', label: 'CUIT', sortable: false },
  { key: 'condicion_iva', label: 'Cond. IVA', sortable: false },
  { key: 'estado', label: 'Estado', sortable: false },
  { key: 'acciones', label: '', sortable: false, align: 'right' as const },
]

// ─── Emisor ───
const emisorForm = useForm({ ...props.emisor })
const guardarEmisor = (): void => {
  emisorForm.put('/admin/facturacion/emisor', { preserveScroll: true })
}

// ─── Alta de compañía ───
const nueva = useForm<{ razon_social: string; cuit: string; condicion_iva: string; domicilio: string; activo: boolean }>({
  razon_social: '', cuit: '', condicion_iva: 'RI', domicilio: '', activo: true,
})
const agregar = (): void => {
  nueva.post('/admin/facturacion/companies', {
    preserveScroll: true,
    onSuccess: () => nueva.reset(),
  })
}

// ─── Edición ───
const editando = ref<Company | null>(null)
const editForm = useForm<{ razon_social: string; cuit: string; condicion_iva: string; domicilio: string; activo: boolean }>({
  razon_social: '', cuit: '', condicion_iva: 'RI', domicilio: '', activo: true,
})
const abrirEdicion = (c: Company): void => {
  borrando.value = null
  editando.value = c
  editForm.clearErrors()
  editForm.razon_social = c.razon_social
  editForm.cuit = c.cuit
  editForm.condicion_iva = c.condicion_iva
  editForm.domicilio = c.domicilio ?? ''
  editForm.activo = c.activo
}
const guardarEdicion = (): void => {
  if (!editando.value) return
  editForm.put(`/admin/facturacion/companies/${editando.value.id}`, {
    preserveScroll: true,
    onSuccess: () => { editando.value = null },
  })
}

// ─── Borrado ───
const borrando = ref<Company | null>(null)
const abrirBorrado = (c: Company): void => {
  editando.value = null
  borrando.value = c
}
const confirmarBorrado = (): void => {
  if (!borrando.value) return
  useForm({}).delete(`/admin/facturacion/companies/${borrando.value.id}`, {
    preserveScroll: true,
    onSuccess: () => { borrando.value = null },
  })
}
</script>
