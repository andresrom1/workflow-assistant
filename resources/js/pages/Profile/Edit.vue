<script setup>
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@/components/UI/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/UI/card'
import { Input } from '@/components/UI/input'
import { FormControl, FormItem, FormLabel, FormMessage } from '@/components/UI/form'

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
    <div class="max-w-2xl mx-auto flex flex-col gap-5">

      <!-- Header -->
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
          Mi perfil
        </h1>
        <p class="text-sm mt-0.5 text-muted-foreground">
          Actualizá tu información personal y contraseña.
        </p>
      </div>

      <!-- Información personal -->
      <Card>
        <CardHeader>
          <CardTitle>Información personal</CardTitle>
        </CardHeader>
        <CardContent>
          <form @submit.prevent="submitInfo" class="flex flex-col gap-4">
            <FormItem name="name" :error="infoForm.errors.name">
              <FormLabel>Nombre completo</FormLabel>
              <FormControl>
                <Input v-model="infoForm.name" type="text" autocomplete="name" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem name="email" :error="infoForm.errors.email">
              <FormLabel>Correo electrónico</FormLabel>
              <FormControl>
                <Input v-model="infoForm.email" type="email" autocomplete="email" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <Button type="submit" class="w-full" :disabled="infoForm.processing">
              {{ infoForm.processing ? 'Guardando…' : 'Guardar cambios' }}
            </Button>
          </form>
        </CardContent>
      </Card>

      <!-- Cambiar contraseña -->
      <Card>
        <CardHeader>
          <CardTitle>Cambiar contraseña</CardTitle>
          <CardDescription>
            Dejá los campos vacíos para no cambiar la contraseña.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form @submit.prevent="submitPassword" class="flex flex-col gap-4">
            <FormItem name="password" :error="passwordForm.errors.password">
              <FormLabel>Nueva contraseña</FormLabel>
              <FormControl>
                <Input v-model="passwordForm.password" type="password" autocomplete="new-password" placeholder="Mínimo 8 caracteres" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem name="password_confirmation" :error="passwordForm.errors.password_confirmation">
              <FormLabel>Confirmar nueva contraseña</FormLabel>
              <FormControl>
                <Input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" placeholder="Repetí la contraseña" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <Button type="submit" class="w-full" :disabled="passwordForm.processing">
              {{ passwordForm.processing ? 'Guardando…' : 'Cambiar contraseña' }}
            </Button>
          </form>
        </CardContent>
      </Card>

    </div>
  </div>
</template>
