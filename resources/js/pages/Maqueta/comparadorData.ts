/**
 * Datos hardcodeados de la maqueta del comparador.
 *
 * Salen de la cotización 6 de producción (2026-07-26), grado `all_risk`:
 * 24 alternativas de 6 compañías para un Peugeot 2008 1.6 Active 2017 a GNC.
 * Precios, sumas aseguradas y textos de cobertura son los reales.
 *
 * Hallazgo que define la forma de estos datos: en toda la base de producción
 * cada tag de cobertura tiene UNA sola descripción, idéntica entre compañías
 * (22 tags, ~3.700 apariciones, 7 compañías, cero excepciones). El glosario es
 * canónico en el proveedor. Por eso acá va una sola vez y cada plan guarda
 * únicamente su lista de tags — y por eso el diff es una diferencia de
 * conjuntos, sin diccionario de sinónimos ni modelo de por medio.
 */

/** Glosario canónico: tag → descripción. Tal cual viene de `full_details`. */
export const GLOSARIO: Record<string, string> = {
  'Auxilio mecánico y/o Grúa': 'Auxilio mecánico y servicio de grúa por avería o accidente.',
  'Caída de árboles': 'Cubre daños causados al vehículo por caída de árboles',
  Cerraduras: 'Daños y/o rotura de cerraduras de las puertas y/o baúl por intento de robo.',
  'Cristal de Techo': 'Cubre el daño y/o rotura accidental del cristal de techo.',
  'Cristales Laterales': 'Daños y/o rotura de cristales laterales.',
  'Daños Parciales': 'Daños parciales por accidentes, sujeta a la franquicia contratada.',
  'Daños Parciales al Amparo del Robo Total':
    'Daños parciales como consecuencia de un Robo Total con posterior aparición del vehículo.',
  'Destrucción Total por accidente':
    'Cuando el valor de reparación de mano de obra y repuestos supera el 80 % del valor del vehículo al momento del siniestro.',
  'Extensión Mercosur':
    'Se extiende la cobertura exclusivamente durante el viaje de ida y vuelta por vía terrestre o fluvial y la permanencia del vehículo asegurado en países limítrofes.',
  Granizo: 'Daños parciales consecuencia del granizo.',
  'Incendio Parcial':
    'Daño producido en la unidad por el accionar del fuego siempre que la reparación no supere el 80% del valor del vehículo al momento del siniestro.',
  'Incendio Total':
    'Cuando el costo de reparación causado por el incendio supera el 80% del valor del vehículo al momento del siniestro.',
  Inundación: 'Cubre daños al vehículo a causa de inundación.',
  Luneta: 'Daño y/o Rotura accidental de la luneta',
  Parabrisas: 'Daño y/o Rotura accidental del Parabrisas.',
  'Reposición 0KM':
    'En caso de Robo, Incendio o Destrucción Total la Compañía repone un auto 0KM siempre que haya sido asegurado desde 0KM en la Compañia.',
  'Responsabilidad Civil': 'Daños a terceros transportados, terceros no transportados y daños a cosas.',
  'Robo Parcial':
    'Robo de los elementos fijos que hacen al funcionamiento de la unidad a excepción de: - Equipo de audio - Tasa de rueda - Escobilla de parabrisas y Luneta - Espejos retrovisores - Insignias',
  'Robo Total':
    'Desaparición del vehículo o una vez aparecido el costo de los faltantes superan el 80% del valor del vehículo al momento del siniestro.',
  Ruedas: 'Cubre por robo las ruedas del vehículo.',
  'Sistema Cleas':
    'La compañía perteneces al CLEAS (sistema de liquidación de siniestros) para una mejor y más rápida atención. http://www.cleas.com.ar/site/index.html',
}

/**
 * Tags que no son coberturas: `Sistema Cleas` es un atributo de la compañía y
 * `Reposición 0KM` un beneficio comercial. Se muestran, pero no bajo un título
 * que diga "cobertura".
 */
export const NO_SON_COBERTURA = new Set(['Sistema Cleas', 'Reposición 0KM'])

export type Plan = {
  id: number
  aseguradora: string
  titulo: string
  /**
   * Hoy solo existe adentro de `titulo` como texto libre; no hay campo en el
   * modelo. Acá va aparte porque es lo que de verdad distingue las variantes.
   */
  franquicia: string
  precio: number
  sumaAsegurada: number
  features: string[]
}

export type Compania = {
  slug: string
  nombre: string
  /** Placeholder hasta tener los logos reales. */
  color: string
  planes: Plan[]
}

export const contexto = {
  vehiculo: 'Peugeot 2008 1.6 Active',
  anio: 2017,
  combustible: 'GNC',
  cobertura: 'Todo Riesgo',
  cotizadoEl: '26 de julio',
  validoHasta: '2 de agosto',
}

/** Número de placeholder — en producción sale de config('whatsapp.public_number'). */
const WA_NUMBER = '5493510000000'

export function waLink(texto: string): string {
  return `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent(texto)}`
}

// ── Vocabularios por compañía ────────────────────────────────────────────────
// Dentro de un mismo grado, todas las variantes de una compañía traen el mismo
// set de tags; lo que cambia entre ellas es la franquicia y el precio.

const BASE = [
  'Responsabilidad Civil',
  'Robo Total',
  'Robo Parcial',
  'Incendio Total',
  'Incendio Parcial',
  'Destrucción Total por accidente',
  'Daños Parciales',
  'Daños Parciales al Amparo del Robo Total',
  'Granizo',
  'Ruedas',
  'Cerraduras',
  'Cristales Laterales',
  'Luneta',
  'Parabrisas',
  'Auxilio mecánico y/o Grúa',
  'Extensión Mercosur',
]

const TAGS_EXPERTA = [...BASE, 'Reposición 0KM']
const TAGS_TRIUNFO = [...BASE, 'Cristal de Techo', 'Inundación']
const TAGS_RIO_URUGUAY = [...BASE, 'Cristal de Techo', 'Sistema Cleas']
const TAGS_SAN_CRISTOBAL = [...BASE, 'Cristal de Techo', 'Inundación', 'Sistema Cleas']
const TAGS_SANCOR = [...TAGS_SAN_CRISTOBAL, 'Reposición 0KM']
const TAGS_GALICIA = [...TAGS_SANCOR, 'Caída de árboles']

// ── Planes ───────────────────────────────────────────────────────────────────
// Las 24 alternativas tal cual vienen de la cotización, con repetidos incluidos.
// La regla que se queda con la más barata está abajo, en `soloLaMasBarata`.

const GALICIA: Compania = {
  slug: 'galicia',
  nombre: 'Galicia',
  color: '#f47b20',
  planes: [
    {
      id: 273,
      aseguradora: 'Galicia',
      titulo: 'Todo Riesgo Franquicia 4%',
      franquicia: '4% de la suma asegurada',
      precio: 90317.04,
      sumaAsegurada: 16512000,
      features: TAGS_GALICIA,
    },
    {
      id: 309,
      aseguradora: 'Galicia',
      titulo: 'Todo Riesgo Franquicia 4%',
      franquicia: '4% de la suma asegurada',
      precio: 90317.04,
      sumaAsegurada: 16512000,
      features: TAGS_GALICIA,
    },
    {
      id: 272,
      aseguradora: 'Galicia',
      titulo: 'Todo Riesgo Franquicia 2%',
      franquicia: '2% de la suma asegurada',
      precio: 111473.27,
      sumaAsegurada: 16512000,
      features: TAGS_GALICIA,
    },
    {
      id: 308,
      aseguradora: 'Galicia',
      titulo: 'Todo Riesgo Franquicia 2%',
      franquicia: '2% de la suma asegurada',
      precio: 111473.27,
      sumaAsegurada: 16512000,
      features: TAGS_GALICIA,
    },
    {
      id: 266,
      aseguradora: 'Galicia',
      titulo: 'Todo Riesgo Franquicia 4%',
      franquicia: '4% de la suma asegurada',
      precio: 116461.65,
      sumaAsegurada: 16512000,
      features: TAGS_GALICIA,
    },
    {
      id: 265,
      aseguradora: 'Galicia',
      titulo: 'Todo Riesgo Franquicia 2%',
      franquicia: '2% de la suma asegurada',
      precio: 144830.9,
      sumaAsegurada: 16512000,
      features: TAGS_GALICIA,
    },
  ],
}

const SANCOR: Compania = {
  slug: 'sancor',
  nombre: 'Sancor',
  color: '#00843d',
  planes: [
    {
      id: 329,
      aseguradora: 'Sancor',
      titulo: 'Todo Riesgo Franquicia 8%',
      franquicia: '8% de la suma asegurada',
      precio: 78768.77,
      sumaAsegurada: 15900000,
      features: TAGS_SANCOR,
    },
    {
      id: 328,
      aseguradora: 'Sancor',
      titulo: 'Todo Riesgo Franquicia 4%',
      franquicia: '4% de la suma asegurada',
      precio: 91689.82,
      sumaAsegurada: 15900000,
      features: TAGS_SANCOR,
    },
    {
      id: 327,
      aseguradora: 'Sancor',
      titulo: 'Todo Riesgo Franquicia 1%',
      franquicia: '1% de la suma asegurada',
      precio: 195058.46,
      sumaAsegurada: 15900000,
      features: TAGS_SANCOR,
    },
  ],
}

const RIO_URUGUAY: Compania = {
  slug: 'rio-uruguay',
  nombre: 'Río Uruguay',
  color: '#1e5aa8',
  planes: [
    {
      id: 276,
      aseguradora: 'Río Uruguay',
      titulo: 'T37 - Todo Riesgo Franquicia 7%',
      franquicia: '7% de la suma asegurada',
      precio: 99113,
      sumaAsegurada: 15817000,
      features: TAGS_RIO_URUGUAY,
    },
    {
      id: 275,
      aseguradora: 'Río Uruguay',
      titulo: 'Todo Riesgo Franquicia 5%',
      franquicia: '5% de la suma asegurada',
      precio: 107039,
      sumaAsegurada: 15817000,
      features: TAGS_RIO_URUGUAY,
    },
    {
      id: 274,
      aseguradora: 'Río Uruguay',
      titulo: 'Todo Riesgo Franquicia 3%',
      franquicia: '3% de la suma asegurada',
      precio: 114965,
      sumaAsegurada: 15817000,
      features: TAGS_RIO_URUGUAY,
    },
  ],
}

const EXPERTA: Compania = {
  slug: 'experta',
  nombre: 'Experta',
  color: '#6b2c91',
  planes: [
    {
      id: 345,
      aseguradora: 'Experta',
      titulo: 'Todo Riesgo XL Franquicia 5%',
      franquicia: '5% de la suma asegurada',
      precio: 103927,
      sumaAsegurada: 16373000,
      features: TAGS_EXPERTA,
    },
    {
      id: 352,
      aseguradora: 'Experta',
      titulo: 'Todo Riesgo XL Franquicia 5%',
      franquicia: '5% de la suma asegurada',
      precio: 115474,
      sumaAsegurada: 16373000,
      features: TAGS_EXPERTA,
    },
    {
      id: 344,
      aseguradora: 'Experta',
      titulo: 'Todo Riesgo XL Franquicia 2%',
      franquicia: '2% de la suma asegurada',
      precio: 116294,
      sumaAsegurada: 16373000,
      features: TAGS_EXPERTA,
    },
    {
      id: 351,
      aseguradora: 'Experta',
      titulo: 'Todo Riesgo XL Franquicia 2%',
      franquicia: '2% de la suma asegurada',
      precio: 129214,
      sumaAsegurada: 16373000,
      features: TAGS_EXPERTA,
    },
  ],
}

const SAN_CRISTOBAL: Compania = {
  slug: 'san-cristobal',
  nombre: 'San Cristóbal',
  color: '#c8102e',
  planes: [
    {
      id: 319,
      aseguradora: 'San Cristóbal',
      titulo: 'Todo Riesgo Franquicia 7,5%',
      franquicia: '7,5% de la suma asegurada',
      precio: 149984,
      sumaAsegurada: 16095000,
      features: TAGS_SAN_CRISTOBAL,
    },
    {
      id: 367,
      aseguradora: 'San Cristóbal',
      titulo: 'Todo Riesgo Franquicia 7,5%',
      franquicia: '7,5% de la suma asegurada',
      precio: 149984,
      sumaAsegurada: 16095000,
      features: TAGS_SAN_CRISTOBAL,
    },
    {
      id: 366,
      aseguradora: 'San Cristóbal',
      titulo: 'Todo Riesgo Franquicia 5%',
      franquicia: '5% de la suma asegurada',
      precio: 161824,
      sumaAsegurada: 16095000,
      features: TAGS_SAN_CRISTOBAL,
    },
    {
      id: 318,
      aseguradora: 'San Cristóbal',
      titulo: 'Todo Riesgo Franquicia 5%',
      franquicia: '5% de la suma asegurada',
      precio: 161824,
      sumaAsegurada: 16095000,
      features: TAGS_SAN_CRISTOBAL,
    },
    {
      id: 377,
      aseguradora: 'San Cristóbal',
      titulo: 'Todo Riesgo Franquicia 7,5%',
      franquicia: '7,5% de la suma asegurada',
      precio: 181823.67,
      sumaAsegurada: 16095000,
      features: TAGS_SAN_CRISTOBAL,
    },
    {
      id: 376,
      aseguradora: 'San Cristóbal',
      titulo: 'Todo Riesgo Franquicia 5%',
      franquicia: '5% de la suma asegurada',
      precio: 196626.33,
      sumaAsegurada: 16095000,
      features: TAGS_SAN_CRISTOBAL,
    },
  ],
}

const TRIUNFO: Compania = {
  slug: 'triunfo',
  nombre: 'Triunfo',
  color: '#f5a623',
  planes: [
    {
      id: 302,
      aseguradora: 'Triunfo',
      titulo: 'D3 - Todo Riesgo Franquicia 10%',
      franquicia: '10% de la suma asegurada, mínimo $400.000',
      precio: 70447.2,
      sumaAsegurada: 15400000,
      features: TAGS_TRIUNFO,
    },
    {
      id: 292,
      aseguradora: 'Triunfo',
      titulo: 'D3 - Todo Riesgo Franquicia 10%',
      franquicia: '10% de la suma asegurada, mínimo $400.000',
      precio: 71799.2,
      sumaAsegurada: 15400000,
      features: TAGS_TRIUNFO,
    },
  ],
}

const TODAS: Compania[] = [TRIUNFO, SANCOR, GALICIA, RIO_URUGUAY, EXPERTA, SAN_CRISTOBAL]

/**
 * Una alternativa sin coberturas no se puede mostrar ni comparar. En la
 * cotización 6, Sancor devuelve "Garage" a $3.321,98 y "Auto Max 15" con
 * `features_tags` vacío: si entraran al listado, la card de Sancor anunciaría
 * "desde $3.321", que no corresponde a ninguna póliza comparable.
 */
function tieneCoberturas(p: Plan): boolean {
  return p.features.length > 0
}

/**
 * Cuando dos alternativas de la misma compañía comparten franquicia y
 * coberturas, y lo único que las separa es el precio, se muestra la más barata.
 *
 * Pasa seguido: Galicia devuelve Franquicia 4% a $90.317 y a $116.462, y San
 * Cristóbal la de 7,5% a $149.984 y a $181.824. En el dominio son
 * indistinguibles — cambia el `external_quote_id` del proveedor y nada más —,
 * así que ofrecer la cara no tiene sentido para el cliente.
 */
function soloLaMasBarata(planes: Plan[]): Plan[] {
  const porVariante = new Map<string, Plan>()

  for (const plan of planes) {
    const clave = `${plan.franquicia}|${[...plan.features].sort().join(',')}`
    const elegido = porVariante.get(clave)

    if (!elegido || plan.precio < elegido.precio) {
      porVariante.set(clave, plan)
    }
  }

  return [...porVariante.values()].sort((a, b) => a.precio - b.precio)
}

export const companias: Compania[] = TODAS.map((c) => ({
  ...c,
  planes: soloLaMasBarata(c.planes.filter(tieneCoberturas)),
})).filter((c) => c.planes.length > 0)

export const todosLosPlanes: Plan[] = companias.flatMap((c) => c.planes)

export function planPorId(id: number): Plan {
  return todosLosPlanes.find((p) => p.id === id)!
}

export const totalOpciones = todosLosPlanes.length

/** Las dos que el agente presentó en el chat, con la razón que le dio al cliente. */
export const recomendadas = {
  principal: {
    plan: planPorId(273), // Galicia Todo Riesgo Franquicia 4%
    razon:
      'Es la franquicia más baja que conseguí a este precio: si tenés un choque, ponés el 4% de la suma asegurada y el resto lo cubre la compañía. Además Galicia está en CLEAS, así que un siniestro con otro asegurado se liquida entre compañías sin que hagas el trámite.',
  },
  segunda: {
    plan: planPorId(302), // Triunfo D3 Franquicia 10%
    razon:
      'Sale $19.870 menos por mes. La contra es la franquicia: 10% de la suma con un mínimo de $400.000, contra el 4% de Galicia. Si el golpe es chico, lo terminás pagando vos.',
  },
}

// ── Diff ─────────────────────────────────────────────────────────────────────

export type ItemDiff = { label: string; nota: string; esCobertura: boolean }

export type Comparacion = {
  comunes: ItemDiff[]
  soloA: ItemDiff[]
  soloB: ItemDiff[]
}

function aItem(label: string): ItemDiff {
  return {
    label,
    nota: GLOSARIO[label] ?? '',
    esCobertura: !NO_SON_COBERTURA.has(label),
  }
}

/**
 * Diferencia de conjuntos sobre los tags. No hace falta normalizar nada: el
 * vocabulario es cerrado y cada tag tiene una única descripción en toda la
 * base, así que un tag compartido significa la misma cobertura.
 *
 * Lo que el dato NO dice son los límites de cada plan (cuántas ruedas, qué
 * tope). Por eso `comunes` se presenta como "incluida en las dos", nunca como
 * "igual en las dos" — el detalle fino sale por el chat, contra la
 * documentación de la compañía.
 */
export function compararPlanes(a: Plan, b: Plan): Comparacion {
  const setA = new Set(a.features)
  const setB = new Set(b.features)

  const ordenar = (items: ItemDiff[]): ItemDiff[] =>
    items.sort((x, y) => Number(y.esCobertura) - Number(x.esCobertura) || x.label.localeCompare(y.label, 'es'))

  return {
    comunes: ordenar(a.features.filter((t) => setB.has(t)).map(aItem)),
    soloA: ordenar(a.features.filter((t) => !setB.has(t)).map(aItem)),
    soloB: ordenar(b.features.filter((t) => !setA.has(t)).map(aItem)),
  }
}

export const comparacion: Comparacion = compararPlanes(
  recomendadas.principal.plan,
  recomendadas.segunda.plan,
)

export const diferenciaPrecio = Math.abs(
  recomendadas.principal.plan.precio - recomendadas.segunda.plan.precio,
)

export const diferenciaPorcentaje = Math.round(
  (diferenciaPrecio / recomendadas.principal.plan.precio) * 100,
)

// ── Formato ──────────────────────────────────────────────────────────────────

export function formatPrecio(n: number): string {
  return n.toLocaleString('es-AR', { maximumFractionDigits: 0 })
}

export function formatSuma(n: number): string {
  return `$ ${n.toLocaleString('es-AR', { maximumFractionDigits: 0 })}`
}

export function desdePrecio(c: Compania): number {
  return Math.min(...c.planes.map((p) => p.precio))
}

/** Coberturas del plan, con su descripción canónica, para la vista de detalle. */
export function coberturasDe(p: Plan): ItemDiff[] {
  return p.features
    .map(aItem)
    .sort((x, y) => Number(y.esCobertura) - Number(x.esCobertura) || x.label.localeCompare(y.label, 'es'))
}
