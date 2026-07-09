<template>
  <MangoLayout hide-header>
    <Head>
      <title>MANGO — Manual de instrucciones</title>
      <meta
        name="description"
        content="Cómo armar tu seguro de auto. Manual de instrucciones. Modelo AUTO-01. Hecho en Argentina."
      />
    </Head>

    <!-- ─── Header técnico ─────────────────────────────────────────────── -->
    <header
      class="sticky top-0 z-30"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="max-w-5xl mx-auto flex items-center justify-between px-5 h-14">
        <div class="flex items-center gap-3">
          <MangoLogo compact :height="22" />
          <span class="mg-folio hidden sm:inline">Manual · v1.0</span>
        </div>
        <div class="flex items-center gap-4">
          <button
            type="button"
            class="w-8 h-8 flex items-center justify-center cursor-pointer transition-opacity hover:opacity-60"
            :style="{ color: 'var(--mg-fg-dim)' }"
            :aria-label="isDark ? 'Modo claro' : 'Modo oscuro'"
            @click="toggleTheme"
          >
            <svg v-if="isDark" class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <svg v-else class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
          </button>
          <a :href="primaryCta.href" :target="primaryCta.target" class="mg-link-cta">
            {{ primaryCta.shortLabel }}
          </a>
        </div>
      </div>
    </header>

    <!-- ─── Portada tipo ficha técnica ─────────────────────────────────── -->
    <section class="relative px-5 pt-16 pb-16 sm:pt-24 sm:pb-24" :style="{ background: 'var(--mg-bg)' }">
      <div class="max-w-5xl mx-auto">
        <!-- Metadatos tipo ficha técnica -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-12 text-xs">
          <div>
            <p class="mg-folio mb-1">Modelo</p>
            <p class="font-semibold" :style="{ color: 'var(--mg-fg)' }">AUTO-01</p>
          </div>
          <div>
            <p class="mg-folio mb-1">Año</p>
            <p class="font-semibold" :style="{ color: 'var(--mg-fg)' }">{{ new Date().getFullYear() }}</p>
          </div>
          <div>
            <p class="mg-folio mb-1">Origen</p>
            <p class="font-semibold" :style="{ color: 'var(--mg-fg)' }">Buenos Aires, AR</p>
          </div>
          <div>
            <p class="mg-folio mb-1">Revisión</p>
            <p class="font-semibold" :style="{ color: 'var(--mg-fg)' }">1.0 · 06/2026</p>
          </div>
        </div>

        <div class="border-t-2 border-b-2 py-12 sm:py-16" :style="{ borderColor: 'var(--mg-fg)' }">
          <p v-reveal class="mg-folio mb-6 text-center">Manual de instrucciones</p>
          <h1 v-reveal="80" class="mg-display text-4xl sm:text-7xl leading-[0.95] text-center mb-6">
            Cómo armar<br>
            <span :style="{ color: 'var(--mg-mango)' }">tu seguro.</span>
          </h1>
          <p v-reveal="160" class="text-center max-w-md mx-auto" :style="{ color: 'var(--mg-fg-dim)' }">
            Modelo AUTO-01. Apto para todo público. Sin herramientas especiales.
          </p>
        </div>

        <!-- Advertencia obligatoria -->
        <div v-reveal="240" class="mt-10 flex items-start gap-4 p-4 sm:p-5 border-2" :style="{ borderColor: 'var(--mg-warn)' }">
          <div class="advertencia-triangulo flex-shrink-0 w-10 h-10 flex items-center justify-center" :style="{ background: 'var(--mg-warn)' }">
            <span class="text-white font-bold text-lg">!</span>
          </div>
          <div class="text-sm leading-relaxed" :style="{ color: 'var(--mg-fg)' }">
            <p class="font-semibold mb-1">ADVERTENCIA</p>
            <p>Antes de usar este seguro, lea todas las instrucciones. No usar en caso de vendedores insistentes, formularios eternos o letra chica sospechosa. En caso de duda, consultar a su productor de confianza.</p>
          </div>
        </div>

        <!-- Mango + código de barras -->
        <div class="mt-10 flex items-center justify-between gap-6">
          <div class="w-20 h-20 sm:w-24 sm:h-24">
            <MangoFruit :outline="true" class="w-full h-full" />
          </div>
          <div class="barcode">
            <div class="flex items-end gap-px h-12">
              <div v-for="(w, i) in barras" :key="i" :style="{ width: w + 'px', background: 'var(--mg-fg)' }"></div>
            </div>
            <p class="mg-folio mt-1 text-center">MNG-AUTO-01-AR</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Índice ─────────────────────────────────────────────────────── -->
    <section class="px-5 py-14" :style="{ background: 'var(--mg-surface)' }">
      <div class="max-w-4xl mx-auto">
        <p class="mg-folio mb-6">Índice</p>
        <ol class="space-y-3">
          <li v-for="(item, i) in indice" :key="i" class="grid grid-cols-[40px_1fr_auto] items-baseline gap-4 py-2" :style="{ borderBottom: '1px dashed var(--mg-hairline)' }">
            <span class="font-mono text-sm font-bold" :style="{ color: 'var(--mg-mango)' }">{{ String(i + 1).padStart(2, '0') }}</span>
            <span class="mg-heading" :style="{ color: 'var(--mg-fg)' }">{{ item.title }}</span>
            <span class="font-mono text-xs" :style="{ color: 'var(--mg-fg-faint)' }">p. {{ item.page }}</span>
          </li>
        </ol>
      </div>
    </section>

    <!-- ─── Lista de partes (despiece) ────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28">
      <div class="max-w-5xl mx-auto">
        <div class="flex items-baseline gap-4 mb-10">
          <span class="font-mono text-3xl font-bold" :style="{ color: 'var(--mg-mango)' }">01</span>
          <h2 class="mg-display text-3xl sm:text-4xl">Lista de partes</h2>
        </div>
        <p class="mb-12 max-w-xl" :style="{ color: 'var(--mg-fg-dim)' }">
          Antes de empezar, verificá que tengas todos los componentes. Si falta alguno, no se puede armar el seguro. Contactanos.
        </p>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
          <ParteItem v-for="(parte, i) in partes" :key="i" :index="i + 1" :titulo="parte.titulo" :cantidad="parte.cantidad" :icono="parte.icono" />
        </div>
      </div>
    </section>

    <!-- ─── Instrucciones paso a paso ─────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28" :style="{ background: 'var(--mg-surface)' }">
      <div class="max-w-4xl mx-auto">
        <div class="flex items-baseline gap-4 mb-4">
          <span class="font-mono text-3xl font-bold" :style="{ color: 'var(--mg-mango)' }">02</span>
          <h2 class="mg-display text-3xl sm:text-4xl">Instrucciones de armado</h2>
        </div>
        <p class="mb-12 max-w-xl" :style="{ color: 'var(--mg-fg-dim)' }">
          Tiempo estimado: 3 minutos. Dificultad: muy fácil. Si te trabás, llamanos.
        </p>

        <ol class="space-y-16">
          <li v-for="(paso, i) in instrucciones" :key="i" class="grid md:grid-cols-[120px_1fr] gap-6 md:gap-10">
            <!-- Número grande -->
            <div class="flex md:block items-baseline gap-4">
              <span class="font-mono text-6xl sm:text-7xl font-bold leading-none" :style="{ color: 'var(--mg-mango)' }">
                {{ String(i + 1).padStart(2, '0') }}
              </span>
              <span class="md:hidden font-mono text-xs" :style="{ color: 'var(--mg-fg-faint)' }">PASO</span>
            </div>

            <!-- Contenido + diagrama -->
            <div>
              <h3 class="mg-heading text-xl mb-2">{{ paso.titulo }}</h3>
              <p class="text-sm leading-relaxed mb-5" :style="{ color: 'var(--mg-fg-dim)' }">{{ paso.body }}</p>

              <!-- Diagrama -->
              <div class="diagram p-4 sm:p-6 flex items-center justify-center min-h-[140px]" :style="{ background: 'var(--mg-bg)' }">
                <component :is="paso.diagrama" />
              </div>
              <p class="font-mono text-[11px] mt-2 text-center" :style="{ color: 'var(--mg-fg-faint)' }">Fig. {{ i + 1 }}. {{ paso.figCaption }}</p>
            </div>
          </li>
        </ol>

        <div class="mt-16 flex flex-col sm:flex-row gap-3">
          <a :href="primaryCta.href" :target="primaryCta.target" class="mg-btn-primary !px-8 !py-4 !text-sm w-full sm:w-auto text-center">
            {{ primaryCta.label }}
          </a>
          <a :href="secondaryCta.href" :target="secondaryCta.target" class="mg-btn-ghost !px-8 !py-4 !text-sm w-full sm:w-auto text-center">
            {{ secondaryCta.label }}
          </a>
        </div>
      </div>
    </section>

    <!-- ─── Solución de problemas ─────────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28">
      <div class="max-w-4xl mx-auto">
        <div class="flex items-baseline gap-4 mb-4">
          <span class="font-mono text-3xl font-bold" :style="{ color: 'var(--mg-mango)' }">03</span>
          <h2 class="mg-display text-3xl sm:text-4xl">Solución de problemas</h2>
        </div>
        <p class="mb-12" :style="{ color: 'var(--mg-fg-dim)' }">
          Tabla de troubleshooting. Si tu problema no aparece acá, escribinos.
        </p>

        <div class="border-2" :style="{ borderColor: 'var(--mg-fg)' }">
          <div class="grid grid-cols-[1fr_1.5fr] sm:grid-cols-[1fr_2fr] font-mono text-xs uppercase font-bold tracking-wider" :style="{ background: 'var(--mg-fg)', color: 'var(--mg-bg)' }">
            <div class="p-3 sm:p-4" :style="{ borderRight: '1px solid var(--mg-bg)' }">Problema</div>
            <div class="p-3 sm:p-4">Solución</div>
          </div>
          <div v-for="(item, i) in troubleshooting" :key="i" class="grid grid-cols-[1fr_1.5fr] sm:grid-cols-[1fr_2fr] text-sm" :style="{ borderTop: '1px solid var(--mg-fg)' }">
            <div class="p-3 sm:p-4 font-semibold" :style="{ borderRight: '1px solid var(--mg-hairline)', color: 'var(--mg-fg)' }">{{ item.problema }}</div>
            <div class="p-3 sm:p-4" :style="{ color: 'var(--mg-fg-dim)' }">{{ item.solucion }}</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Garantía / Postventa ──────────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28" :style="{ background: 'var(--mg-mango-tint)' }">
      <div class="max-w-4xl mx-auto">
        <div class="flex items-baseline gap-4 mb-4">
          <span class="font-mono text-3xl font-bold" :style="{ color: 'var(--mg-mango)' }">04</span>
          <h2 class="mg-display text-3xl sm:text-4xl">Servicio post-venta</h2>
        </div>
        <p class="mb-10 max-w-xl" :style="{ color: 'var(--mg-fg-dim)' }">
          Este seguro incluye garantía de por vida. La garantía se llama: una persona que te conoce.
        </p>

        <div class="grid sm:grid-cols-2 gap-6">
          <div class="polaroid bg-white p-5 sm:p-6">
            <div class="aspect-[4/5] w-full max-w-[180px] mx-auto rounded-lg flex items-center justify-center mb-4" :style="{ background: 'var(--mg-mango)' }">
              <span class="mg-display text-7xl text-white">M</span>
            </div>
            <p class="mg-heading text-lg text-center">Martina</p>
            <p class="text-xs font-mono text-center mt-1" :style="{ color: 'var(--mg-fg-faint)' }">PRODUCTORA ASESORA · SERIE M-2026</p>
          </div>

          <div>
            <h3 class="mg-heading text-lg mb-3">Incluye:</h3>
            <ul class="space-y-2.5 text-sm" :style="{ color: 'var(--mg-fg-dim)' }">
              <li class="flex gap-2.5"><span :style="{ color: 'var(--mg-mango)' }">✓</span> Una persona real, no un bot.</li>
              <li class="flex gap-2.5"><span :style="{ color: 'var(--mg-mango)' }">✓</span> WhatsApp directo, sin 0800.</li>
              <li class="flex gap-2.5"><span :style="{ color: 'var(--mg-mango)' }">✓</span> Atención el día del siniestro.</li>
              <li class="flex gap-2.5"><span :style="{ color: 'var(--mg-mango)' }">✓</span> Renovaciones sin vueltas.</li>
              <li class="flex gap-2.5"><span :style="{ color: 'var(--mg-mango)' }">✓</span> Trato de persona a persona.</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Especificaciones técnicas ─────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28">
      <div class="max-w-4xl mx-auto">
        <div class="flex items-baseline gap-4 mb-4">
          <span class="font-mono text-3xl font-bold" :style="{ color: 'var(--mg-mango)' }">05</span>
          <h2 class="mg-display text-3xl sm:text-4xl">Especificaciones</h2>
        </div>

        <div class="grid sm:grid-cols-2 gap-x-12 gap-y-2 font-mono text-sm">
          <div v-for="(spec, i) in specs" :key="i" class="grid grid-cols-[1fr_auto] gap-4 py-3" :style="{ borderBottom: '1px dashed var(--mg-hairline)' }">
            <span :style="{ color: 'var(--mg-fg-dim)' }">{{ spec.label }}</span>
            <span class="font-semibold" :style="{ color: 'var(--mg-fg)' }">{{ spec.value }}</span>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Contraportada ─────────────────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 py-24 sm:py-32" :style="{ background: 'var(--mg-fg)' }">
      <div class="max-w-3xl mx-auto text-center">
        <p class="font-mono text-xs uppercase tracking-[0.22em] mb-6" :style="{ color: 'var(--mg-mango-soft)' }">Última página</p>
        <h2 class="mg-display text-5xl sm:text-7xl leading-[0.95] mb-6" :style="{ color: 'var(--mg-bg)' }">
          Te cuidamos<br>
          <span :style="{ color: 'var(--mg-mango)' }">el mango.</span>
        </h2>
        <p class="text-lg sm:text-xl mb-10" :style="{ color: 'var(--mg-bg)' }">
          Y cuando
          <span :key="rotatorWord" class="rotator font-semibold" :style="{ color: 'var(--mg-mango-soft)', borderBottom: '2px solid var(--mg-mango)' }">{{ rotatorWord }}</span>,
          te atiende una persona.
        </p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
          <a :href="primaryCta.href" :target="primaryCta.target" class="mg-btn-primary !px-10 !py-4 !text-sm w-full sm:w-auto">
            {{ primaryCta.label }}
          </a>
          <a :href="secondaryCta.href" :target="secondaryCta.target" class="mg-btn-ghost !px-9 !py-4 !text-sm w-full sm:w-auto" :style="{ borderColor: 'rgba(255,255,255,0.3)', color: 'var(--mg-bg)' }">
            {{ secondaryCta.label }}
          </a>
        </div>
        <p class="mt-10 font-mono text-xs uppercase tracking-widest" :style="{ color: 'var(--mg-fg-faint)' }">
          Hecho en Buenos Aires · {{ new Date().getFullYear() }} · v1.0
        </p>
      </div>
    </section>

    <!-- ─── Footer ─────────────────────────────────────────────────────── -->
    <footer class="px-5 py-10" :style="{ borderTop: '1px solid var(--mg-hairline)' }">
      <div class="max-w-5xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-5">
        <MangoLogo :height="32" />
        <div class="flex items-center gap-6 text-xs" :style="{ color: 'var(--mg-fg-faint)' }">
          <a href="/privacy" class="hover:underline" :style="{ color: 'var(--mg-fg-dim)' }">Privacidad</a>
          <a href="/login" class="hover:underline" :style="{ color: 'var(--mg-fg-dim)' }">Ingresar</a>
          <span>© {{ new Date().getFullYear() }} MANGO Insurance Broker</span>
        </div>
      </div>
    </footer>
  </MangoLayout>
</template>

<script setup lang="ts">
import { computed, h, onBeforeUnmount, onMounted, ref } from 'vue'
import type { Directive } from 'vue'
import { Head } from '@inertiajs/vue3'
import MangoLayout from '@/layouts/MangoLayout.vue'
import MangoLogo from '@/components/Mango/MangoLogo.vue'

const props = defineProps<{
  waQuoteUrl: string | null
  appDownloadUrl: string | null
}>()

const reduceMotion =
  typeof window !== 'undefined' &&
  window.matchMedia('(prefers-reduced-motion: reduce)').matches

// ── v-reveal ────────────────────────────────────────────────────────────
const pendingReveals = new Set<HTMLElement>()

const revealNow = (el: HTMLElement) => {
  el.classList.add('reveal-in')
  pendingReveals.delete(el)
  revealObserver?.unobserve(el)
}

const isInViewport = (el: HTMLElement): boolean => {
  const rect = el.getBoundingClientRect()
  const vh = window.innerHeight || document.documentElement.clientHeight
  return rect.top < vh * 0.92 && rect.bottom > 0
}

const checkPendingReveals = () => {
  for (const el of [...pendingReveals]) {
    if (isInViewport(el)) {
      revealNow(el)
    }
  }
}

const revealObserver =
  typeof IntersectionObserver !== 'undefined'
    ? new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            if (entry.isIntersecting) {
              revealNow(entry.target as HTMLElement)
            }
          }
        },
        { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
      )
    : null

const vReveal: Directive<HTMLElement, number | undefined> = {
  mounted(el, binding) {
    if (reduceMotion) {
      el.classList.add('reveal-in')
      return
    }
    el.classList.add('reveal')
    if (binding.value) {
      el.style.transitionDelay = `${binding.value}ms`
    }
    pendingReveals.add(el)
    revealObserver?.observe(el)
  },
}

// ── CTA ──────────────────────────────────────────────────────────────────
const HERO_CTA: 'cotizar' | 'app' = 'cotizar'

const ctas = computed(() => ({
  cotizar: {
    label: 'Empezar ahora',
    shortLabel: 'Cotizar',
    href: props.waQuoteUrl ?? '#',
    target: props.waQuoteUrl ? '_blank' : undefined,
  },
  app: {
    label: 'Descargá la app',
    shortLabel: 'La app',
    href: props.appDownloadUrl ?? '#',
    target: props.appDownloadUrl ? '_blank' : undefined,
  },
}))

const primaryCta = computed(() => ctas.value[HERO_CTA])
const secondaryCta = computed(() => ctas.value[HERO_CTA === 'cotizar' ? 'app' : 'cotizar'])

// ── Toggle claro/oscuro ─────────────────────────────────────────────────
const THEME_KEY = 'pas-theme'
const isDark = ref(false)

const toggleTheme = () => {
  isDark.value = !isDark.value
  const theme = isDark.value ? 'dark' : 'light'
  localStorage.setItem(THEME_KEY, theme)
  document.documentElement.setAttribute('data-theme', theme)
}

// ── Datos ──────────────────────────────────────────────────────────────
const indice = [
  { title: 'Lista de partes', page: '02' },
  { title: 'Instrucciones de armado', page: '04' },
  { title: 'Solución de problemas', page: '06' },
  { title: 'Servicio post-venta', page: '07' },
  { title: 'Especificaciones técnicas', page: '08' },
]

const partes = [
  { titulo: 'Auto', cantidad: 1, icono: 'auto' },
  { titulo: 'Teléfono', cantidad: 1, icono: 'tel' },
  { titulo: 'WhatsApp', cantidad: 1, icono: 'wa' },
  { titulo: 'IA cotizadora', cantidad: 1, icono: 'ia' },
  { titulo: 'Productor humano', cantidad: 1, icono: 'hum' },
  { titulo: 'Paciencia', cantidad: '∞', icono: 'pac' },
]

// ── Diagramas de pasos ─────────────────────────────────────────────────
const DiagramaAuto = () =>
  h('svg', { viewBox: '0 0 200 120', fill: 'none', stroke: 'var(--mg-fg)', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
    h('path', { d: 'M30 80 L40 50 L70 45 L100 45 L130 45 L160 50 L170 80 L150 80 M50 80 L80 80 M120 80 L150 80' }),
    h('circle', { cx: 60, cy: 90, r: 10 }),
    h('circle', { cx: 140, cy: 90, r: 10 }),
    h('path', { d: 'M30 80 L170 80' }),
    h('text', { x: 100, y: 30, 'text-anchor': 'middle', 'font-family': 'var(--mg-font-mono)', 'font-size': 9, fill: 'var(--mg-mango)' }, 'AUTO'),
  ])

const DiagramaWhatsApp = () =>
  h('svg', { viewBox: '0 0 200 140', fill: 'none', stroke: 'var(--mg-fg)', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
    h('rect', { x: 40, y: 20, width: 120, height: 90, rx: 8 }),
    h('path', { d: 'M40 30 L160 30' }),
    h('circle', { cx: 50, cy: 25, r: 2, fill: 'var(--mg-fg)' }),
    h('circle', { cx: 58, cy: 25, r: 2, fill: 'var(--mg-fg)' }),
    h('rect', { x: 55, y: 45, width: 90, height: 15, rx: 4, fill: 'var(--mg-mango-tint)' }),
    h('rect', { x: 70, y: 70, width: 60, height: 15, rx: 4, fill: 'var(--mg-mango-tint)' }),
    h('path', { d: 'M100 110 L100 125 L115 125' }),
    h('text', { x: 100, y: 135, 'text-anchor': 'middle', 'font-family': 'var(--mg-font-mono)', 'font-size': 9, fill: 'var(--mg-mango)' }, 'WHATSAPP'),
  ])

const DiagramaCotizacion = () =>
  h('svg', { viewBox: '0 0 200 130', fill: 'none', stroke: 'var(--mg-fg)', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
    h('rect', { x: 20, y: 30, width: 50, height: 70, rx: 4 }),
    h('rect', { x: 75, y: 30, width: 50, height: 70, rx: 4 }),
    h('rect', { x: 130, y: 30, width: 50, height: 70, rx: 4 }),
    h('path', { d: 'M30 50 L60 50 M30 60 L60 60 M30 70 L50 70' }),
    h('path', { d: 'M85 50 L115 50 M85 60 L115 60 M85 70 L105 70' }),
    h('path', { d: 'M140 50 L170 50 M140 60 L170 60 M140 70 L160 70' }),
    h('text', { x: 100, y: 120, 'text-anchor': 'middle', 'font-family': 'var(--mg-font-mono)', 'font-size': 9, fill: 'var(--mg-mango)' }, 'COTIZACIÓN'),
  ])

const DiagramaPersona = () =>
  h('svg', { viewBox: '0 0 200 130', fill: 'none', stroke: 'var(--mg-fg)', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
    h('circle', { cx: 100, cy: 50, r: 18 }),
    h('path', { d: 'M70 110 Q70 80 100 80 Q130 80 130 110' }),
    h('path', { d: 'M70 110 L130 110' }),
    h('path', { d: 'M100 30 L100 38 M95 35 L100 38 L105 35' }),
    h('text', { x: 100, y: 125, 'text-anchor': 'middle', 'font-family': 'var(--mg-font-mono)', 'font-size': 9, fill: 'var(--mg-mango)' }, 'PRODUCTOR'),
  ])

const DiagramaCheck = () =>
  h('svg', { viewBox: '0 0 200 130', fill: 'none', stroke: 'var(--mg-fg)', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
    h('circle', { cx: 100, cy: 60, r: 30, stroke: 'var(--mg-mango)' }),
    h('path', { d: 'M85 60 L96 71 L115 50', stroke: 'var(--mg-mango)' }),
    h('path', { d: 'M40 60 L60 60 M140 60 L160 60' }),
    h('text', { x: 100, y: 115, 'text-anchor': 'middle', 'font-family': 'var(--mg-font-mono)', 'font-size': 9, fill: 'var(--mg-mango)' }, 'LISTO'),
  ])

const instrucciones = [
  {
    titulo: 'Identificá tu auto.',
    body: 'Necesitás marca, modelo, año y versión. Si no los sabés, no te preocupes: podés averiguarlo en la cédula verde. Si la perdiste, también te ayudamos.',
    diagrama: DiagramaAuto,
    figCaption: 'Identificación del vehículo',
  },
  {
    titulo: 'Abrí WhatsApp.',
    body: 'Buscá nuestro número oficial. Escribinos "Hola". El chat se inicia solo. No hay menú, no hay IVR, no hay "para cotizar presione 1".',
    diagrama: DiagramaWhatsApp,
    figCaption: 'Inicio de conversación',
  },
  {
    titulo: 'Cotizá en varias compañías.',
    body: 'La IA busca en todas las aseguradoras del mercado. Te muestra las opciones una al lado de la otra, en un mismo chat, con precio, cobertura y letra legible.',
    diagrama: DiagramaCotizacion,
    figCaption: 'Comparativa de cotizaciones',
  },
  {
    titulo: 'Conocé a tu productor.',
    body: 'En cuanto elijas una cobertura, te asignamos una persona real. No un call center. Una persona con nombre, apellido y WhatsApp directo.',
    diagrama: DiagramaPersona,
    figCaption: 'Asignación de productor',
  },
  {
    titulo: 'Listo. Tu seguro está armado.',
    body: 'Recibís la póliza por mail, los papeles en la app, y el contacto de tu productor. A partir de acá, tenés una persona para lo que necesites.',
    diagrama: DiagramaCheck,
    figCaption: 'Producto terminado',
  },
]

const troubleshooting = [
  { problema: 'No me responde la IA', solucion: 'Escribinos de nuevo. Si sigue sin responder, te atiende Martina directamente.' },
  { problema: 'El precio es muy caro', solucion: 'Cotizamos en más de 20 aseguradoras. Si el precio no te cierra, probamos otras opciones.' },
  { problema: 'Tuve un choque', solucion: 'Escribile directo a tu productor. No llames al 0800. No llenes formularios web. Tu productor se encarga.' },
  { problema: 'Me roban el auto', solucion: 'Mismo procedimiento. Tu productor inicia la denuncia y te acompaña en el proceso.' },
  { problema: 'Me atendieron mal', solucion: 'Eso no debería pasar. Contanos qué pasó y lo resolvemos personalmente.' },
  { problema: 'No entiendo la póliza', solucion: 'Te la explicamos por WhatsApp en castellano normal. Sin tecnicismos.' },
]

const specs = [
  { label: 'Modelo', value: 'AUTO-01' },
  { label: 'Cotización', value: '~ 3 min' },
  { label: 'Aseguradoras', value: '+20' },
  { label: 'Atención humana', value: '24/7' },
  { label: 'Tiempo de respuesta', value: '< 5 min' },
  { label: 'Idioma', value: 'Castellano (AR)' },
  { label: 'Garantía', value: 'De por vida' },
  { label: 'Hecho en', value: 'Buenos Aires' },
]

const barras = [3, 1, 2, 1, 3, 2, 1, 2, 3, 1, 1, 2, 3, 1, 2, 1, 3, 2, 1, 1, 3, 1, 2, 3, 1, 2, 1, 1, 3, 1, 2, 1, 3, 2, 1]

// ── Rotador ────────────────────────────────────────────────────────────
const rotatorWords = ['tenés un choque', 'te roban', 'tenés una duda', 'cae granizo']
const rotatorWord = ref(rotatorWords[0])
let rotatorIdx = 0
let rotatorTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  isDark.value = document.documentElement.getAttribute('data-theme') === 'dark'

  window.addEventListener('scroll', checkPendingReveals, { passive: true })
  window.addEventListener('resize', checkPendingReveals)
  requestAnimationFrame(() =>
    requestAnimationFrame(() => {
      checkPendingReveals()
    }),
  )

  rotatorTimer = setInterval(() => {
    rotatorIdx = (rotatorIdx + 1) % rotatorWords.length
    rotatorWord.value = rotatorWords[rotatorIdx]
  }, 2000)
})

onBeforeUnmount(() => {
  revealObserver?.disconnect()
  window.removeEventListener('scroll', checkPendingReveals)
  window.removeEventListener('resize', checkPendingReveals)
  if (rotatorTimer) {
    clearInterval(rotatorTimer)
  }
})

// ── Mango ──────────────────────────────────────────────────────────────
const MangoFruit = (fruitProps: { mono?: boolean; outline?: boolean }) => {
  const color = fruitProps.mono ? '#ffffff' : 'var(--mg-mango)'
  const shape = fruitProps.outline
    ? { fill: 'none', stroke: color, 'stroke-width': 2.4, 'stroke-linejoin': 'round' }
    : { fill: color }
  return h('svg', { viewBox: '4 2 78 88', xmlns: 'http://www.w3.org/2000/svg', 'aria-hidden': 'true' }, [
    h('path', { d: 'M44,17 C44,12 46,7 50,5', fill: 'none', stroke: color, 'stroke-width': 2.5, 'stroke-linecap': 'round' }),
    h('path', { d: 'M44,16 C48,8 62,6 64,12 C57,15 49,16 44,16 Z', ...shape }),
    h('path', {
      d: 'M44,22 C56,17 69,22 72,35 C76,48 71,67 62,77 C53,87 38,90 27,83 C16,76 13,61 16,47 C19,33 30,27 44,22 Z',
      ...shape,
    }),
  ])
}

// ── Ícono de parte ─────────────────────────────────────────────────────
const ParteItem = (props: { index: number; titulo: string; cantidad: number | string; icono: string }) => {
  const svg = (() => {
    switch (props.icono) {
      case 'auto':
        return h('svg', { viewBox: '0 0 60 40', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
          h('path', { d: 'M8 28 L13 16 L25 14 L35 14 L47 16 L52 28 L44 28 M16 28 L24 28 M36 28 L44 28' }),
          h('circle', { cx: 18, cy: 32, r: 3 }),
          h('circle', { cx: 42, cy: 32, r: 3 }),
        ])
      case 'tel':
        return h('svg', { viewBox: '0 0 60 40', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
          h('rect', { x: 22, y: 6, width: 16, height: 28, rx: 2 }),
          h('path', { d: 'M28 30 L32 30' }),
        ])
      case 'wa':
        return h('svg', { viewBox: '0 0 60 40', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
          h('circle', { cx: 30, cy: 20, r: 12 }),
          h('path', { d: 'M24 32 L22 36 L28 33' }),
          h('path', { d: 'M25 17 Q25 14 28 14 Q31 14 31 17 L31 23 L25 23 Z', fill: 'currentColor' }),
        ])
      case 'ia':
        return h('svg', { viewBox: '0 0 60 40', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
          h('rect', { x: 14, y: 12, width: 32, height: 20, rx: 3 }),
          h('circle', { cx: 22, cy: 22, r: 1.5, fill: 'currentColor' }),
          h('circle', { cx: 30, cy: 22, r: 1.5, fill: 'currentColor' }),
          h('circle', { cx: 38, cy: 22, r: 1.5, fill: 'currentColor' }),
          h('path', { d: 'M20 12 L20 8 M30 12 L30 8 M40 12 L40 8' }),
        ])
      case 'hum':
        return h('svg', { viewBox: '0 0 60 40', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
          h('circle', { cx: 30, cy: 14, r: 5 }),
          h('path', { d: 'M20 34 Q20 22 30 22 Q40 22 40 34' }),
        ])
      case 'pac':
        return h('svg', { viewBox: '0 0 60 40', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
          h('path', { d: 'M30 8 L33 16 L42 16 L35 21 L37 30 L30 25 L23 30 L25 21 L18 16 L27 16 Z' }),
        ])
    }
  })()

  return h('div', { class: 'parte p-4 sm:p-5', style: { background: 'var(--mg-surface)', border: '1px solid var(--mg-hairline)' } }, [
    h('div', { class: 'flex items-baseline justify-between mb-3' }, [
      h('span', { class: 'font-mono text-xs font-bold', style: { color: 'var(--mg-mango)' } }, String(props.index).padStart(2, '0')),
      h('span', { class: 'font-mono text-xs', style: { color: 'var(--mg-fg-faint)' } }, 'x' + props.cantidad),
    ]),
    h('div', { class: 'mb-3', style: { color: 'var(--mg-fg)', height: '40px' } }, [svg]),
    h('p', { class: 'font-mono text-xs font-semibold uppercase tracking-wider' }, props.titulo),
  ])
}
</script>

<style scoped>
.mg-folio {
  font-family: var(--mg-font-mono);
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--mg-fg-dim);
}

.mg-link-cta {
  font-family: var(--mg-font-ui);
  font-size: 13px;
  font-weight: 600;
  color: var(--mg-mango);
}
.mg-link-cta:hover {
  text-decoration: underline;
  text-underline-offset: 3px;
}

/* Reveal */
.reveal {
  opacity: 0;
  transform: translateY(22px);
  transition:
    opacity 0.6s ease,
    transform 0.6s cubic-bezier(0.22, 1, 0.36, 1);
  will-change: opacity, transform;
}
.reveal-in {
  opacity: 1;
  transform: none;
}

/* Triángulo de advertencia */
.advertencia-triangulo {
  clip-path: polygon(0% 0%, 100% 0%, 50% 100%);
  border-radius: 0;
}

/* Diagrama */
.diagram {
  border: 1px solid var(--mg-hairline);
  border-radius: 4px;
}

/* Polaroid */
.polaroid {
  border-radius: 4px;
  border: 1px solid var(--mg-hairline);
  box-shadow:
    0 2px 4px rgba(0,0,0,0.04),
    0 12px 28px rgba(0,0,0,0.08);
}

/* Rotador */
.rotator {
  display: inline-block;
  animation: rotator-in 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
}
@keyframes rotator-in {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
  .reveal {
    opacity: 1;
    transform: none;
    transition: none;
  }
  .rotator {
    animation: none;
  }
}
</style>
