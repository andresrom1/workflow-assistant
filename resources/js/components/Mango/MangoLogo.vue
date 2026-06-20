<template>
  <svg
    class="mango-logo"
    :viewBox="`0 0 310 ${compact ? 88 : 110}`"
    :style="{ height: `${height}px`, width: 'auto' }"
    role="img"
    aria-label="MANGO Insurance Broker"
    xmlns="http://www.w3.org/2000/svg"
  >
    <!-- Isotipo: fruta mango -->
    <path class="ml-stem" d="M44,17 C44,12 46,7 50,5" />
    <path class="ml-leaf" d="M44,16 C48,8 62,6 64,12 C57,15 49,16 44,16 Z" />
    <path
      class="ml-fruit"
      d="M44,22 C56,17 69,22 72,35 C76,48 71,67 62,77 C53,87 38,90 27,83 C16,76 13,61 16,47 C19,33 30,27 44,22 Z"
    />

    <!-- Wordmark -->
    <text class="ml-word" x="90" y="72">M</text>
    <text class="ml-word" x="126" y="72">A</text>
    <text class="ml-word" x="160" y="72">N</text>
    <text class="ml-word" x="194" y="72">G</text>
    <text class="ml-word" x="228" y="72">O</text>
    <text class="ml-period" x="272" y="72">.</text>

    <!-- Tagline -->
    <text v-if="!compact" class="ml-tagline" x="91" y="98">Insurance Broker</text>
  </svg>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    /** Oculta la tagline "Insurance Broker" para headers chicos. */
    compact?: boolean
    /** Altura renderizada en px (el ancho se ajusta por aspecto). */
    height?: number
  }>(),
  { compact: false, height: 32 },
)
</script>

<style scoped>
/* Los fills/fuentes que el SVG original referenciaba externamente, ligados a
   los tokens de marca → adapta noir/light automáticamente. */
.ml-fruit,
.ml-leaf,
.ml-stem {
  fill: var(--mg-mango);
}
.ml-stem {
  fill: none;
  stroke: var(--mg-mango);
  stroke-width: 2.5;
  stroke-linecap: round;
}
.ml-word {
  fill: var(--mg-fg);
  font-family: var(--mg-font-display);
  font-weight: 700;
  font-size: 48px;
}
.ml-period {
  fill: var(--mg-mango);
  font-family: var(--mg-font-display);
  font-weight: 700;
  font-size: 48px;
}
.ml-tagline {
  fill: var(--mg-fg-dim);
  font-family: var(--mg-font-ui);
  font-weight: 500;
  font-size: 13px;
  letter-spacing: 0.04em;
}
</style>
