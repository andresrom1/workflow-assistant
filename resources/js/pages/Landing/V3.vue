<template>
  <MangoLayout hide-header>
    <Head>
      <title>MANGO — Edición Nº 1</title>
      <meta
        name="description"
        content="Una publicación sobre cuidar lo tuyo sin volverte loco. IA que cotiza. Humano que te cuida."
      />
    </Head>

    <!-- ─── Masthead tipo tapa de revista indie ────────────────────────── -->
    <header
      class="sticky top-0 z-30"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="max-w-6xl mx-auto flex items-center justify-between px-5 h-14">
        <MangoLogo compact :height="24" />
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

    <!-- ─── Portada: póster/zine ───────────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 pt-16 pb-20 sm:pt-24 sm:pb-32">
      <!-- Fondo texturizado tipo papel -->
      <div class="paper-grain absolute inset-0 pointer-events-none opacity-[0.04]"></div>

      <!-- Mango outline gigante, descentrado, como ilustración de tapa -->
      <div class="hero-fruit absolute pointer-events-none select-none -right-20 -top-16 sm:right-0 sm:top-0 w-[320px] h-[320px] sm:w-[520px] sm:h-[520px]">
        <MangoFruit :outline="true" class="w-full h-full" />
      </div>

      <!-- Sello circular -->
      <div class="absolute top-8 left-5 sm:top-12 sm:left-8 pointer-events-none">
        <SelloZine class="w-24 h-24 sm:w-32 sm:h-32" />
      </div>

      <div class="max-w-6xl mx-auto relative">
        <!-- Metadatos de tapa -->
        <div class="flex items-center justify-between mb-12 sm:mb-20">
          <p class="mg-folio">Buenos Aires · {{ new Date().getFullYear() }}</p>
          <p class="mg-folio">Precio: gratis</p>
        </div>

        <!-- Título descentrado, como tapa de revista -->
        <div class="max-w-4xl">
          <p v-reveal class="mg-folio mb-6 sm:mb-8">Edición Nº 1</p>

          <h1 v-reveal="80" class="mg-display text-[3.2rem] leading-[0.95] sm:text-[5.5rem] sm:leading-[0.92] mb-8">
            IA que<br>
            <span :style="{ color: 'var(--mg-mango)' }">cotiza.</span><br>
            Humano<br>
            que te cuida.
          </h1>

          <p v-reveal="160" class="text-lg sm:text-xl leading-relaxed max-w-md mb-12" :style="{ color: 'var(--mg-fg-dim)' }">
            Una publicación sobre cuidar lo tuyo sin volverte loco.
          </p>

          <div v-reveal="240" class="flex flex-col sm:flex-row items-start sm:items-center gap-4">
            <a :href="primaryCta.href" :target="primaryCta.target" class="mg-btn-primary !px-9 !py-4 !text-sm">
              {{ primaryCta.label }}
            </a>
            <a :href="secondaryCta.href" :target="secondaryCta.target" class="mg-btn-ghost !px-9 !py-4 !text-sm">
              {{ secondaryCta.label }}
            </a>
          </div>
        </div>

        <!-- Nota al margen -->
        <div v-reveal="320" class="hidden lg:block absolute right-0 bottom-0 max-w-[220px]">
          <div class="p-4 border-l-2" :style="{ borderColor: 'var(--mg-mango)' }">
            <p class="text-sm leading-relaxed italic" :style="{ color: 'var(--mg-fg-dim)' }">
              "Los seguros son un negocio de miedo. Nosotros lo queremos convertir en un negocio de cuidado."
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Editorial de tapa: carta abierta ───────────────────────────── -->
    <section class="px-5 py-20 sm:py-28" :style="{ background: 'var(--mg-surface)' }">
      <div class="max-w-5xl mx-auto">
        <div class="grid lg:grid-cols-[1.2fr_1fr] gap-12 lg:gap-20 items-start">
          <div>
            <p v-reveal class="mg-folio mb-6">Editorial de tapa</p>
            <h2 v-reveal="80" class="mg-display text-3xl sm:text-4xl leading-tight mb-8">
              No queríamos hacer otra app de seguros.
            </h2>
            <div v-reveal="160" class="space-y-5 text-base leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
              <p>
                Hace unos años, a uno de nosotros le chocaron el auto. Lo que siguió fue un laberinto de 0800,
                formularios y gente que le pedía los mismos datos una y otra vez.
              </p>
              <p>
                Ahí entendimos: el problema no eran los seguros. Era la distancia entre la empresa y la persona.
              </p>
              <p>
                Por eso armamos MANGO. La tecnología se encarga de la parte aburrida — cotizar, comparar, repetir.
                Y las personas se quedan para lo que importa: el día en que algo se rompe.
              </p>
            </div>
            <p v-reveal="240" class="mt-8 font-semibold" :style="{ color: 'var(--mg-fg)' }">
              — El equipo MANGO
            </p>
          </div>

          <div v-reveal="200" class="lg:sticky lg:top-24">
            <div class="p-6 sm:p-8 border-2 border-dashed" :style="{ borderColor: 'var(--mg-hairline-strong)' }">
              <p class="mg-display text-2xl sm:text-3xl leading-tight mb-4">
                "La cotización te la resuelve la tecnología. El mal momento te lo resuelve una persona."
              </p>
              <p class="mg-folio" :style="{ color: 'var(--mg-fg-faint)' }">— Nota al margen</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Viñetas: antes vs ahora ────────────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28">
      <div class="max-w-5xl mx-auto">
        <p v-reveal class="mg-folio mb-4 text-center">Historieta de la semana</p>
        <h2 v-reveal="80" class="mg-display text-3xl sm:text-5xl leading-tight text-center mb-16">
          El seguro, contado en dos viñetas.
        </h2>

        <div class="grid md:grid-cols-2 gap-8 lg:gap-12">
          <ViñetaCard v-reveal rotate="-1.5deg">
            <template #corner>Antes</template>
            <template #title>El clásico</template>
            <div class="space-y-3 text-sm" :style="{ color: 'var(--mg-fg-dim)' }">
              <p>Te vende un vendedor que nunca más aparece.</p>
              <p>Chocás. Llamás a un 0800. Te transfieren tres veces.</p>
              <p>Al final llenás un formulario que "alguien va a revisar".</p>
            </div>
            <template #footer>Una persona sola, contra una máquina.</template>
          </ViñetaCard>

          <ViñetaCard v-reveal="120" rotate="1.5deg" highlight>
            <template #corner>Ahora</template>
            <template #title>El MANGO</template>
            <div class="space-y-3 text-sm" :style="{ color: 'var(--mg-fg-dim)' }">
              <p>Cotizás por WhatsApp, cuando querés, sin presión.</p>
              <p>Te asignamos un productor propio. Una persona real.</p>
              <p>Cuando pasa algo, le escribís directo. Te conoce.</p>
            </div>
            <template #footer>Una persona a tu lado, con tecnología atrás.</template>
          </ViñetaCard>
        </div>
      </div>
    </section>

    <!-- ─── Recorte: el chat como foto pegada ──────────────────────────── -->
    <section class="px-5 py-16 sm:py-24" :style="{ background: 'var(--mg-mango-tint)' }">
      <div class="max-w-4xl mx-auto">
        <p v-reveal class="mg-folio mb-6 text-center">Recorte de pantalla</p>
        <figure v-reveal="80" class="recorte mx-auto max-w-sm">
          <div class="mg-card p-5" :style="{ background: 'var(--mg-surface)' }">
            <div class="flex items-center gap-3 pb-4 mb-4" :style="{ borderBottom: '1px solid var(--mg-hairline)' }">
              <div class="w-9 h-9 rounded-full flex items-center justify-center" :style="{ background: 'var(--mg-mango-tint)' }">
                <MangoFruit :outline="true" class="w-5 h-5" />
              </div>
              <div>
                <p class="text-sm font-semibold leading-none mb-1">MANGO</p>
                <p class="text-[11px] flex items-center gap-1.5" :style="{ color: 'var(--mg-fg-dim)' }">
                  <span class="pulse-dot w-1.5 h-1.5 rounded-full inline-block" :style="{ background: 'var(--mg-ok)' }"></span>
                  Asistente en línea · 24/7
                </p>
              </div>
            </div>
            <div class="space-y-2.5 text-sm min-h-[172px]">
              <div
                v-for="(msg, i) in chatSteps.slice(0, chatVisible)"
                :key="i"
                class="chat-pop rounded-2xl px-4 py-2.5 max-w-[85%]"
                :class="msg.side === 'right' ? 'rounded-tr-md ml-auto' : 'rounded-tl-md'"
                :style="{ background: msg.side === 'right' ? 'var(--mg-mango-tint)' : 'var(--mg-surface-2)' }"
              >
                {{ msg.text }}
              </div>
              <div
                v-if="chatTyping"
                class="chat-pop rounded-2xl rounded-tl-md px-4 py-3 w-fit flex items-center gap-1"
                :style="{ background: 'var(--mg-surface-2)' }"
              >
                <span class="typing-dot"></span>
                <span class="typing-dot" style="animation-delay: 0.15s"></span>
                <span class="typing-dot" style="animation-delay: 0.3s"></span>
              </div>
            </div>
          </div>
          <figcaption class="mt-4 text-center mg-folio" :style="{ color: 'var(--mg-fg-faint)' }">
            Fig. 1 — Cotizar en serio, en un chat.
          </figcaption>
        </figure>
      </div>
    </section>

    <!-- ─── Herramientas del bolsillo (índice numerado) ────────────────── -->
    <section class="px-5 py-20 sm:py-28">
      <div class="max-w-4xl mx-auto">
        <div class="mb-14 text-center">
          <p v-reveal class="mg-folio mb-6">Herramientas del bolsillo</p>
          <h2 v-reveal="80" class="mg-display text-4xl sm:text-5xl leading-tight mb-5">La app, sin la burocracia.</h2>
          <p v-reveal="160" class="mx-auto max-w-lg leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
            La mayoría de las apps de seguros te sirven para pagar. La nuestra te sirve para el resto.
          </p>
        </div>

        <ul>
          <li
            v-for="(feature, i) in appFeatures"
            :key="feature.title"
            v-reveal="i * 70"
            class="index-row group grid grid-cols-[auto_1fr] sm:grid-cols-[auto_auto_1fr] gap-x-5 sm:gap-x-8 gap-y-1 items-start sm:items-baseline py-7"
            :style="{ borderTop: '1px solid var(--mg-hairline)' }"
          >
            <span class="mg-display text-3xl sm:text-4xl leading-none tabular-nums transition-colors" :style="{ color: 'var(--mg-fg-faint)' }">
              {{ String(i + 1).padStart(2, '0') }}
            </span>
            <span class="hand-icon mt-0.5 sm:mt-0 self-center row-start-2 col-start-1 sm:row-start-1 sm:col-start-2" v-html="feature.icon"></span>
            <div class="col-span-2 sm:col-span-1 -mt-1 sm:mt-0">
              <h3 class="mg-heading text-lg mb-1">{{ feature.title }}</h3>
              <p class="text-sm leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">{{ feature.body }}</p>
            </div>
          </li>
        </ul>

        <div v-reveal class="text-center mt-14">
          <a :href="appDownloadUrl ?? '#'" class="mg-btn-primary !px-9 !py-4 !text-sm">Descargá la app</a>
        </div>
      </div>
    </section>

    <!-- ─── La persona: ficha de personaje ─────────────────────────────── -->
    <section class="px-5 py-20 sm:py-28" :style="{ background: 'var(--mg-surface)' }">
      <div class="max-w-4xl mx-auto">
        <p v-reveal class="mg-folio mb-6 text-center">Ficha de personaje</p>
        <h2 v-reveal="80" class="mg-display text-4xl sm:text-5xl leading-tight text-center mb-16">
          Cuando algo pasa, ella es la que responde.
        </h2>

        <div v-reveal="160" class="grid md:grid-cols-[280px_1fr] gap-10 items-center">
          <!-- Polaroid -->
          <div class="polaroid mx-auto md:mx-0">
            <div class="p-3 pb-10" :style="{ background: 'var(--mg-surface)' }">
              <div class="aspect-[4/5] rounded-lg flex items-center justify-center" :style="{ background: 'var(--mg-mango-tint)' }">
                <span class="mg-display text-6xl" :style="{ color: 'var(--mg-mango)' }">M</span>
              </div>
            </div>
            <p class="absolute bottom-3 left-0 right-0 text-center text-xs font-semibold" :style="{ color: 'var(--mg-fg-dim)' }">
              Martina · Productora
            </p>
          </div>

          <div>
            <p class="text-lg leading-relaxed mb-6" :style="{ color: 'var(--mg-fg-dim)' }">
              No es un bot. No es un call center. Es tu productora asesora: la misma persona desde el día uno,
              con tu número guardado y tu historia anotada.
            </p>
            <ul class="space-y-3 text-sm" :style="{ color: 'var(--mg-fg-dim)' }">
              <li class="flex gap-2.5"><IconDot /> Te atiende por WhatsApp, cuando le escribís.</li>
              <li class="flex gap-2.5"><IconDot /> Te conoce. Sabe qué auto tenés y qué te preocupa.</li>
              <li class="flex gap-2.5"><IconDot /> Te acompaña en el siniestro, paso a paso.</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Contraportada: póster final ────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 py-24 sm:py-36" :style="{ background: 'var(--mg-bg)' }">
      <div class="paper-grain absolute inset-0 pointer-events-none opacity-[0.04]"></div>
      <div class="fruit-float-slow absolute pointer-events-none select-none -right-20 -bottom-24 w-[320px] h-[320px] opacity-[0.18] rotate-12">
        <MangoFruit :outline="true" class="w-full h-full" />
      </div>

      <div class="max-w-3xl mx-auto text-center relative">
        <p v-reveal class="mg-folio mb-8">Contraportada</p>
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
        <p v-reveal="360" class="mt-10 mg-folio" :style="{ color: 'var(--mg-fg-faint)' }">
          Gracias por leer · Compartí si te resuena
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
    label: 'Cotizá tu seguro',
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

// ── Chat animado del hero ───────────────────────────────────────────────
type ChatMsg = { side: 'left' | 'right'; text: string }

const chatSteps: ChatMsg[] = [
  { side: 'left', text: 'Hola 👋 Decime qué auto tenés y te cotizo en varias compañías.' },
  { side: 'right', text: 'Un Cruze LT 2022' },
  { side: 'left', text: 'Listo ✓ Ya tengo 3 cotizaciones para mostrarte. ¿Las comparamos?' },
]
const chatVisible = ref(0)
const chatTyping = ref(false)

const rotatorWords = ['tenés un choque', 'te roban', 'tenés una duda', 'cae granizo']
const rotatorWord = ref(rotatorWords[0])
let rotatorIdx = 0
let rotatorTimer: ReturnType<typeof setInterval> | null = null

let timers: ReturnType<typeof setTimeout>[] = []
const after = (ms: number, fn: () => void) => {
  timers.push(setTimeout(fn, ms))
}

const playHeroChat = () => {
  chatVisible.value = 0
  chatTyping.value = true
  after(1100, () => {
    chatTyping.value = false
    chatVisible.value = 1
  })
  after(2200, () => {
    chatVisible.value = 2
  })
  after(3100, () => {
    chatTyping.value = true
  })
  after(4300, () => {
    chatTyping.value = false
    chatVisible.value = 3
  })
  after(7200, playHeroChat)
}

onMounted(() => {
  isDark.value = document.documentElement.getAttribute('data-theme') === 'dark'

  if (reduceMotion) {
    chatVisible.value = chatSteps.length
    return
  }

  window.addEventListener('scroll', checkPendingReveals, { passive: true })
  window.addEventListener('resize', checkPendingReveals)
  requestAnimationFrame(() =>
    requestAnimationFrame(() => {
      checkPendingReveals()
    }),
  )

  playHeroChat()

  rotatorTimer = setInterval(() => {
    rotatorIdx = (rotatorIdx + 1) % rotatorWords.length
    rotatorWord.value = rotatorWords[rotatorIdx]
  }, 2000)
})

onBeforeUnmount(() => {
  timers.forEach(clearTimeout)
  timers = []
  if (rotatorTimer) {
    clearInterval(rotatorTimer)
  }
  revealObserver?.disconnect()
  window.removeEventListener('scroll', checkPendingReveals)
  window.removeEventListener('resize', checkPendingReveals)
})

// ── Bullet naranja ──────────────────────────────────────────────────────
const IconDot = () =>
  h('span', {
    class: 'mt-[7px] w-1.5 h-1.5 shrink-0 rounded-full',
    style: { background: 'var(--mg-mango)' },
  })

// ── Isotipo fruta ───────────────────────────────────────────────────────
// Isotipo decorativo: componente compartido MangoFruit (importado arriba).

// ── Sello circular de tapa de zine ──────────────────────────────────────
const SelloZine = () =>
  h('svg', { viewBox: '0 0 100 100', xmlns: 'http://www.w3.org/2000/svg', 'aria-hidden': 'true' }, [
    h('circle', { cx: 50, cy: 50, r: 47, fill: 'none', stroke: 'var(--mg-mango)', 'stroke-width': 2 }),
    h('path', {
      id: 'circlePath',
      d: 'M 50, 50 m -37, 0 a 37,37 0 1,1 74,0 a 37,37 0 1,1 -74,0',
      fill: 'none',
    }),
    h('text', { fill: 'var(--mg-mango)', 'font-size': '9.5', 'font-family': 'var(--mg-font-mono)', 'letter-spacing': '0.18em', 'text-transform': 'uppercase' }, [
      h('textPath', { href: '#circlePath' }, 'EDICIÓN Nº 1 · MANGO · '),
    ]),
    h('text', {
      x: 50,
      y: 56,
      'text-anchor': 'middle',
      fill: 'var(--mg-mango)',
      'font-family': 'var(--mg-font-display)',
      'font-size': '22',
      'font-weight': '700',
    }, 'M'),
  ])

// ── Viñeta con rotación opcional ────────────────────────────────────────
const ViñetaCard = (props: { rotate?: string; highlight?: boolean }, { slots }: any) => {
  return h(
    'div',
    {
      class: 'viñeta-card p-6 sm:p-8 rounded-2xl relative',
      style: {
        background: props.highlight ? 'var(--mg-mango-tint)' : 'var(--mg-surface)',
        border: '1px solid var(--mg-hairline)',
        transform: `rotate(${props.rotate ?? '0deg'})`,
        boxShadow: '0 12px 32px rgba(0,0,0,0.06)',
      },
    },
    [
      h('div', { class: 'mg-folio mb-4', style: { color: props.highlight ? 'var(--mg-mango)' : 'var(--mg-fg-dim)' } }, slots.corner?.()),
      h('h3', { class: 'mg-display text-2xl mb-4' }, slots.title?.()),
      h('div', { class: 'mb-4' }, slots.default?.()),
      h('p', { class: 'text-xs font-semibold', style: { color: 'var(--mg-fg-faint)' } }, slots.footer?.()),
    ],
  )
}

// ── Iconografía hecha a mano ────────────────────────────────────────────
const appFeatures = [
  {
    title: 'Tus pólizas siempre a mano',
    body: 'La cobertura y la suma asegurada de cada vehículo, de un vistazo. Sin llamar a nadie.',
    icon: `<svg viewBox="0 0 40 40" fill="none" aria-hidden="true">
      <path d="M11 7c-1 4-1.5 12-1 26 6-1.5 11-1.5 18 0 .6-9 .6-18 0-26-5.5 1.4-11 1.4-17 0Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M15 16c3-.6 6-.6 9 0M15 22c2.2-.4 4.4-.4 6.5 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
      <path d="m24.5 26 2 2.2 3.5-4.2" stroke="var(--mg-mango)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>`,
  },
  {
    title: 'Los papeles del auto, sin señal',
    body: 'Licencia, póliza, tarjeta y cédula guardados en tu teléfono. Funcionan sin internet.',
    icon: `<svg viewBox="0 0 40 40" fill="none" aria-hidden="true">
      <path d="M8 12.5c8-1.2 16-1.2 24 0 .5 5 .5 10 0 15-8-1.2-16-1.2-24 0-.6-5-.6-10 0-15Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="15" cy="19" r="2.6" stroke="currentColor" stroke-width="1.7"/>
      <path d="M22 17.5c2.5-.3 4.5-.3 6.5 0M22 22c1.8-.2 3.2-.2 5 0" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
      <path d="M28 8.5c2.6.8 4.4 2.6 5.2 5.2" stroke="var(--mg-mango)" stroke-width="2" stroke-linecap="round"/>
    </svg>`,
  },
  {
    title: 'Un botón para el peor momento',
    body: '“Tuve un siniestro”: guía paso a paso y llamada directa a tu productor. No a un call center.',
    icon: `<svg viewBox="0 0 40 40" fill="none" aria-hidden="true">
      <path d="M20 6c5 2 9 3 13 3-.4 12-4.5 19.5-13 24C11.5 28.5 7.4 21 7 9c4 0 8-1 13-3Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M20 14v6" stroke="var(--mg-mango)" stroke-width="2.2" stroke-linecap="round"/>
      <circle cx="20" cy="24.5" r="1.3" fill="var(--mg-mango)"/>
    </svg>`,
  },
  {
    title: 'Te avisa si viene el granizo',
    body: 'Alertas del Servicio Meteorológico Nacional según dónde estés. Guardás el auto a tiempo.',
    icon: `<svg viewBox="0 0 40 40" fill="none" aria-hidden="true">
      <path d="M12 21c-3.2 0-5-2-5-4.6 0-2.4 1.8-4.3 4.4-4.4C12.4 8.6 15.4 6.5 19 6.7c4 .2 7 3 7.6 6.6 3 .2 5.4 2 5.4 4.8 0 2.6-2.2 4.4-5.4 4.4-4.6-.6-9.6-.6-14.6 0Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M15 27.5v.2M20 26v.2M25 27.5v.2M17.5 31v.2M22.5 31v.2" stroke="var(--mg-mango)" stroke-width="2.6" stroke-linecap="round"/>
    </svg>`,
  },
  {
    title: 'Ayuda de verdad en una emergencia',
    body: 'Compartís tu ubicación en vivo con hasta tres contactos, con un toque.',
    icon: `<svg viewBox="0 0 40 40" fill="none" aria-hidden="true">
      <path d="M20 6c5.2 0 9 3.6 9 8.4C29 21 23 28 20 33c-3-5-9-12-9-18.6C11 9.6 14.8 6 20 6Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="20" cy="14.5" r="3" fill="var(--mg-mango)"/>
    </svg>`,
  },
  {
    title: 'Un auto, toda la familia',
    body: 'Compartís el vehículo en la app con quienes lo manejan. Todos ven la cobertura y el productor.',
    icon: `<svg viewBox="0 0 40 40" fill="none" aria-hidden="true">
      <circle cx="15" cy="15" r="4.2" stroke="currentColor" stroke-width="1.7"/>
      <path d="M7.5 30c.5-4.5 3.8-7 7.5-7s7 2.5 7.5 7c-5 .8-10 .8-15 0Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="27" cy="17" r="3.2" stroke="var(--mg-mango)" stroke-width="1.8"/>
      <path d="M25 30c4-.4 6.5-.5 8.5-.4-.3-3.3-2.4-5.6-5-6" stroke="var(--mg-mango)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>`,
  },
]
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

/* Textura de papel */
.paper-grain {
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
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

/* Hero fruit */
.hero-fruit {
  opacity: 0.75;
  animation: fruit-float 14s ease-in-out infinite;
}

/* Viñeta */
.viñeta-card {
  transition: transform 0.3s ease;
}
.viñeta-card:hover {
  transform: rotate(0deg) scale(1.02) !important;
}

/* Recorte tipo foto pegada */
.recorte {
  transform: rotate(-1deg);
  transition: transform 0.3s ease;
}
.recorte:hover {
  transform: rotate(0deg);
}

/* Polaroid */
.polaroid {
  position: relative;
  transform: rotate(-2deg);
  box-shadow:
    0 2px 4px rgba(0,0,0,0.04),
    0 12px 28px rgba(0,0,0,0.08);
  border-radius: 4px;
  border: 1px solid var(--mg-hairline);
}
.polaroid:hover {
  transform: rotate(0deg);
  transition: transform 0.3s ease;
}

/* Index rows */
.index-row:hover .tabular-nums {
  color: var(--mg-mango) !important;
}
.hand-icon {
  display: inline-flex;
  width: 40px;
  height: 40px;
  color: var(--mg-fg-dim);
  transition: color 0.28s ease, transform 0.28s ease;
}
.hand-icon :deep(svg) {
  width: 100%;
  height: 100%;
}
.index-row:hover .hand-icon {
  color: var(--mg-fg);
  transform: rotate(-4deg) scale(1.06);
}

/* Chat bubbles */
.chat-pop {
  animation: chat-pop 0.34s cubic-bezier(0.22, 1, 0.36, 1) both;
}
@keyframes chat-pop {
  from {
    opacity: 0;
    transform: translateY(8px) scale(0.97);
  }
  to {
    opacity: 1;
    transform: none;
  }
}

.typing-dot {
  width: 6px;
  height: 6px;
  border-radius: 9999px;
  background: var(--mg-fg-faint);
  display: inline-block;
  animation: typing 1.1s ease-in-out infinite;
}
@keyframes typing {
  0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
  30% { transform: translateY(-4px); opacity: 1; }
}

.pulse-dot {
  animation: pulse-dot 1.8s ease-in-out infinite;
}
@keyframes pulse-dot {
  0%, 100% { box-shadow: 0 0 0 0 rgba(0, 168, 107, 0.5); }
  50% { box-shadow: 0 0 0 4px rgba(0, 168, 107, 0); }
}

.fruit-float-slow {
  animation: fruit-float 15s ease-in-out infinite;
}
@keyframes fruit-float {
  0%, 100% { transform: translate(0, 0) rotate(0deg); }
  25% { transform: translate(8px, -14px) rotate(2.5deg); }
  50% { transform: translate(0, -22px) rotate(0deg); }
  75% { transform: translate(-8px, -12px) rotate(-2.5deg); }
}

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
  .chat-pop,
  .typing-dot,
  .pulse-dot,
  .hero-fruit,
  .fruit-float-slow,
  .rotator {
    animation: none;
    transition: none;
  }
}
</style>
