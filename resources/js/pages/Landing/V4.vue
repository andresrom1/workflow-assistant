<template>
  <MangoLayout hide-header>
    <Head>
      <title>MANGO — Recetas para cuidar lo tuyo</title>
      <meta
        name="description"
        content="La receta del seguro perfecto: IA que cotiza, humano que te cuida. Sin 0800, sin letra chica."
      />
    </Head>

    <!-- ─── Masthead tipo recetario ────────────────────────────────────── -->
    <header
      class="sticky top-0 z-30"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="max-w-5xl mx-auto flex items-center justify-between px-5 h-14">
        <div class="flex items-center gap-3">
          <MangoLogo compact :height="24" />
          <span class="mg-folio hidden sm:inline">Recetario</span>
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

    <!-- ─── Hero: la receta del día ────────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 pt-16 pb-20 sm:pt-24 sm:pb-28">
      <!-- Utensilios de fondo -->
      <div class="absolute -right-16 top-20 w-56 h-56 sm:w-80 sm:h-80 opacity-[0.06] pointer-events-none rotate-12">
        <IconCucharaTenedor class="w-full h-full" />
      </div>

      <div class="max-w-5xl mx-auto relative">
        <div class="text-center mb-10">
          <p v-reveal class="mg-folio mb-4">Recetario MANGO · Edición especial</p>
          <h1 v-reveal="80" class="mg-display text-4xl sm:text-6xl leading-[1] mb-5">
            Seguro de auto<br>
            <span :style="{ color: 'var(--mg-mango)' }">al horno.</span>
          </h1>
          <p v-reveal="160" class="text-lg sm:text-xl" :style="{ color: 'var(--mg-fg-dim)' }">
            La receta del seguro perfecto: pocos ingredientes, cero vueltas.
          </p>
        </div>

        <!-- Tarjeta de receta -->
        <div v-reveal="240" class="recipe-card max-w-3xl mx-auto">
          <div class="recipe-card-header p-6 sm:p-8" :style="{ background: 'var(--mg-mango)' }">
            <div class="flex items-center justify-between gap-4 flex-wrap">
              <div>
                <p class="text-white/80 text-xs font-semibold tracking-widest uppercase mb-1">Receta del día</p>
                <h2 class="mg-display text-2xl sm:text-3xl text-white">Seguro de auto sin 0800</h2>
              </div>
              <div class="w-14 h-14 sm:w-16 sm:h-16 rounded-full bg-white/20 flex items-center justify-center">
                <MangoFruit :outline="true" class="w-9 h-9" />
              </div>
            </div>
          </div>
          <div class="recipe-card-body p-6 sm:p-8">
            <div class="flex flex-wrap gap-4 mb-6 pb-6" :style="{ borderBottom: '1px dashed var(--mg-hairline)' }">
              <BadgeReceta icon="timer">3 minutos</BadgeReceta>
              <BadgeReceta icon="level">Facilísimo</BadgeReceta>
              <BadgeReceta icon="yield">Rinde: 1 persona tranquila</BadgeReceta>
            </div>
            <p class="text-base leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
              Una receta argentina para cocinar tranquilidad. La IA se encarga de la cotización,
              el humano se encarga de cuidarte. Ideal para el día a día y esencial para el peor momento.
            </p>
            <div class="mt-8 flex flex-col sm:flex-row gap-3">
              <a :href="primaryCta.href" :target="primaryCta.target" class="mg-btn-primary !px-8 !py-4 !text-sm w-full sm:w-auto text-center">
                Empezar la receta
              </a>
              <a :href="secondaryCta.href" :target="secondaryCta.target" class="mg-btn-ghost !px-8 !py-4 !text-sm w-full sm:w-auto text-center">
                Ver la app
              </a>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Ingredientes + Preparación ─────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28" :style="{ background: 'var(--mg-surface)' }">
      <div class="max-w-5xl mx-auto">
        <div class="grid lg:grid-cols-[360px_1fr] gap-12 lg:gap-16">
          <!-- Ingredientes -->
          <aside v-reveal>
            <div class="sticky top-24">
              <div class="flex items-center gap-3 mb-6">
                <IconOlla class="w-8 h-8" :style="{ color: 'var(--mg-mango)' }" />
                <h2 class="mg-display text-2xl">Ingredientes</h2>
              </div>
              <ul class="space-y-3 text-sm">
                <li v-for="(ing, i) in ingredientes" :key="ing" class="ingredient-item flex items-start gap-3 py-2.5" :style="{ borderBottom: '1px dashed var(--mg-hairline)' }">
                  <span class="ingredient-check mt-0.5 w-5 h-5 rounded-full border flex items-center justify-center flex-shrink-0" :style="{ borderColor: 'var(--mg-mango)', color: 'var(--mg-mango)' }">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                  </span>
                  <span :style="{ color: 'var(--mg-fg-dim)' }">{{ ing }}</span>
                </li>
              </ul>
              <p class="mt-6 text-xs italic" :style="{ color: 'var(--mg-fg-faint)' }">
                *Consejo del chef: no agregar vendedores ni letra chica. Arruinan el sabor.
              </p>
            </div>
          </aside>

          <!-- Preparación -->
          <div>
            <div class="flex items-center gap-3 mb-10">
              <IconCuchara class="w-8 h-8" :style="{ color: 'var(--mg-mango)' }" />
              <h2 class="mg-display text-2xl">Preparación</h2>
            </div>

            <ol class="space-y-10">
              <li v-for="(paso, i) in pasos" :key="i" v-reveal="i * 80" class="relative pl-14">
                <span class="absolute left-0 top-0 w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold" :style="{ background: 'var(--mg-mango)', color: '#fff' }">
                  {{ i + 1 }}
                </span>
                <h3 class="mg-heading text-lg mb-2">{{ paso.title }}</h3>
                <p class="text-sm leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">{{ paso.body }}</p>
              </li>
            </ol>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Tips del chef ──────────────────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28">
      <div class="max-w-4xl mx-auto">
        <p v-reveal class="mg-folio mb-4 text-center">Notas del chef</p>
        <h2 v-reveal="80" class="mg-display text-3xl sm:text-5xl leading-tight text-center mb-16">
          Secretos que no te cuenta la industria.
        </h2>

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
          <TipCard v-reveal v-for="(tip, i) in tips" :key="i" :delay="i">
            <template #title>{{ tip.title }}</template>
            {{ tip.body }}
          </TipCard>
        </div>
      </div>
    </section>

    <!-- ─── Receta del mal momento ─────────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28" :style="{ background: 'var(--mg-mango-tint)' }">
      <div class="max-w-4xl mx-auto">
        <div class="text-center mb-14">
          <p v-reveal class="mg-folio mb-4">Receta de emergencia</p>
          <h2 v-reveal="80" class="mg-display text-3xl sm:text-5xl leading-tight mb-5">
            Cuando se quema todo.
          </h2>
          <p v-reveal="160" class="max-w-lg mx-auto" :style="{ color: 'var(--mg-fg-dim)' }">
            Para esos días en los que el auto no volvió a casa igual. Servir inmediatamente con mucha calma.
          </p>
        </div>

        <div v-reveal="240" class="recipe-card max-w-2xl mx-auto">
          <div class="recipe-card-body p-6 sm:p-8">
            <ol class="space-y-6">
              <li class="flex gap-4">
                <span class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold" :style="{ background: 'var(--mg-mango)', color: '#fff' }">1</span>
                <div>
                  <h3 class="mg-heading mb-1">Respirar.</h3>
                  <p class="text-sm" :style="{ color: 'var(--mg-fg-dim)' }">Lo material se arregla. Vos primero.</p>
                </div>
              </li>
              <li class="flex gap-4">
                <span class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold" :style="{ background: 'var(--mg-mango)', color: '#fff' }">2</span>
                <div>
                  <h3 class="mg-heading mb-1">Sacar fotos.</h3>
                  <p class="text-sm" :style="{ color: 'var(--mg-fg-dim)' }">Del choque, del lugar, de los daños. Sin apuro.</p>
                </div>
              </li>
              <li class="flex gap-4">
                <span class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold" :style="{ background: 'var(--mg-mango)', color: '#fff' }">3</span>
                <div>
                  <h3 class="mg-heading mb-1">Escribirle a tu productor.</h3>
                  <p class="text-sm" :style="{ color: 'var(--mg-fg-dim)' }">Una persona real, que te conoce, te va a decir qué hacer.</p>
                </div>
              </li>
              <li class="flex gap-4">
                <span class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold" :style="{ background: 'var(--mg-mango)', color: '#fff' }">4</span>
                <div>
                  <h3 class="mg-heading mb-1">Dejar que MANGO cocine.</h3>
                  <p class="text-sm" :style="{ color: 'var(--mg-fg-dim)' }">Nosotros iniciamos el trámite y te llamamos en 5 minutos.</p>
                </div>
              </li>
            </ol>

            <div class="mt-8 pt-6" :style="{ borderTop: '1px dashed var(--mg-hairline)' }">
              <p class="text-sm italic text-center" :style="{ color: 'var(--mg-fg-faint)' }">
                "No hay siniestro que no se arregle con un buen trato humano."
                <span class="block mt-1 not-italic font-semibold" :style="{ color: 'var(--mg-mango)' }">— Martina, productora</span>
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Quién cocina acá ───────────────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28">
      <div class="max-w-4xl mx-auto">
        <div class="grid md:grid-cols-2 gap-12 items-center">
          <div>
            <p v-reveal class="mg-folio mb-4">Sobre la cocina</p>
            <h2 v-reveal="80" class="mg-display text-3xl sm:text-4xl leading-tight mb-6">
              Acá no vendemos seguros.<br>
              Preparamos tranquilidad.
            </h2>
            <div v-reveal="160" class="space-y-4 text-base leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
              <p>
                MANGO nació porque nos cansamos de que cuidar lo propio se sintiera como hacer un trámite.
              </p>
              <p>
                Por eso mezclamos la tecnología más rápida con la atención más humana.
                La IA hace la parte repetitiva. Las personas hacen la parte que importa.
              </p>
              <p>
                Y como toda buena receta, esto se mejora con tiempo, escucha y un poco de amor.
              </p>
            </div>
          </div>

          <div v-reveal="200" class="flex justify-center">
            <div class="polaroid">
              <div class="p-3 pb-12">
                <div class="aspect-square w-56 sm:w-64 rounded-lg flex items-center justify-center" :style="{ background: 'var(--mg-mango-tint)' }">
                  <MangoFruit :outline="true" class="w-32 h-32" />
                </div>
              </div>
              <p class="absolute bottom-4 left-0 right-0 text-center text-xs font-semibold" :style="{ color: 'var(--mg-fg-dim)' }">
                El ingrediente secreto · MANGO
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Cierre: suscribite al recetario ────────────────────────────── -->
    <section class="relative overflow-hidden px-5 py-24 sm:py-32" :style="{ background: 'var(--mg-mango)' }">
      <div class="absolute -left-16 -bottom-20 w-64 h-64 opacity-[0.12] rotate-12 pointer-events-none">
        <IconCucharaTenedor class="w-full h-full" />
      </div>

      <div class="max-w-3xl mx-auto text-center relative">
        <p v-reveal class="mg-folio mb-6 !text-white/80">Última página</p>
        <h2 v-reveal="80" class="mg-display text-5xl sm:text-7xl leading-[0.95] text-white mb-6">
          Te cuidamos<br>el mango.
        </h2>
        <p v-reveal="200" class="text-lg sm:text-xl text-white/90 mb-10">
          Y cuando
          <span :key="rotatorWord" class="rotator font-semibold" :style="{ color: '#fff', borderBottom: '2px solid rgba(255,255,255,0.6)' }">{{ rotatorWord }}</span>,
          te atiende una persona.
        </p>
        <div v-reveal="280" class="flex flex-col sm:flex-row items-center justify-center gap-3">
          <a :href="primaryCta.href" :target="primaryCta.target" class="mg-btn-primary !px-10 !py-4 !text-sm w-full sm:w-auto !text-[#c94f00]" :style="{ background: '#fff' }">
            {{ primaryCta.label }}
          </a>
          <a :href="secondaryCta.href" :target="secondaryCta.target" class="mg-btn-ghost !px-9 !py-4 !text-sm w-full sm:w-auto !text-white" :style="{ borderColor: 'rgba(255,255,255,0.5)' }">
            {{ secondaryCta.label }}
          </a>
        </div>
        <p v-reveal="360" class="mt-10 mg-folio !text-white/60">
          Compartí esta receta · Buenos Aires · {{ new Date().getFullYear() }}
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
    label: 'Empezar la receta',
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

// ── Ingredientes y pasos ────────────────────────────────────────────────
const ingredientes = [
  '1 auto (cualquier modelo, cualquier año)',
  '1 teléfono con WhatsApp',
  '1 IA cotizadora bien entrenada',
  '1 productor humano de confianza',
  '0 llamadas a 0800',
  '0 formularios infinitos',
  '0 letra chica',
  'Mucha calma, a gusto',
]

const pasos = [
  {
    title: 'Abrir WhatsApp y escribirle a MANGO.',
    body: 'No hace falta instalar nada nuevo. El chat es el lugar más natural del mundo.',
  },
  {
    title: 'Contarle qué auto tenés.',
    body: 'Un par de datos. Nada de cuestionarios eternos. Hablamos como hablan las personas.',
  },
  {
    title: 'Dejar que la IA cocine las cotizaciones.',
    body: 'En minutos, comparás opciones reales de varias compañías. Clara, sin presión.',
  },
  {
    title: 'Elegir la que más te guste.',
    body: 'Vos decidís. Sin vendedor respirándote en la nuca.',
  },
  {
    title: 'Agregar un humano al final.',
    body: 'A partir de acá, tu productor asesor queda a disposición. Siempre el mismo.',
  },
]

const tips = [
  {
    title: 'No agregar vendedores',
    body: 'Arruinan el sabor de cualquier receta. Si aparece uno, descartar inmediatamente.',
  },
  {
    title: 'Servir la app fría',
    body: 'La app de MANGO está hecha para el peor día: granizo, choque, robo, dudas de madrugada.',
  },
  {
    title: 'Conservar en persona',
    body: 'La receta se conserva mejor cuando hay alguien que te conoce del otro lado.',
  },
]

// ── Palabra rotadora ────────────────────────────────────────────────────
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

// ── Isotipo fruta ───────────────────────────────────────────────────────
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

// ── Iconos de cocina hechos a mano ──────────────────────────────────────
const IconCucharaTenedor = () =>
  h('svg', { viewBox: '0 0 100 100', fill: 'none', stroke: 'currentColor', 'stroke-width': 2.5, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' }, [
    h('path', { d: 'M35 20v35c0 12-8 18-8 28M35 20c0-8-6-8-6 0v20M41 20c0-8-6-8-6 0v20' }),
    h('path', { d: 'M72 12v22M66 12v22M78 12v22M72 34c0 14-12 18-12 34v15' }),
  ])

const IconOlla = () =>
  h('svg', { viewBox: '0 0 40 40', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' }, [
    h('path', { d: 'M8 14c0 12 4 20 12 20s12-8 12-20H8Z' }),
    h('path', { d: 'M6 14h28M14 8c2-3 10-3 12 0' }),
    h('path', { d: 'M14 20c2 2 10 2 12 0', stroke: 'var(--mg-mango)' }),
  ])

const IconCuchara = () =>
  h('svg', { viewBox: '0 0 40 40', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' }, [
    h('path', { d: 'M20 6c-6 0-8 6-8 12 0 6 4 8 4 14v4M20 6c6 0 8 6 8 12 0 6-4 8-4 14v4' }),
    h('path', { d: 'M17 32h6', stroke: 'var(--mg-mango)' }),
  ])

// ── Badge de receta ─────────────────────────────────────────────────────
const BadgeReceta = (props: { icon: string }, { slots }: any) => {
  const iconSvg =
    props.icon === 'timer'
      ? h('path', { d: 'M12 8v4l3 3M12 21a9 9 0 100-18 9 9 0 000 18Z', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round' })
      : props.icon === 'yield'
        ? h('path', { d: 'M12 3v18M3 12h18M6 6l12 12M6 18L18 6', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round' })
        : h('path', { d: 'M12 2l3 7h7l-5.5 4 2 7-6.5-4-6.5 4 2-7L2 9h7l3-7Z', fill: 'currentColor' })

  return h('div', { class: 'inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold', style: { background: 'var(--mg-mango-tint)', color: 'var(--mg-mango)' } }, [
    h('svg', { class: 'w-3.5 h-3.5', viewBox: '0 0 24 24', fill: 'none' }, [iconSvg]),
    h('span', {}, slots.default?.()),
  ])
}

// ── Tarjeta de tip ──────────────────────────────────────────────────────
const TipCard = (props: { delay?: number }, { slots }: any) => {
  return h(
    'div',
    {
      class: 'tip-card p-6 rounded-2xl',
      style: {
        background: 'var(--mg-surface)',
        border: '1px solid var(--mg-hairline)',
        transform: `rotate(${(props.delay ?? 0) % 2 === 0 ? '-1deg' : '1deg'})`,
        boxShadow: '0 8px 24px rgba(0,0,0,0.04)',
      },
    },
    [
      h('div', { class: 'w-8 h-8 rounded-full flex items-center justify-center mb-4', style: { background: 'var(--mg-mango-tint)' } }, [
        h('svg', { class: 'w-4 h-4', viewBox: '0 0 24 24', fill: 'none', stroke: 'var(--mg-mango)', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
          h('path', { d: 'M12 3c-4 4-4 10 0 14m0-14c4 4 4 10 0 14M5 8h14M5 16h14' }),
        ]),
      ]),
      h('h3', { class: 'mg-heading text-lg mb-2' }, slots.title?.()),
      h('p', { class: 'text-sm leading-relaxed', style: { color: 'var(--mg-fg-dim)' } }, slots.default?.()),
    ],
  )
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

/* Tarjeta de receta */
.recipe-card {
  border-radius: 20px;
  overflow: hidden;
  box-shadow:
    0 2px 4px rgba(120, 60, 10, 0.04),
    0 16px 40px rgba(120, 60, 10, 0.1);
}
.recipe-card-body {
  background: var(--mg-surface);
}

/* Ingredientes interactivos */
.ingredient-item {
  transition: transform 0.2s ease;
}
.ingredient-item:hover {
  transform: translateX(4px);
}
.ingredient-check {
  transition: background 0.2s ease, color 0.2s ease;
}
.ingredient-item:hover .ingredient-check {
  background: var(--mg-mango);
  color: #fff;
}

/* Tips */
.tip-card {
  transition: transform 0.3s ease;
}
.tip-card:hover {
  transform: rotate(0deg) scale(1.02) !important;
}

/* Polaroid */
.polaroid {
  position: relative;
  background: var(--mg-bg);
  border-radius: 4px;
  box-shadow:
    0 2px 4px rgba(0,0,0,0.04),
    0 12px 28px rgba(0,0,0,0.08);
  border: 1px solid var(--mg-hairline);
  transform: rotate(-2deg);
  transition: transform 0.3s ease;
}
.polaroid:hover {
  transform: rotate(0deg);
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
