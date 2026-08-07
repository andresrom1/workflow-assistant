/**
 * Tipos y helpers de presentación de la vista pública de cotizaciones.
 *
 * Toda la lógica de dominio — glosario, dedupe, agrupación y diff — vive en
 * `App\Services\Quote\QuoteComparisonService`. Acá solo queda formato y navegación por las props.
 */

export type ItemDiff = {
  label: string
  nota: string
  esCobertura: boolean
}

export type Plan = {
  id: number
  aseguradora: string
  companiaSlug: string
  titulo: string
  franquicia: string | null
  precio: number
  sumaAsegurada: number
  sumaAseguradaTexto: string | null
  features: string[]
}

export type Compania = {
  slug: string
  nombre: string
  desde: number
  sumaAsegurada: number | null
  planes: Plan[]
}

export type Glosario = Record<string, { nota: string; esCobertura: boolean }>

export type Recomendadas = {
  principal: { planId: number; razon: string | null }
  segunda: { planId: number; razon: string | null }
}

/**
 * El par presentado cuando son de grados distintos y una contiene a la otra: no hay dos columnas
 * de exclusivas, hay una sola lista de lo que suma la más cara.
 */
export type Escalon = {
  arribaPlanId: number
  abajoPlanId: number
  abajoTitulo: string
  diferenciaPrecio: number
  sumaCoberturas: ItemDiff[]
  sumaExtras: ItemDiff[]
}

export type Comparacion = {
  comunes: ItemDiff[]
  soloA: ItemDiff[]
  soloB: ItemDiff[]
  diferenciaPrecio: number
  ahorroAnual: number
  /** Las dos presentadas son de grados distintos. */
  cruzada: boolean
  /** Presente solo si además una contiene a la otra. */
  escalon: Escalon | null
}

export type Vehiculo = {
  marca: string | null
  modelo: string | null
  version: string | null
  year: number | null
  combustible: string | null
  descripcion: string
}

/** El payload completo que arma PublicQuoteController. */
export type Vista = {
  /** El de la URL. Lo necesita el CTA para postear el checkout. */
  token: string
  vigente: boolean
  expiresAt: string | null
  cotizadoEl: string | null
  vehiculo: Vehiculo
  /** `label` es null cuando se muestran dos grados: no hay uno solo que sea cierto. */
  cobertura: { grade: string | null; label: string | null }
  totalOpciones: number
  glosario: Glosario
  companias: Compania[]
  recomendadas: Recomendadas | null
  comparacion: Comparacion | null
  whatsappNumber: string | null
}

export function formatPrecio(n: number): string {
  return n.toLocaleString('es-AR', { maximumFractionDigits: 0 })
}

export function formatSuma(n: number | null): string {
  return n === null ? '—' : `$ ${n.toLocaleString('es-AR', { maximumFractionDigits: 0 })}`
}

/**
 * Link al chat con el texto ya escrito. Null cuando no hay número configurado: la vista
 * deshabilita el CTA en vez de generar un `wa.me` roto.
 */
export function waLink(numero: string | null, texto: string): string | null {
  return numero ? `https://wa.me/${numero}?text=${encodeURIComponent(texto)}` : null
}

/**
 * La más barata de las dos recomendadas. El resumen de precio siempre habla de ésta: el agente
 * puede recomendar la cara, y decir que "sale menos" de la que sale más sería falso.
 */
export function masBarata(a: Plan, b: Plan): Plan {
  return b.precio < a.precio ? b : a
}

export function pluralizar(n: number, singular: string, plural: string): string {
  return `${n} ${n === 1 ? singular : plural}`
}

/**
 * La única frase que escribe la vista en el escalón.
 *
 * Habla desde el precio bajo a propósito: es la lectura que hay que corregir. Las otras dos frases
 * de la comparación las escribe el agente (`presentation_reasons`), y la de la opción cara ya dice
 * "por $X más al mes" — abrir con lo mismo la duplicaría a pocos centímetros.
 */
export function leyendaEscalon(e: Escalon): string {
  const coberturas =
    e.sumaCoberturas.length === 1
      ? 'esta cobertura menos'
      : `estas ${e.sumaCoberturas.length} coberturas menos`

  return (
    `Si bien la ${e.abajoTitulo} sale $${formatPrecio(e.diferenciaPrecio)} menos por mes, ` +
    `no lo veas como un ahorro: son ${coberturas}.`
  )
}

export function planPorId(companias: Compania[], id: number): Plan | null {
  for (const compania of companias) {
    const plan = compania.planes.find((p) => p.id === id)
    if (plan) {
      return plan
    }
  }

  return null
}

export function companiaDe(companias: Compania[], slug: string): Compania | null {
  return companias.find((c) => c.slug === slug) ?? null
}

/**
 * Las coberturas del plan resueltas contra el glosario, ordenadas igual que en el backend:
 * primero lo que es cobertura, después alfabético.
 */
export function coberturasDe(plan: Plan, glosario: Glosario): ItemDiff[] {
  return plan.features
    .map((label) => ({
      label,
      nota: glosario[label]?.nota ?? '',
      esCobertura: glosario[label]?.esCobertura ?? true,
    }))
    .sort(
      (a, b) =>
        Number(b.esCobertura) - Number(a.esCobertura) || a.label.localeCompare(b.label, 'es'),
    )
}
