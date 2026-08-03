/**
 * Color de cada compañía, para el monograma que reemplaza al logo.
 *
 * El mapa cubre las compañías que devuelve el proveedor hoy; la lista es abierta, así que
 * cualquier nombre nuevo cae a un color derivado del slug — determinístico, para que la misma
 * compañía no cambie de color entre visitas.
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
