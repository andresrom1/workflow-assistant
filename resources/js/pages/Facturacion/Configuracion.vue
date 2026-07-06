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
        <a href="/admin/facturacion" class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);">
          ← Volver
        </a>
      </div>

      <!-- ─── Datos del emisor ─── -->
      <form
        class="rounded-[14px] p-4 mb-8"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
        @submit.prevent="guardarEmisor">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-1);">Tus datos (emisor)</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="block sm:col-span-2">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Razón social</span>
            <input v-model="emisorForm.razon_social" type="text" class="field mt-1" />
            <span v-if="emisorForm.errors.razon_social" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ emisorForm.errors.razon_social }}</span>
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">CUIT (11 dígitos, sin guiones)</span>
            <input v-model="emisorForm.cuit" type="text" inputmode="numeric" placeholder="20304050607" class="field mt-1 font-mono" />
            <span v-if="emisorForm.errors.cuit" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ emisorForm.errors.cuit }}</span>
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Punto de venta</span>
            <input v-model="emisorForm.punto_venta" type="number" min="1" class="field mt-1 font-mono" />
            <span v-if="emisorForm.errors.punto_venta" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ emisorForm.errors.punto_venta }}</span>
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Condición frente al IVA</span>
            <input v-model="emisorForm.condicion_iva" type="text" placeholder="Responsable Monotributo" class="field mt-1" />
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Subtítulo (opcional)</span>
            <input v-model="emisorForm.subtitulo" type="text" placeholder="Productor Asesor de Seguros" class="field mt-1" />
          </label>
          <label class="block sm:col-span-2">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Domicilio comercial (opcional)</span>
            <input v-model="emisorForm.domicilio" type="text" class="field mt-1" />
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Ingresos Brutos (opcional)</span>
            <input v-model="emisorForm.ingresos_brutos" type="text" class="field mt-1" />
          </label>
          <label class="block">
            <span class="text-xs font-semibold" style="color: var(--text-2);">Inicio de actividades (opcional)</span>
            <input v-model="emisorForm.inicio_actividades" type="text" placeholder="01/2020" class="field mt-1" />
          </label>
        </div>

        <div class="flex items-center justify-between mt-4">
          <p class="text-[11px]" style="color: var(--text-3);">
            El CUIT debe coincidir con el del certificado de AFIP configurado en el servidor.
          </p>
          <button class="btn btn-primary text-sm py-1.5 px-4" type="submit" :disabled="emisorForm.processing">
            {{ emisorForm.processing ? 'Guardando…' : 'Guardar datos' }}
          </button>
        </div>
      </form>

      <!-- ─── Compañías ─── -->
      <div
        class="rounded-[14px] p-4"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h2 class="text-sm font-semibold mb-3" style="color: var(--text-1);">Compañías a facturar</h2>

        <!-- Alta -->
        <form class="mb-4" @submit.prevent="agregar">
          <div class="grid grid-cols-1 sm:grid-cols-[1fr_150px_120px] gap-2 mb-2">
            <label class="block">
              <span class="text-xs font-semibold" style="color: var(--text-2);">Razón social</span>
              <input v-model="nueva.razon_social" type="text" class="field mt-1" />
            </label>
            <label class="block">
              <span class="text-xs font-semibold" style="color: var(--text-2);">CUIT</span>
              <input v-model="nueva.cuit" type="text" inputmode="numeric" class="field mt-1 font-mono" />
            </label>
            <label class="block">
              <span class="text-xs font-semibold" style="color: var(--text-2);">Cond. IVA</span>
              <select v-model="nueva.condicion_iva" class="field mt-1">
                <option v-for="c in condiciones" :key="c.value" :value="c.value">{{ c.value }}</option>
              </select>
            </label>
          </div>
          <div class="flex gap-2 items-end">
            <label class="block flex-1">
              <span class="text-xs font-semibold" style="color: var(--text-2);">Domicilio (opcional)</span>
              <input v-model="nueva.domicilio" type="text" placeholder="Calle 123 - Ciudad, Provincia" class="field mt-1" />
            </label>
            <button class="btn btn-primary text-sm py-1.5 px-4" type="submit" :disabled="nueva.processing">Agregar</button>
          </div>
        </form>
        <p v-if="nueva.errors.cuit" class="text-[11px] -mt-3 mb-3" style="color: var(--badge-danger-txt);">{{ nueva.errors.cuit }}</p>
        <p v-if="nueva.errors.razon_social" class="text-[11px] -mt-3 mb-3" style="color: var(--badge-danger-txt);">{{ nueva.errors.razon_social }}</p>

        <!-- Listado -->
        <div class="overflow-x-auto" v-if="companies.length">
          <table class="w-full text-sm" style="color: var(--text-2);">
            <thead>
              <tr class="text-left text-xs" style="color: var(--text-3);">
                <th class="py-2 pr-3 font-semibold">Razón social</th>
                <th class="py-2 pr-3 font-semibold">CUIT</th>
                <th class="py-2 pr-3 font-semibold">Cond. IVA</th>
                <th class="py-2 pr-3 font-semibold">Estado</th>
                <th class="py-2 font-semibold text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in companies" :key="c.id" style="border-top: 1px solid var(--border);"
                :style="c.activo ? '' : 'opacity: 0.55;'">
                <td class="py-2 pr-3" style="color: var(--text-1);">{{ c.razon_social }}</td>
                <td class="py-2 pr-3 font-mono">{{ c.cuit }}</td>
                <td class="py-2 pr-3">{{ c.condicion_iva }}</td>
                <td class="py-2 pr-3">
                  <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold"
                    :style="c.activo ? 'background: var(--badge-ok-bg); color: var(--badge-ok-txt);' : 'background: var(--bg-subtle); color: var(--text-3);'">
                    {{ c.activo ? 'Activa' : 'Inactiva' }}
                  </span>
                </td>
                <td class="py-2 text-right whitespace-nowrap">
                  <button class="text-xs underline" style="color: var(--accent-600);" @click="abrirEdicion(c)">Editar</button>
                  <button class="text-xs underline ml-3" style="color: var(--badge-danger-txt);" @click="editando = null; borrando = c">Borrar</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="text-sm text-center py-6" style="color: var(--text-3);">
          Todavía no cargaste ninguna compañía.
        </p>
      </div>
    </div>

    <!-- Modal editar compañía -->
    <Transition name="fade">
      <div v-if="editando" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="editando = null">
        <div class="w-full max-w-md rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-base font-semibold mb-3" style="color: var(--text-1);">Editar compañía</h2>
          <div class="space-y-3">
            <label class="block">
              <span class="text-xs font-semibold" style="color: var(--text-2);">Razón social</span>
              <input v-model="editForm.razon_social" type="text" class="field mt-1" />
              <span v-if="editForm.errors.razon_social" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ editForm.errors.razon_social }}</span>
            </label>
            <div class="grid grid-cols-2 gap-3">
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">CUIT</span>
                <input v-model="editForm.cuit" type="text" inputmode="numeric" class="field mt-1 font-mono" />
                <span v-if="editForm.errors.cuit" class="text-[11px]" style="color: var(--badge-danger-txt);">{{ editForm.errors.cuit }}</span>
              </label>
              <label class="block">
                <span class="text-xs font-semibold" style="color: var(--text-2);">Cond. IVA</span>
                <select v-model="editForm.condicion_iva" class="field mt-1">
                  <option v-for="c in condiciones" :key="c.value" :value="c.value">{{ c.value }}</option>
                </select>
              </label>
            </div>
            <label class="block">
              <span class="text-xs font-semibold" style="color: var(--text-2);">Domicilio (opcional)</span>
              <input v-model="editForm.domicilio" type="text" placeholder="Calle 123 - Ciudad, Provincia" class="field mt-1" />
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" v-model="editForm.activo" />
              <span class="text-sm" style="color: var(--text-2);">Activa (aparece en el formulario de facturación)</span>
            </label>
          </div>
          <div class="flex justify-end gap-2 mt-4">
            <button class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);" @click="editando = null">Cancelar</button>
            <button class="btn btn-primary text-sm py-1.5 px-3" :disabled="editForm.processing" @click="guardarEdicion">Guardar</button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Modal borrar compañía -->
    <Transition name="fade">
      <div v-if="borrando" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4" @click.self="borrando = null">
        <div class="w-full max-w-sm rounded-[14px] p-5"
          style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-base font-semibold mb-1" style="color: var(--text-1);">Borrar compañía</h2>
          <p class="text-sm mb-4" style="color: var(--text-3);">
            ¿Borrar «{{ borrando.razon_social }}»? Si ya tiene facturas emitidas, se desactiva en vez de borrarse (el historial es inmutable).
          </p>
          <div class="flex justify-end gap-2">
            <button class="btn text-sm py-1.5 px-3" style="background: var(--bg-subtle); color: var(--text-2);" @click="borrando = null">Cancelar</button>
            <button class="btn text-sm py-1.5 px-3" style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);" @click="confirmarBorrado">Borrar</button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

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
const confirmarBorrado = (): void => {
  if (!borrando.value) return
  useForm({}).delete(`/admin/facturacion/companies/${borrando.value.id}`, {
    preserveScroll: true,
    onSuccess: () => { borrando.value = null },
  })
}
</script>
