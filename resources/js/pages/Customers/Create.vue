<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-xl mx-auto">

      <BackLink href="/customers" label="Clientes" class="mb-4" />

      <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mb-6" style="color: var(--text-1);">
        Nuevo cliente
      </h1>

      <form @submit.prevent="submit"
        class="rounded-[14px] p-5 space-y-4"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

        <div>
          <label class="field-label">Nombre *</label>
          <input v-model="form.name" type="text" class="field"
            :class="{ 'field-error': form.errors.name }" placeholder="Ej: Juan Pérez" />
          <p v-if="form.errors.name" class="field-error-text">{{ form.errors.name }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="field-label">DNI</label>
            <input v-model="form.dni" type="text" class="field"
              :class="{ 'field-error': form.errors.dni }" placeholder="30123456" />
            <p v-if="form.errors.dni" class="field-error-text">{{ form.errors.dni }}</p>
          </div>
          <div>
            <label class="field-label">Teléfono</label>
            <input v-model="form.phone" type="text" class="field"
              :class="{ 'field-error': form.errors.phone }" placeholder="+5493512345678" />
            <p v-if="form.errors.phone" class="field-error-text">{{ form.errors.phone }}</p>
          </div>
        </div>

        <div>
          <label class="field-label">Email</label>
          <input v-model="form.email" type="email" class="field"
            :class="{ 'field-error': form.errors.email }" placeholder="cliente@email.com" />
          <p v-if="form.errors.email" class="field-error-text">{{ form.errors.email }}</p>
        </div>

        <p class="text-xs" style="color: var(--text-3);">
          Ingresá al menos un identificador: DNI, email o teléfono.
        </p>

        <div class="flex justify-end gap-2 pt-1">
          <Link href="/customers" class="btn btn-secondary text-sm">Cancelar</Link>
          <button type="submit" class="btn btn-primary text-sm" :disabled="form.processing">
            {{ form.processing ? 'Guardando...' : 'Crear cliente' }}
          </button>
        </div>
      </form>

    </div>
  </div>
</template>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import BackLink from '@/components/UI/BackLink.vue'

const form = useForm({
  name: '',
  dni: '',
  email: '',
  phone: '',
})

const submit = () => {
  form.post('/customers')
}
</script>
