<template>
  <div
    class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold"
    :style="colorStyle"
  >
    {{ initial }}
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue'

const props = defineProps<{ name: string }>()

const initial = computed(() => props.name?.charAt(0).toUpperCase() ?? '?')

// Paletas: [bgLight, textLight, bgDark, textDark]
// El índice 0 usa vars del sistema (acento) — conmuta automáticamente.
// Los otros usan hex: bgDark/textDark aplican cuando data-theme="dark".
const PALETTES: [string, string, string, string][] = [
  ['var(--accent-100)', 'var(--accent-600)', 'var(--accent-100)', 'var(--accent-400)'],
  ['#99f6e4', '#0f766e', '#042f2e', '#5eead4'],
  ['#fde68a', '#92400e', '#1c1009', '#fcd34d'],
  ['#fecdd3', '#9f1239', '#1f0a0f', '#fb7185'],
  ['#ddd6fe', '#5b21b6', '#1e1033', '#a78bfa'],
]

// Reactive theme — se actualiza cuando cambia data-theme en <html>
const isDark = ref(false)

let observer: MutationObserver | null = null

const readTheme = () => {
  isDark.value = document.documentElement.getAttribute('data-theme') === 'dark'
}

onMounted(() => {
  readTheme()
  observer = new MutationObserver(readTheme)
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })
})

onUnmounted(() => observer?.disconnect())

const colorStyle = computed(() => {
  const idx = (props.name?.charCodeAt(0) ?? 0) % PALETTES.length
  const [bgL, txtL, bgD, txtD] = PALETTES[idx]
  return `background: ${isDark.value ? bgD : bgL}; color: ${isDark.value ? txtD : txtL};`
})
</script>
