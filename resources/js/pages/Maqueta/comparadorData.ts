/**
 * Datos hardcodeados de la maqueta del comparador.
 *
 * Salen de `quote_alternatives` de la cotización 20 (grado `third_party_complete`):
 * 16 alternativas, 4 compañías, misma suma asegurada. Precios y textos de detalle
 * son los reales — la maqueta existe para evaluar la UX con contenido de verdad,
 * no con lorem ipsum.
 *
 * Los campos `variante`, `notaVariante` y `featuresNormalizadas` NO existen hoy en
 * el modelo: son la propuesta de la maqueta. Ver la discusión del feature.
 */

export type Plan = {
  id: number
  aseguradora: string
  titulo: string
  precio: number
  /** Lo que distingue esta variante de las otras de la misma compañía. */
  variante: string
  /** Cuando dos variantes son indistinguibles con los datos que tenemos. */
  notaVariante?: string
  features: string[]
  detalle: Record<string, string>
}

export type Compania = {
  slug: string
  nombre: string
  /** Placeholder hasta tener los logos reales de las compañías. */
  color: string
  planes: Plan[]
}

export const contexto = {
  vehiculo: 'Fiat Pulse 1.3 Drive',
  anio: 2026,
  patente: 'AF 123 XX',
  cobertura: 'Terceros Completo',
  sumaAsegurada: '$ 15.000.000',
  cotizadoEl: '20 de julio',
  validoHasta: '27 de julio',
  totalOpciones: 16,
}

/** Número de placeholder — en producción sale de config('whatsapp.public_number'). */
const WA_NUMBER = '5493510000000'

export function waLink(texto: string): string {
  return `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent(texto)}`
}

const RIO_URUGUAY: Compania = {
  slug: 'rio-uruguay',
  nombre: 'Río Uruguay',
  color: '#1e5aa8',
  planes: [
    {
      id: 675,
      aseguradora: 'Río Uruguay',
      titulo: 'Sigma Cero',
      precio: 28789,
      variante: 'Cobertura base',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
      },
    },
    {
      id: 676,
      aseguradora: 'Río Uruguay',
      titulo: 'Sigma',
      precio: 37094,
      variante: 'Suma granizo y cristales',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido.',
      },
    },
    {
      id: 677,
      aseguradora: 'Río Uruguay',
      titulo: 'Sigma Importado',
      precio: 40238,
      variante: 'Igual que Sigma, para vehículos importados',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido.',
      },
    },
    {
      id: 678,
      aseguradora: 'Río Uruguay',
      titulo: 'C3-80',
      precio: 44230,
      variante: 'Suma ruedas',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales', 'Ruedas'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido.',
        Ruedas: 'Incluido. Consultar condiciones de póliza para límite de eventos.',
      },
    },
    {
      id: 679,
      aseguradora: 'Río Uruguay',
      titulo: 'Robo Plus',
      precio: 47790,
      variante: 'Robo ampliado y cerraduras',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales', 'Ruedas', 'Cerraduras'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido con cobertura ampliada.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido.',
        Ruedas: 'Incluido. Consultar condiciones de póliza para límite de eventos.',
        Cerraduras: 'Incluido.',
      },
    },
  ],
}

const SANCOR: Compania = {
  slug: 'sancor',
  nombre: 'Sancor',
  color: '#00843d',
  planes: [
    {
      id: 666,
      aseguradora: 'Sancor',
      titulo: 'Auto Max 3',
      precio: 41370,
      variante: 'Cobertura base',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
      },
    },
    {
      id: 667,
      aseguradora: 'Sancor',
      titulo: 'Auto Max 6',
      precio: 53199,
      variante: 'Suma granizo y cristales',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido.',
      },
    },
    {
      id: 668,
      aseguradora: 'Sancor',
      titulo: 'Premium Max',
      precio: 60957,
      variante: 'Suma ruedas y cerraduras',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales', 'Ruedas', 'Cerraduras'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido.',
        Ruedas: 'Incluido. Consultar condiciones de póliza para límite de eventos.',
        Cerraduras: 'Incluido.',
      },
    },
  ],
}

const SAN_CRISTOBAL: Compania = {
  slug: 'san-cristobal',
  nombre: 'San Cristóbal',
  color: '#c8102e',
  planes: [
    {
      id: 660,
      aseguradora: 'San Cristóbal',
      titulo: 'Auto Plus',
      precio: 34288,
      variante: 'Cobertura base',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
      },
    },
    {
      id: 661,
      aseguradora: 'San Cristóbal',
      titulo: 'Auto Plus Mas',
      precio: 44282,
      variante: 'Suma granizo y cristales',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido.',
      },
    },
    {
      id: 662,
      aseguradora: 'San Cristóbal',
      titulo: 'Auto Mega',
      precio: 54809,
      variante: 'Suma ruedas y cerraduras',
      features: ['Responsabilidad Civil', 'Robo Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales', 'Ruedas', 'Cerraduras'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido.',
        Ruedas: 'Incluido. Consultar condiciones de póliza para límite de eventos.',
        Cerraduras: 'Incluido.',
      },
    },
  ],
}

const TRIUNFO: Compania = {
  slug: 'triunfo',
  nombre: 'Triunfo',
  color: '#f5a623',
  planes: [
    {
      id: 649,
      aseguradora: 'Triunfo',
      titulo: 'C',
      precio: 29254,
      variante: 'Cobertura base',
      features: ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo o Hurto Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Ruedas: 'Reposición a valor de fábrica. 1 rueda por evento.',
      },
    },
    {
      id: 650,
      aseguradora: 'Triunfo',
      titulo: 'C1',
      precio: 32582,
      variante: 'Cobertura base',
      notaVariante: 'Con los datos que nos manda la compañía, C1 cubre lo mismo que C. Preguntame y lo confirmo.',
      features: ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo o Hurto Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Ruedas: 'Reposición a valor de fábrica. 1 rueda por evento.',
      },
    },
    {
      id: 651,
      aseguradora: 'Triunfo',
      titulo: 'C2',
      precio: 36862,
      variante: 'Suma granizo y cristales',
      features: ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo o Hurto Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido hasta suma asegurada.',
        Ruedas: 'Reposición a valor de fábrica. 1 rueda por evento, 2 eventos por vigencia.',
      },
    },
    {
      id: 652,
      aseguradora: 'Triunfo',
      titulo: 'C8',
      precio: 40383,
      variante: 'Suma granizo y cristales',
      notaVariante: 'C8 cubre lo mismo que C2 según los datos de la compañía. Preguntame y lo confirmo.',
      features: ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo o Hurto Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada.',
        Cristales: 'Incluido hasta suma asegurada.',
        Ruedas: 'Reposición a valor de fábrica. 1 rueda por evento, 2 eventos por vigencia.',
      },
    },
    {
      id: 653,
      aseguradora: 'Triunfo',
      titulo: 'C2 Full',
      precio: 46139,
      variante: 'Suma inundación y dos ruedas por evento',
      features: ['Responsabilidad Civil', 'Robo o Hurto Total y Parcial', 'Incendio Total y Parcial', 'Destrucción Total por Accidente', 'Granizo', 'Cristales de Techo', 'Inundación o Desbordamiento', 'Ruedas'],
      detalle: {
        'Responsabilidad Civil': 'Incluido.',
        'Robo o Hurto Total y Parcial': 'Incluido.',
        'Incendio Total y Parcial': 'Incluido.',
        'Destrucción Total por Accidente': 'Incluido.',
        Granizo: 'Cubierto hasta suma asegurada del vehículo.',
        'Cristales de Techo': 'Incluido hasta suma asegurada.',
        'Inundación o Desbordamiento': 'Incluido hasta suma asegurada del vehículo.',
        Ruedas: 'Reposición a valor de fábrica. 2 ruedas por evento, 2 eventos por vigencia de póliza.',
      },
    },
  ],
}

export const companias: Compania[] = [RIO_URUGUAY, SANCOR, SAN_CRISTOBAL, TRIUNFO]

export const todosLosPlanes: Plan[] = companias.flatMap((c) => c.planes)

export function planPorId(id: number): Plan {
  return todosLosPlanes.find((p) => p.id === id)!
}

/** Las dos que el agente presentó en el chat, con la razón que le dio al cliente. */
export const recomendadas = {
  principal: {
    plan: planPorId(667), // Sancor Auto Max 6
    razon:
      'Sancor está en CLEAS: si chocás con otro asegurado, las compañías liquidan el siniestro entre ellas y vos no hacés el trámite. Cubre granizo y cristales, que fue lo que me pediste.',
  },
  segunda: {
    plan: planPorId(653), // Triunfo C2 Full
    razon:
      'Sale $7.060 menos por mes y suma inundación y dos ruedas por evento. La diferencia: cubre el cristal del techo, no los laterales.',
  },
}

/**
 * Diff de las dos recomendadas.
 *
 * Escrito a mano a propósito: los tags vienen redactados por cada compañía
 * ("Robo Total y Parcial" vs "Robo o Hurto Total y Parcial"), así que un diff
 * de conjuntos crudo inventa diferencias que no existen. Sin normalizar los
 * nombres de cobertura, esta vista no se puede generar automáticamente.
 */
export const comparacion = {
  iguales: [
    { label: 'Responsabilidad Civil', nota: 'Incluido en las dos' },
    { label: 'Robo e incendio total y parcial', nota: 'Incluido en las dos' },
    { label: 'Destrucción total por accidente', nota: 'Incluido en las dos' },
    { label: 'Granizo', nota: 'Hasta suma asegurada en las dos' },
    { label: 'Suma asegurada', nota: '$ 15.000.000 en las dos' },
  ],
  soloPrincipal: [
    { label: 'Cristales', nota: 'Todos los cristales, hasta suma asegurada' },
  ],
  soloSegunda: [
    { label: 'Inundación o desbordamiento', nota: 'Hasta suma asegurada del vehículo' },
    { label: 'Ruedas', nota: '2 ruedas por evento, 2 eventos por vigencia' },
    { label: 'Cristales de techo', nota: 'Solo el cristal del techo' },
  ],
  diferenciaPrecio: 7060,
}

export function formatPrecio(n: number): string {
  return n.toLocaleString('es-AR', { maximumFractionDigits: 0 })
}
