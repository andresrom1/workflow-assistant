<template>
  <div style="font-family: var(--mg-font-ui)">

    <!-- ══════ Barra superior ══════ -->
    <header
      class="px-8 py-4 sticky top-0 z-20"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="max-w-[1180px] mx-auto flex items-center justify-between gap-6">
        <MangoLogo compact :height="24" />
        <div class="flex-1 min-w-0 text-center">
          <p class="mg-heading text-[14px] truncate">
            {{ vista.vehiculo.descripcion }} {{ vista.vehiculo.year }}
            <span v-if="vista.vehiculo.combustible" style="color: var(--mg-fg-faint)">
              · {{ vista.vehiculo.combustible.toUpperCase() }}
            </span>
          </p>
        </div>
        <a
          v-if="waLink(vista.whatsappNumber, textoVolverAlChat)"
          :href="waLink(vista.whatsappNumber, textoVolverAlChat)!"
          class="flex-shrink-0 flex items-center gap-2 px-4 py-2 rounded-full text-[11.5px] font-semibold uppercase tracking-wider"
          :style="{ border: '1px solid var(--mg-hairline-strong)', color: 'var(--mg-fg)' }"
        >
          <ChatIcon :size="15" />
          Volver al chat
        </a>
      </div>
    </header>

    <!-- ══════ Encabezado editorial ══════ -->
    <section class="px-8 pt-10 pb-7">
      <div class="max-w-[1180px] mx-auto">
        <p class="mg-overline mb-3">
          {{ vista.cobertura.label }} · {{ vista.totalOpciones }} opciones
        </p>
        <h1 class="mg-display text-[38px] leading-[1.1] max-w-2xl">
          Estas son todas las opciones que conseguí para tu {{ auto }}.
        </h1>
        <p class="text-[14px] mt-3.5 max-w-xl leading-relaxed" style="color: var(--mg-fg-dim)">
          Todas son {{ vista.cobertura.label }}, pero no son equivalentes entre sí: mirá la suma
          asegurada y qué cubre cada una.
          <template v-if="vista.vigente">
            Cotizado el {{ vista.cotizadoEl }}; los precios valen hasta el final del día.
          </template>
        </p>

        <div
          v-if="!vista.vigente"
          class="mt-5 p-4 rounded-2xl text-[13.5px] leading-relaxed max-w-xl"
          :style="{ background: 'var(--mg-mango-tint)' }"
        >
          <strong>Estos precios ya vencieron.</strong> Los valores eran del
          {{ vista.cotizadoEl }} y las compañías los actualizan todos los días. Escribime y te los
          recotizo en el momento.
        </div>
      </div>
    </section>

    <!-- ══════ Master / detail ══════ -->
    <div class="px-8 pb-16">
      <div class="max-w-[1180px] mx-auto grid grid-cols-[390px_1fr] gap-7 items-start">

        <!-- ── Columna izquierda: la lista ── -->
        <div>
          <template v-if="destacadas.length">
            <p class="mg-overline mb-2.5">Lo que te recomendé</p>

            <button
              v-for="(item, i) in destacadas"
              :key="item.plan.id"
              type="button"
              class="w-full mg-card p-3.5 mb-2.5 text-left transition-all"
              :style="estiloFila(item.plan.id, i === 0)"
              @click="seleccionar(item.plan.id)"
            >
              <div class="flex items-start gap-2.5">
                <span
                  class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-[13px] font-bold text-white"
                  :style="{ background: colorDeCompania(item.plan.companiaSlug) }"
                >{{ item.plan.aseguradora.charAt(0) }}</span>
                <div class="min-w-0 flex-1">
                  <p class="mg-heading text-[14px] leading-tight flex items-center gap-1.5">
                    {{ item.plan.aseguradora }}
                    <span
                      v-if="i === 0"
                      class="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full"
                      :style="{ background: 'var(--mg-mango)', color: '#fff' }"
                    >Recomendada</span>
                  </p>
                  <p class="text-[11.5px] mt-1" :style="{ color: 'var(--mg-leaf)' }">
                    {{ item.plan.franquicia ? `Franquicia ${item.plan.franquicia}` : item.plan.titulo }}
                  </p>
                </div>
                <div class="flex-shrink-0 text-right leading-none">
                  <span class="mg-display text-[21px]">${{ formatPrecio(item.plan.precio) }}</span>
                  <span class="block text-[10px] mt-0.5" style="color: var(--mg-fg-faint)">por mes</span>
                </div>
              </div>
            </button>

            <button
              v-if="vista.comparacion"
              type="button"
              class="w-full mg-card px-3.5 py-2.5 mb-7 flex items-center justify-between transition-all"
              :style="{
                background: panel === 'compare' ? 'var(--mg-mango-tint)' : 'var(--mg-surface-2)',
                borderStyle: 'dashed',
                borderColor: panel === 'compare' ? 'var(--mg-mango)' : 'var(--mg-hairline)',
              }"
              @click="panel = 'compare'"
            >
              <span class="text-[12.5px] font-semibold">Qué cambia entre las dos</span>
              <span class="text-[11.5px]" style="color: var(--mg-mango)">Comparar →</span>
            </button>
          </template>

          <p class="mg-overline mb-1">Todas las opciones</p>
          <p class="text-[11.5px] mb-3" style="color: var(--mg-fg-dim)">
            Agrupadas por compañía, de la más barata a la más cara.
          </p>

          <div v-for="c in vista.companias" :key="c.slug" class="mb-5">
            <div class="flex items-center gap-2 mb-1.5">
              <span
                class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-[10.5px] font-bold text-white"
                :style="{ background: colorDeCompania(c.slug) }"
              >{{ c.nombre.charAt(0) }}</span>
              <span class="text-[12.5px] font-semibold">{{ c.nombre }}</span>
              <span v-if="c.sumaAsegurada !== null" class="text-[11px]" style="color: var(--mg-fg-faint)">
                {{ formatSuma(c.sumaAsegurada) }}
              </span>
            </div>

            <button
              v-for="p in c.planes"
              :key="p.id"
              type="button"
              class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-left transition-all"
              :style="estiloFila(p.id, false)"
              @click="seleccionar(p.id)"
            >
              <div class="min-w-0 flex-1">
                <p class="text-[13px] font-semibold leading-tight">
                  {{ p.franquicia ? `Franquicia ${p.franquicia}` : p.titulo }}
                  <span
                    v-if="esRecomendada(p.id)"
                    class="ml-1 text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full align-middle"
                    :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-mango)' }"
                  >Te la recomendé</span>
                </p>
              </div>
              <span class="flex-shrink-0 mg-display text-[16px]">${{ formatPrecio(p.precio) }}</span>
            </button>
          </div>
        </div>

        <!-- ── Columna derecha: el panel sticky ── -->
        <div class="sticky top-[88px]">

          <!-- Detalle de un plan -->
          <article v-if="panel === 'plan' && planActivo" class="mg-card p-6">
            <div class="flex items-start gap-4">
              <span
                class="flex-shrink-0 w-14 h-14 rounded-full flex items-center justify-center text-[19px] font-bold text-white"
                :style="{ background: colorDeCompania(planActivo.companiaSlug) }"
              >{{ planActivo.aseguradora.charAt(0) }}</span>
              <div class="min-w-0 flex-1">
                <p class="mg-display text-[26px] leading-tight">{{ planActivo.aseguradora }}</p>
                <p class="text-[14px]" style="color: var(--mg-fg-dim)">{{ planActivo.titulo }}</p>
              </div>
              <div class="flex-shrink-0 text-right leading-none">
                <span class="mg-display text-[34px]">${{ formatPrecio(planActivo.precio) }}</span>
                <span
                  class="block text-[12px] mt-1"
                  style="color: var(--mg-fg-dim); font-style: italic; font-family: var(--mg-font-display)"
                >por mes</span>
              </div>
            </div>

            <div
              class="flex gap-8 mt-5 py-3.5"
              :style="{ borderTop: '1px solid var(--mg-hairline)', borderBottom: '1px solid var(--mg-hairline)' }"
            >
              <div v-if="planActivo.franquicia">
                <p class="mg-overline mb-1">Franquicia</p>
                <p class="mg-heading text-[15px]">{{ planActivo.franquicia }}</p>
              </div>
              <div>
                <p class="mg-overline mb-1">Suma asegurada</p>
                <p class="mg-heading text-[15px]">{{ formatSuma(planActivo.sumaAsegurada) }}</p>
              </div>
              <div>
                <p class="mg-overline mb-1">Coberturas</p>
                <p class="mg-heading text-[15px]">{{ coberturasPlan.length }}</p>
              </div>
            </div>

            <blockquote
              v-if="razonDe(planActivo.id)"
              class="mt-5 pl-4 py-1 text-[14px] leading-relaxed"
              :style="{ borderLeft: '2px solid var(--mg-mango)', color: 'var(--mg-fg-dim)' }"
            >
              {{ razonDe(planActivo.id) }}
            </blockquote>

            <p class="mg-overline mt-6 mb-2.5">Qué cubre</p>

            <div class="grid grid-cols-2 gap-x-6">
              <div
                v-for="item in coberturasPlan"
                :key="item.label"
                class="flex items-start gap-2.5 py-2.5"
                :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
              >
                <svg
                  class="flex-shrink-0 mt-0.5" width="15" height="15" viewBox="0 0 24 24"
                  fill="none" stroke="currentColor" stroke-width="2.5"
                  :style="{ color: 'var(--mg-leaf)' }"
                >
                  <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <div class="min-w-0 flex-1">
                  <p class="text-[13px] font-semibold leading-tight">{{ item.label }}</p>
                  <p v-if="item.nota" class="text-[11.5px] mt-0.5 leading-snug" style="color: var(--mg-fg-dim)">
                    {{ item.nota }}
                  </p>
                </div>
              </div>
            </div>

            <template v-if="extrasPlan.length">
              <p class="mg-overline mt-6 mb-2">Además</p>
              <div class="grid grid-cols-2 gap-x-6">
                <div
                  v-for="item in extrasPlan"
                  :key="item.label"
                  class="py-2.5"
                  :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
                >
                  <p class="text-[13px] font-semibold leading-tight">{{ item.label }}</p>
                  <p v-if="item.nota" class="text-[11.5px] mt-0.5 leading-snug" style="color: var(--mg-fg-dim)">
                    {{ item.nota }}
                  </p>
                </div>
              </div>
            </template>

            <div class="flex gap-3 mt-7">
              <!-- Vencida la cotización, el CTA de contratar se apaga: el precio ya no vale. -->
              <button
                v-if="!vista.vigente"
                type="button"
                disabled
                class="mg-btn-primary !opacity-50 !cursor-not-allowed !shadow-none"
              >Precio vencido</button>
              <button
                v-else
                type="button"
                class="mg-btn-primary"
                :disabled="estadoContratacion === 'enviando'"
                @click="contratar(planActivo.id)"
              >La quiero</button>
              <a
                v-if="waLink(vista.whatsappNumber, preguntaSobre(planActivo))"
                :href="waLink(vista.whatsappNumber, preguntaSobre(planActivo))!"
                class="mg-btn-ghost"
              >
                <ChatIcon :size="16" />
                Preguntar por WhatsApp
              </a>
            </div>
          </article>

          <!-- Comparación de las dos recomendadas -->
          <article v-else-if="vista.comparacion && destacadas.length === 2" class="mg-card p-6">
            <p class="mg-display text-[26px]">Qué cambia entre las dos</p>
            <p class="text-[13px] mt-1.5" style="color: var(--mg-fg-dim)">
              Las dos que te recomendé, lado a lado. Solo lo que las diferencia.
            </p>

            <div class="grid grid-cols-2 gap-4 mt-6">
              <div
                v-for="(item, i) in destacadas"
                :key="item.plan.id"
                class="p-4 rounded-2xl"
                :style="{
                  background: 'var(--mg-surface-2)',
                  border: i === 0 ? '1.5px solid var(--mg-mango)' : '1px solid var(--mg-hairline)',
                }"
              >
                <div class="flex items-center gap-2.5">
                  <span
                    class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-[12px] font-bold text-white"
                    :style="{ background: colorDeCompania(item.plan.companiaSlug) }"
                  >{{ item.plan.aseguradora.charAt(0) }}</span>
                  <div class="min-w-0">
                    <p class="text-[13.5px] font-semibold leading-tight">{{ item.plan.aseguradora }}</p>
                    <p class="text-[11.5px] leading-tight" style="color: var(--mg-fg-dim)">{{ item.plan.titulo }}</p>
                  </div>
                </div>
                <p class="mg-display text-[28px] mt-3 leading-none">${{ formatPrecio(item.plan.precio) }}</p>
                <p class="text-[11px] mt-1" style="color: var(--mg-fg-faint)">por mes</p>

                <div class="mt-4 pt-3.5" :style="{ borderTop: '1px solid var(--mg-hairline)' }">
                  <p class="mg-overline mb-2">Solo acá</p>
                  <p
                    v-if="!(i === 0 ? vista.comparacion.soloA : vista.comparacion.soloB).length"
                    class="text-[11.5px] leading-snug"
                    style="color: var(--mg-fg-dim)"
                  >
                    Nada que la otra no tenga.
                  </p>
                  <div
                    v-for="f in (i === 0 ? vista.comparacion.soloA : vista.comparacion.soloB)"
                    :key="f.label"
                    class="flex items-start gap-2 py-1.5"
                  >
                    <span
                      class="flex-shrink-0 w-1.5 h-1.5 rounded-full mt-1.5"
                      :style="{ background: i === 0 ? 'var(--mg-mango)' : 'var(--mg-leaf)' }"
                    />
                    <span>
                      <span class="block text-[12.5px] font-semibold leading-tight">{{ f.label }}</span>
                      <span class="block text-[11px] leading-snug" style="color: var(--mg-fg-dim)">{{ f.nota }}</span>
                    </span>
                  </div>
                </div>

                <button
                  v-if="!vista.vigente"
                  type="button"
                  disabled
                  class="mt-4 w-full py-2.5 rounded-full text-[11.5px] font-semibold uppercase tracking-wider opacity-50 cursor-not-allowed"
                  :style="{ border: '1px solid var(--mg-hairline-strong)', color: 'var(--mg-fg)' }"
                >Precio vencido</button>
                <button
                  v-else
                  type="button"
                  :disabled="estadoContratacion === 'enviando'"
                  class="mt-4 w-full py-2.5 rounded-full flex items-center justify-center text-[11.5px] font-semibold uppercase tracking-wider"
                  :style="i === 0
                    ? { background: 'var(--mg-mango)', color: '#fff' }
                    : { border: '1px solid var(--mg-hairline-strong)', color: 'var(--mg-fg)' }"
                  @click="contratar(item.plan.id)"
                >Quiero esta</button>
              </div>
            </div>

            <p class="mg-overline mt-6 mb-2">Lo que más cambia</p>
            <div
              v-for="fila in filasClave"
              :key="fila.label"
              class="grid grid-cols-[130px_1fr_1fr] gap-4 py-2.5 items-baseline"
              :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
            >
              <span class="mg-overline">{{ fila.label }}</span>
              <span class="text-[13px] font-semibold" :style="{ color: 'var(--mg-mango)' }">{{ fila.a }}</span>
              <span class="text-[13px] font-semibold">{{ fila.b }}</span>
            </div>

            <p
              class="mt-5 p-4 rounded-2xl text-[13.5px] leading-relaxed"
              :style="{ background: 'var(--mg-ok-tint)' }"
            >
              <strong>{{ masBarata(destacadas[0].plan, destacadas[1].plan).aseguradora }} sale
                ${{ formatPrecio(vista.comparacion.diferenciaPrecio) }} menos por mes.</strong>
              Vas a ahorrar aproximadamente ${{ formatPrecio(vista.comparacion.ahorroAnual) }} al año.
            </p>

            <div class="mt-6">
              <p class="mg-overline mb-1.5">Incluidas en las dos ({{ vista.comparacion.comunes.length }})</p>
              <p class="text-[12px] mb-2.5" style="color: var(--mg-fg-faint)">
                Las dos las incluyen. Los topes y límites de cada una te los confirmo por chat.
              </p>
              <div class="grid grid-cols-2 gap-x-6">
                <div
                  v-for="f in vista.comparacion.comunes"
                  :key="f.label"
                  class="flex items-start gap-2.5 py-2"
                  :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
                >
                  <svg
                    class="flex-shrink-0 mt-0.5" width="15" height="15" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2.5"
                    :style="{ color: 'var(--mg-fg-faint)' }"
                  >
                    <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  <span>
                    <span class="block text-[12.5px] font-semibold leading-tight">{{ f.label }}</span>
                    <span class="block text-[11px] leading-snug" style="color: var(--mg-fg-dim)">{{ f.nota }}</span>
                  </span>
                </div>
              </div>
            </div>

            <a
              v-if="waLink(vista.whatsappNumber, textoCualMeConviene)"
              :href="waLink(vista.whatsappNumber, textoCualMeConviene)!"
              class="mg-btn-ghost mt-7"
            >
              <ChatIcon :size="16" />
              Preguntame cuál te conviene
            </a>
          </article>
        </div>
      </div>
    </div>

    <ContratarModal
      :estado="estadoContratacion"
      :mensaje-error="mensajeError"
      :whatsapp-number="vista.whatsappNumber"
      @cerrar="cerrarContratacion"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import MangoLogo from '@/components/Mango/MangoLogo.vue'
import ChatIcon from '@/components/Mango/ChatIcon.vue'
import ContratarModal from './ContratarModal.vue'
import { useContratar } from './useContratar'
import { colorDeCompania } from './companyColors'
import {
  coberturasDe,
  formatPrecio,
  formatSuma,
  masBarata,
  planPorId,
  pluralizar,
  waLink,
  type Plan,
  type Vista,
} from './comparador'

const props = defineProps<{ vista: Vista }>()

const {
  estado: estadoContratacion,
  mensajeError,
  contratar,
  cerrar: cerrarContratacion,
} = useContratar(props.vista.token)

/** Qué muestra el panel derecho. El nombre evita colisión con la prop `vista`. */
const panel = ref<'plan' | 'compare'>('plan')

/** Las dos recomendadas resueltas a planes. Vacío si la cotización nunca se presentó. */
const destacadas = computed(() => {
  const r = props.vista.recomendadas
  if (r === null) {
    return []
  }

  return [
    { plan: planPorId(props.vista.companias, r.principal.planId), razon: r.principal.razon },
    { plan: planPorId(props.vista.companias, r.segunda.planId), razon: r.segunda.razon },
  ].filter((d): d is { plan: Plan; razon: string | null } => d.plan !== null)
})

/** Arranca en la recomendada; sin recomendación, en el plan más barato. */
const planActivoId = ref<number | null>(
  props.vista.recomendadas?.principal.planId ?? props.vista.companias[0]?.planes[0]?.id ?? null,
)

const planActivo = computed(() =>
  planActivoId.value === null ? null : planPorId(props.vista.companias, planActivoId.value),
)

const coberturasPlan = computed(() =>
  planActivo.value ? coberturasDe(planActivo.value, props.vista.glosario).filter((i) => i.esCobertura) : [],
)
const extrasPlan = computed(() =>
  planActivo.value ? coberturasDe(planActivo.value, props.vista.glosario).filter((i) => !i.esCobertura) : [],
)

const filasClave = computed(() => {
  if (destacadas.value.length !== 2) {
    return []
  }

  const [a, b] = destacadas.value.map((d) => d.plan)
  const filas = [
    { label: 'Suma asegurada', a: formatSuma(a.sumaAsegurada), b: formatSuma(b.sumaAsegurada) },
    {
      label: 'Coberturas',
      a: pluralizar(a.features.length, 'ítem', 'ítems'),
      b: pluralizar(b.features.length, 'ítem', 'ítems'),
    },
  ]

  if (a.franquicia || b.franquicia) {
    filas.unshift({ label: 'Franquicia', a: a.franquicia ?? '—', b: b.franquicia ?? '—' })
  }

  return filas
})

const auto = computed(() => props.vista.vehiculo.modelo ?? 'auto')
const textoVolverAlChat = computed(() => `Volvamos con lo del seguro del ${auto.value}.`)
const textoCualMeConviene = computed(() =>
  destacadas.value.length === 2
    ? `Vi la comparación de ${destacadas.value[0].plan.aseguradora} y ${destacadas.value[1].plan.aseguradora}. ¿Cuál me conviene?`
    : 'Vi las opciones de la cotización. ¿Cuál me conviene?',
)

function preguntaSobre(plan: Plan): string {
  return `Hola, tengo una pregunta sobre ${plan.aseguradora} ${plan.titulo}.`
}

function esRecomendada(id: number): boolean {
  return destacadas.value.some((d) => d.plan.id === id)
}

function razonDe(id: number): string | null {
  return destacadas.value.find((d) => d.plan.id === id)?.razon ?? null
}

function seleccionar(id: number): void {
  planActivoId.value = id
  panel.value = 'plan'
}

function estiloFila(id: number, destacada: boolean): Record<string, string> {
  const activa = panel.value === 'plan' && planActivoId.value === id

  if (activa) {
    return {
      background: 'var(--mg-mango-tint)',
      border: '1.5px solid var(--mg-mango)',
    }
  }

  return {
    background: 'var(--mg-surface)',
    border: destacada ? '1.5px solid var(--mg-hairline-strong)' : '1px solid var(--mg-hairline)',
  }
}
</script>
