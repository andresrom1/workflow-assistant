<template>
  <div class="min-h-screen bg-gray-50 py-8 px-4">
    <div class="max-w-2xl mx-auto">

      <!-- Header con resumen de la cobertura seleccionada -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <p class="text-xs font-semibold text-blue-600 uppercase tracking-wider mb-1">Cobertura seleccionada</p>
        <h1 class="text-2xl font-bold text-gray-900">{{ alternative.aseguradora }}</h1>
        <p class="text-gray-600">{{ alternative.titulo }} — {{ alternative.marketing_title }}</p>
        <div class="mt-3 flex items-center gap-4">
          <span class="text-3xl font-bold text-gray-900">
            $ {{ formatPrice(alternative.precio) }}
          </span>
          <span class="text-sm text-gray-500">/mes</span>
        </div>
        <p class="mt-1 text-sm text-gray-500">
          {{ risk.marca }} {{ risk.modelo }} {{ risk.year }}
          <span v-if="risk.patente" class="ml-1 font-medium">— {{ risk.patente }}</span>
        </p>
        <!-- Tags de cobertura -->
        <div v-if="alternative.features_tags?.length" class="mt-3 flex flex-wrap gap-1">
          <span
            v-for="tag in alternative.features_tags"
            :key="tag"
            class="px-2 py-0.5 bg-blue-50 text-blue-700 text-xs rounded-full border border-blue-100"
          >{{ tag }}</span>
        </div>
      </div>

      <!-- Indicador de pasos -->
      <div class="flex items-center justify-center mb-8 gap-2">
        <div
          v-for="(label, i) in stepLabels"
          :key="i"
          class="flex items-center gap-2"
        >
          <div
            class="flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold transition-colors"
            :class="stepCircleClass(i + 1)"
          >{{ i + 1 }}</div>
          <span
            class="text-sm font-medium hidden sm:block"
            :class="step === i + 1 ? 'text-blue-700' : 'text-gray-400'"
          >{{ label }}</span>
          <div v-if="i < stepLabels.length - 1" class="w-8 h-0.5 bg-gray-200 hidden sm:block" />
        </div>
      </div>

      <form :action="submitUrl" method="POST" enctype="multipart/form-data" ref="formRef">
        <input type="hidden" name="_token" :value="csrfToken" />

        <!-- ══════════ PASO 1: Datos personales ══════════ -->
        <div v-show="step === 1" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
          <h2 class="text-lg font-bold text-gray-800 mb-6">Datos del tomador</h2>

          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nombre completo *</label>
              <input
                v-model="form.nombre"
                type="text"
                name="nombre"
                placeholder="Juan Alberto Pérez"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                :class="{ 'border-red-400': errors.nombre }"
              />
              <p v-if="errors.nombre" class="mt-1 text-xs text-red-600">{{ errors.nombre }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">DNI *</label>
              <input
                v-model="form.dni"
                type="text"
                name="dni"
                placeholder="30000000"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                :class="{ 'border-red-400': errors.dni }"
              />
              <p v-if="errors.dni" class="mt-1 text-xs text-red-600">{{ errors.dni }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Domicilio *</label>
              <input
                v-model="form.domicilio"
                type="text"
                name="domicilio"
                placeholder="Av. Siempreviva 742, CABA"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                :class="{ 'border-red-400': errors.domicilio }"
              />
              <p v-if="errors.domicilio" class="mt-1 text-xs text-red-600">{{ errors.domicilio }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
                <input
                  v-model="form.email"
                  type="email"
                  name="email"
                  placeholder="juan@ejemplo.com"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-400': errors.email }"
                />
                <p v-if="errors.email" class="mt-1 text-xs text-red-600">{{ errors.email }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono *</label>
                <input
                  v-model="form.telefono"
                  type="tel"
                  name="telefono"
                  placeholder="+54 9 11 1234-5678"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-400': errors.telefono }"
                />
                <p v-if="errors.telefono" class="mt-1 text-xs text-red-600">{{ errors.telefono }}</p>
              </div>
            </div>
          </div>

          <div class="mt-6 flex justify-end">
            <button
              type="button"
              @click="goToStep(2)"
              class="bg-blue-600 text-white px-6 py-2.5 rounded-lg font-semibold text-sm hover:bg-blue-700 transition-colors"
            >
              Siguiente →
            </button>
          </div>
        </div>

        <!-- ══════════ PASO 2: Fotos de inspección ══════════ -->
        <div v-show="step === 2" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
          <h2 class="text-lg font-bold text-gray-800 mb-2">Fotos de inspección</h2>
          <p class="text-sm text-gray-500 mb-6">Subí fotos del vehículo para la inspección. Formatos: JPG, PNG, HEIC, PDF. Máx. 10 MB por archivo.</p>

          <!-- Zona de drop -->
          <div
            class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors"
            :class="{ 'border-blue-400 bg-blue-50': isDragging }"
            @dragover.prevent="isDragging = true"
            @dragleave="isDragging = false"
            @drop.prevent="onDrop"
            @click="$refs.photoInput.click()"
          >
            <svg class="w-10 h-10 mx-auto text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <p class="text-sm text-gray-600 font-medium">Arrastrá fotos aquí o <span class="text-blue-600">elegí archivos</span></p>
            <p class="text-xs text-gray-400 mt-1">Hasta 10 fotos</p>
          </div>
          <input
            ref="photoInput"
            type="file"
            name="photos[]"
            multiple
            accept=".jpg,.jpeg,.png,.heic,.pdf"
            class="hidden"
            @change="onFileSelect"
          />

          <!-- Previews -->
          <div v-if="photoFiles.length" class="mt-4 grid grid-cols-3 gap-3">
            <div
              v-for="(preview, i) in photoFiles"
              :key="i"
              class="relative group rounded-lg overflow-hidden border border-gray-200 aspect-square bg-gray-100 flex items-center justify-center"
            >
              <img
                v-if="preview.url"
                :src="preview.url"
                class="w-full h-full object-cover"
                :alt="`foto-${i + 1}`"
              />
              <div v-else class="text-xs text-gray-500 text-center px-2">
                <svg class="w-6 h-6 mx-auto mb-1 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                {{ preview.name }}
              </div>
              <button
                type="button"
                @click="removePhoto(i)"
                class="absolute top-1 right-1 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition-opacity"
              >×</button>
            </div>
          </div>

          <div class="mt-6 flex justify-between">
            <button
              type="button"
              @click="step = 1"
              class="text-gray-600 px-6 py-2.5 rounded-lg font-semibold text-sm hover:bg-gray-100 transition-colors"
            >
              ← Atrás
            </button>
            <button
              type="button"
              @click="goToStep(3)"
              class="bg-blue-600 text-white px-6 py-2.5 rounded-lg font-semibold text-sm hover:bg-blue-700 transition-colors"
            >
              Siguiente →
            </button>
          </div>
        </div>

        <!-- ══════════ PASO 3: Tarjeta de crédito ══════════ -->
        <div v-show="step === 3" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
          <h2 class="text-lg font-bold text-gray-800 mb-6">Datos de pago</h2>

          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Marca de tarjeta *</label>
              <select
                v-model="form.cc_brand"
                name="cc_brand"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                :class="{ 'border-red-400': errors.cc_brand }"
              >
                <option value="" disabled>Seleccioná la marca</option>
                <option value="visa">Visa</option>
                <option value="mastercard">Mastercard</option>
                <option value="amex">American Express</option>
                <option value="naranja">Naranja</option>
                <option value="cabal">Cabal</option>
                <option value="maestro">Maestro</option>
              </select>
              <p v-if="errors.cc_brand" class="mt-1 text-xs text-red-600">{{ errors.cc_brand }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Número de tarjeta *</label>
              <input
                v-model="form.cc_pan"
                type="text"
                name="cc_pan"
                placeholder="1234 5678 9012 3456"
                maxlength="19"
                @input="formatPan"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500"
                :class="{ 'border-red-400': errors.cc_pan }"
                autocomplete="off"
              />
              <p v-if="errors.cc_pan" class="mt-1 text-xs text-red-600">{{ errors.cc_pan }}</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Vencimiento *</label>
                <input
                  v-model="form.cc_expiry"
                  type="text"
                  name="cc_expiry"
                  placeholder="MM/AA"
                  maxlength="5"
                  @input="formatExpiry"
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="{ 'border-red-400': errors.cc_expiry }"
                  autocomplete="off"
                />
                <p v-if="errors.cc_expiry" class="mt-1 text-xs text-red-600">{{ errors.cc_expiry }}</p>
              </div>
              <div class="flex items-end">
                <p class="text-xs text-gray-400 pb-2">No se solicita el código de seguridad (CVV).</p>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del titular *</label>
              <input
                v-model="form.cc_holder_name"
                type="text"
                name="cc_holder_name"
                placeholder="Juan Alberto Pérez"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                :class="{ 'border-red-400': errors.cc_holder_name }"
                autocomplete="off"
              />
              <p v-if="errors.cc_holder_name" class="mt-1 text-xs text-red-600">{{ errors.cc_holder_name }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">DNI del titular *</label>
              <input
                v-model="form.cc_holder_dni"
                type="text"
                name="cc_holder_dni"
                placeholder="30000000"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                :class="{ 'border-red-400': errors.cc_holder_dni }"
                autocomplete="off"
              />
              <p class="mt-1 text-xs text-gray-400">El titular puede ser una persona diferente al tomador del seguro.</p>
              <p v-if="errors.cc_holder_dni" class="mt-1 text-xs text-red-600">{{ errors.cc_holder_dni }}</p>
            </div>
          </div>

          <!-- Resumen final -->
          <div class="mt-6 bg-gray-50 rounded-lg p-4 border border-gray-200 text-sm">
            <p class="font-semibold text-gray-700 mb-2">Resumen</p>
            <div class="space-y-1 text-gray-600">
              <p><span class="text-gray-400">Tomador:</span> {{ form.nombre || '—' }}</p>
              <p><span class="text-gray-400">Vehículo:</span> {{ risk.marca }} {{ risk.modelo }} {{ risk.year }}</p>
              <p><span class="text-gray-400">Cobertura:</span> {{ alternative.aseguradora }} — {{ alternative.titulo }}</p>
              <p><span class="text-gray-400">Prima mensual:</span> $ {{ formatPrice(alternative.precio) }}</p>
              <p><span class="text-gray-400">Fotos adjuntas:</span> {{ photoFiles.length }}</p>
            </div>
          </div>

          <div class="mt-6 flex justify-between">
            <button
              type="button"
              @click="step = 2"
              class="text-gray-600 px-6 py-2.5 rounded-lg font-semibold text-sm hover:bg-gray-100 transition-colors"
            >
              ← Atrás
            </button>
            <button
              type="submit"
              :disabled="submitting"
              class="bg-green-600 text-white px-8 py-2.5 rounded-lg font-semibold text-sm hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              @click.prevent="submitForm"
            >
              <span v-if="submitting">Enviando…</span>
              <span v-else>Confirmar y enviar</span>
            </button>
          </div>
        </div>

      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'

const props = defineProps<{
  quote: { id: number; status: string }
  alternative: {
    id: number
    aseguradora: string
    titulo: string
    descripcion: string
    precio: number
    moneda: string
    marketing_title: string
    features_tags: string[]
    normalized_grade: string
  }
  risk: {
    marca: string
    modelo: string
    version: string
    year: number
    patente: string | null
  }
  submitUrl: string
}>()

// ─── CSRF token desde meta tag ────────────────────────────────────────────────
const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''

// ─── Estado del wizard ────────────────────────────────────────────────────────
const step = ref(1)
const stepLabels = ['Datos personales', 'Fotos', 'Pago']
const submitting = ref(false)

const stepCircleClass = (s: number) => {
  if (step.value > s) return 'bg-green-500 text-white'
  if (step.value === s) return 'bg-blue-600 text-white'
  return 'bg-gray-200 text-gray-500'
}

// ─── Form data ────────────────────────────────────────────────────────────────
const form = reactive({
  nombre: '',
  dni: '',
  domicilio: '',
  email: '',
  telefono: '',
  cc_brand: '',
  cc_pan: '',
  cc_expiry: '',
  cc_holder_name: '',
  cc_holder_dni: '',
})

const errors = reactive<Record<string, string>>({})

// ─── Fotos ────────────────────────────────────────────────────────────────────
const isDragging = ref(false)
const photoInput = ref<HTMLInputElement | null>(null)
const photoFiles = ref<Array<{ file: File; url: string | null; name: string }>>([])
const formRef = ref<HTMLFormElement | null>(null)

const addFiles = (files: FileList | File[]) => {
  Array.from(files).forEach(file => {
    if (photoFiles.value.length >= 10) return
    const isImage = file.type.startsWith('image/')
    const entry = { file, url: null as string | null, name: file.name }
    if (isImage) {
      const reader = new FileReader()
      reader.onload = e => { entry.url = e.target?.result as string }
      reader.readAsDataURL(file)
    }
    photoFiles.value.push(entry)
  })
}

const onFileSelect = (e: Event) => {
  const input = e.target as HTMLInputElement
  if (input.files) addFiles(input.files)
}

const onDrop = (e: DragEvent) => {
  isDragging.value = false
  if (e.dataTransfer?.files) addFiles(e.dataTransfer.files)
}

const removePhoto = (i: number) => {
  photoFiles.value.splice(i, 1)
}

// ─── Formateo de tarjeta ──────────────────────────────────────────────────────
const formatPan = () => {
  const raw = form.cc_pan.replace(/\D/g, '').slice(0, 16)
  form.cc_pan = raw.replace(/(.{4})/g, '$1 ').trim()
}

const formatExpiry = () => {
  const raw = form.cc_expiry.replace(/\D/g, '').slice(0, 4)
  form.cc_expiry = raw.length > 2 ? `${raw.slice(0, 2)}/${raw.slice(2)}` : raw
}

// ─── Validación por paso ──────────────────────────────────────────────────────
const validateStep = (s: number): boolean => {
  // Limpiar errores previos del paso
  Object.keys(errors).forEach(k => delete (errors as Record<string, string>)[k])

  if (s === 1) {
    if (!form.nombre.trim()) errors.nombre = 'Requerido'
    if (!form.dni.trim()) errors.dni = 'Requerido'
    if (!form.domicilio.trim()) errors.domicilio = 'Requerido'
    if (!form.email.trim()) errors.email = 'Requerido'
    if (!form.telefono.trim()) errors.telefono = 'Requerido'
  }

  if (s === 3) {
    if (!form.cc_brand) errors.cc_brand = 'Seleccioná la marca'
    const pan = form.cc_pan.replace(/\s/g, '')
    if (pan.length !== 16) errors.cc_pan = 'Ingresá los 16 dígitos'
    if (!/^\d{2}\/\d{2}$/.test(form.cc_expiry)) errors.cc_expiry = 'Formato MM/AA'
    if (!form.cc_holder_name.trim()) errors.cc_holder_name = 'Requerido'
    if (!form.cc_holder_dni.trim()) errors.cc_holder_dni = 'Requerido'
  }

  return Object.keys(errors).length === 0
}

const goToStep = (s: number) => {
  if (validateStep(step.value)) step.value = s
}

// ─── Submit ───────────────────────────────────────────────────────────────────
const submitForm = () => {
  if (!validateStep(3)) return
  submitting.value = true

  const formData = new FormData()
  formData.append('_token', csrfToken)
  formData.append('nombre', form.nombre)
  formData.append('dni', form.dni)
  formData.append('domicilio', form.domicilio)
  formData.append('email', form.email)
  formData.append('telefono', form.telefono)
  formData.append('cc_brand', form.cc_brand)
  // Enviamos el PAN sin espacios
  formData.append('cc_pan', form.cc_pan.replace(/\s/g, ''))
  formData.append('cc_expiry', form.cc_expiry)
  formData.append('cc_holder_name', form.cc_holder_name)
  formData.append('cc_holder_dni', form.cc_holder_dni)
  photoFiles.value.forEach(({ file }) => formData.append('photos[]', file))

  fetch(props.submitUrl, { method: 'POST', body: formData })
    .then(async res => {
      if (res.redirected) {
        window.location.href = res.url
      } else if (!res.ok) {
        const data = await res.json().catch(() => ({}))
        if (data.errors) {
          Object.assign(errors, data.errors)
          // Volver al paso con errores
          const step1Fields = ['nombre', 'dni', 'domicilio', 'email', 'telefono']
          if (step1Fields.some(f => data.errors[f])) step.value = 1
          else step.value = 3
        }
        submitting.value = false
      }
    })
    .catch(() => { submitting.value = false })
}

// ─── Formateo de precio ───────────────────────────────────────────────────────
const formatPrice = (n: number) =>
  new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0 }).format(n)
</script>
