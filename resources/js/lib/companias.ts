/**
 * Identidad visual de cada compañía: el logo cuando lo tenemos, el color del monograma cuando no.
 *
 * La clave es el slug que arma el backend con `Str::slug($alternative->aseguradora)` — ver
 * `QuoteComparisonService::plan()` y `CheckoutController::show()`. La lista es abierta: el
 * proveedor puede devolver una compañía que no está acá, así que todo tiene fallback.
 */

import experta from '@/assets/logos/experta.png'
import galicia from '@/assets/logos/galicia.png'
import mercantilAndina from '@/assets/logos/mercantil-andina.png'
import rioUruguay from '@/assets/logos/rio-uruguay.png'
import sanCristobal from '@/assets/logos/san-cristobal.png'
import sanCristobalNegativo from '@/assets/logos/san-cristobal-negativo.png'
import sancor from '@/assets/logos/sancor.png'
import triunfo from '@/assets/logos/triunfo.png'

/**
 * Los imports son explícitos a propósito: si un archivo se renombra o se borra, falla el build en
 * vez de dejar un logo faltante que solo se descubre mirando la página.
 */
const LOGOS: Record<string, string> = {
  experta,
  galicia,
  'mercantil-andina': mercantilAndina,
  'rio-uruguay': rioUruguay,
  'san-cristobal': sanCristobal,
  sancor,
  triunfo,
}

/**
 * Variante para fondo oscuro, solo de las compañías que la tienen.
 *
 * Los logos son tinta oscura sobre transparente — están hechos para papel. Sobre el fondo del tema
 * noir (`#161412` la tarjeta, `#0a0908` el header del checkout) ninguno llega a 3,6:1, y las tintas
 * azul-casi-negro directamente desaparecen: San Cristóbal da 1,20:1. El negativo que publica la
 * compañía lo resuelve sin ponerle una caja clara atrás al logo.
 *
 * Las que no están acá se sirven igual en los dos temas.
 */
const LOGOS_DARK: Record<string, string> = {
  'san-cristobal': sanCristobalNegativo,
}

/** La URL hasheada del logo, o null si de esa compañía no tenemos archivo. */
export function logoDeCompania(slug: string): string | null {
  return LOGOS[slug] ?? null
}

/** La variante para fondo oscuro, o null si esa compañía usa la misma en los dos temas. */
export function logoDeCompaniaDark(slug: string): string | null {
  return LOGOS_DARK[slug] ?? null
}

/**
 * Color del monograma, el círculo con la inicial que se usa cuando no hay logo.
 *
 * El mapa cubre las compañías conocidas; cualquier nombre nuevo cae a un color derivado del slug —
 * determinístico, para que la misma compañía no cambie de color entre visitas.
 */
const CONOCIDAS: Record<string, string> = {
  galicia: '#f47b20',
  sancor: '#00843d',
  'rio-uruguay': '#1e5aa8',
  'san-cristobal': '#c8102e',
  experta: '#6b2c91',
  triunfo: '#f5a623',
  'mercantil-andina': '#0b7285',
  'la-caja': '#d64000',
}

const PALETA = ['#1e5aa8', '#00843d', '#c8102e', '#6b2c91', '#f47b20', '#0b7285', '#8a6d1f']

export function colorDeCompania(slug: string): string {
  if (CONOCIDAS[slug]) {
    return CONOCIDAS[slug]
  }

  let hash = 0
  for (let i = 0; i < slug.length; i++) {
    hash = (hash * 31 + slug.charCodeAt(i)) >>> 0
  }

  return PALETA[hash % PALETA.length]
}
