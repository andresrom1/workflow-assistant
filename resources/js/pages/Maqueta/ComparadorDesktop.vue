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
            {{ contexto.vehiculo }} {{ contexto.anio }}
            <span style="color: var(--mg-fg-faint)">· {{ contexto.patente }}</span>
          </p>
        </div>
        <a
          :href="waLink('Volvamos con lo del seguro del Pulse.')"
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
        <p class="mg-overline mb-3">{{ contexto.cobertura }} · {{ contexto.totalOpciones }} opciones</p>
        <h1 class="mg-display text-[38px] leading-[1.1] max-w-2xl">
          Estas son todas las opciones que conseguí para tu Pulse.
        </h1>
        <p class="text-[14px] mt-3.5 max-w-xl leading-relaxed" style="color: var(--mg-fg-dim)">
          Todas con suma asegurada de {{ contexto.sumaAsegurada }}. Cotizado el
          {{ contexto.cotizadoEl }}; los precios valen hasta el {{ contexto.validoHasta }}.
        </p>
      </div>
    </section>

    <!-- ══════ Master / detail ══════ -->
    <div class="px-8 pb-16">
      <div class="max-w-[1180px] mx-auto grid grid-cols-[390px_1fr] gap-7 items-start">

        <!-- ── Columna izquierda: la lista ── -->
        <div>
          <p class="mg-overline mb-2.5">Lo que te recomendé</p>

          <button
            v-for="(item, i) in [recomendadas.principal, recomendadas.segunda]"
            :key="item.plan.id"
            type="button"
            class="w-full mg-card p-3.5 mb-2.5 text-left transition-all"
            :style="estiloFila(item.plan.id, i === 0)"
            @click="seleccionar(item.plan.id)"
          >
            <div class="flex items-start gap-2.5">
              <span
                class="flex-shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-[13px] font-bold text-white"
                :style="{ background: colorDe(item.plan.aseguradora) }"
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
                <p class="text-[12.5px] leading-tight" style="color: var(--mg-fg-dim)">{{ item.plan.titulo }}</p>
                <p class="text-[11.5px] mt-1" :style="{ color: 'var(--mg-leaf)' }">{{ item.plan.variante }}</p>
              </div>
              <div class="flex-shrink-0 text-right leading-none">
                <span class="mg-display text-[21px]">${{ formatPrecio(item.plan.precio) }}</span>
                <span class="block text-[10px] mt-0.5" style="color: var(--mg-fg-faint)">por mes</span>
              </div>
            </div>
          </button>

          <button
            type="button"
            class="w-full mg-card px-3.5 py-2.5 mb-7 flex items-center justify-between transition-all"
            :style="{
              background: vista === 'compare' ? 'var(--mg-mango-tint)' : 'var(--mg-surface-2)',
              borderStyle: 'dashed',
              borderColor: vista === 'compare' ? 'var(--mg-mango)' : 'var(--mg-hairline)',
            }"
            @click="vista = 'compare'"
          >
            <span class="text-[12.5px] font-semibold">Qué cambia entre las dos</span>
            <span class="text-[11.5px]" style="color: var(--mg-mango)">Comparar →</span>
          </button>

          <p class="mg-overline mb-1">Todas las opciones</p>
          <p class="text-[11.5px] mb-3" style="color: var(--mg-fg-dim)">
            Agrupadas por compañía. Lo que cambia dentro de cada una está anotado.
          </p>

          <div v-for="c in companias" :key="c.slug" class="mb-5">
            <div class="flex items-center gap-2 mb-1.5">
              <span
                class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-[10.5px] font-bold text-white"
                :style="{ background: c.color }"
              >{{ c.nombre.charAt(0) }}</span>
              <span class="text-[12.5px] font-semibold">{{ c.nombre }}</span>
              <span class="text-[11px]" style="color: var(--mg-fg-faint)">{{ c.planes.length }} planes</span>
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
                  {{ p.titulo }}
                  <span
                    v-if="esRecomendada(p.id)"
                    class="ml-1 text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded-full align-middle"
                    :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-mango)' }"
                  >Te la recomendé</span>
                </p>
                <p class="text-[11.5px] leading-snug mt-0.5" :style="{ color: 'var(--mg-leaf)' }">{{ p.variante }}</p>
                <p
                  v-if="p.notaVariante"
                  class="text-[11px] leading-snug mt-0.5"
                  style="color: var(--mg-warn)"
                >{{ p.notaVariante }}</p>
              </div>
              <span class="flex-shrink-0 mg-display text-[16px]">${{ formatPrecio(p.precio) }}</span>
            </button>
          </div>
        </div>

        <!-- ── Columna derecha: el panel sticky ── -->
        <div class="sticky top-[88px]">

          <!-- Detalle de un plan -->
          <article v-if="vista === 'plan' && planActivo" class="mg-card p-6">
            <div class="flex items-start gap-4">
              <span
                class="flex-shrink-0 w-14 h-14 rounded-full flex items-center justify-center text-[19px] font-bold text-white"
                :style="{ background: colorDe(planActivo.aseguradora) }"
              >{{ planActivo.aseguradora.charAt(0) }}</span>
              <div class="min-w-0 flex-1">
                <p class="mg-display text-[26px] leading-tight">{{ planActivo.aseguradora }}</p>
                <p class="text-[14px]" style="color: var(--mg-fg-dim)">
                  {{ planActivo.titulo }} · {{ planActivo.variante }}
                </p>
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
              <div>
                <p class="mg-overline mb-1">Suma asegurada</p>
                <p class="mg-heading text-[15px]">{{ contexto.sumaAsegurada }}</p>
              </div>
              <div>
                <p class="mg-overline mb-1">Cobertura</p>
                <p class="mg-heading text-[15px]">{{ contexto.cobertura }}</p>
              </div>
              <div>
                <p class="mg-overline mb-1">Coberturas incluidas</p>
                <p class="mg-heading text-[15px]">{{ Object.keys(planActivo.detalle).length }}</p>
              </div>
            </div>

            <blockquote
              v-if="razonDe(planActivo.id)"
              class="mt-5 pl-4 py-1 text-[14px] leading-relaxed"
              :style="{ borderLeft: '2px solid var(--mg-mango)', color: 'var(--mg-fg-dim)' }"
            >
              {{ razonDe(planActivo.id) }}
            </blockquote>

            <div
              v-if="planActivo.notaVariante"
              class="mt-4 p-3.5 rounded-xl text-[13px] leading-relaxed"
              :style="{ background: 'var(--mg-mango-tint)' }"
            >
              {{ planActivo.notaVariante }}
            </div>

            <p class="mg-overline mt-6 mb-1">Qué cubre</p>
            <p class="text-[12px] mb-3" style="color: var(--mg-fg-faint)">
              Cualquiera de estas coberturas te la puedo explicar por WhatsApp con lo que dice la póliza.
            </p>

            <div class="grid grid-cols-2 gap-x-6">
              <a
                v-for="(nota, label) in planActivo.detalle"
                :key="label"
                :href="waLink(
                  `Sobre ${planActivo.aseguradora} ${planActivo.titulo}: ¿qué cubre exactamente “${label}”? ¿Tiene tope o franquicia?`,
                )"
                class="group flex items-start gap-2.5 py-2.5"
                :style="{ borderBottom: '1px solid var(--mg-hairline)' }"
              >
                <svg
                  class="flex-shrink-0 mt-0.5" width="15" height="15" viewBox="0 0 24 24"
                  fill="none" stroke="currentColor" stroke-width="2.5"
                  :style="{ color: 'var(--mg-leaf)' }"
                >
                  <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span class="min-w-0 flex-1">
                  <span class="block text-[13px] font-semibold leading-tight">{{ label }}</span>
                  <span class="block text-[11.5px] mt-0.5 leading-snug" style="color: var(--mg-fg-dim)">{{ nota }}</span>
                </span>
                <span
                  class="flex-shrink-0 mt-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
                  :style="{ color: 'var(--mg-mango)' }"
                >
                  <ChatIcon :size="14" />
                </span>
              </a>
            </div>

            <div class="flex gap-3 mt-7">
              <a
                :href="waLink(`Quiero avanzar con ${planActivo.aseguradora} ${planActivo.titulo} a $${formatPrecio(planActivo.precio)} por mes.`)"
                class="mg-btn-primary"
              >La quiero</a>
              <a
                :href="waLink(`Tengo una pregunta sobre ${planActivo.aseguradora} ${planActivo.titulo}.`)"
                class="mg-btn-ghost"
              >
                <ChatIcon :size="16" />
                Preguntar al asistente
              </a>
            </div>
          </article>

          <!-- Comparación de las dos recomendadas -->
          <article v-else class="mg-card p-6">
            <p class="mg-display text-[26px]">Qué cambia entre las dos</p>
            <p class="text-[13px] mt-1.5" style="color: var(--mg-fg-dim)">
              Las dos que te recomendé, lado a lado. Solo lo que las diferencia.
            </p>

            <div class="grid grid-cols-2 gap-4 mt-6">
              <div
                v-for="(item, i) in [recomendadas.principal, recomendadas.segunda]"
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
                    :style="{ background: colorDe(item.plan.aseguradora) }"
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
                  <div
                    v-for="f in (i === 0 ? comparacion.soloPrincipal : comparacion.soloSegunda)"
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

                <a
                  :href="waLink(`Quiero avanzar con ${item.plan.aseguradora} ${item.plan.titulo} a $${formatPrecio(item.plan.precio)} por mes.`)"
                  class="mt-4 w-full py-2.5 rounded-full flex items-center justify-center text-[11.5px] font-semibold uppercase tracking-wider"
                  :style="i === 0
                    ? { background: 'var(--mg-mango)', color: '#fff' }
                    : { border: '1px solid var(--mg-hairline-strong)', color: 'var(--mg-fg)' }"
                >Quiero esta</a>
              </div>
            </div>

            <p
              class="mt-5 p-4 rounded-2xl text-[13.5px] leading-relaxed"
              :style="{ background: 'var(--mg-ok-tint)' }"
            >
              <strong>{{ recomendadas.segunda.plan.aseguradora }} sale
                ${{ formatPrecio(comparacion.diferenciaPrecio) }} menos por mes</strong>
              — ${{ formatPrecio(comparacion.diferenciaPrecio * 12) }} en el año. La cobertura no es
              peor ni mejor: cambia qué cubre.
            </p>

            <div class="mt-6">
              <p class="mg-overline mb-2.5">Igual en las dos</p>
              <div class="grid grid-cols-2 gap-x-6">
                <div
                  v-for="f in comparacion.iguales"
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
              :href="waLink('Vi la comparación de Sancor Auto Max 6 y Triunfo C2 Full. ¿Cuál me conviene?')"
              class="mg-btn-ghost mt-7"
            >
              <ChatIcon :size="16" />
              Preguntame cuál te conviene
            </a>
          </article>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import MangoLogo from '@/components/Mango/MangoLogo.vue'
import ChatIcon from './ChatIcon.vue'
import {
  companias,
  comparacion,
  contexto,
  formatPrecio,
  planPorId,
  recomendadas,
  waLink,
} from './comparadorData'

const vista = ref<'plan' | 'compare'>('plan')
const planActivoId = ref<number>(recomendadas.principal.plan.id)

const planActivo = computed(() => planPorId(planActivoId.value))

function seleccionar(id: number): void {
  planActivoId.value = id
  vista.value = 'plan'
}

function esRecomendada(id: number): boolean {
  return id === recomendadas.principal.plan.id || id === recomendadas.segunda.plan.id
}

function razonDe(id: number): string | null {
  if (id === recomendadas.principal.plan.id) {
    return recomendadas.principal.razon
  }
  if (id === recomendadas.segunda.plan.id) {
    return recomendadas.segunda.razon
  }

  return null
}

function colorDe(aseguradora: string): string {
  return companias.find((c) => c.nombre === aseguradora)?.color ?? 'var(--mg-fg-dim)'
}

function estiloFila(id: number, destacada: boolean): Record<string, string> {
  const activa = vista.value === 'plan' && planActivoId.value === id

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
