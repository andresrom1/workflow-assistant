/**
 * El CTA "La quiero": abre el checkout de la alternativa elegida sin pasar por el chat.
 *
 * Vive acá y no en cada componente porque el comparador tiene dos vistas (móvil y escritorio) que
 * tienen que comportarse igual: son dos layouts del mismo botón, no dos features.
 *
 * En un celular el servidor redirige al formulario de checkout y esta página se desmonta. En
 * escritorio no se puede completar (las 7 fotos de inspección salen de la cámara), así que el
 * servidor manda el link por WhatsApp y vuelve acá — de ahí el modal.
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { esDispositivoMovil } from '@/lib/dispositivo'

export type EstadoContratacion = 'enviando' | 'linkEnviado' | 'error'

export function useContratar(token: string) {
  const estado = ref<EstadoContratacion | null>(null)
  const mensajeError = ref<string | null>(null)

  function contratar(planId: number): void {
    if (estado.value === 'enviando') {
      return
    }

    const movil = esDispositivoMovil()

    estado.value = 'enviando'
    mensajeError.value = null

    router.post(
      `/cotizaciones/${token}/checkout`,
      { alternative_id: planId, movil },
      {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          // En móvil ya estamos navegando al checkout: dejar el modal abierto haría que
          // parpadee arriba de la página nueva.
          estado.value = movil ? null : 'linkEnviado'
        },
        onError: (errores) => {
          estado.value = 'error'
          mensajeError.value =
            errores.alternative_id ?? 'No pudimos abrir la contratación. Probá de nuevo.'
        },
      },
    )
  }

  function cerrar(): void {
    estado.value = null
    mensajeError.value = null
  }

  return { estado, mensajeError, contratar, cerrar }
}
