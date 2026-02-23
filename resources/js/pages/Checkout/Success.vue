<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center px-4">
    <div class="max-w-md w-full text-center">
      <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-10">
        <!-- Ícono de check -->
        <div class="mx-auto w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mb-6">
          <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
          </svg>
        </div>

        <h1 class="text-2xl font-bold text-gray-900 mb-3">¡Solicitud recibida!</h1>
        <p class="text-gray-500 text-sm leading-relaxed">
          Recibimos tus datos de contratación.<br>
          <span v-if="email">
            Te contactaremos a <strong class="text-gray-700">{{ email }}</strong> para confirmar los detalles de la emisión.
          </span>
          <span v-else>
            Un asesor te contactará en breve para confirmar la emisión.
          </span>
        </p>

        <div class="mt-8 pt-6 border-t border-gray-100 text-xs text-gray-400">
          Podés cerrar esta ventana.
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  email: string | null
}>()
</script>
