<template>
  <svg
    class="mango-logo"
    :viewBox="`0 0 310 ${compact ? 88 : 110}`"
    :style="{ height: `${height}px`, width: 'auto' }"
    role="img"
    aria-label="MANGO Insurance Broker"
    xmlns="http://www.w3.org/2000/svg"
  >
    <!-- Isotipo: fruta mango (geometría compartida con MangoFruit.vue) -->
    <path class="ml-stem" :d="MANGO_STEM" />
    <path class="ml-leaf" :d="MANGO_LEAF" />
    <path class="ml-fruit" :d="MANGO_FRUIT" />

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
import { MANGO_FRUIT, MANGO_LEAF, MANGO_STEM } from './mangoIsotype'

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
   los tokens de marca → adapta noir/light automáticamente.
   Isotipo siempre en outline: la fruta en mango, la ramita y la hoja en verde. */
.ml-fruit,
.ml-leaf,
.ml-stem {
  fill: none;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.ml-fruit {
  stroke: var(--mg-mango);
  stroke-width: 3;
}
.ml-leaf {
  stroke: var(--mg-leaf);
  stroke-width: 2.5;
}
.ml-stem {
  stroke: var(--mg-leaf);
  stroke-width: 2.5;
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
