<template>
  <MangoLayout>
    <div class="flex items-center justify-center p-6 min-h-[calc(100dvh-58px)]">
      <div class="w-full max-w-sm text-center">

        <!-- Check icon -->
        <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-7"
          :style="{ background: 'var(--mg-ok-tint)' }">
          <svg class="w-8 h-8" :style="{ color: 'var(--mg-ok)' }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
          </svg>
        </div>

        <p class="mg-overline mb-2">Solicitud recibida</p>
        <h1 class="mg-display text-3xl mb-4">¡Listo!</h1>

        <p class="text-sm leading-relaxed" style="color: var(--mg-fg-dim)">
          Recibimos tus datos de contratación.<br>
          Estamos gestionando la emisión con la compañía — te confirmamos por este mismo WhatsApp.
        </p>

        <div class="mt-7 pt-5 text-xs"
          :style="{ borderTop: '1px solid var(--mg-hairline)', color: 'var(--mg-fg-faint)' }">
          Ya podés cerrar esta ventana.
        </div>
      </div>
    </div>
  </MangoLayout>
</template>

<script setup lang="ts">
import MangoLayout from '@/layouts/MangoLayout.vue'
</script>
