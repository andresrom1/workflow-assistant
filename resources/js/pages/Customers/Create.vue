<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-xl mx-auto">

      <AppBackLink href="/customers" label="Clientes" class="mb-4" />

      <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mb-6" style="color: var(--text-1);">
        Nuevo cliente
      </h1>

      <Card>
        <form @submit.prevent="submit" class="p-5 flex flex-col gap-4">

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <FormItem name="first_name" :error="form.errors.first_name">
              <FormLabel>Nombre</FormLabel>
              <FormControl>
                <Input v-model="form.first_name" type="text" placeholder="Juan" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem name="last_name" :error="form.errors.last_name">
              <FormLabel>Apellido</FormLabel>
              <FormControl>
                <Input v-model="form.last_name" type="text" placeholder="Pérez" />
              </FormControl>
              <FormMessage />
            </FormItem>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <FormItem name="dni" :error="form.errors.dni">
              <FormLabel>DNI</FormLabel>
              <FormControl>
                <Input v-model="form.dni" type="text" placeholder="30123456" />
              </FormControl>
              <FormMessage />
            </FormItem>

            <FormItem name="phone" :error="form.errors.phone">
              <FormLabel>Teléfono</FormLabel>
              <FormControl>
                <Input v-model="form.phone" type="text" placeholder="+5493512345678" />
              </FormControl>
              <FormMessage />
            </FormItem>
          </div>

          <FormItem name="email" :error="form.errors.email">
            <FormLabel>Email</FormLabel>
            <FormControl>
              <Input v-model="form.email" type="email" placeholder="cliente@email.com" />
            </FormControl>
            <FormMessage />
          </FormItem>

          <p class="text-xs text-muted-foreground">
            Ingresá al menos un identificador: DNI, email o teléfono. El resto del perfil del tomador se completa luego en la edición.
          </p>

          <div class="flex justify-end gap-2 pt-1">
            <Button type="button" variant="secondary" size="sm" as-child>
              <Link href="/customers">Cancelar</Link>
            </Button>
            <Button type="submit" size="sm" :disabled="form.processing">
              {{ form.processing ? 'Guardando...' : 'Crear cliente' }}
            </Button>
          </div>
        </form>
      </Card>

    </div>
  </div>
</template>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import AppBackLink from '@/components/App/BackLink.vue'
import { Button } from '@/components/UI/button'
import { Card } from '@/components/UI/card'
import { Input } from '@/components/UI/input'
import { FormControl, FormItem, FormLabel, FormMessage } from '@/components/UI/form'

const form = useForm({
  first_name: '',
  last_name: '',
  dni: '',
  email: '',
  phone: '',
})

const submit = () => {
  form.post('/customers')
}
</script>
