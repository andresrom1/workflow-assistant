<script setup>
import { ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Loader2Icon } from '@lucide/vue'
import { Badge } from '@/components/UI/badge'
import { Button } from '@/components/UI/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/UI/dialog'
import { FormControl, FormItem, FormLabel, FormMessage } from '@/components/UI/form'
import { Input } from '@/components/UI/input'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'

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
            <FormItem :error="form.errors.name">
              <FormLabel for="name">Nombre completo</FormLabel>
              <FormControl>
                <Input
                  id="name"
                  v-model="form.name"
                  type="text"
                  autocomplete="name"
                  placeholder="Juan Pérez"
                />
              </FormControl>
              <FormMessage />
            </FormItem>

            <!-- Email -->
            <FormItem :error="form.errors.email">
              <FormLabel for="email">Correo electrónico</FormLabel>
              <FormControl>
                <Input
                  id="email"
                  v-model="form.email"
                  type="email"
                  autocomplete="off"
                  placeholder="usuario@ejemplo.com"
                />
              </FormControl>
              <FormMessage />
            </FormItem>

            <!-- Contraseña -->
            <FormItem :error="form.errors.password">
              <FormLabel for="password">Contraseña</FormLabel>
              <FormControl>
                <Input
                  id="password"
                  v-model="form.password"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Mínimo 8 caracteres"
                />
              </FormControl>
              <FormMessage />
            </FormItem>

            <!-- Confirmar contraseña -->
            <FormItem :error="form.errors.password_confirmation">
              <FormLabel for="password_confirmation">Confirmar contraseña</FormLabel>
              <FormControl>
                <Input
                  id="password_confirmation"
                  v-model="form.password_confirmation"
                  type="password"
                  autocomplete="new-password"
                  placeholder="Repetí la contraseña"
                />
              </FormControl>
              <FormMessage />
            </FormItem>

            <!-- Rol -->
            <FormItem :error="form.errors.role">
              <FormLabel for="role">Rol</FormLabel>
              <FormControl>
                <Select v-model="form.role">
                  <SelectTrigger id="role" class="w-full">
                    <SelectValue placeholder="Seleccionar rol" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectItem v-for="role in roles" :key="role.value" :value="role.value">
                        {{ role.label }}
                      </SelectItem>
                    </SelectGroup>
                  </SelectContent>
                </Select>
              </FormControl>
              <FormMessage />
            </FormItem>

            <Button
              type="submit"
              class="w-full mt-1"
              :disabled="form.processing"
            >
              <Loader2Icon v-if="form.processing" class="animate-spin" data-icon="inline-start" />
              {{ form.processing ? 'Creando…' : 'Crear usuario' }}
            </Button>
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
                <Badge :variant="user.role === 'admin' ? 'default' : 'secondary'">
                  {{ user.role === 'admin' ? 'Admin' : 'Usuario' }}
                </Badge>

                <Button
                  variant="secondary"
                  size="sm"
                  :disabled="resetForm.processing"
                  @click="resetPassword(user.id)"
                >
                  Resetear
                </Button>

                <Button
                  v-if="user.id !== currentUserId"
                  variant="destructive"
                  size="sm"
                  :disabled="deleteForm.processing"
                  @click="deleteUser(user.id, user.name)"
                >
                  Eliminar
                </Button>
              </div>
            </li>
          </ul>
        </div>

      </div>
    </div>
  </div>

  <!-- Modal de confirmación -->
  <Dialog :open="!!confirmTarget" @update:open="confirmTarget = $event ? confirmTarget : null">
    <DialogContent>
      <DialogHeader>
        <DialogTitle>{{ confirmTarget?.title }}</DialogTitle>
        <DialogDescription>
          {{ confirmTarget?.description }}
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="secondary" @click="confirmTarget = null">
          Cancelar
        </Button>
        <Button variant="destructive" @click="submitConfirm">
          {{ confirmTarget?.actionLabel }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
