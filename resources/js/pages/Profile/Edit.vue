<script setup>
import { useForm, usePage } from '@inertiajs/vue3'

const props = defineProps({
  auth: { type: Object, required: true },
})

const page = usePage()

const infoForm = useForm({
  name: props.auth.user.name,
  email: props.auth.user.email,
})

const submitInfo = () => {
  infoForm.put('/profile')
}

const passwordForm = useForm({
  password: '',
  password_confirmation: '',
})

const submitPassword = () => {
  passwordForm
    .transform((data) => ({
      name: page.props.auth.user.name,
      email: page.props.auth.user.email,
      ...data,
    }))
    .put('/profile', {
      onSuccess: () => {
        passwordForm.password = ''
        passwordForm.password_confirmation = ''
      },
    })
}
</script>

<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-2xl mx-auto space-y-5">

      <!-- Header -->
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Mi perfil
        </h1>
        <p class="text-sm mt-0.5" style="color: var(--text-3);">
          Actualizá tu información personal y contraseña.
        </p>
      </div>

<!-- ── Información personal ──────────────────────────────── -->
      <div class="card p-6">
        <h2 class="text-sm font-semibold mb-5" style="color: var(--text-1);">
          Información personal
        </h2>

        <form @submit.prevent="submitInfo" class="flex flex-col gap-4">
          <div>
            <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="name">
              Nombre completo
            </label>
            <input
              id="name"
              v-model="infoForm.name"
              type="text"
              autocomplete="name"
              class="field"
              :class="{ 'field-error': infoForm.errors.name }"
            />
            <p v-if="infoForm.errors.name" class="mt-1 text-xs" style="color: #dc2626;">
              {{ infoForm.errors.name }}
            </p>
          </div>

          <div>
            <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="email">
              Correo electrónico
            </label>
            <input
              id="email"
              v-model="infoForm.email"
              type="email"
              autocomplete="email"
              class="field"
              :class="{ 'field-error': infoForm.errors.email }"
            />
            <p v-if="infoForm.errors.email" class="mt-1 text-xs" style="color: #dc2626;">
              {{ infoForm.errors.email }}
            </p>
          </div>

          <button
            type="submit"
            class="btn btn-primary w-full mt-1"
            :disabled="infoForm.processing"
          >
            {{ infoForm.processing ? 'Guardando…' : 'Guardar cambios' }}
          </button>
        </form>
      </div>

      <!-- ── Cambiar contraseña ─────────────────────────────────── -->
      <div class="card p-6">
        <h2 class="text-sm font-semibold mb-1" style="color: var(--text-1);">
          Cambiar contraseña
        </h2>
        <p class="text-xs mb-5" style="color: var(--text-3);">
          Dejá los campos vacíos para no cambiar la contraseña.
        </p>

        <form @submit.prevent="submitPassword" class="flex flex-col gap-4">
          <div>
            <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="password">
              Nueva contraseña
            </label>
            <input
              id="password"
              v-model="passwordForm.password"
              type="password"
              autocomplete="new-password"
              class="field"
              :class="{ 'field-error': passwordForm.errors.password }"
              placeholder="Mínimo 8 caracteres"
            />
            <p v-if="passwordForm.errors.password" class="mt-1 text-xs" style="color: #dc2626;">
              {{ passwordForm.errors.password }}
            </p>
          </div>

          <div>
            <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="password_confirmation">
              Confirmar nueva contraseña
            </label>
            <input
              id="password_confirmation"
              v-model="passwordForm.password_confirmation"
              type="password"
              autocomplete="new-password"
              class="field"
              :class="{ 'field-error': passwordForm.errors.password_confirmation }"
              placeholder="Repetí la contraseña"
            />
          </div>

          <button
            type="submit"
            class="btn btn-primary w-full mt-1"
            :disabled="passwordForm.processing"
          >
            {{ passwordForm.processing ? 'Guardando…' : 'Cambiar contraseña' }}
          </button>
        </form>
      </div>

    </div>
  </div>
</template>
