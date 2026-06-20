<template>
  <div
    class="mg-root min-h-[100dvh]"
    :style="{ background: 'var(--mg-bg)', color: 'var(--mg-fg)', fontFamily: 'var(--mg-font-ui)' }"
  >
    <!-- Header de marca -->
    <header
      v-if="!hideHeader"
      class="flex items-center justify-center px-4 py-3.5 sticky top-0 z-30"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <MangoLogo compact :height="26" />
    </header>

    <slot />
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import MangoLogo from '@/components/Mango/MangoLogo.vue'

withDefaults(defineProps<{ hideHeader?: boolean }>(), { hideHeader: false })

// El Select de shadcn teletransporta su dropdown al <body>, fuera del .mg-root.
// Seteamos data-brand en <html> para que ese contenido portaleado herede los
// tokens MANGO. Se limpia al desmontar para no contaminar el panel admin si se
// navega a otra página Inertia en la misma sesión.
onMounted(() => {
  document.documentElement.dataset.brand = 'mango'
})
onUnmounted(() => {
  delete document.documentElement.dataset.brand
})
</script>
