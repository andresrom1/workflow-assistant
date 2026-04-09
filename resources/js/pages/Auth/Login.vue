<script setup>
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useForm } from '@inertiajs/vue3'

defineOptions({ layout: AuthLayout })

defineProps({
  canResetPassword: { type: Boolean, default: false },
  status: { type: String, default: null },
})

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

const submit = () => {
  form.post('/login', {
    onFinish: () => form.reset('password'),
  })
}
</script>

<template>
  <div class="card p-8">
    <!-- Logo + título -->
    <div class="flex flex-col items-center mb-8">
      <div class="w-10 h-10 rounded-[10px] flex items-center justify-center bg-[#5b5ef6] mb-4">
        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944
               a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9
               c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03
               9-11.622 0-1.042-.133-2.052-.382-3.016z"
          />
        </svg>
      </div>
      <h1 class="text-lg font-semibold" style="color: var(--text-1);">PAS Mobile</h1>
      <p class="text-sm mt-1" style="color: var(--text-3);">Ingresá a tu cuenta</p>
    </div>

    <!-- Flash status (password reset, etc.) -->
    <div
      v-if="status"
      class="mb-4 px-3 py-2 rounded-[10px] text-sm"
      style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);"
    >
      {{ status }}
    </div>

    <form @submit.prevent="submit" class="flex flex-col gap-4">
      <!-- Email -->
      <div>
        <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="email">
          Correo electrónico
        </label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          autocomplete="username"
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

      <!-- Contraseña -->
      <div>
        <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="password">
          Contraseña
        </label>
        <input
          id="password"
          v-model="form.password"
          type="password"
          autocomplete="current-password"
          required
          class="field"
          :class="{ 'field-error': form.errors.password }"
          placeholder="••••••••"
        />
        <p v-if="form.errors.password" class="mt-1 text-xs" style="color: #dc2626;">
          {{ form.errors.password }}
        </p>
      </div>

      <!-- Botón -->
      <button
        type="submit"
        class="btn btn-primary w-full mt-2"
        :disabled="form.processing"
      >
        <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        {{ form.processing ? 'Ingresando…' : 'Ingresar' }}
      </button>

      <!-- Olvidé mi contraseña -->
      <div v-if="canResetPassword" class="text-center">
        <a
          href="/forgot-password"
          class="text-xs hover:underline"
          style="color: var(--text-3);"
        >
          ¿Olvidaste tu contraseña?
        </a>
      </div>
    </form>
  </div>
</template>
