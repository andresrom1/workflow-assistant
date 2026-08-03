<template>
  <div class="pb-8" style="font-family: var(--mg-font-ui)">

    <!-- ══════ Header sticky ══════ -->
    <header
      class="px-4 pt-3 pb-3 sticky top-0 z-20"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="flex items-center justify-between mb-2.5">
        <MangoLogo compact :height="20" />
        <span class="mg-overline">{{ vista.totalOpciones }} opciones</span>
      </div>
      <p class="mg-heading text-[15px] leading-tight truncate">
        {{ vista.vehiculo.descripcion }} {{ vista.vehiculo.year }}
      </p>
      <div class="flex items-center gap-2 mt-1.5">
        <span
          class="text-[11px] font-semibold px-2 py-0.5 rounded-full"
          :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-mango)' }"
        >
          {{ vista.cobertura.label }}
        </span>
        <span v-if="vista.vehiculo.combustible" class="text-[11px]" style="color: var(--mg-fg-dim)">
          {{ vista.vehiculo.combustible.toUpperCase() }}
        </span>
      </div>
    </header>

    <!-- ══════ Cotización vencida ══════ -->
    <div
      v-if="!vista.vigente"
      class="mx-4 mt-4 p-3.5 rounded-xl text-[12.5px] leading-relaxed"
      :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-fg)' }"
    >
      <strong>Estos precios ya vencieron.</strong> Los valores eran del
      {{ vista.cotizadoEl }} y las compañías los actualizan todos los días. Escribime y te los
      recotizo en el momento.
    </div>

    <!-- ══════ Las dos que presentó el agente ══════ -->
    <section v-if="destacadas.length" class="px-4 pt-5">
      <p class="mg-overline mb-2.5">Lo que te recomendé</p>

      <article
        v-for="(item, i) in destacadas"
        :key="item.plan.id"
        class="mg-card overflow-hidden mb-3"
        :style="i === 0 ? { borderColor: 'var(--mg-mango)', borderWidth: '1.5px' } : {}"
      >
        <div
          v-if="i === 0"
          class="px-3.5 py-1.5 flex items-center gap-1.5"
          :style="{ background: 'var(--mg-mango)' }"
        >
          <svg width="12" height="12" viewBox="0 0 24 24" fill="#fff">
            <path d="M12 2l2.9 6.6 7.1.6-5.4 4.7 1.6 7-6.2-3.8-6.2 3.8 1.6-7L2 9.2l7.1-.6z" />
          </svg>
          <span class="text-[10.5px] font-bold uppercase tracking-widest text-white">Mi recomendación</span>
        </div>

        <div class="p-3.5">
          <div class="flex items-start gap-2.5">
            <span
              class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-[13px] font-bold text-white"
              :style="{ background: colorDeCompania(item.plan.companiaSlug) }"
            >
              {{ item.plan.aseguradora.charAt(0) }}
            </span>
            <div class="min-w-0 flex-1">
              <p class="mg-heading text-[15px] leading-tight">{{ item.plan.aseguradora }}</p>
              <p class="text-[12.5px] leading-tight" style="color: var(--mg-fg-dim)">
                {{ item.plan.titulo }}
              </p>
            </div>
            <div class="text-right flex-shrink-0 leading-none">
              <span class="mg-display text-[26px]">${{ formatPrecio(item.plan.precio) }}</span>
              <span
                class="block text-[11px] mt-0.5"
                style="color: var(--mg-fg-dim); font-style: italic; font-family: var(--mg-font-display)"
              >por mes</span>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-2 mt-3">
            <div v-if="item.plan.franquicia">
              <p class="mg-overline mb-0.5">Franquicia</p>
              <p class="text-[12.5px] leading-snug font-semibold">{{ item.plan.franquicia }}</p>
            </div>
            <div>
              <p class="mg-overline mb-0.5">Suma asegurada</p>
              <p class="text-[12.5px] leading-snug font-semibold">{{ formatSuma(item.plan.sumaAsegurada) }}</p>
            </div>
          </div>

          <blockquote
            v-if="item.razon"
            class="mt-3 pl-3 text-[13px] leading-relaxed"
            :style="{ borderLeft: '2px solid var(--mg-hairline-strong)', color: 'var(--mg-fg-dim)' }"
          >
            {{ item.razon }}
          </blockquote>

          <button
            type="button"
            class="w-full mt-3.5 py-2.5 rounded-full text-[12px] font-semibold uppercase tracking-wider"
            :style="{
              background: i === 0 ? 'var(--mg-mango)' : 'transparent',
              color: i === 0 ? '#fff' : 'var(--mg-fg)',
              border: i === 0 ? 'none' : '1px solid var(--mg-hairline-strong)',
            }"
            @click="abrirPlan(item.plan.id)"
          >
            Ver qué cubre
          </button>

          <a
            v-if="waLink(vista.whatsappNumber, preguntaSobre(item.plan))"
            :href="waLink(vista.whatsappNumber, preguntaSobre(item.plan))!"
            class="mt-2 w-full py-2.5 rounded-full flex items-center justify-center gap-2 text-[12px] font-semibold"
            :style="{ border: '1px solid var(--mg-hairline-strong)', color: 'var(--mg-fg)' }"
          >
            <ChatIcon :size="15" />
            Preguntar por WhatsApp
          </a>
        </div>
      </article>

      <!-- Diff — el gancho: dos opciones, una pantalla -->
      <button
        v-if="vista.comparacion"
        type="button"
        class="w-full mg-card px-3.5 py-3 flex items-center justify-between"
        :style="{ background: 'var(--mg-surface-2)', borderStyle: 'dashed' }"
        @click="sheet = 'compare'"
      >
        <span class="text-[13px] font-semibold">Qué cambia entre las dos</span>
        <span class="flex items-center gap-1.5 text-[12px]" style="color: var(--mg-mango)">
          Comparar
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M9 6l6 6-6 6" stroke-linecap="round" />
          </svg>
        </span>
      </button>
    </section>

    <!-- ══════ El resto del grado, agrupado por compañía ══════ -->
    <section class="px-4 pt-7">
      <p class="mg-overline mb-1">Todas las opciones</p>
      <p class="text-[12px] mb-3" style="color: var(--mg-fg-dim)">
        {{ vista.totalOpciones }} {{ vista.totalOpciones === 1 ? 'plan' : 'planes' }} de
        {{ vista.companias.length }}
        {{ vista.companias.length === 1 ? 'compañía' : 'compañías' }}, todos
        {{ vista.cobertura.label.toLowerCase() }}.
      </p>

      <button
        v-for="c in vista.companias"
        :key="c.slug"
        type="button"
        class="w-full mg-card px-3.5 py-3 mb-2 flex items-center gap-3 text-left"
        @click="abrirCompania(c.slug)"
      >
        <span
          class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-[13px] font-bold text-white"
          :style="{ background: colorDeCompania(c.slug) }"
        >
          {{ c.nombre.charAt(0) }}
        </span>
        <div class="min-w-0 flex-1">
          <p class="mg-heading text-[14px] leading-tight">{{ c.nombre }}</p>
          <p class="text-[12px] mt-0.5" style="color: var(--mg-fg-dim)">
            {{ c.planes.length }} {{ c.planes.length === 1 ? 'plan' : 'planes' }} · desde
            ${{ formatPrecio(c.desde) }}
          </p>
        </div>
        <svg
          width="18" height="18" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2"
          :style="{ color: 'var(--mg-fg-faint)' }"
        >
          <path d="M9 6l6 6-6 6" stroke-linecap="round" />
        </svg>
      </button>
    </section>

    <!-- ══════ Pie: vigencia y vuelta al chat ══════ -->
    <footer class="px-4 pt-7">
      <p class="text-[11.5px] leading-relaxed" style="color: var(--mg-fg-faint)">
        <template v-if="vista.vigente">
          Cotizado el {{ vista.cotizadoEl }}. Los precios valen hasta el final del día; después te
          los recotizo.
        </template>
        <template v-else>
          Cotizado el {{ vista.cotizadoEl }}. Los precios ya no están vigentes.
        </template>
      </p>
      <a
        v-if="waLink(vista.whatsappNumber, textoVolverAlChat)"
        :href="waLink(vista.whatsappNumber, textoVolverAlChat)!"
        class="mt-4 w-full py-3 rounded-full flex items-center justify-center gap-2 text-[12px] font-semibold uppercase tracking-wider"
        :style="{ border: '1px solid var(--mg-hairline-strong)', color: 'var(--mg-fg)' }"
      >
        <ChatIcon />
        Volver al chat
      </a>
    </footer>

    <!-- ══════════════ SHEET: detalle de un plan ══════════════ -->
    <BottomSheet :open="sheet === 'plan'" @close="cerrar">
      <div v-if="planActivo" class="px-4 pb-4">
        <button
          v-if="volverACompania"
          type="button"
          class="flex items-center gap-1 text-[12px] mb-3 -ml-1"
          style="color: var(--mg-fg-dim)"
          @click="sheet = 'company'"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M15 6l-6 6 6 6" stroke-linecap="round" />
          </svg>
          {{ volverACompania.nombre }}
        </button>

        <div class="flex items-start gap-3 pt-1">
          <span
            class="flex-shrink-0 w-11 h-11 rounded-full flex items-center justify-center text-[15px] font-bold text-white"
            :style="{ background: colorDeCompania(planActivo.companiaSlug) }"
          >
            {{ planActivo.aseguradora.charAt(0) }}
          </span>
          <div class="min-w-0 flex-1">
            <p class="mg-display text-[19px] leading-tight">{{ planActivo.aseguradora }}</p>
            <p class="text-[13px]" style="color: var(--mg-fg-dim)">{{ planActivo.titulo }}</p>
          </div>
          <span
            v-if="esRecomendada(planActivo.id)"
            class="flex-shrink-0 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-full"
            :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-mango)' }"
          >Recomendada</span>
        </div>

        <div
          class="grid grid-cols-2 gap-3 mt-4 py-3.5"
          :style="{ borderTop: '1px solid var(--mg-hairline)', borderBottom: '1px solid var(--mg-hairline)' }"
        >
          <div>
            <p class="mg-overline mb-1">Por mes</p>
            <p class="mg-display text-[24px] leading-none">${{ formatPrecio(planActivo.precio) }}</p>
          </div>
          <div>
            <p class="mg-overline mb-1">Suma asegurada</p>
            <p class="mg-heading text-[15px] leading-none pt-1.5">{{ formatSuma(planActivo.sumaAsegurada) }}</p>
          </div>
        </div>

        <div v-if="planActivo.franquicia" class="mt-3.5">
          <p class="mg-overline mb-1">Franquicia</p>
          <p class="text-[13.5px] font-semibold leading-snug">{{ planActivo.franquicia }}</p>
          <p class="text-[11.5px] mt-1 leading-snug" style="color: var(--mg-fg-dim)">
            Es lo que ponés vos en un siniestro con daños parciales antes de que entre la compañía.
          </p>
        </div>

        <p class="mg-overline mt-5 mb-2.5">Qué cubre</p>

        <div
          v-for="item in coberturasPlan"
          :key="item.label"
          class="flex items-start gap-2.5 py-3"
          :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
        >
          <svg
            class="flex-shrink-0 mt-0.5" width="16" height="16" viewBox="0 0 24 24"
            fill="none" stroke="currentColor" stroke-width="2.5"
            :style="{ color: 'var(--mg-leaf)' }"
          >
            <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <div class="min-w-0 flex-1">
            <p class="text-[13.5px] font-semibold leading-tight">{{ item.label }}</p>
            <p v-if="item.nota" class="text-[12px] mt-0.5 leading-snug" style="color: var(--mg-fg-dim)">
              {{ item.nota }}
            </p>
          </div>
        </div>

        <template v-if="extrasPlan.length">
          <p class="mg-overline mt-5 mb-2">Además</p>
          <div
            v-for="item in extrasPlan"
            :key="item.label"
            class="py-2.5"
            :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
          >
            <p class="text-[13.5px] font-semibold leading-tight">{{ item.label }}</p>
            <p v-if="item.nota" class="text-[12px] mt-0.5 leading-snug" style="color: var(--mg-fg-dim)">
              {{ item.nota }}
            </p>
          </div>
        </template>
      </div>

      <template #footer>
        <div v-if="planActivo" class="flex gap-2.5">
          <!-- Vencida la cotización, el CTA de contratar se apaga: el precio ya no vale. -->
          <button
            v-if="!vista.vigente"
            type="button"
            disabled
            class="mg-btn-primary flex-1 !opacity-50 !cursor-not-allowed !shadow-none"
          >
            Precio vencido
          </button>
          <button
            v-else
            type="button"
            class="mg-btn-primary flex-1"
            :disabled="estadoContratacion === 'enviando'"
            @click="contratar(planActivo.id)"
          >
            La quiero
          </button>
          <a
            v-if="waLink(vista.whatsappNumber, preguntaSobre(planActivo))"
            :href="waLink(vista.whatsappNumber, preguntaSobre(planActivo))!"
            class="mg-btn-ghost flex-shrink-0 !px-4"
          >
            <ChatIcon :size="16" />
            Preguntar
          </a>
        </div>
      </template>
    </BottomSheet>

    <!-- ══════════════ SHEET: variantes de una compañía ══════════════ -->
    <BottomSheet :open="sheet === 'company'" @close="cerrar">
      <div v-if="companiaActiva" class="px-4 pb-5">
        <div class="flex items-center gap-3 pt-1">
          <span
            class="flex-shrink-0 w-11 h-11 rounded-full flex items-center justify-center text-[15px] font-bold text-white"
            :style="{ background: colorDeCompania(companiaActiva.slug) }"
          >
            {{ companiaActiva.nombre.charAt(0) }}
          </span>
          <div>
            <p class="mg-display text-[19px] leading-tight">{{ companiaActiva.nombre }}</p>
            <p class="text-[12px]" style="color: var(--mg-fg-dim)">
              {{ companiaActiva.planes.length }}
              {{ companiaActiva.planes.length === 1 ? 'plan' : 'planes' }} de
              {{ vista.cobertura.label.toLowerCase() }}
            </p>
          </div>
        </div>

        <p
          v-if="companiaActiva.sumaAsegurada !== null"
          class="text-[12px] mt-4 mb-1"
          style="color: var(--mg-fg-dim)"
        >
          Todos con suma asegurada de {{ formatSuma(companiaActiva.sumaAsegurada) }}.
        </p>

        <button
          v-for="p in companiaActiva.planes"
          :key="p.id"
          type="button"
          class="w-full flex items-center gap-3 py-3.5 text-left"
          :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
          @click="abrirPlan(p.id, companiaActiva!.slug)"
        >
          <div class="min-w-0 flex-1">
            <p class="text-[14px] font-semibold leading-tight">
              {{ p.franquicia ? `Franquicia ${p.franquicia}` : p.titulo }}
              <span
                v-if="esRecomendada(p.id)"
                class="ml-1.5 text-[9.5px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full align-middle"
                :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-mango)' }"
              >Te la recomendé</span>
            </p>
            <p v-if="p.franquicia" class="text-[11.5px] mt-0.5 leading-snug" style="color: var(--mg-fg-faint)">
              {{ p.titulo }}
            </p>
          </div>
          <div class="flex-shrink-0 text-right leading-none">
            <span class="mg-display text-[19px]">${{ formatPrecio(p.precio) }}</span>
            <span class="block text-[10px] mt-0.5" style="color: var(--mg-fg-faint)">por mes</span>
          </div>
        </button>
      </div>
    </BottomSheet>

    <!-- ══════════════ SHEET: comparación de las dos ══════════════ -->
    <BottomSheet :open="sheet === 'compare'" @close="cerrar">
      <div v-if="vista.comparacion && destacadas.length === 2" class="px-4 pb-5">
        <p class="mg-display text-[19px] pt-1">Qué cambia entre las dos</p>

        <div class="grid grid-cols-2 gap-2.5 mt-4">
          <div
            v-for="(item, i) in destacadas"
            :key="item.plan.id"
            class="p-3 rounded-xl"
            :style="{
              background: 'var(--mg-surface-2)',
              border: i === 0 ? '1.5px solid var(--mg-mango)' : '1px solid var(--mg-hairline)',
            }"
          >
            <p class="text-[12.5px] font-semibold leading-tight">{{ item.plan.aseguradora }}</p>
            <p class="mg-display text-[20px] mt-2 leading-none">${{ formatPrecio(item.plan.precio) }}</p>
            <p class="text-[10px] mt-1" style="color: var(--mg-fg-faint)">por mes</p>
          </div>
        </div>

        <p class="mg-overline mt-5 mb-2">Lo que más cambia</p>
        <div
          v-for="fila in filasClave"
          :key="fila.label"
          class="py-2.5"
          :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
        >
          <p class="mg-overline mb-1.5">{{ fila.label }}</p>
          <div class="grid grid-cols-2 gap-2.5">
            <p class="text-[12.5px] font-semibold leading-snug" :style="{ color: 'var(--mg-mango)' }">{{ fila.a }}</p>
            <p class="text-[12.5px] font-semibold leading-snug">{{ fila.b }}</p>
          </div>
        </div>

        <p
          class="mt-3 p-3 rounded-xl text-[12.5px] leading-relaxed"
          :style="{ background: 'var(--mg-ok-tint)', color: 'var(--mg-fg)' }"
        >
          <strong>{{ masBarata(destacadas[0].plan, destacadas[1].plan).aseguradora }} sale
            ${{ formatPrecio(vista.comparacion.diferenciaPrecio) }} menos por mes.</strong>
          Vas a ahorrar aproximadamente ${{ formatPrecio(vista.comparacion.ahorroAnual) }} al año.
        </p>

        <div class="mt-5">
          <p class="mg-overline mb-2">Solo en {{ destacadas[0].plan.aseguradora }}</p>
          <p v-if="!vista.comparacion.soloA.length" class="text-[12.5px]" style="color: var(--mg-fg-dim)">
            Nada que la otra no tenga.
          </p>
          <div
            v-for="f in vista.comparacion.soloA"
            :key="f.label"
            class="flex items-start gap-2.5 py-2"
          >
            <span
              class="flex-shrink-0 w-1.5 h-1.5 rounded-full mt-1.5"
              :style="{ background: 'var(--mg-mango)' }"
            />
            <span>
              <span class="block text-[13.5px] font-semibold leading-tight">{{ f.label }}</span>
              <span class="block text-[12px] leading-snug" style="color: var(--mg-fg-dim)">{{ f.nota }}</span>
            </span>
          </div>
        </div>

        <div class="mt-4">
          <p class="mg-overline mb-2">Solo en {{ destacadas[1].plan.aseguradora }}</p>
          <p v-if="!vista.comparacion.soloB.length" class="text-[12.5px] leading-relaxed" style="color: var(--mg-fg-dim)">
            Nada: todo lo que cubre {{ destacadas[1].plan.aseguradora }} lo cubre también
            {{ destacadas[0].plan.aseguradora }}.
          </p>
          <div
            v-for="f in vista.comparacion.soloB"
            :key="f.label"
            class="flex items-start gap-2.5 py-2"
          >
            <span
              class="flex-shrink-0 w-1.5 h-1.5 rounded-full mt-1.5"
              :style="{ background: 'var(--mg-leaf)' }"
            />
            <span>
              <span class="block text-[13.5px] font-semibold leading-tight">{{ f.label }}</span>
              <span class="block text-[12px] leading-snug" style="color: var(--mg-fg-dim)">{{ f.nota }}</span>
            </span>
          </div>
        </div>

        <div class="mt-5 pt-4" :style="{ borderTop: '1px solid var(--mg-hairline)' }">
          <p class="mg-overline mb-1.5">Incluidas en las dos ({{ vista.comparacion.comunes.length }})</p>
          <p class="text-[11.5px] mb-2" style="color: var(--mg-fg-faint)">
            Las dos las incluyen. Los topes y límites de cada una te los confirmo por chat.
          </p>
          <p class="text-[12.5px] leading-relaxed" style="color: var(--mg-fg-dim)">
            {{ vista.comparacion.comunes.map((f) => f.label).join(' · ') }}
          </p>
        </div>
      </div>

      <template #footer>
        <a
          v-if="waLink(vista.whatsappNumber, textoCualMeConviene)"
          :href="waLink(vista.whatsappNumber, textoCualMeConviene)!"
          class="mg-btn-primary w-full"
        >
          <ChatIcon :size="16" />
          Preguntame cuál te conviene
        </a>
      </template>
    </BottomSheet>

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
import BottomSheet from '@/components/Mango/BottomSheet.vue'
import ChatIcon from '@/components/Mango/ChatIcon.vue'
import ContratarModal from './ContratarModal.vue'
import { useContratar } from './useContratar'
import { colorDeCompania } from './companyColors'
import {
  coberturasDe,
  companiaDe,
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

type SheetKind = 'plan' | 'company' | 'compare' | null

const sheet = ref<SheetKind>(null)
const planActivoId = ref<number | null>(null)
const companiaActivaSlug = ref<string | null>(null)
/** Si el plan se abrió desde una compañía, el sheet muestra el link de vuelta. */
const volverACompaniaSlug = ref<string | null>(null)

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

const planActivo = computed(() =>
  planActivoId.value === null ? null : planPorId(props.vista.companias, planActivoId.value),
)
const companiaActiva = computed(() =>
  companiaActivaSlug.value === null ? null : companiaDe(props.vista.companias, companiaActivaSlug.value),
)
const volverACompania = computed(() =>
  volverACompaniaSlug.value === null ? null : companiaDe(props.vista.companias, volverACompaniaSlug.value),
)

const coberturasPlan = computed(() =>
  planActivo.value ? coberturasDe(planActivo.value, props.vista.glosario).filter((i) => i.esCobertura) : [],
)
const extrasPlan = computed(() =>
  planActivo.value ? coberturasDe(planActivo.value, props.vista.glosario).filter((i) => !i.esCobertura) : [],
)

/** Las filas que de verdad separan a las dos recomendadas. */
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

const auto = computed(() => props.vista.vehiculo.modelo ?? 'mi auto')
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

function abrirPlan(id: number, desdeCompania?: string): void {
  planActivoId.value = id
  volverACompaniaSlug.value = desdeCompania ?? null
  sheet.value = 'plan'
}

function abrirCompania(slug: string): void {
  companiaActivaSlug.value = slug
  sheet.value = 'company'
}

function cerrar(): void {
  sheet.value = null
}
</script>
