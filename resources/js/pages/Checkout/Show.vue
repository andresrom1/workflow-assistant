<template>
  <!-- ══════ FALLBACK: no es dispositivo móvil ══════ -->
  <div v-if="!isMobile" class="co-center-msg">
    <div class="co-center-icon">📱</div>
    <h1 class="co-center-title">Abrí este link desde tu celular</h1>
    <p class="co-center-desc">
      Este formulario fue diseñado para completarse desde un dispositivo móvil.<br><br>
      Por favor, copiá el link y abrilo desde tu teléfono.
    </p>
    <div style="margin-top:24px; display:flex; gap:12px; justify-content:center;">
      <div class="co-badge-os"><span>🤖</span> Android</div>
      <div class="co-badge-os"><span>🍎</span> iOS</div>
    </div>
  </div>

  <!-- ══════ ESTADO INVÁLIDO ══════ -->
  <div v-else-if="quote.status !== 'checkout_pending'" class="co-center-msg">
    <div class="co-center-icon">⚠️</div>
    <h1 class="co-center-title">Link no disponible</h1>
    <p class="co-center-desc">
      Este link de checkout ya fue procesado, expiró o no es válido para cargar información.
    </p>
  </div>

  <!-- ══════ FORMULARIO PRINCIPAL (mobile only) ══════ -->
  <div v-else class="co-app">

    <!-- Header sticky con cobertura -->
    <div class="co-header">
      <p class="co-label-sm co-label-blue">Cobertura seleccionada</p>
      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:4px;">
        <div style="min-width:0; padding-right:12px;">
          <p style="font-size:14px; font-weight:700; color:var(--co-txt-1);">{{ alternative.aseguradora }} — {{ alternative.titulo }}</p>
          <p style="font-size:12px; color:var(--co-txt-3); margin-top:2px;">
            {{ vehicle.marca }} {{ vehicle.modelo }} {{ vehicle.year }}
            <span v-if="vehicle.patente">· {{ vehicle.patente }}</span>
          </p>
        </div>
        <div style="text-align:right; flex-shrink:0;">
          <span style="font-size:18px; font-weight:700; color:var(--co-txt-1);">${{ formatPrice(alternative.precio) }}</span>
          <span style="font-size:11px; color:var(--co-txt-3); display:block;">/mes</span>
        </div>
      </div>
    </div>

    <!-- Indicador de pasos -->
    <div class="co-steps">
      <div style="display:flex; align-items:center; justify-content:space-between;">
        <div v-for="(label, i) in stepLabels" :key="i" style="display:flex; align-items:center;">
          <div class="co-step-item">
            <div class="co-step-circle" :class="step > i + 1 ? 'done' : step === i + 1 ? 'active' : 'wait'">
              <span v-if="step > i + 1">✓</span>
              <span v-else>{{ i + 1 }}</span>
            </div>
            <span class="co-step-label" :class="step === i + 1 ? 'active' : step > i + 1 ? '' : 'wait'">{{ label }}</span>
          </div>
          <div v-if="i < stepLabels.length - 1" class="co-step-line" />
        </div>
      </div>
    </div>

    <div ref="formRef" style="padding-bottom: 32px;">

      <!-- ══════════ PASO 1: Datos personales ══════════ -->
      <div v-show="step === 1" class="co-section">
        <h2 class="co-section-title">Datos del tomador</h2>

        <div class="co-card">
          <Field label="Nombre completo *" :error="errors.nombre">
            <input v-model="form.nombre" type="text" name="nombre" placeholder="Juan Alberto Pérez"
              class="co-input" :class="{ 'error': errors.nombre }" autocomplete="name" />
          </Field>
          <div style="height: 16px;"></div>

          <Field label="DNI *" :error="errors.dni">
            <input v-model="form.dni" type="text" name="dni" placeholder="30000000" inputmode="numeric"
              class="co-input" :class="{ 'error': errors.dni }" />
          </Field>
          <div style="height: 16px;"></div>

          <Field label="Email *" :error="errors.email">
            <input v-model="form.email" type="email" name="email" placeholder="juan@ejemplo.com"
              class="co-input" :class="{ 'error': errors.email }" autocomplete="email" />
          </Field>
          <div style="height: 16px;"></div>

          <Field label="Teléfono *" :error="errors.telefono">
            <input v-model="form.telefono" type="tel" name="telefono" placeholder="+54 9 11 1234-5678"
              class="co-input" :class="{ 'error': errors.telefono }" autocomplete="tel" />
          </Field>
        </div>

        <h2 class="co-section-title" style="margin-top:24px;">Domicilio</h2>
        <div class="co-card">
          <Field label="Calle *" :error="errors.domicilio_calle">
            <input v-model="form.domicilio_calle" type="text" name="domicilio_calle" placeholder="Av. Siempreviva"
              class="co-input" :class="{ 'error': errors.domicilio_calle }" autocomplete="street-address" />
          </Field>
          <div style="height: 16px;"></div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <Field label="Número *" :error="errors.domicilio_numero">
              <input v-model="form.domicilio_numero" type="text" name="domicilio_numero" placeholder="742"
                class="co-input" :class="{ 'error': errors.domicilio_numero }" inputmode="numeric" />
            </Field>
            <Field label="Código Postal *" :error="errors.domicilio_cp">
              <input v-model="form.domicilio_cp" type="text" name="domicilio_cp" placeholder="1414"
                class="co-input" :class="{ 'error': errors.domicilio_cp }" inputmode="numeric" autocomplete="postal-code" />
            </Field>
          </div>
          <div style="height: 16px;"></div>

          <Field label="Provincia *" :error="errors.domicilio_provincia">
            <select v-model="form.domicilio_provincia" name="domicilio_provincia"
              class="co-input" :class="{ 'error': errors.domicilio_provincia }">
              <option value="" disabled>Seleccioná la provincia</option>
              <option v-for="p in provincias" :key="p" :value="p">{{ p }}</option>
            </select>
          </Field>
          <div style="height: 16px;"></div>

          <Field label="Localidad *" :error="errors.domicilio_localidad">
            <input v-model="form.domicilio_localidad" type="text" name="domicilio_localidad" placeholder="Buenos Aires"
              class="co-input" :class="{ 'error': errors.domicilio_localidad }" autocomplete="address-level2" />
          </Field>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:16px;">
          <button type="button" @click="goToStep(2)" class="co-btn co-btn-primary">Siguiente →</button>
        </div>
      </div>

      <!-- ══════════ PASO 2: Datos de pago ══════════ -->
      <div v-show="step === 2" class="co-section">
        <h2 class="co-section-title">Datos de pago</h2>

        <div class="co-card">
          <Field label="Marca de tarjeta *" :error="errors.cc_brand">
            <select v-model="form.cc_brand" name="cc_brand" class="co-input" :class="{ 'error': errors.cc_brand }">
              <option value="" disabled>Seleccioná la marca</option>
              <option value="visa">Visa</option>
              <option value="mastercard">Mastercard</option>
              <option value="amex">American Express</option>
              <option value="naranja">Naranja</option>
              <option value="cabal">Cabal</option>
              <option value="maestro">Maestro</option>
            </select>
          </Field>
          <div style="height: 16px;"></div>

          <Field label="Número de tarjeta *" :error="errors.cc_pan">
            <input v-model="form.cc_pan" type="text" name="cc_pan" placeholder="4111 1111 1111 1111" maxlength="19"
              inputmode="numeric" @input="formatPan" @blur="validatePanLuhn" class="co-input" style="font-family: monospace;"
              :class="{ 'error': errors.cc_pan }" autocomplete="off" />
          </Field>
          <div style="height: 16px;"></div>

          <div style="max-width: 150px;">
            <Field label="Vencimiento *" :error="errors.cc_expiry">
              <input v-model="form.cc_expiry" type="text" name="cc_expiry" placeholder="MM/AA" maxlength="5"
                inputmode="numeric" @input="formatExpiry" class="co-input" style="font-family: monospace;"
                :class="{ 'error': errors.cc_expiry }" autocomplete="off" />
            </Field>
          </div>
          <div style="height: 16px;"></div>

          <Field label="Nombre del titular *" :error="errors.cc_holder_name">
            <input v-model="form.cc_holder_name" type="text" name="cc_holder_name" placeholder="Juan Alberto Pérez"
              class="co-input" :class="{ 'error': errors.cc_holder_name }" autocomplete="off" />
          </Field>
          <div style="height: 16px;"></div>

          <Field label="DNI del titular *" :error="errors.cc_holder_dni">
            <input v-model="form.cc_holder_dni" type="text" name="cc_holder_dni" placeholder="30000000"
              inputmode="numeric" class="co-input" :class="{ 'error': errors.cc_holder_dni }" autocomplete="off" />
            <p class="co-hint-txt">Puede diferir del tomador del seguro.</p>
          </Field>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:16px;">
          <button type="button" @click="step = 1" class="co-btn co-btn-ghost">← Atrás</button>
          <button type="button" @click="goToStep(3)" class="co-btn co-btn-primary">Siguiente →</button>
        </div>
      </div>

      <!-- ══════════ PASO 3: Verificación del vehículo ══════════ -->
      <div v-show="step === 3" class="co-section">
        <h2 class="co-section-title">Verificación del vehículo</h2>
        <p class="co-section-desc">Confirmá que los datos del vehículo sean correctos. Estos datos provienen del snapshot de cotización.</p>

        <!-- Datos inmutables del snapshot -->
        <div class="co-card co-card-blue">
          <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
            <span class="co-label-sm co-label-blue">Datos del snapshot</span>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <ReadOnlyField v-if="vehicle.patente" label="Patente" :value="vehicle.patente" />
            <ReadOnlyField label="Marca" :value="vehicle.marca" />
            <ReadOnlyField label="Modelo" :value="vehicle.modelo" />
            <ReadOnlyField label="Año" :value="String(vehicle.year)" />
            <ReadOnlyField label="Versión" :value="vehicle.version" />
            <ReadOnlyField label="Combustible" :value="vehicle.combustible" />
          </div>
        </div>

        <!-- Datos adicionales que ingresa el cliente -->
        <h3 class="co-section-title" style="margin-top:24px; font-size:14px;">Datos adicionales</h3>
        <div class="co-card">
          <Field label="Uso del vehículo *" :error="errors.vehiculo_uso">
            <div class="co-radio-wrap">
              <label class="co-radio" :class="{ 'active': form.vehiculo_uso === 'particular' }">
                <input type="radio" v-model="form.vehiculo_uso" value="particular" name="vehiculo_uso" style="display:none;" />
                <span style="font-size:20px;">🚗</span> <span style="font-size:13px; font-weight:500;">Particular</span>
              </label>
              <label class="co-radio" :class="{ 'active': form.vehiculo_uso === 'otro' }">
                <input type="radio" v-model="form.vehiculo_uso" value="otro" name="vehiculo_uso" style="display:none;" />
                <span style="font-size:20px;">🚕</span> <span style="font-size:13px; font-weight:500;">Otro</span>
              </label>
            </div>
          </Field>
          <div style="height: 16px;"></div>

          <Field label="Nro. de chasis *" :error="errors.vehiculo_nro_chasis">
            <input v-model="form.vehiculo_nro_chasis" type="text" name="vehiculo_nro_chasis"
              placeholder="9BWZZZ..." class="co-input" style="font-family: monospace; text-transform: uppercase;"
              :class="{ 'error': errors.vehiculo_nro_chasis }"
              @input="form.vehiculo_nro_chasis = form.vehiculo_nro_chasis.toUpperCase()" />
          </Field>
          <div style="height: 16px;"></div>

          <Field label="Nro. de motor *" :error="errors.vehiculo_nro_motor">
            <input v-model="form.vehiculo_nro_motor" type="text" name="vehiculo_nro_motor" placeholder="AZD..."
              class="co-input" style="font-family: monospace; text-transform: uppercase;"
              :class="{ 'error': errors.vehiculo_nro_motor }"
              @input="form.vehiculo_nro_motor = form.vehiculo_nro_motor.toUpperCase()" />
          </Field>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:16px;">
          <button type="button" @click="step = 2" class="co-btn co-btn-ghost">← Atrás</button>
          <button type="button" @click="goToStep(4)" class="co-btn co-btn-primary">Siguiente →</button>
        </div>
      </div>

      <!-- ══════════ PASO 4: Inspección fotográfica ══════════ -->
      <div v-show="step === 4" class="co-section">
        <h2 class="co-section-title">Inspección fotográfica</h2>
        <p class="co-section-desc">
          Sacá cada foto <strong>en este momento</strong> con la cámara de tu teléfono.
          No se permite subir imágenes desde la galería.
        </p>

        <div>
          <div v-for="(slot, i) in photoSlots" :key="slot.key"
            class="co-photo-slot"
            :class="photoIds[slot.key] ? 'done' : (uploading[slot.key] ? 'uploading' : (errors[`photo_${slot.key}`] ? 'error' : ''))">
            
            <div class="co-photo-thumb">
              <img v-if="photos[slot.key]" :src="photos[slot.key]" style="width:100%; height:100%; object-fit:cover;" :alt="slot.label" />
              <div v-else-if="uploading[slot.key]" class="co-spin"></div>
              <span v-else>{{ slot.icon }}</span>
            </div>

            <div class="co-photo-info">
              <p class="co-photo-title">{{ slot.label }}</p>
              <p v-if="uploading[slot.key]" class="co-photo-desc" style="color:var(--co-blue);">Subiendo foto…</p>
              <p v-else class="co-photo-desc">{{ slot.hint }}</p>
              <p v-if="errors[`photo_${slot.key}`]" class="co-error-txt">{{ errors[`photo_${slot.key}`] }}</p>
            </div>

            <button v-if="photoIds[slot.key] && !uploading[slot.key]" type="button" @click="removePhoto(slot.key)" class="co-photo-btn del">
              <svg style="width:16px; height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
            </button>

            <label class="co-photo-btn cam" :class="photoIds[slot.key] ? 'co-photo-badge' : ''" v-else style="cursor:pointer;" :style="uploading[slot.key] ? 'opacity:0.5; pointer-events:none; background:var(--co-border); color:var(--co-txt-3);' : ''" @click.stop>
              <input type="file" accept="image/*" capture="environment" style="display:none;" @change="onPhotoCapture($event, slot.key)" :disabled="!!uploading[slot.key]" />
              <span v-if="photoIds[slot.key]" style="color:var(--co-bg);">✓</span>
              <svg v-else style="width:20px; height:20px; color:var(--co-accent-txt);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            </label>
          </div>
        </div>

        <!-- Progreso de fotos -->
        <div class="co-progress">
          <div style="display:flex; justify-content:space-between; align-items:center;">
            <span style="font-size:13px; font-weight:600; color:var(--co-txt-1);">Progreso</span>
            <span style="font-size:13px; font-weight:700;" :style="photoCount === photoSlots.length ? 'color:var(--co-success);' : 'color:var(--co-txt-3);'">
              {{ photoCount }}/{{ photoSlots.length }}
            </span>
          </div>
          <div class="co-progress-bars">
            <div v-for="(slot, i) in photoSlots" :key="slot.key" class="co-progress-bar" :class="{ 'done': photoIds[slot.key] }"></div>
          </div>
        </div>

        <!-- Resumen antes del envío -->
        <div class="co-summary">
          <p style="font-weight:700; margin-bottom:12px;">Resumen final</p>
          <div style="color:var(--co-txt-2);">
            <p><span>Tomador:</span> {{ form.nombre || '—' }}</p>
            <p><span>Vehículo:</span> {{ vehicle.marca }} {{ vehicle.modelo }} {{ vehicle.year }}</p>
            <p><span>Cobertura:</span> {{ alternative.aseguradora }} — {{ alternative.titulo }}</p>
            <p><span>Prima:</span> ${{ formatPrice(alternative.precio) }}/mes</p>
            <p><span>Fotos:</span> {{ photoCount }}/{{ photoSlots.length }}</p>
          </div>
        </div>

        <div style="display:flex; justify-content:space-between; margin-top:16px;">
          <button type="button" @click="step = 3" class="co-btn co-btn-ghost">← Atrás</button>
          <button type="button" :disabled="submitting || photoCount < photoSlots.length" class="co-btn co-btn-primary" @click="submitForm">
            <span v-if="submitting">Enviando…</span>
            <span v-else-if="photoCount < photoSlots.length">Fotos incompletas ({{ photoCount }}/{{ photoSlots.length }})</span>
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
:root, .co-app {
  --co-bg:        #f5f4f2;
  --co-surface:   #ffffff;
  --co-border:    #e4e2de;
  --co-border-hover: #d1cfc9;
  --co-txt-1:     #1a1917;
  --co-txt-2:     #5c5a56;
  --co-txt-3:     #9c9991;
  --co-accent:    #2c2f33;
  --co-accent-txt:#ffffff;
  --co-accent-hover:#40444a;
  --co-danger:    #e25c5c;
  --co-danger-bg: #fce8e8;
  --co-success:   #3c9f60;
  --co-success-bg:#e6f6ec;
  --co-blue:      #4078c0;
  --co-blue-bg:   #e8f0f8;
  --co-input-bg:  #ffffff;
}

@media (prefers-color-scheme: dark) {
  :root, .co-app {
    --co-bg:        #141312;
    --co-surface:   #1e1d1b;
    --co-border:    #2e2d2a;
    --co-border-hover: #4a4843;
    --co-txt-1:     #f2f0eb; /* Ajustado ligeramente más claro sin volar */
    --co-txt-2:     #a09e99;
    --co-txt-3:     #7a7772;
    --co-accent:    #e8e6e1;
    --co-accent-txt:#141312;
    --co-accent-hover:#ffffff;
    --co-danger:    #ea7979;
    --co-danger-bg: #2d1818;
    --co-success:   #5ecea0;
    --co-success-bg:#122a1d;
    --co-blue:      #7baaf7; /* Azul más vivo */
    --co-blue-bg:   #233045; /* Fondo con mucho más contraste sobre #141312 */
    --co-input-bg:  #141312;
  }
}

.co-app {
  min-height: 100dvh;
  background: var(--co-bg);
  color: var(--co-txt-1);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
  -webkit-font-smoothing: antialiased;
  padding-bottom: 32px;
}
.co-center-msg {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  min-height: 100dvh; padding: 24px; text-align: center;
}
.co-center-icon { font-size: 48px; margin-bottom: 20px; }
.co-center-title { font-size: 20px; font-weight: 700; margin-bottom: 12px; color: var(--co-txt-1); }
.co-center-desc { font-size: 14px; line-height: 1.6; color: var(--co-txt-2); }
.co-badge-os { display: flex; align-items: center; gap: 8px; background: var(--co-surface); border: 1px solid var(--co-border); border-radius: 12px; padding: 10px 16px; font-size: 13px; font-weight: 600; color: var(--co-txt-1); }

.co-header {
  background: var(--co-surface); border-bottom: 1px solid var(--co-border);
  padding: 16px; position: sticky; top: 0; z-index: 10;
}
.co-header p { margin: 0; }
.co-label-sm { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--co-txt-3); }
.co-label-blue { color: var(--co-blue); }

.co-steps { background: var(--co-surface); border-bottom: 1px solid var(--co-border); padding: 12px 16px; }
.co-step-item { display: flex; flex-direction: column; align-items: center; }
.co-step-circle {
  width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; transition: all 0.2s;
}
.co-step-circle.active { background: var(--co-accent); color: var(--co-accent-txt); }
.co-step-circle.done { background: var(--co-success); color: #fff; }
.co-step-circle.wait { background: var(--co-bg); color: var(--co-txt-3); border: 1px solid var(--co-border); }
.co-step-label { font-size: 11px; font-weight: 500; margin-top: 6px; }
.co-step-label.active { color: var(--co-txt-1); font-weight: 600; }
.co-step-label.wait { color: var(--co-txt-3); }
.co-step-line { width: 20px; height: 1px; background: var(--co-border); margin: 0 4px 16px; }

.co-section { padding: 24px 16px 0; }
.co-section-title { font-size: 16px; font-weight: 700; color: var(--co-txt-1); margin: 0 0 12px; }
.co-section-desc { font-size: 12px; line-height: 1.5; color: var(--co-txt-2); margin: 0 0 16px; }

.co-card { background: var(--co-surface); border: 1px solid var(--co-border); border-radius: 16px; padding: 16px; }
.co-card-blue { background: var(--co-blue-bg); border: 1px solid var(--co-blue); border-radius: 16px; padding: 16px; }

.co-field-wrap { margin-bottom: 16px; }
.co-field-wrap:last-child { margin-bottom: 0; }
.co-field-label { display: block; font-size: 13px; font-weight: 600; color: var(--co-txt-1); margin-bottom: 6px; }
.co-input {
  width: 100%; background: var(--co-input-bg); border: 1px solid var(--co-border); border-radius: 12px;
  padding: 12px; font-size: 14px; color: var(--co-txt-1); transition: border-color 0.2s;
}
.co-input::placeholder { color: var(--co-txt-3); }
.co-input:focus { outline: none; border-color: var(--co-accent); }
.co-input.error { border-color: var(--co-danger); }
.co-error-txt { font-size: 11px; color: var(--co-danger); margin: 6px 0 0; }
.co-hint-txt { font-size: 11px; color: var(--co-txt-3); margin: 6px 0 0; }

.co-btn {
  display: inline-flex; align-items: center; justify-content: center; border-radius: 12px;
  font-size: 14px; font-weight: 600; transition: all 0.2s; border: none; cursor: pointer;
}
.co-btn-primary { background: var(--co-accent); color: var(--co-accent-txt); padding: 12px 20px; }
.co-btn-primary:active { background: var(--co-accent-hover); }
.co-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.co-btn-ghost { background: transparent; color: var(--co-txt-2); padding: 12px 16px; }
.co-btn-ghost:active { color: var(--co-txt-1); background: var(--co-bg); }

.co-radio-wrap { display: flex; gap: 12px; margin-top: 6px; }
.co-radio {
  flex: 1; display: flex; align-items: center; gap: 8px; border: 1px solid var(--co-border);
  border-radius: 12px; padding: 12px; cursor: pointer; transition: all 0.2s; background: var(--co-surface);
}
.co-radio.active { border-color: var(--co-accent); background: var(--co-bg); }

.co-photo-slot { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid var(--co-border); border-radius: 12px; margin-bottom: 12px; background: var(--co-surface); }
.co-photo-slot.done { border-color: var(--co-success); }
.co-photo-slot.uploading { border-color: var(--co-blue); }
.co-photo-slot.error { border-color: var(--co-danger); }
.co-photo-thumb { width: 56px; height: 56px; border-radius: 8px; background: var(--co-bg); display: flex; align-items: center; justify-content: center; overflow: hidden; font-size: 24px; flex-shrink: 0; }
.co-photo-info { flex: 1; min-width: 0; }
.co-photo-title { font-size: 13px; font-weight: 600; color: var(--co-txt-1); margin: 0; }
.co-photo-desc { font-size: 11px; color: var(--co-txt-3); margin: 4px 0 0; }
.co-photo-btn { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; flex-shrink: 0; transition: all 0.2s; }
.co-photo-btn.cam { background: var(--co-accent); color: var(--co-accent-txt); }
.co-photo-btn.cam:active { background: var(--co-accent-hover); }
.co-photo-btn.del { background: var(--co-danger-bg); color: var(--co-danger); }
.co-photo-badge { background: var(--co-success); color: #fff; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: bold; }

.co-progress { background: var(--co-surface); border: 1px solid var(--co-border); border-radius: 16px; padding: 12px; margin-bottom: 16px; }
.co-progress-bars { display: flex; gap: 4px; margin-top: 8px; }
.co-progress-bar { height: 6px; flex: 1; border-radius: 3px; background: var(--co-bg); transition: background-color 0.3s; }
.co-progress-bar.done { background: var(--co-success); }

.co-summary { background: var(--co-bg); border: 1px solid var(--co-border); border-radius: 16px; padding: 16px; font-size: 13px; margin-bottom: 16px; }
.co-summary p { margin: 0 0 6px; color: var(--co-txt-1); }
.co-summary span { color: var(--co-txt-3); display: inline-block; width: 70px; }

/* Spinners */
.co-spin { width: 20px; height: 20px; border: 2px solid var(--co-blue); border-top-color: transparent; border-radius: 50%; animation: co-spin .8s linear infinite; }
@keyframes co-spin { to { transform: rotate(360deg); } }
</style>
