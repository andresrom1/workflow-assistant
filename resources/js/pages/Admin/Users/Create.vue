<script setup>
import { ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'

const props = defineProps({
  roles: { type: Array, required: true },
  users: { type: Array, required: true },
})

const page = usePage()
const currentUserId = page.props.auth?.user?.id

const form = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role: 'user',
})

const resetForm = useForm({})
const deleteForm = useForm({})

const confirmTarget = ref(null)

const submit = () => {
  form.post('/admin/users', {
    onSuccess: () => form.reset(),
  })
}

const resetPassword = (userId) => {
  confirmTarget.value = {
    title: '¿Resetear contraseña?',
    description: 'Se generará una contraseña temporal que se mostrará en pantalla.',
    actionLabel: 'Resetear',
    callback: () => resetForm.post(`/admin/users/${userId}/reset-password`),
  }
}

const deleteUser = (userId, userName) => {
  confirmTarget.value = {
    title: '¿Eliminar usuario?',
    description: `"${userName}" será eliminado permanentemente. Esta acción no se puede deshacer.`,
    actionLabel: 'Eliminar',
    callback: () => deleteForm.delete(`/admin/users/${userId}`),
  }
}

const submitConfirm = () => {
  confirmTarget.value?.callback()
  confirmTarget.value = null
}
</script>

<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto space-y-5">

      <!-- Header -->
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Gestión de usuarios
        </h1>
        <p class="text-sm mt-0.5" style="color: var(--text-3);">
          Creá nuevos usuarios y gestioná el acceso al panel.
        </p>
      </div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        <!-- ── Crear nuevo usuario ───────────────────────────── -->
        <div class="card p-6">
          <h2 class="text-sm font-semibold mb-5" style="color: var(--text-1);">
            Crear nuevo usuario
          </h2>

          <form @submit.prevent="submit" class="flex flex-col gap-4">
            <!-- Nombre -->
            <div>
              <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="name">
                Nombre completo
              </label>
              <input
                id="name"
                v-model="form.name"
                type="text"
                autocomplete="name"
                class="field"
                :class="{ 'field-error': form.errors.name }"
                placeholder="Juan Pérez"
              />
              <p v-if="form.errors.name" class="mt-1 text-xs" style="color: #dc2626;">
                {{ form.errors.name }}
              </p>
            </div>

            <!-- Email -->
            <div>
              <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="email">
                Correo electrónico
              </label>
              <input
                id="email"
                v-model="form.email"
                type="email"
                autocomplete="off"
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
                autocomplete="new-password"
                class="field"
                :class="{ 'field-error': form.errors.password }"
                placeholder="Mínimo 8 caracteres"
              />
              <p v-if="form.errors.password" class="mt-1 text-xs" style="color: #dc2626;">
                {{ form.errors.password }}
              </p>
            </div>

            <!-- Confirmar contraseña -->
            <div>
              <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="password_confirmation">
                Confirmar contraseña
              </label>
              <input
                id="password_confirmation"
                v-model="form.password_confirmation"
                type="password"
                autocomplete="new-password"
                class="field"
                :class="{ 'field-error': form.errors.password_confirmation }"
                placeholder="Repetí la contraseña"
              />
            </div>

            <!-- Rol -->
            <div>
              <label class="block text-xs font-medium mb-1.5" style="color: var(--text-2);" for="role">
                Rol
              </label>
              <select
                id="role"
                v-model="form.role"
                class="field"
                :class="{ 'field-error': form.errors.role }"
              >
                <option
                  v-for="role in roles"
                  :key="role.value"
                  :value="role.value"
                >
                  {{ role.label }}
                </option>
              </select>
              <p v-if="form.errors.role" class="mt-1 text-xs" style="color: #dc2626;">
                {{ form.errors.role }}
              </p>
            </div>

            <button
              type="submit"
              class="btn btn-primary w-full mt-1"
              :disabled="form.processing"
            >
              <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              {{ form.processing ? 'Creando…' : 'Crear usuario' }}
            </button>
          </form>
        </div>

        <!-- ── Lista de usuarios existentes ─────────────────── -->
        <div class="card p-6">
          <h2 class="text-sm font-semibold mb-5" style="color: var(--text-1);">
            Usuarios existentes
          </h2>

          <div v-if="users.length === 0" class="text-sm" style="color: var(--text-3);">
            No hay usuarios aún.
          </div>

          <ul v-else class="flex flex-col divide-y" style="--tw-divide-color: var(--border-sub);">
            <li
              v-for="user in users"
              :key="user.id"
              class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
            >
              <div class="min-w-0">
                <p class="text-sm font-medium truncate" style="color: var(--text-1);">
                  {{ user.name }}
                </p>
                <p class="text-xs truncate" style="color: var(--text-3);">
                  {{ user.email }}
                </p>
              </div>

              <div class="flex items-center gap-2 flex-shrink-0">
                <span
                  class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold"
                  :style="user.role === 'admin'
                    ? 'background: var(--badge-accent-bg); color: var(--badge-accent-txt);'
                    : 'background: var(--bg-raised); color: var(--text-2);'"
                >
                  {{ user.role === 'admin' ? 'Admin' : 'Usuario' }}
                </span>

                <button
                  class="btn btn-secondary text-xs px-2.5 py-1"
                  :disabled="resetForm.processing"
                  @click="resetPassword(user.id)"
                >
                  Resetear
                </button>

                <button
                  v-if="user.id !== currentUserId"
                  class="btn btn-danger text-xs px-2.5 py-1"
                  :disabled="deleteForm.processing"
                  @click="deleteUser(user.id, user.name)"
                >
                  Eliminar
                </button>
              </div>
            </li>
          </ul>
        </div>

      </div>
    </div>
  </div>

  <!-- Modal de confirmación -->
  <Transition name="fade">
    <div v-if="confirmTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="fixed inset-0 bg-black/50" @click="confirmTarget = null" />
      <div class="relative z-10 rounded-2xl p-6 max-w-sm w-full"
           style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
        <h3 class="text-base font-semibold mb-3" style="color: var(--text-1);">
          {{ confirmTarget.title }}
        </h3>
        <p class="text-sm mb-5" style="color: var(--text-2);">
          {{ confirmTarget.description }}
        </p>
        <div class="flex justify-end gap-2">
          <button
            @click="confirmTarget = null"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--bg-raised); color: var(--text-2); border: 1px solid var(--border);"
          >
            Cancelar
          </button>
          <button
            @click="submitConfirm"
            class="px-4 py-2 rounded-lg text-sm font-semibold"
            style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);"
          >
            {{ confirmTarget.actionLabel }}
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
