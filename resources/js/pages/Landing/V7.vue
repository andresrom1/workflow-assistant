<template>
  <MangoLayout hide-header>
    <Head>
      <title>MANGO — El faro</title>
      <meta
        name="description"
        content="Un faro en la ruta. Un taller donde te cuidan. Una persona cuando más lo necesitás."
      />
    </Head>

    <!-- ─── Header tipo señal de ruta ─────────────────────────────────── -->
    <header
      class="sticky top-0 z-30"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="max-w-6xl mx-auto flex items-center justify-between px-5 h-14">
        <MangoLogo compact :height="22" />
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

    <!-- ─── Hero: cartel de ruta ──────────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 pt-20 pb-24 sm:pt-32 sm:pb-40" :style="{ background: 'var(--mg-bg)' }">
      <!-- Sol/faro de fondo -->
      <div class="absolute -right-32 -top-32 w-[500px] h-[500px] sm:w-[700px] sm:h-[700px] pointer-events-none">
        <SolFaro />
      </div>

      <div class="max-w-6xl mx-auto relative">
        <div class="max-w-3xl">
          <p v-reveal class="font-mono text-xs uppercase tracking-[0.3em] mb-8" :style="{ color: 'var(--mg-fg-faint)' }">
            Ruta 3 · Km 14 · Buenos Aires
          </p>

          <h1 v-reveal="80" class="font-sans text-[3.5rem] sm:text-[7rem] leading-[0.9] font-extrabold mb-8 tracking-tight" :style="{ color: 'var(--mg-fg)' }">
            Un faro<br>
            <span :style="{ color: 'var(--mg-mango)' }">en la ruta.</span>
          </h1>

          <p v-reveal="160" class="text-xl sm:text-2xl leading-snug max-w-xl mb-12" :style="{ color: 'var(--mg-fg-dim)' }">
            Un taller donde te cuidan. Una persona cuando más lo necesitás.
          </p>

          <div v-reveal="240" class="flex flex-col sm:flex-row gap-3">
            <a :href="primaryCta.href" :target="primaryCta.target" class="mg-btn-primary !px-9 !py-4 !text-sm w-full sm:w-auto text-center">
              {{ primaryCta.label }}
            </a>
            <a :href="secondaryCta.href" :target="secondaryCta.target" class="mg-btn-ghost !px-9 !py-4 !text-sm w-full sm:w-auto text-center">
              {{ secondaryCta.label }}
            </a>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── El taller ─────────────────────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28" :style="{ background: 'var(--mg-surface)' }">
      <div class="max-w-6xl mx-auto">
        <div class="grid lg:grid-cols-[1.2fr_1fr] gap-16 items-center">
          <div>
            <p v-reveal class="font-mono text-xs uppercase tracking-[0.3em] mb-6" :style="{ color: 'var(--mg-mango)' }">El taller</p>
            <h2 v-reveal="80" class="mg-display text-4xl sm:text-6xl leading-[1] mb-8">
              Acá no hay<br>mostradores de acrílico.
            </h2>
            <div v-reveal="160" class="space-y-5 text-lg leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
              <p>
                MANGO es un obrador. Un lugar con herramientas, con un gato hidráulico, con grasa en las
                manos. Pero en vez de reparar motores, reparamos preocupaciones.
              </p>
              <p>
                Cuando llegás, no te atiende un sistema. Te atiende alguien que escucha, que anota tu
                historia en una libretita, y que después se acuerda de cómo te llamás.
              </p>
            </div>
          </div>

          <div v-reveal="200" class="relative">
            <div class="aspect-square w-full max-w-md mx-auto flex items-center justify-center" :style="{ background: 'var(--mg-mango-tint)' }">
              <MangoFruit :outline="true" class="w-3/4 h-3/4" />
            </div>
            <p class="mt-4 text-center font-mono text-xs uppercase tracking-widest" :style="{ color: 'var(--mg-fg-faint)' }">
              Escudo · MANGO · 2026
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── La ruta (hitos) ───────────────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28">
      <div class="max-w-4xl mx-auto">
        <p v-reveal class="font-mono text-xs uppercase tracking-[0.3em] mb-6 text-center" :style="{ color: 'var(--mg-mango)' }">La ruta</p>
        <h2 v-reveal="80" class="mg-display text-4xl sm:text-5xl leading-tight text-center mb-20">
          Cuatro hitos en el camino.
        </h2>

        <ol class="space-y-12 relative">
          <!-- Línea conectora -->
          <div class="absolute left-[19px] sm:left-[27px] top-3 bottom-3 w-px hidden sm:block" :style="{ background: 'var(--mg-hairline-strong)' }"></div>

          <li v-for="(hito, i) in hitos" :key="i" v-reveal="i * 100" class="relative flex gap-6 sm:gap-8 items-start">
            <div class="flex-shrink-0 w-10 h-10 sm:w-14 sm:h-14 rounded-full flex items-center justify-center font-bold text-sm sm:text-base" :style="{ background: 'var(--mg-mango)', color: '#fff' }">
              {{ String(i + 1).padStart(2, '0') }}
            </div>
            <div class="flex-1 pt-1 sm:pt-3">
              <p class="font-mono text-[10px] uppercase tracking-[0.25em] mb-1" :style="{ color: 'var(--mg-mango)' }">Km {{ hito.km }}</p>
              <h3 class="mg-display text-2xl sm:text-3xl mb-2">{{ hito.titulo }}</h3>
              <p class="text-base leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">{{ hito.body }}</p>
            </div>
          </li>
        </ol>
      </div>
    </section>

    <!-- ─── El faro (sección full-bleed) ──────────────────────────────── -->
    <section class="relative overflow-hidden px-5 py-32 sm:py-40" :style="{ background: 'var(--mg-mango)' }">
      <!-- Faro de fondo -->
      <div class="absolute -right-20 -bottom-40 w-[400px] h-[600px] sm:w-[500px] sm:h-[800px] opacity-25 pointer-events-none">
        <FaroSvg class="w-full h-full" />
      </div>

      <div class="max-w-4xl mx-auto relative">
        <p v-reveal class="font-mono text-xs uppercase tracking-[0.3em] mb-6" :style="{ color: 'rgba(255,255,255,0.7)' }">El faro</p>
        <h2 v-reveal="80" class="mg-display text-5xl sm:text-7xl leading-[0.95] text-white mb-8">
          Cuando la tormenta<br>no te deja ver.
        </h2>
        <p v-reveal="160" class="text-xl leading-relaxed text-white/90 max-w-xl mb-10">
          MANGO es la luz que se mantiene firme cuando el seguro tradicional te deja a oscuras.
          Línea directa con una persona, todos los días, a toda hora.
        </p>
        <div v-reveal="240" class="grid sm:grid-cols-3 gap-6 max-w-2xl">
          <div v-for="dato in datosFaro" :key="dato.label" class="p-5 bg-white/10 backdrop-blur-sm rounded-lg" :style="{ borderLeft: '3px solid rgba(255,255,255,0.7)' }">
            <p class="font-display text-3xl text-white mb-1" :style="{ fontFamily: 'var(--mg-font-display)' }">{{ dato.valor }}</p>
            <p class="font-mono text-[10px] uppercase tracking-widest text-white/70">{{ dato.label }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── El equipo ─────────────────────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28" :style="{ background: 'var(--mg-bg)' }">
      <div class="max-w-5xl mx-auto">
        <p v-reveal class="font-mono text-xs uppercase tracking-[0.3em] mb-6 text-center" :style="{ color: 'var(--mg-fg-faint)' }">La cuadrilla</p>
        <h2 v-reveal="80" class="mg-display text-4xl sm:text-5xl leading-tight text-center mb-20">
          La gente del taller.
        </h2>

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
          <PersonaCard v-for="(p, i) in equipo" :key="i" v-reveal="i * 80" :inicial="p.inicial" :nombre="p.nombre" :rol="p.rol" :bio="p.bio" :color="p.color" />
        </div>
      </div>
    </section>

    <!-- ─── Cierre: salí a la ruta ────────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 py-24 sm:py-36">
      <div class="max-w-3xl mx-auto text-center">
        <p v-reveal class="font-mono text-xs uppercase tracking-[0.3em] mb-6" :style="{ color: 'var(--mg-fg-faint)' }">Indicaciones finales</p>
        <h2 v-reveal="80" class="mg-display text-5xl sm:text-7xl leading-[0.95] mb-8">
          Te cuidamos<br>
          <span :style="{ color: 'var(--mg-mango)' }">el mango.</span>
        </h2>
        <p v-reveal="200" class="text-lg sm:text-xl mb-12" :style="{ color: 'var(--mg-fg-dim)' }">
          Y cuando
          <span :key="rotatorWord" class="rotator font-semibold" :style="{ color: 'var(--mg-fg)', borderBottom: '2px solid var(--mg-mango)' }">{{ rotatorWord }}</span>,
          te atiende una persona.
        </p>
        <div v-reveal="280" class="flex flex-col sm:flex-row items-center justify-center gap-3">
          <a :href="primaryCta.href" :target="primaryCta.target" class="mg-btn-primary !px-10 !py-4 !text-sm w-full sm:w-auto">
            {{ primaryCta.label }}
          </a>
          <a :href="secondaryCta.href" :target="secondaryCta.target" class="mg-btn-ghost !px-9 !py-4 !text-sm w-full sm:w-auto">
            {{ secondaryCta.label }}
          </a>
        </div>
        <p v-reveal="360" class="mt-12 font-mono text-xs uppercase tracking-widest" :style="{ color: 'var(--mg-fg-faint)' }">
          Próxima salida · MANGO Insurance · Buenos Aires
        </p>
      </div>
    </section>

    <!-- ─── Footer ─────────────────────────────────────────────────────── -->
    <footer class="px-5 py-10" :style="{ borderTop: '1px solid var(--mg-hairline)' }">
      <div class="max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-5">
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
import MangoFruit from '@/components/Mango/MangoFruit.vue'

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
    label: 'Empezá la ruta',
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

const hitos = [
  { km: 0, titulo: 'Salida', body: 'Te comunicás con MANGO por WhatsApp. Sin formularios eternos, sin letra chica.' },
  { km: 3, titulo: 'Cruce', body: 'La IA cotiza en 20+ aseguradoras. Te muestra las opciones en un solo lugar.' },
  { km: 7, titulo: 'Encuentro', body: 'Elegís la cobertura. Te asignamos un productor humano propio.' },
  { km: 14, titulo: 'Destino', body: 'A partir de acá, tenés una persona para el día a día y para el peor momento.' },
]

const datosFaro = [
  { valor: '< 5 min', label: 'Tiempo de respuesta' },
  { valor: '24/7', label: 'Atención humana' },
  { valor: '1', label: 'Productor asignado' },
]

const equipo = [
  { inicial: 'A', nombre: 'Andrés Romero', rol: 'Productor · Socio', bio: 'Trabajó 8 años en seguros hasta que se cansó de los 0800.', color: 'var(--mg-mango)' },
  { inicial: 'M', nombre: 'Martina Suárez', rol: 'Productora · Postventa', bio: 'Atiende siniestros de madrugada porque alguien tiene que estar.', color: 'var(--mg-mango)' },
  { inicial: 'L', nombre: 'Lucía Ferreyra', rol: 'Atención al cliente', bio: 'Se acuerda del nombre de tu señora y de la patente de tu auto.', color: 'var(--mg-mango)' },
  { inicial: 'D', nombre: 'Diego Iturbe', rol: 'Cotizaciones', bio: 'La IA le tiene miedo. Encuentra precios que no deberían existir.', color: 'var(--mg-mango)' },
  { inicial: 'C', nombre: 'Camila Pérez', rol: 'Renovaciones', bio: 'Te avisa un mes antes para que no te agarre el apuro.', color: 'var(--mg-mango)' },
  { inicial: 'R', nombre: 'Ramiro Vázquez', rol: 'Siniestros', bio: 'Llamó a la aseguradora un domingo a las 11pm. Sí, un domingo.', color: 'var(--mg-mango)' },
]

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
// Isotipo decorativo: componente compartido MangoFruit (importado arriba).

// ── Sol/faro del hero ─────────────────────────────────────────────────
const SolFaro = () =>
  h('svg', { viewBox: '0 0 200 200', xmlns: 'http://www.w3.org/2000/svg', 'aria-hidden': 'true' }, [
    h('circle', { cx: 100, cy: 100, r: 50, fill: 'var(--mg-mango)' }),
    h('circle', { cx: 100, cy: 100, r: 70, fill: 'none', stroke: 'var(--mg-mango)', 'stroke-width': 1, opacity: 0.5 }),
    h('circle', { cx: 100, cy: 100, r: 90, fill: 'none', stroke: 'var(--mg-mango)', 'stroke-width': 1, opacity: 0.3 }),
  ])

// ── Faro full-bleed ────────────────────────────────────────────────────
const FaroSvg = () =>
  h('svg', { viewBox: '0 0 200 400', fill: 'none', xmlns: 'http://www.w3.org/2000/svg', 'aria-hidden': 'true' }, [
    h('path', { d: 'M85 80 L115 80 L110 70 L90 70 Z', fill: 'rgba(255,255,255,0.7)' }),
    h('rect', { x: 90, y: 80, width: 20, height: 6, fill: 'rgba(255,255,255,0.7)' }),
    h('path', { d: 'M80 86 L120 86 L130 180 L70 180 Z', fill: 'rgba(255,255,255,0.7)' }),
    h('rect', { x: 85, y: 120, width: 30, height: 25, fill: 'var(--mg-bg)' }),
    h('path', { d: 'M88 145 L112 145', stroke: 'rgba(255,255,255,0.7)', 'stroke-width': 1.5 }),
    h('rect', { x: 70, y: 180, width: 60, height: 14, fill: 'rgba(255,255,255,0.7)' }),
    h('path', { d: 'M60 194 L140 194 L150 380 L50 380 Z', fill: 'rgba(255,255,255,0.7)' }),
    h('rect', { x: 80, y: 240, width: 40, height: 30, fill: 'var(--mg-bg)' }),
    h('rect', { x: 100, y: 320, width: 20, height: 60, fill: 'var(--mg-bg)' }),
  ])

// ── Tarjeta de persona ────────────────────────────────────────────────
const PersonaCard = (props: { inicial: string; nombre: string; rol: string; bio: string; color: string }) => {
  return h('div', { class: 'persona-card p-6', style: { background: 'var(--mg-surface)', border: '1px solid var(--mg-hairline)' } }, [
    h('div', { class: 'flex items-center gap-4 mb-4' }, [
      h('div', { class: 'w-14 h-14 rounded-full flex items-center justify-center text-white font-bold text-2xl', style: { background: props.color, fontFamily: 'var(--mg-font-display)' } }, props.inicial),
      h('div', null, [
        h('p', { class: 'mg-heading text-base' }, props.nombre),
        h('p', { class: 'font-mono text-[10px] uppercase tracking-widest mt-0.5', style: { color: 'var(--mg-fg-faint)' } }, props.rol),
      ]),
    ]),
    h('p', { class: 'text-sm leading-relaxed', style: { color: 'var(--mg-fg-dim)' } }, props.bio),
  ])
}
</script>

<style scoped>
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

.persona-card {
  transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.persona-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 12px 28px rgba(0,0,0,0.06);
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
