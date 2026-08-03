<template>
  <Teleport to="body">
    <Transition name="mq-fade">
      <div
        v-if="estado !== null"
        class="fixed inset-0 z-[60] flex items-center justify-center p-5"
        style="background: rgba(0, 0, 0, 0.55); backdrop-filter: blur(2px)"
        @click.self="estado === 'enviando' ? null : emit('cerrar')"
      >
        <div
          class="w-full max-w-[380px] rounded-2xl p-5"
          :style="{
            background: 'var(--mg-surface)',
            border: '1px solid var(--mg-hairline)',
          }"
          role="dialog"
          aria-modal="true"
        >
          <template v-if="estado === 'enviando'">
            <p class="mg-display text-[19px] leading-tight">Un momento…</p>
            <p class="text-[13.5px] mt-2 leading-snug" style="color: var(--mg-fg-dim)">
              Estamos preparando tu contratación.
            </p>
          </template>

          <template v-else-if="estado === 'linkEnviado'">
            <p class="mg-display text-[19px] leading-tight">Te lo mandamos por WhatsApp</p>
            <p class="text-[13.5px] mt-2 leading-snug" style="color: var(--mg-fg-dim)">
              La contratación se termina desde el celular: hay que sacarle 7 fotos al auto con la
              cámara para la inspección. Te mandamos el link por WhatsApp para que sigas desde ahí.
            </p>
            <p class="text-[12px] mt-3 leading-snug" style="color: var(--mg-fg-dim)">
              ¿No te llegó? Escribinos y te lo pasamos de nuevo.
            </p>
          </template>

          <template v-else>
            <p class="mg-display text-[19px] leading-tight">No pudimos seguir</p>
            <p class="text-[13.5px] mt-2 leading-snug" style="color: var(--mg-fg-dim)">
              {{ mensajeError }}
            </p>
          </template>

          <div v-if="estado !== 'enviando'" class="flex gap-2.5 mt-5">
            <a v-if="linkWhatsapp" :href="linkWhatsapp" class="mg-btn-primary flex-1">
              Escribinos
            </a>
            <button type="button" class="mg-btn-ghost flex-1" @click="emit('cerrar')">
              Cerrar
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
/**
 * Modal del CTA "La quiero". Lo comparten las vistas móvil y escritorio del comparador.
 *
 * El botón de WhatsApp está siempre, incluso cuando el link se mandó bien: si la ventana de 24hs
 * de Meta está cerrada, la API rechaza el mensaje y el cliente se quedaría esperando algo que
 * nunca llega. Que escriba él reabre la ventana.
 */
import { computed } from 'vue'
import { waLink } from './comparador'
import type { EstadoContratacion } from './useContratar'

const props = defineProps<{
  estado: EstadoContratacion | null
  mensajeError: string | null
  whatsappNumber: string | null
}>()

const emit = defineEmits<{ cerrar: [] }>()

const linkWhatsapp = computed(() =>
  waLink(
    props.whatsappNumber,
    props.estado === 'linkEnviado'
      ? 'Hola, quiero contratar pero no me llegó el link.'
      : 'Hola, quiero avanzar con la contratación.',
  ),
)
</script>
