<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-xl mx-auto">

      <BackLink :href="`/conversations/${customer.id}`" label="Cliente" class="mb-4" />

      <h1 class="text-xl sm:text-2xl font-semibold tracking-tight mb-6" style="color: var(--text-1);">
        Editar cliente
      </h1>

      <form @submit.prevent="submit"
        class="rounded-[14px] p-5 space-y-4"
        style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">

        <div>
          <label class="field-label">Nombre *</label>
          <input v-model="form.name" type="text" class="field"
            :class="{ 'field-error': form.errors.name }" />
          <p v-if="form.errors.name" class="field-error-text">{{ form.errors.name }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="field-label">DNI</label>
            <input v-model="form.dni" type="text" class="field"
              :class="{ 'field-error': form.errors.dni }" />
            <p v-if="form.errors.dni" class="field-error-text">{{ form.errors.dni }}</p>
          </div>
          <div>
            <label class="field-label">Teléfono</label>
            <input v-model="form.phone" type="text" class="field"
              :class="{ 'field-error': form.errors.phone }" />
            <p v-if="form.errors.phone" class="field-error-text">{{ form.errors.phone }}</p>
          </div>
        </div>

        <div>
          <label class="field-label">Email</label>
          <input v-model="form.email" type="email" class="field"
            :class="{ 'field-error': form.errors.email }" />
          <p v-if="form.errors.email" class="field-error-text">{{ form.errors.email }}</p>
        </div>

        <div class="flex justify-end gap-2 pt-1">
          <Link :href="`/conversations/${customer.id}`" class="btn btn-secondary text-sm">Cancelar</Link>
          <button type="submit" class="btn btn-primary text-sm" :disabled="form.processing">
            {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
          </button>
        </div>
      </form>

    </div>
  </div>
</template>

<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import BackLink from '@/components/UI/BackLink.vue'

interface Customer {
  id: number
  name: string
  dni: string | null
  email: string | null
  phone: string | null
}

const props = defineProps<{ customer: Customer }>()

const form = useForm({
  name: props.customer.name ?? '',
  dni: props.customer.dni ?? '',
  email: props.customer.email ?? '',
  phone: props.customer.phone ?? '',
})

const submit = () => {
  form.put(`/conversations/${props.customer.id}`)
}
</script>
