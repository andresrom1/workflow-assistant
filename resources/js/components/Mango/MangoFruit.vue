<template>
  <svg viewBox="4 2 78 88" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <path :d="MANGO_STEM" fill="none" :stroke="leafColor" stroke-width="2.5" stroke-linecap="round" />
    <path :d="MANGO_LEAF" :style="leafStyle" />
    <path :d="MANGO_FRUIT" :style="fruitStyle" />
  </svg>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { MANGO_FRUIT, MANGO_LEAF, MANGO_STEM } from './mangoIsotype'

const props = withDefaults(
  defineProps<{
    /** Pinta todo el isotipo en blanco (para la franja naranja de cierre). */
    mono?: boolean
    /** Dibuja la fruta/hoja en contorno en vez de relleno. */
    outline?: boolean
  }>(),
  { mono: false, outline: false },
)

const fruitColor = computed(() => (props.mono ? '#ffffff' : 'var(--mg-mango)'))
const leafColor = computed(() => (props.mono ? '#ffffff' : 'var(--mg-leaf)'))

const shape = (color: string) =>
  props.outline
    ? { fill: 'none', stroke: color, strokeWidth: '2.4', strokeLinejoin: 'round' }
    : { fill: color }

const fruitStyle = computed(() => shape(fruitColor.value))
const leafStyle = computed(() => shape(leafColor.value))
</script>
