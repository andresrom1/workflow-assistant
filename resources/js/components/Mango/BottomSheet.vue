<template>
  <Teleport to="body">
    <Transition name="mq-fade">
      <div
        v-if="open"
        class="fixed inset-0 z-40"
        style="background: rgba(0, 0, 0, 0.55); backdrop-filter: blur(2px)"
        @click="emit('close')"
      />
    </Transition>

    <Transition name="mq-sheet">
      <section
        v-if="open"
        class="fixed inset-x-0 bottom-0 z-50 flex flex-col"
        :style="{
          background: 'var(--mg-surface)',
          borderTop: '1px solid var(--mg-hairline)',
          borderRadius: '24px 24px 0 0',
          maxHeight: '92dvh',
          transform: dragY ? `translateY(${dragY}px)` : undefined,
          transition: dragging ? 'none' : undefined,
        }"
        role="dialog"
        aria-modal="true"
      >
        <!-- Tirador: arrastrar hacia abajo cierra -->
        <header
          class="flex-shrink-0 pt-2.5 pb-1 cursor-grab touch-none"
          @touchstart="onStart"
          @touchmove="onMove"
          @touchend="onEnd"
          @mousedown="onStart"
        >
          <div
            class="mx-auto rounded-full"
            style="width: 38px; height: 4px; background: var(--mg-hairline-strong)"
          />
        </header>

        <div class="flex-1 overflow-y-auto overscroll-contain">
          <slot />
        </div>

        <footer
          v-if="$slots.footer"
          class="flex-shrink-0 px-4 pt-3"
          :style="{
            borderTop: '1px solid var(--mg-hairline)',
            paddingBottom: 'calc(1rem + env(safe-area-inset-bottom))',
            background: 'var(--mg-surface)',
          }"
        >
          <slot name="footer" />
        </footer>
      </section>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ close: [] }>()

const dragY = ref(0)
const dragging = ref(false)
let startY = 0

function onStart(e: TouchEvent | MouseEvent): void {
  dragging.value = true
  startY = 'touches' in e ? e.touches[0].clientY : e.clientY
}

function onMove(e: TouchEvent): void {
  if (!dragging.value) {
    return
  }
  const delta = e.touches[0].clientY - startY
  dragY.value = Math.max(0, delta)
}

function onEnd(): void {
  dragging.value = false
  if (dragY.value > 110) {
    emit('close')
  }
  dragY.value = 0
}

// Bloquea el scroll del body mientras el sheet está abierto.
watch(
  () => props.open,
  (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : ''
    if (!isOpen) {
      dragY.value = 0
    }
  },
)
</script>

<style scoped>
.mq-fade-enter-active,
.mq-fade-leave-active {
  transition: opacity 220ms ease;
}
.mq-fade-enter-from,
.mq-fade-leave-to {
  opacity: 0;
}

.mq-sheet-enter-active,
.mq-sheet-leave-active {
  transition: transform 300ms cubic-bezier(0.32, 0.72, 0, 1);
}
.mq-sheet-enter-from,
.mq-sheet-leave-to {
  transform: translateY(100%);
}
</style>
