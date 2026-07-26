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
            <span style="color: var(--mg-fg-faint)">· {{ contexto.combustible }}</span>
          </p>
        </div>
        <a
          :href="waLink('Volvamos con lo del seguro del 2008.')"
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
        <p class="mg-overline mb-3">{{ contexto.cobertura }} · {{ totalOpciones }} opciones</p>
        <h1 class="mg-display text-[38px] leading-[1.1] max-w-2xl">
          Estas son todas las opciones que conseguí para tu 2008.
        </h1>
        <p class="text-[14px] mt-3.5 max-w-xl leading-relaxed" style="color: var(--mg-fg-dim)">
          Todas son Todo Riesgo y cubren prácticamente lo mismo. Lo que cambia entre ellas es la
          franquicia, la suma asegurada y el precio. Cotizado el {{ contexto.cotizadoEl }}; los
          precios valen hasta el {{ contexto.validoHasta }}.
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
                <p class="text-[11.5px] mt-1" :style="{ color: 'var(--mg-leaf)' }">
                  Franquicia {{ item.plan.franquicia }}
                </p>
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
            Agrupadas por compañía. Dentro de cada una, lo que cambia es la franquicia.
          </p>

          <div v-for="c in companias" :key="c.slug" class="mb-5">
            <div class="flex items-center gap-2 mb-1.5">
              <span
                class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-[10.5px] font-bold text-white"
                :style="{ background: c.color }"
              >{{ c.nombre.charAt(0) }}</span>
              <span class="text-[12.5px] font-semibold">{{ c.nombre }}</span>
              <span class="text-[11px]" style="color: var(--mg-fg-faint)">
                {{ formatSuma(c.planes[0].sumaAsegurada) }}
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
                  Franquicia {{ p.franquicia }}
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
          <article v-if="vista === 'plan'" class="mg-card p-6">
            <div class="flex items-start gap-4">
              <span
                class="flex-shrink-0 w-14 h-14 rounded-full flex items-center justify-center text-[19px] font-bold text-white"
                :style="{ background: colorDe(planActivo.aseguradora) }"
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
              <div>
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

            <p class="mg-overline mt-6 mb-1">Qué cubre</p>
            <p class="text-[12px] mb-3" style="color: var(--mg-fg-faint)">
              Cualquiera de estas te la puedo explicar por WhatsApp con lo que dice la póliza.
            </p>

            <div class="grid grid-cols-2 gap-x-6">
              <a
                v-for="item in coberturasPlan"
                :key="item.label"
                :href="waLink(
                  `Sobre ${planActivo.aseguradora} ${planActivo.titulo}: ¿qué cubre exactamente “${item.label}”? ¿Tiene tope o límite de eventos?`,
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
                  <span class="block text-[13px] font-semibold leading-tight">{{ item.label }}</span>
                  <span class="block text-[11.5px] mt-0.5 leading-snug" style="color: var(--mg-fg-dim)">{{ item.nota }}</span>
                </span>
                <span
                  class="flex-shrink-0 mt-0.5 flex items-center gap-1"
                  :style="{ color: 'var(--mg-mango)' }"
                >
                  <ChatIcon :size="13" />
                  <span class="text-[10.5px] font-semibold">Preguntar</span>
                </span>
              </a>
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
                  <p class="text-[11.5px] mt-0.5 leading-snug" style="color: var(--mg-fg-dim)">{{ item.nota }}</p>
                </div>
              </div>
            </template>

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
                Preguntar por WhatsApp
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
                  <p
                    v-if="!(i === 0 ? comparacion.soloA : comparacion.soloB).length"
                    class="text-[11.5px] leading-snug"
                    style="color: var(--mg-fg-dim)"
                  >
                    Nada que la otra no tenga.
                  </p>
                  <div
                    v-for="f in (i === 0 ? comparacion.soloA : comparacion.soloB)"
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

            <!-- Lo que de verdad las separa -->
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
              <strong>{{ recomendadas.segunda.plan.aseguradora }} sale
                ${{ formatPrecio(diferenciaPrecio) }} menos por mes</strong>, un
              {{ diferenciaPorcentaje }}% menos. Tené en cuenta que la cuota se actualiza cuando la
              compañía reajusta la suma asegurada.
            </p>

            <div class="mt-6">
              <p class="mg-overline mb-1.5">Incluidas en las dos ({{ comparacion.comunes.length }})</p>
              <p class="text-[12px] mb-2.5" style="color: var(--mg-fg-faint)">
                Las dos las incluyen. Los topes y límites de cada una te los confirmo por chat.
              </p>
              <div class="grid grid-cols-2 gap-x-6">
                <div
                  v-for="f in comparacion.comunes"
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
              :href="waLink(
                `Vi la comparación de ${recomendadas.principal.plan.aseguradora} y ${recomendadas.segunda.plan.aseguradora}. ¿Cuál me conviene?`,
              )"
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
  coberturasDe,
  companias,
  comparacion,
  contexto,
  diferenciaPorcentaje,
  diferenciaPrecio,
  formatPrecio,
  formatSuma,
  planPorId,
  recomendadas,
  totalOpciones,
  waLink,
} from './comparadorData'

const vista = ref<'plan' | 'compare'>('plan')
const planActivoId = ref<number>(recomendadas.principal.plan.id)

const destacadas = [recomendadas.principal, recomendadas.segunda]

const planActivo = computed(() => planPorId(planActivoId.value))
const coberturasPlan = computed(() => coberturasDe(planActivo.value).filter((i) => i.esCobertura))
const extrasPlan = computed(() => coberturasDe(planActivo.value).filter((i) => !i.esCobertura))

/** Las filas que de verdad separan a las dos recomendadas. */
const filasClave = computed(() => {
  const a = recomendadas.principal.plan
  const b = recomendadas.segunda.plan

  return [
    { label: 'Franquicia', a: a.franquicia, b: b.franquicia },
    { label: 'Suma asegurada', a: formatSuma(a.sumaAsegurada), b: formatSuma(b.sumaAsegurada) },
    { label: 'Coberturas', a: `${a.features.length} ítems`, b: `${b.features.length} ítems` },
  ]
})

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
