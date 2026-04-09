<script setup>
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useForm } from '@inertiajs/vue3'

defineOptions({ layout: AuthLayout })

defineProps({
  status: { type: String, default: null },
})

const form = useForm({ email: '' })

const submit = () => form.post('/forgot-password')
</script>

<template>
  <div class="card p-8">
    <div class="flex flex-col items-center mb-6">
      <div class="w-10 h-10 rounded-[10px] flex items-center justify-center bg-[#5b5ef6] mb-4">
        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944
               a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9
               c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03
               9-11.622 0-1.042-.133-2.052-.382-3.016z" />
        </svg>
      </div>
      <h1 class="text-lg font-semibold" style="color: var(--text-1);">Recuperar contraseña</h1>
      <p class="text-sm mt-1 text-center" style="color: var(--text-3);">
        Ingresá tu correo y te enviamos un link para resetearla.
      </p>
    </div>

    <div
      v-if="status"
      class="mb-4 px-3 py-2 rounded-[10px] text-sm"
      style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);"
    >
      {{ status }}
    </div>

    <form @submit.prevent="submit" class="flex flex-col gap-4">
      <div>
        <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="email">
          Correo electrónico
        </label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          autofocus
          required
          class="field"
          :class="{ 'field-error': form.errors.email }"
          placeholder="usuario@ejemplo.com"
        />
        <p v-if="form.errors.email" class="mt-1 text-xs" style="color: #dc2626;">
          {{ form.errors.email }}
        </p>
      </div>

      <button type="submit" class="btn btn-primary w-full mt-1" :disabled="form.processing">
        {{ form.processing ? 'Enviando…' : 'Enviar link de reseteo' }}
      </button>

      <div class="text-center">
        <a href="/login" class="text-xs hover:underline" style="color: var(--text-3);">
          Volver al inicio de sesión
        </a>
      </div>
    </form>
  </div>
</template>
