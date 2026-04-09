<script setup>
import AuthLayout from '@/layouts/AuthLayout.vue'

defineOptions({ layout: AuthLayout })
// Registro público deshabilitado — solo accesible por admins via /admin/users/create
</script>

<template>
  <div class="card p-8 text-center">
    <p class="text-sm" style="color: var(--text-3);">
      El registro público no está habilitado.
    </p>
    <a href="/login" class="btn btn-primary mt-4 inline-flex">
      Ir al inicio de sesión
    </a>
  </div>
</template>
