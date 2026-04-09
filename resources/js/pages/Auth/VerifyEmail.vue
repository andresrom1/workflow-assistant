<script setup>
import AuthLayout from '@/layouts/AuthLayout.vue'
import { computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'

defineOptions({ layout: AuthLayout })

const props = defineProps({
  status: { type: String, default: null },
})

const form = useForm({})
const submit = () => form.post('/email/verification-notification')
const verificationLinkSent = computed(() => props.status === 'verification-link-sent')
</script>

<template>
  <div class="card p-8">
    <div class="flex flex-col items-center mb-6">
      <div class="w-10 h-10 rounded-[10px] flex items-center justify-center bg-[#5b5ef6] mb-4">
        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
            d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        </svg>
      </div>
      <h1 class="text-lg font-semibold" style="color: var(--text-1);">Verificá tu email</h1>
      <p class="text-sm mt-2 text-center" style="color: var(--text-3);">
        Antes de continuar, verificá tu dirección de correo haciendo clic en el link que te enviamos.
      </p>
    </div>

    <div
      v-if="verificationLinkSent"
      class="mb-4 px-3 py-2 rounded-[10px] text-sm"
      style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);"
    >
      Se envió un nuevo link de verificación a tu correo.
    </div>

    <form @submit.prevent="submit" class="flex flex-col gap-3">
      <button type="submit" class="btn btn-primary w-full" :disabled="form.processing">
        {{ form.processing ? 'Enviando…' : 'Reenviar email de verificación' }}
      </button>

      <Link
        href="/logout"
        method="post"
        as="button"
        class="btn btn-ghost w-full"
      >
        Cerrar sesión
      </Link>
    </form>
  </div>
</template>
