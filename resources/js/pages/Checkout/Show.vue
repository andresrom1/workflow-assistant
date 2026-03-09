<template>
  <!-- ══════ FALLBACK: no es dispositivo móvil ══════ -->
  <div v-if="!isMobile" class="min-h-screen bg-gray-900 flex items-center justify-center p-6">
    <div class="text-center max-w-sm">
      <div class="text-6xl mb-6">📱</div>
      <h1 class="text-2xl font-bold text-white mb-3">Abrí este link desde tu celular</h1>
      <p class="text-gray-400 text-sm leading-relaxed">
        Este formulario fue diseñado para completarse desde un dispositivo móvil
        <strong class="text-gray-200">(Android o iOS)</strong>.<br><br>
        Por favor, copiá el link y abrilo desde tu teléfono.
      </p>
      <div class="mt-8 flex justify-center gap-4">
        <div class="flex items-center gap-2 bg-gray-800 rounded-xl px-4 py-3">
          <span class="text-2xl">🤖</span>
          <span class="text-sm text-gray-300 font-medium">Android</span>
        </div>
        <div class="flex items-center gap-2 bg-gray-800 rounded-xl px-4 py-3">
          <span class="text-2xl">🍎</span>
          <span class="text-sm text-gray-300 font-medium">iOS</span>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════ ESTADO INVÁLIDO ══════ -->
  <div v-else-if="quote.status !== 'checkout_pending'"
    class="min-h-screen bg-gray-50 flex items-center justify-center p-6">
    <div class="text-center max-w-sm">
      <div class="text-6xl mb-6">⚠️</div>
      <h1 class="text-2xl font-bold text-gray-900 mb-3">Link no disponible</h1>
      <p class="text-gray-500 text-sm leading-relaxed">
        Este link de checkout ya fue procesado, ha expirado o no es válido para cargar información.
      </p>
    </div>
  </div>

  <!-- ══════ FORMULARIO PRINCIPAL (mobile only) ══════ -->
  <div v-else class="min-h-screen bg-gray-50">

    <!-- Header sticky con cobertura -->
    <div class="bg-white border-b border-gray-200 px-4 py-4 sticky top-0 z-10 shadow-sm">
      <p class="text-xs font-semibold text-blue-600 uppercase tracking-wider">Cobertura seleccionada</p>
      <div class="flex items-center justify-between mt-1">
        <div>
          <p class="font-bold text-gray-900 text-sm">{{ alternative.aseguradora }} — {{ alternative.titulo }}</p>
          <p class="text-xs text-gray-500">{{ vehicle.marca }} {{ vehicle.modelo }} {{ vehicle.year }} <span
              v-if="vehicle.patente">· {{ vehicle.patente }}</span></p>
        </div>
        <div class="text-right">
          <span class="text-lg font-bold text-gray-900">${{ formatPrice(alternative.precio) }}</span>
          <span class="text-xs text-gray-400 block">/mes</span>
        </div>
      </div>
    </div>

    <!-- Indicador de pasos -->
    <div class="bg-white border-b border-gray-100 px-4 py-3">
      <div class="flex items-center justify-between">
        <div v-for="(label, i) in stepLabels" :key="i" class="flex items-center">
          <div class="flex flex-col items-center">
            <div
              class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-200"
              :class="stepCircleClass(i + 1)">
              <span v-if="step > i + 1">✓</span>
              <span v-else>{{ i + 1 }}</span>
            </div>
            <span class="text-xs mt-1 font-medium" :class="step === i + 1 ? 'text-blue-600' : 'text-gray-400'">{{ label
            }}</span>
          </div>
          <div v-if="i < stepLabels.length - 1" class="w-6 h-px bg-gray-200 mb-4 mx-1" />
        </div>
      </div>
    </div>

    <div ref="formRef" class="pb-8">

      <!-- ══════════ PASO 1: Datos personales ══════════ -->
      <div v-show="step === 1" class="px-4 pt-6 space-y-4">
        <h2 class="text-base font-bold text-gray-800">Datos del tomador</h2>

        <div class="bg-white rounded-xl border border-gray-200 p-4 space-y-4">
          <Field label="Nombre completo *" :error="errors.nombre">
            <input v-model="form.nombre" type="text" name="nombre" placeholder="Juan Alberto Pérez" class="field"
              :class="{ 'field-error': errors.nombre }" autocomplete="name" />
          </Field>

          <Field label="DNI *" :error="errors.dni">
            <input v-model="form.dni" type="text" name="dni" placeholder="30000000" inputmode="numeric" class="field"
              :class="{ 'field-error': errors.dni }" />
          </Field>

          <Field label="Email *" :error="errors.email">
            <input v-model="form.email" type="email" name="email" placeholder="juan@ejemplo.com" class="field"
              :class="{ 'field-error': errors.email }" autocomplete="email" />
          </Field>

          <Field label="Teléfono *" :error="errors.telefono">
            <input v-model="form.telefono" type="tel" name="telefono" placeholder="+54 9 11 1234-5678" class="field"
              :class="{ 'field-error': errors.telefono }" autocomplete="tel" />
          </Field>
        </div>

        <h2 class="text-base font-bold text-gray-800 pt-2">Domicilio</h2>
        <div class="bg-white rounded-xl border border-gray-200 p-4 space-y-4">
          <Field label="Calle *" :error="errors.domicilio_calle">
            <input v-model="form.domicilio_calle" type="text" name="domicilio_calle" placeholder="Av. Siempreviva"
              class="field" :class="{ 'field-error': errors.domicilio_calle }" autocomplete="street-address" />
          </Field>

          <div class="grid grid-cols-2 gap-3 items-start">
            <Field label="Número *" :error="errors.domicilio_numero">
              <input v-model="form.domicilio_numero" type="text" name="domicilio_numero" placeholder="742" class="field"
                :class="{ 'field-error': errors.domicilio_numero }" inputmode="numeric" />
            </Field>
            <Field label="Código Postal *" :error="errors.domicilio_cp">
              <input v-model="form.domicilio_cp" type="text" name="domicilio_cp" placeholder="1414" class="field"
                :class="{ 'field-error': errors.domicilio_cp }" inputmode="numeric" autocomplete="postal-code" />
            </Field>
          </div>

          <Field label="Provincia *" :error="errors.domicilio_provincia">
            <select v-model="form.domicilio_provincia" name="domicilio_provincia" class="field"
              :class="{ 'field-error': errors.domicilio_provincia }">
              <option value="" disabled>Seleccioná la provincia</option>
              <option v-for="p in provincias" :key="p" :value="p">{{ p }}</option>
            </select>
          </Field>

          <Field label="Localidad *" :error="errors.domicilio_localidad">
            <input v-model="form.domicilio_localidad" type="text" name="domicilio_localidad" placeholder="Buenos Aires"
              class="field" :class="{ 'field-error': errors.domicilio_localidad }" autocomplete="address-level2" />
          </Field>
        </div>

        <div class="flex justify-end pt-2">
          <button type="button" @click="goToStep(2)" class="btn-primary">Siguiente →</button>
        </div>
      </div>

      <!-- ══════════ PASO 2: Datos de pago ══════════ -->
      <div v-show="step === 2" class="px-4 pt-6 space-y-4">
        <h2 class="text-base font-bold text-gray-800">Datos de pago</h2>

        <div class="bg-white rounded-xl border border-gray-200 p-4 space-y-4">
          <Field label="Marca de tarjeta *" :error="errors.cc_brand">
            <select v-model="form.cc_brand" name="cc_brand" class="field" :class="{ 'field-error': errors.cc_brand }">
              <option value="" disabled>Seleccioná la marca</option>
              <option value="visa">Visa</option>
              <option value="mastercard">Mastercard</option>
              <option value="amex">American Express</option>
              <option value="naranja">Naranja</option>
              <option value="cabal">Cabal</option>
              <option value="maestro">Maestro</option>
            </select>
          </Field>

          <Field label="Número de tarjeta *" :error="errors.cc_pan">
            <input v-model="form.cc_pan" type="text" name="cc_pan" placeholder="4111 1111 1111 1111" maxlength="19"
              inputmode="numeric" @input="formatPan" @blur="validatePanLuhn" class="field font-mono"
              :class="{ 'field-error': errors.cc_pan }" autocomplete="off" />
          </Field>

          <div class="max-w-[150px]">
            <Field label="Vencimiento *" :error="errors.cc_expiry">
              <input v-model="form.cc_expiry" type="text" name="cc_expiry" placeholder="MM/AA" maxlength="5"
                inputmode="numeric" @input="formatExpiry" class="field font-mono"
                :class="{ 'field-error': errors.cc_expiry }" autocomplete="off" />
            </Field>
          </div>

          <Field label="Nombre del titular *" :error="errors.cc_holder_name">
            <input v-model="form.cc_holder_name" type="text" name="cc_holder_name" placeholder="Juan Alberto Pérez"
              class="field" :class="{ 'field-error': errors.cc_holder_name }" autocomplete="off" />
          </Field>

          <Field label="DNI del titular *" :error="errors.cc_holder_dni">
            <input v-model="form.cc_holder_dni" type="text" name="cc_holder_dni" placeholder="30000000"
              inputmode="numeric" class="field" :class="{ 'field-error': errors.cc_holder_dni }" autocomplete="off" />
            <p class="text-xs text-gray-400 mt-1">Puede diferir del tomador del seguro.</p>
          </Field>
        </div>

        <div class="flex justify-between pt-2">
          <button type="button" @click="step = 1" class="btn-ghost">← Atrás</button>
          <button type="button" @click="goToStep(3)" class="btn-primary">Siguiente →</button>
        </div>
      </div>

      <!-- ══════════ PASO 3: Verificación del vehículo ══════════ -->
      <div v-show="step === 3" class="px-4 pt-6 space-y-4">
        <h2 class="text-base font-bold text-gray-800">Verificación del vehículo</h2>
        <p class="text-xs text-gray-500">Confirmá que los datos del vehículo sean correctos. Estos datos son inmutables
          y provienen del snapshot de cotización.</p>

        <!-- Datos inmutables del snapshot -->
        <div class="bg-blue-50 rounded-xl border border-blue-100 p-4 space-y-3">
          <div class="flex items-center justify-between">
            <span class="text-xs text-blue-600 font-semibold uppercase tracking-wider">Datos del snapshot</span>
            <span class="text-xs bg-blue-100 text-blue-700 rounded-full px-2 py-0.5 font-medium">Solo lectura</span>
          </div>
          <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <ReadOnlyField v-if="vehicle.patente" label="Patente" :value="vehicle.patente" />
            <ReadOnlyField label="Marca" :value="vehicle.marca" />
            <ReadOnlyField label="Modelo" :value="vehicle.modelo" />
            <ReadOnlyField label="Año" :value="String(vehicle.year)" />
            <ReadOnlyField label="Versión" :value="vehicle.version" />
            <ReadOnlyField label="Combustible" :value="vehicle.combustible" />
          </div>
        </div>

        <!-- Datos adicionales que ingresa el cliente -->
        <h3 class="text-sm font-bold text-gray-700 pt-1">Datos adicionales</h3>
        <div class="bg-white rounded-xl border border-gray-200 p-4 space-y-4">
          <Field label="Uso del vehículo *" :error="errors.vehiculo_uso">
            <div class="grid grid-cols-2 gap-3 mt-1">
              <label class="flex items-center gap-2 border rounded-lg px-3 py-2.5 cursor-pointer transition-colors"
                :class="form.vehiculo_uso === 'particular' ? 'border-blue-500 bg-blue-50' : 'border-gray-300'">
                <input type="radio" v-model="form.vehiculo_uso" value="particular" name="vehiculo_uso" class="hidden" />
                <span class="text-xl">🚗</span>
                <span class="text-sm font-medium">Particular</span>
              </label>
              <label class="flex items-center gap-2 border rounded-lg px-3 py-2.5 cursor-pointer transition-colors"
                :class="form.vehiculo_uso === 'otro' ? 'border-blue-500 bg-blue-50' : 'border-gray-300'">
                <input type="radio" v-model="form.vehiculo_uso" value="otro" name="vehiculo_uso" class="hidden" />
                <span class="text-xl">🚕</span>
                <span class="text-sm font-medium">Otro</span>
              </label>
            </div>
            <p v-if="errors.vehiculo_uso" class="text-xs text-red-600 mt-1">{{ errors.vehiculo_uso }}</p>
          </Field>

          <Field label="Nro. de chasis *" :error="errors.vehiculo_nro_chasis">
            <input v-model="form.vehiculo_nro_chasis" type="text" name="vehiculo_nro_chasis"
              placeholder="9BWZZZ377VT004251" class="field font-mono text-sm"
              :class="{ 'field-error': errors.vehiculo_nro_chasis }" style="text-transform: uppercase"
              @input="form.vehiculo_nro_chasis = form.vehiculo_nro_chasis.toUpperCase()" />
          </Field>

          <Field label="Nro. de motor *" :error="errors.vehiculo_nro_motor">
            <input v-model="form.vehiculo_nro_motor" type="text" name="vehiculo_nro_motor" placeholder="AZD5789"
              class="field font-mono text-sm" :class="{ 'field-error': errors.vehiculo_nro_motor }"
              style="text-transform: uppercase"
              @input="form.vehiculo_nro_motor = form.vehiculo_nro_motor.toUpperCase()" />
          </Field>
        </div>

        <div class="flex justify-between pt-2">
          <button type="button" @click="step = 2" class="btn-ghost">← Atrás</button>
          <button type="button" @click="goToStep(4)" class="btn-primary">Siguiente →</button>
        </div>
      </div>

      <!-- ══════════ PASO 4: Inspección fotográfica ══════════ -->
      <div v-show="step === 4" class="px-4 pt-6 space-y-4">
        <h2 class="text-base font-bold text-gray-800">Inspección fotográfica</h2>
        <p class="text-xs text-gray-500 leading-relaxed">
          Sacá cada foto <strong>en este momento</strong> con la cámara de tu teléfono.
          No se permite subir imágenes desde la galería.
        </p>

        <div class="space-y-3">
          <div v-for="(slot, i) in photoSlots" :key="slot.key"
            class="bg-white rounded-xl border overflow-hidden transition-colors"
            :class="photoIds[slot.key] ? 'border-green-400' : (uploading[slot.key] ? 'border-blue-400' : (errors[`photo_${slot.key}`] ? 'border-red-400' : 'border-gray-200'))">
            <div class="flex items-center gap-3 p-3">
              <!-- Preview / ícono -->
              <div
                class="flex-shrink-0 w-16 h-16 rounded-lg overflow-hidden bg-gray-100 flex items-center justify-center">
                <img v-if="photos[slot.key]" :src="photos[slot.key]" class="w-full h-full object-cover"
                  :alt="slot.label" />
                <div v-else-if="uploading[slot.key]"
                  class="animate-spin w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full"></div>
                <span v-else class="text-2xl">{{ slot.icon }}</span>
              </div>

              <div class="flex-1 min-w-0">
                <p class="font-medium text-sm text-gray-800">{{ slot.label }}</p>
                <p v-if="uploading[slot.key]" class="text-xs text-blue-600 mt-0.5">Subiendo foto…</p>
                <p v-else class="text-xs text-gray-500 mt-0.5">{{ slot.hint }}</p>
                <p v-if="errors[`photo_${slot.key}`]" class="text-xs text-red-600 mt-0.5">{{ errors[`photo_${slot.key}`]
                }}</p>
              </div>

              <!-- Botón eliminar (solo si hay foto subida) -->
              <button v-if="photoIds[slot.key] && !uploading[slot.key]"
                type="button"
                @click="removePhoto(slot.key)"
                class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 flex items-center justify-center transition-colors active:bg-red-200">
                <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
              </button>

              <!-- Botón cámara -->
              <label class="flex-shrink-0 cursor-pointer"
                :class="{ 'pointer-events-none opacity-50': uploading[slot.key] }"
                @click.stop>
                <input type="file" accept="image/*" capture="environment" class="hidden"
                  @change="onPhotoCapture($event, slot.key)" :disabled="!!uploading[slot.key]" />
                <div class="w-10 h-10 rounded-full flex items-center justify-center transition-colors"
                  :class="photoIds[slot.key] ? 'bg-green-500' : (uploading[slot.key] ? 'bg-gray-400' : 'bg-blue-600')">
                  <span v-if="photoIds[slot.key]" class="text-white text-sm">✓</span>
                  <svg v-else class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                </div>
              </label>
            </div>
          </div>
        </div>

        <!-- Progreso de fotos -->
        <div class="bg-white rounded-xl border border-gray-200 p-3">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-gray-700">Progreso</span>
            <span class="text-sm font-bold"
              :class="photoCount === photoSlots.length ? 'text-green-600' : 'text-gray-500'">{{ photoCount }}/{{
                photoSlots.length }}</span>
          </div>
          <div class="flex gap-1">
            <div v-for="(slot, i) in photoSlots" :key="slot.key" class="h-1.5 flex-1 rounded-full transition-colors"
              :class="photoIds[slot.key] ? 'bg-green-500' : 'bg-gray-200'" />
          </div>
        </div>

        <!-- Resumen antes del envío -->
        <div class="bg-gray-50 rounded-xl border border-gray-200 p-4 text-sm">
          <p class="font-semibold text-gray-700 mb-2">Resumen final</p>
          <div class="space-y-1 text-gray-600">
            <p><span class="text-gray-400">Tomador:</span> {{ form.nombre || '—' }}</p>
            <p><span class="text-gray-400">Vehículo:</span> {{ vehicle.marca }} {{ vehicle.modelo }} {{ vehicle.year }}
            </p>
            <p><span class="text-gray-400">Cobertura:</span> {{ alternative.aseguradora }} — {{ alternative.titulo }}
            </p>
            <p><span class="text-gray-400">Prima:</span> ${{ formatPrice(alternative.precio) }}/mes</p>
            <p><span class="text-gray-400">Fotos:</span> {{ photoCount }}/{{ photoSlots.length }}</p>
          </div>
        </div>

        <div class="flex justify-between pt-2">
          <button type="button" @click="step = 3" class="btn-ghost">← Atrás</button>
          <button type="button" :disabled="submitting || photoCount < photoSlots.length" class="btn-submit"
            @click="submitForm">
            <span v-if="submitting">Enviando…</span>
            <span v-else-if="photoCount < photoSlots.length">Fotos incompletas ({{ photoCount }}/{{ photoSlots.length
            }})</span>
            <span v-else>Confirmar y enviar ✓</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, defineComponent, h, onMounted, onUnmounted } from 'vue'
// import { ref, reactive, computed, defineComponent, h } from 'vue'

// ─── Componentes inline ────────────────────────────────────────────────────────
const Field = defineComponent({
  props: { label: String, error: String },
  setup(props, { slots }) {
    return () => h('div', [
      h('span', { class: 'block text-sm font-medium text-gray-700 mb-1' }, props.label),
      slots.default?.(),
      props.error ? h('p', { class: 'mt-1 text-xs text-red-600' }, props.error) : null,
    ])
  }
})

const ReadOnlyField = defineComponent({
  props: { label: String, value: String },
  setup(props) {
    return () => h('div', [
      h('p', { class: 'text-xs text-gray-500' }, props.label),
      h('p', { class: 'font-semibold text-gray-800 truncate' }, props.value || '—'),
    ])
  }
})

// ─── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
  quote: { id: number; status: string }
  alternative: {
    id: number; aseguradora: string; titulo: string; descripcion: string
    precio: number; moneda: string; marketing_title: string
    features_tags: string[]; normalized_grade: string
  }
  vehicle: {
    patente: string | null; marca: string; modelo: string
    version: string; year: number; combustible: string
  }
  checkoutToken: string
  submitUrl: string
  uploadPhotoUrl: string
  deletePhotoUrl: string
}>()

// ─── Mobile detection ──────────────────────────────────────────────────────────
// const isMobile = computed(() =>
//   /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
// )
// const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
const isMobile = true  // Forzar mobile durante desarrollo
// ─── CSRF ──────────────────────────────────────────────────────────────────────
const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''

// ─── Prevenir que Inertia recargue el componente al volver de la cámara ────────
// Android dispara popstate cuando vuelve de la cámara, Inertia lo intercepta
// y destruye el estado de Vue. Capturamos el evento antes que Inertia (fase capture).
const stopPopState = (e: PopStateEvent) => {
  e.stopImmediatePropagation()
}

// También prevenimos visibilitychange que puede triggear una visita de Inertia
const stopVisibilityChange = () => {
  // No hacer nada — solo evitar que Inertia reaccione
}

onMounted(() => {
  window.addEventListener('popstate', stopPopState, true)

  // Debug — borrar después de confirmar
  window.addEventListener('beforeunload', () => {
    console.log('[beforeunload] RECARGA DETECTADA')
  })
  
  document.addEventListener('visibilitychange', () => {
    console.log('[visibilitychange]', document.visibilityState)
  })
})

onUnmounted(() => {
  window.removeEventListener('popstate', stopPopState, true)
  document.removeEventListener('visibilitychange', stopVisibilityChange, true)
})

// ─── Wizard ────────────────────────────────────────────────────────────────────
const step = ref(1)
const stepLabels = ['Personal', 'Pago', 'Vehículo', 'Inspección']
const submitting = ref(false)

const stepCircleClass = (s: number) => {
  if (step.value > s) return 'bg-green-500 text-white'
  if (step.value === s) return 'bg-blue-600 text-white'
  return 'bg-gray-200 text-gray-500'
}

// ─── Form data ─────────────────────────────────────────────────────────────────
const form = reactive({
  nombre: '', dni: '', email: '', telefono: '',
  domicilio_calle: '', domicilio_numero: '', domicilio_cp: '',
  domicilio_provincia: '', domicilio_localidad: '',
  cc_brand: '', cc_pan: '', cc_expiry: '', cc_holder_name: '', cc_holder_dni: '',
  vehiculo_uso: '' as 'particular' | 'otro' | '',
  vehiculo_nro_chasis: '', vehiculo_nro_motor: '',
})

const errors = reactive<Record<string, string>>({})

// ─── Provincias ────────────────────────────────────────────────────────────────
const provincias = [
  'Buenos Aires', 'CABA', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba',
  'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja',
  'Mendoza', 'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan',
  'San Luis', 'Santa Cruz', 'Santa Fe', 'Santiago del Estero',
  'Tierra del Fuego', 'Tucumán',
]

// ─── Fotos ─────────────────────────────────────────────────────────────────────
const photoSlots = [
  { key: 'tarjeta_verde', label: 'Frente Tarjeta Verde', icon: '📝', hint: 'Foto de frente' },
  { key: 'frente', label: 'Frente del vehículo', icon: '🚗', hint: 'Vista frontal completa' },
  { key: 'atras', label: 'Atrás del vehículo', icon: '🔙', hint: 'Vista trasera completa' },
  { key: 'lateral_i', label: 'Lateral izquierdo', icon: '◀️', hint: 'Desde el lado izquierdo' },
  { key: 'lateral_d', label: 'Lateral derecho', icon: '▶️', hint: 'Desde el lado derecho' },
  { key: 'auxilio', label: 'Rueda de auxilio', icon: '🔧', hint: 'En su habitáculo / baúl' },
  { key: 'parabrisas', label: 'Parabrisas desde el interior', icon: '🪟', hint: 'Sentado adentro, mirando adelante' },
]

const photos = reactive<Record<string, string>>({})     // key → preview URL (Cloudinary URL)
const photoIds = reactive<Record<string, string>>({})   // key → Cloudinary public_id
const uploading = reactive<Record<string, boolean>>({}) // key → upload in progress
const photoCount = computed(() => Object.keys(photoIds).length)

/** Redimensiona a máx 1024px y convierte a JPEG para ahorrar memoria. */
const processPhoto = (file: File): Promise<File> =>
  new Promise((resolve, reject) => {
    const img = new Image()
    const objectUrl = URL.createObjectURL(file)
    img.onerror = () => {
      URL.revokeObjectURL(objectUrl)
      reject(new Error('No se pudo procesar la imagen'))
    }
    img.onload = () => {
      URL.revokeObjectURL(objectUrl)
      try {
        const MAX = 1024
        let w = img.width, h = img.height
        if (w > MAX || h > MAX) {
          if (w >= h) { h = Math.round((h * MAX) / w); w = MAX }
          else { w = Math.round((w * MAX) / h); h = MAX }
        }
        const canvas = document.createElement('canvas')
        canvas.width = w
        canvas.height = h
        canvas.getContext('2d')!.drawImage(img, 0, 0, w, h)
        canvas.toBlob(blob => {
          if (!blob) { reject(new Error('Error al generar JPEG')); return }
          const finalFile = new File([blob], 'photo.jpg', { type: 'image/jpeg' })
          resolve(finalFile)
        }, 'image/jpeg', 0.7)
      } catch (err) {
        reject(err)
      }
    }
    img.src = objectUrl
  })

/** Sube una foto al servidor y luego a Cloudinary, liberando la memoria inmediatamente. */
const onPhotoCapture = async (e: Event, key: string) => {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  // Limpiar el input para liberar la referencia al archivo original
  input.value = ''
  delete errors[`photo_${key}`]
  uploading[key] = true

  try {
    console.log(`[onPhotoCapture] Iniciando proceso para foto: ${key}`)
    const processedFile = await processPhoto(file)
    console.log(`[onPhotoCapture] Foto procesada correctamente.`, processedFile)

    // Subir al servidor (que sube a Cloudinary)
    const fd = new FormData()
    fd.append('_token', csrfToken)
    fd.append('checkout_token', props.checkoutToken)
    fd.append('photo_key', key)
    fd.append('photo', processedFile)

    const res = await fetch(props.uploadPhotoUrl, { method: 'POST', body: fd })
    
    let data;
    try {
      data = await res.json()
    } catch(err) {
      console.error("[onPhotoCapture] Error leyendo JSON de respuesta", err)
      throw new Error('Respuesta del servidor no es JSON')
    }

    if (!res.ok || !data.success) {
      console.error("[onPhotoCapture] Error del servidor:", data)
      throw new Error(data.error || 'Error al subir la foto')
    }

    // Guardar el ID y la URL de Cloudinary — liberar cualquier blob previo
    if (photos[key] && photos[key].startsWith('blob:')) {
      URL.revokeObjectURL(photos[key])
    }
    photoIds[key] = data.public_id
    photos[key] = data.url  // URL de Cloudinary, no blob
    console.log(`[onPhotoCapture] Éxito: ${data.public_id}`, data.url)
    // El File ya fue enviado y no se almacena en memoria
  } catch (err: any) {
    console.error(`[onPhotoCapture] Gran error capturado para ${key}:`, err)
    errors[`photo_${key}`] = err.message || 'Error al subir la foto. Intentá de nuevo.'
  } finally {
    uploading[key] = false
  }
}

/** 
 * Elimina una foto mediante Optimistic UI. 
 * Primero limpia la interfaz y luego pide la eliminación del asset en backend. 
 */
const removePhoto = async (key: string) => {
  // 1. Optimistic UI: Limpiar localmente la foto instantáneamente
  if (photos[key] && photos[key].startsWith('blob:')) {
    URL.revokeObjectURL(photos[key])
  }
  delete photos[key]
  delete photoIds[key]
  delete errors[`photo_${key}`]

  // 2. Ejecutar borrado asíncrono hacia el backend
  try {
    const res = await fetch(props.deletePhotoUrl, {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({
        checkout_token: props.checkoutToken,
        photo_key: key
      })
    })

    let data = {}
    try {
      data = await res.json()
    } catch {
      // Ignorar error si la respuesta no trae body (ej 204 No Content)
    }

    if (!res.ok) {
      throw new Error((data as any).error || 'Error de red al borrar')
    }
  } catch (err) {
    // 3. Fallo en red: Como es Optimistic UI, informamos el problema pero lo hemos ocultado.  
    // Si bien no existe local, la CleanupTempPhotos lo borrará 24h más tarde.
    console.error(`Fallo borrado silencioso de foto ${key}`, err)
    alert('Hubo un error de conexión al limpiar la foto, pero podés continuar completando el formulario.')
  }
}

// ─── Luhn ──────────────────────────────────────────────────────────────────────
const luhn = (num: string): boolean => {
  const digits = num.replace(/\D/g, '')
  if (digits.length === 0) return false
  let sum = 0, isEven = false
  for (let i = digits.length - 1; i >= 0; i--) {
    let n = parseInt(digits[i])
    if (isEven) { n *= 2; if (n > 9) n -= 9 }
    sum += n; isEven = !isEven
  }
  return sum % 10 === 0
}

// ─── Formateo de tarjeta ───────────────────────────────────────────────────────
const formatPan = () => {
  const raw = form.cc_pan.replace(/\D/g, '').slice(0, 16)
  form.cc_pan = raw.replace(/(.{4})/g, '$1 ').trim()
}

const validatePanLuhn = () => {
  const raw = form.cc_pan.replace(/\s/g, '')
  if (raw.length > 0 && !luhn(raw))
    errors.cc_pan = 'Número de tarjeta inválido'
  else
    delete errors.cc_pan
}

const formatExpiry = () => {
  const raw = form.cc_expiry.replace(/\D/g, '').slice(0, 4)
  form.cc_expiry = raw.length > 2 ? `${raw.slice(0, 2)}/${raw.slice(2)}` : raw
}

// ─── Validación por paso ───────────────────────────────────────────────────────
const validateStep = (s: number): boolean => {
  const clear = (keys: string[]) => keys.forEach(k => delete errors[k])

  if (s === 1) {
    clear(['nombre', 'dni', 'email', 'telefono', 'domicilio_calle', 'domicilio_numero', 'domicilio_cp', 'domicilio_provincia', 'domicilio_localidad'])
    if (!form.nombre.trim()) errors.nombre = 'Requerido'
    if (!form.dni.trim()) errors.dni = 'Requerido'
    if (!form.email.trim()) errors.email = 'Requerido'
    if (!form.telefono.trim()) errors.telefono = 'Requerido'
    if (!form.domicilio_calle.trim()) errors.domicilio_calle = 'Requerido'
    if (!form.domicilio_numero.trim()) errors.domicilio_numero = 'Requerido'
    if (!form.domicilio_cp.trim()) errors.domicilio_cp = 'Requerido'
    if (!form.domicilio_provincia) errors.domicilio_provincia = 'Requerido'
    if (!form.domicilio_localidad.trim()) errors.domicilio_localidad = 'Requerido'
  }

  if (s === 2) {
    clear(['cc_brand', 'cc_pan', 'cc_expiry', 'cc_holder_name', 'cc_holder_dni'])
    if (!form.cc_brand) errors.cc_brand = 'Seleccioná la marca'
    const pan = form.cc_pan.replace(/\s/g, '')
    if (pan.length !== 16) errors.cc_pan = 'Ingresá los 16 dígitos'
    else if (!luhn(pan)) errors.cc_pan = 'Número de tarjeta inválido'
    if (!/^\d{2}\/\d{2}$/.test(form.cc_expiry)) errors.cc_expiry = 'Formato MM/AA'
    if (!form.cc_holder_name.trim()) errors.cc_holder_name = 'Requerido'
    if (!form.cc_holder_dni.trim()) errors.cc_holder_dni = 'Requerido'
  }

  if (s === 3) {
    clear(['vehiculo_uso', 'vehiculo_nro_chasis', 'vehiculo_nro_motor'])
    if (!form.vehiculo_uso) errors.vehiculo_uso = 'Seleccioná el tipo de uso'
    if (!form.vehiculo_nro_chasis.trim()) errors.vehiculo_nro_chasis = 'Requerido'
    if (!form.vehiculo_nro_motor.trim()) errors.vehiculo_nro_motor = 'Requerido'
  }

  if (s === 4) {
    photoSlots.forEach(slot => {
      if (!photoIds[slot.key]) errors[`photo_${slot.key}`] = 'Foto requerida'
    })
  }

  return !Object.keys(errors).some(k => {
    const step1keys = ['nombre', 'dni', 'email', 'telefono', 'domicilio_calle', 'domicilio_numero', 'domicilio_cp', 'domicilio_provincia', 'domicilio_localidad']
    const step2keys = ['cc_brand', 'cc_pan', 'cc_expiry', 'cc_holder_name', 'cc_holder_dni']
    const step3keys = ['vehiculo_uso', 'vehiculo_nro_chasis', 'vehiculo_nro_motor']
    if (s === 1) return step1keys.includes(k)
    if (s === 2) return step2keys.includes(k)
    if (s === 3) return step3keys.includes(k)
    return k.startsWith('photo_')
  })
}

const goToStep = (s: number) => {
  if (validateStep(step.value)) step.value = s
}

// ─── Submit ────────────────────────────────────────────────────────────────────
const submitForm = async () => {
  if (!validateStep(4) || photoCount.value < photoSlots.length) return
  submitting.value = true

  // Construir el payload JSON (liviano — solo strings, no archivos)
  const payload: Record<string, any> = {
    checkout_token: props.checkoutToken,
    nombre: form.nombre,
    dni: form.dni,
    email: form.email,
    telefono: form.telefono,
    domicilio_calle: form.domicilio_calle,
    domicilio_numero: form.domicilio_numero,
    domicilio_cp: form.domicilio_cp,
    domicilio_provincia: form.domicilio_provincia,
    domicilio_localidad: form.domicilio_localidad,
    vehiculo_uso: form.vehiculo_uso,
    vehiculo_nro_chasis: form.vehiculo_nro_chasis,
    vehiculo_nro_motor: form.vehiculo_nro_motor,
    cc_brand: form.cc_brand,
    cc_pan: form.cc_pan.replace(/\s/g, ''),
    cc_expiry: form.cc_expiry,
    cc_holder_name: form.cc_holder_name,
    cc_holder_dni: form.cc_holder_dni,
    photo_ids: { ...photoIds },
  }

  try {
    const res = await fetch(props.submitUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify(payload),
    })

    const data = await res.json().catch(() => ({}))

    if (res.ok && data.redirect_url) {
      window.location.href = data.redirect_url
      return
    }

    if (res.status === 409) {
      alert('El link de checkout expiró o ya fue procesado.')
      window.location.reload()
      return
    }

    // Errores de validación
    if (data.errors) {
      Object.assign(errors, data.errors)
      const s1 = ['nombre', 'dni', 'email', 'telefono', 'domicilio_calle', 'domicilio_numero', 'domicilio_cp', 'domicilio_provincia', 'domicilio_localidad']
      const s2 = ['cc_brand', 'cc_pan', 'cc_expiry', 'cc_holder_name', 'cc_holder_dni']
      const s3 = ['vehiculo_uso', 'vehiculo_nro_chasis', 'vehiculo_nro_motor']
      if (Object.keys(data.errors).some((k: string) => s1.includes(k))) step.value = 1
      else if (Object.keys(data.errors).some((k: string) => s2.includes(k))) step.value = 2
      else if (Object.keys(data.errors).some((k: string) => s3.includes(k))) step.value = 3
      else step.value = 4
    }
  } catch {
    alert('Error de conexión. Verificá tu internet e intentá de nuevo.')
  } finally {
    submitting.value = false
  }
}

// ─── Precio ────────────────────────────────────────────────────────────────────
const formatPrice = (n: number) =>
  new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0 }).format(n)
</script>

<style scoped>
/* Usamos clases inline de Tailwind CDN/utility directamente — no @apply */
</style>
