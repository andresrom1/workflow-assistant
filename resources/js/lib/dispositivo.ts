/**
 * Detección de dispositivo móvil por user-agent.
 *
 * La usan el checkout (que **exige** móvil: las 7 fotos de inspección se sacan con la cámara) y
 * el CTA "La quiero" del comparador (que decide si mandar al cliente al formulario o pasarle el
 * link por WhatsApp). Vive acá y no duplicada en cada componente porque si las dos pantallas
 * clasifican distinto, el cliente pasa el CTA y choca contra la pared del checkout.
 *
 * OJO — no confundir con el corte de layout del comparador (`max-width: 899px`), que es de ancho
 * de ventana y no de dispositivo: una ventana angosta en escritorio es "mobile" para el layout y
 * escritorio para esto.
 */
const REGEX_MOVIL = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i

export function esDispositivoMovil(): boolean {
  return typeof navigator !== 'undefined' && REGEX_MOVIL.test(navigator.userAgent)
}
