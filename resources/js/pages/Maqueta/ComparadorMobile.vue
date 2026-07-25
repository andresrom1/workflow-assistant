<template>
  <div class="pb-8" style="font-family: var(--mg-font-ui)">

    <!-- ══════ Header sticky ══════ -->
    <header
      class="px-4 pt-3 pb-3 sticky top-0 z-20"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="flex items-center justify-between mb-2.5">
        <MangoLogo compact :height="20" />
        <span class="mg-overline">{{ contexto.totalOpciones }} opciones</span>
      </div>
      <p class="mg-heading text-[15px] leading-tight truncate">
        {{ contexto.vehiculo }} {{ contexto.anio }}
      </p>
      <div class="flex items-center gap-2 mt-1.5">
        <span
          class="text-[11px] font-semibold px-2 py-0.5 rounded-full"
          :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-mango)' }"
        >
          {{ contexto.cobertura }}
        </span>
        <span class="text-[11px]" style="color: var(--mg-fg-dim)">
          Suma asegurada {{ contexto.sumaAsegurada }}
        </span>
      </div>
    </header>

    <!-- ══════ Las dos que presentó el agente ══════ -->
    <section class="px-4 pt-5">
      <p class="mg-overline mb-2.5">Lo que te recomendé</p>

      <article
        v-for="(item, i) in [recomendadas.principal, recomendadas.segunda]"
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
              :style="{ background: colorDe(item.plan.aseguradora) }"
            >
              {{ item.plan.aseguradora.charAt(0) }}
            </span>
            <div class="min-w-0 flex-1">
              <p class="mg-heading text-[15px] leading-tight">{{ item.plan.aseguradora }}</p>
              <p class="text-[13px] leading-tight" style="color: var(--mg-fg-dim)">
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

          <p
            class="text-[12px] mt-2.5 pl-0.5"
            :style="{ color: 'var(--mg-leaf)' }"
          >
            {{ item.plan.variante }}
          </p>

          <blockquote
            class="mt-3 pl-3 text-[13px] leading-relaxed"
            :style="{ borderLeft: '2px solid var(--mg-hairline-strong)', color: 'var(--mg-fg-dim)' }"
          >
            {{ item.razon }}
          </blockquote>

          <div class="flex gap-2 mt-3.5">
            <button
              type="button"
              class="flex-1 py-2.5 rounded-full text-[12px] font-semibold uppercase tracking-wider"
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
              :href="waLink(`Hola, tengo una pregunta sobre ${item.plan.aseguradora} ${item.plan.titulo}.`)"
              class="flex-shrink-0 w-11 flex items-center justify-center rounded-full"
              :style="{ border: '1px solid var(--mg-hairline-strong)', color: 'var(--mg-fg)' }"
              aria-label="Preguntar al asistente"
            >
              <ChatIcon />
            </a>
          </div>
        </div>
      </article>

      <!-- Diff — el gancho: dos opciones, una pantalla -->
      <button
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
        {{ contexto.totalOpciones }} planes de {{ companias.length }} compañías, todos
        {{ contexto.cobertura.toLowerCase() }}.
      </p>

      <button
        v-for="c in companias"
        :key="c.slug"
        type="button"
        class="w-full mg-card px-3.5 py-3 mb-2 flex items-center gap-3 text-left"
        @click="abrirCompania(c.slug)"
      >
        <span
          class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-[13px] font-bold text-white"
          :style="{ background: c.color }"
        >
          {{ c.nombre.charAt(0) }}
        </span>
        <div class="min-w-0 flex-1">
          <p class="mg-heading text-[14px] leading-tight">{{ c.nombre }}</p>
          <p class="text-[12px] mt-0.5" style="color: var(--mg-fg-dim)">
            {{ c.planes.length }} planes · desde ${{ formatPrecio(desdePrecio(c)) }}
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
        Cotizado el {{ contexto.cotizadoEl }}. Los precios valen hasta el
        {{ contexto.validoHasta }}; después te los recotizo.
      </p>
      <a
        :href="waLink('Volvamos con lo del seguro del Pulse.')"
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
            :style="{ background: colorDe(planActivo.aseguradora) }"
          >
            {{ planActivo.aseguradora.charAt(0) }}
          </span>
          <div class="min-w-0 flex-1">
            <p class="mg-display text-[19px] leading-tight">{{ planActivo.aseguradora }}</p>
            <p class="text-[13px]" style="color: var(--mg-fg-dim)">{{ planActivo.titulo }}</p>
          </div>
          <span
            v-if="planActivo.id === recomendadas.principal.plan.id"
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
            <p class="mg-heading text-[15px] leading-none pt-1.5">{{ contexto.sumaAsegurada }}</p>
          </div>
        </div>

        <div
          v-if="planActivo.notaVariante"
          class="mt-3.5 p-3 rounded-xl text-[12.5px] leading-relaxed"
          :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-fg)' }"
        >
          {{ planActivo.notaVariante }}
        </div>

        <p class="mg-overline mt-5 mb-1">Qué cubre</p>
        <p class="text-[11.5px] mb-2.5" style="color: var(--mg-fg-faint)">
          Tocá cualquier cobertura y te explico qué dice la póliza.
        </p>

        <a
          v-for="(nota, label) in planActivo.detalle"
          :key="label"
          :href="waLink(
            `Sobre ${planActivo.aseguradora} ${planActivo.titulo}: ¿qué cubre exactamente “${label}”? ¿Tiene tope o franquicia?`,
          )"
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
          <span class="min-w-0 flex-1">
            <span class="block text-[13.5px] font-semibold leading-tight">{{ label }}</span>
            <span class="block text-[12px] mt-0.5 leading-snug" style="color: var(--mg-fg-dim)">{{ nota }}</span>
          </span>
          <span class="flex-shrink-0 mt-0.5" :style="{ color: 'var(--mg-fg-faint)' }">
            <ChatIcon :size="15" />
          </span>
        </a>
      </div>

      <template #footer>
        <div v-if="planActivo" class="flex gap-2.5">
          <a
            :href="waLink(`Quiero avanzar con ${planActivo.aseguradora} ${planActivo.titulo} a $${formatPrecio(planActivo.precio)} por mes.`)"
            class="mg-btn-primary flex-1"
          >
            La quiero
          </a>
          <a
            :href="waLink(`Tengo una pregunta sobre ${planActivo.aseguradora} ${planActivo.titulo}.`)"
            class="mg-btn-ghost flex-shrink-0 !px-4"
            aria-label="Preguntar al asistente"
          >
            <ChatIcon :size="17" />
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
            :style="{ background: companiaActiva.color }"
          >
            {{ companiaActiva.nombre.charAt(0) }}
          </span>
          <div>
            <p class="mg-display text-[19px] leading-tight">{{ companiaActiva.nombre }}</p>
            <p class="text-[12px]" style="color: var(--mg-fg-dim)">
              {{ companiaActiva.planes.length }} planes de {{ contexto.cobertura.toLowerCase() }}
            </p>
          </div>
        </div>

        <p class="text-[12px] mt-4 mb-1" style="color: var(--mg-fg-dim)">
          Todos con suma asegurada {{ contexto.sumaAsegurada }}. Lo que cambia es esto:
        </p>

        <button
          v-for="p in companiaActiva.planes"
          :key="p.id"
          type="button"
          class="w-full flex items-center gap-3 py-3.5 text-left"
          :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
          @click="abrirPlan(p.id, companiaActiva.slug)"
        >
          <div class="min-w-0 flex-1">
            <p class="text-[14px] font-semibold leading-tight">
              {{ p.titulo }}
              <span
                v-if="p.id === recomendadas.principal.plan.id || p.id === recomendadas.segunda.plan.id"
                class="ml-1.5 text-[9.5px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full align-middle"
                :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-mango)' }"
              >Te la recomendé</span>
            </p>
            <p class="text-[12px] mt-0.5 leading-snug" :style="{ color: 'var(--mg-leaf)' }">
              {{ p.variante }}
            </p>
            <p
              v-if="p.notaVariante"
              class="text-[11.5px] mt-1 leading-snug"
              style="color: var(--mg-warn)"
            >
              {{ p.notaVariante }}
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
      <div class="px-4 pb-5">
        <p class="mg-display text-[19px] pt-1">Qué cambia entre las dos</p>

        <div class="grid grid-cols-2 gap-2.5 mt-4">
          <div
            v-for="(item, i) in [recomendadas.principal, recomendadas.segunda]"
            :key="item.plan.id"
            class="p-3 rounded-xl"
            :style="{
              background: 'var(--mg-surface-2)',
              border: i === 0 ? '1.5px solid var(--mg-mango)' : '1px solid var(--mg-hairline)',
            }"
          >
            <p class="text-[12.5px] font-semibold leading-tight">{{ item.plan.aseguradora }}</p>
            <p class="text-[11px] leading-tight" style="color: var(--mg-fg-dim)">{{ item.plan.titulo }}</p>
            <p class="mg-display text-[20px] mt-2 leading-none">${{ formatPrecio(item.plan.precio) }}</p>
          </div>
        </div>

        <p
          class="mt-3 p-3 rounded-xl text-[12.5px] leading-relaxed"
          :style="{ background: 'var(--mg-ok-tint)', color: 'var(--mg-fg)' }"
        >
          <strong>{{ recomendadas.segunda.plan.aseguradora }} sale
            ${{ formatPrecio(comparacion.diferenciaPrecio) }} menos por mes</strong>
          — ${{ formatPrecio(comparacion.diferenciaPrecio * 12) }} en el año.
        </p>

        <div class="mt-5">
          <p class="mg-overline mb-2">Solo en {{ recomendadas.principal.plan.aseguradora }}</p>
          <div
            v-for="f in comparacion.soloPrincipal"
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
          <p class="mg-overline mb-2">Solo en {{ recomendadas.segunda.plan.aseguradora }}</p>
          <div
            v-for="f in comparacion.soloSegunda"
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
          <p class="mg-overline mb-2">Igual en las dos</p>
          <p class="text-[12.5px] leading-relaxed" style="color: var(--mg-fg-dim)">
            {{ comparacion.iguales.map((f) => f.label).join(' · ') }}
          </p>
        </div>
      </div>

      <template #footer>
        <a
          :href="waLink('Vi la comparación de Sancor Auto Max 6 y Triunfo C2 Full. ¿Cuál me conviene?')"
          class="mg-btn-primary w-full"
        >
          Preguntame cuál te conviene
        </a>
      </template>
    </BottomSheet>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import MangoLogo from '@/components/Mango/MangoLogo.vue'
import BottomSheet from './BottomSheet.vue'
import ChatIcon from './ChatIcon.vue'
import {
  companias,
  comparacion,
  contexto,
  formatPrecio,
  planPorId,
  recomendadas,
  waLink,
  type Compania,
} from './comparadorData'

type SheetKind = 'plan' | 'company' | 'compare' | null

const sheet = ref<SheetKind>(null)
const planActivoId = ref<number | null>(null)
const companiaActivaSlug = ref<string | null>(null)
/** Si el plan se abrió desde una compañía, el sheet muestra el link de vuelta. */
const volverACompaniaSlug = ref<string | null>(null)

const planActivo = computed(() => (planActivoId.value ? planPorId(planActivoId.value) : null))
const companiaActiva = computed(() => companias.find((c) => c.slug === companiaActivaSlug.value) ?? null)
const volverACompania = computed(() => companias.find((c) => c.slug === volverACompaniaSlug.value) ?? null)

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

function colorDe(aseguradora: string): string {
  return companias.find((c) => c.nombre === aseguradora)?.color ?? 'var(--mg-fg-dim)'
}

function desdePrecio(c: Compania): number {
  return Math.min(...c.planes.map((p) => p.precio))
}
</script>
