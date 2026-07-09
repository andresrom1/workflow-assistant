<template>
  <MangoLayout hide-header>
    <Head>
      <title>MANGO — IA que cotiza. Humano que te cuida.</title>
      <meta
        name="description"
        content="Cotizás tu seguro de auto en un chat. Y cuando algo pasa de verdad, te atiende una persona: tu productor, siempre el mismo, por WhatsApp."
      />
    </Head>

    <!-- ─── Masthead ────────────────────────────────────────────────────── -->
    <header
      class="sticky top-0 z-30"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="max-w-5xl mx-auto grid grid-cols-[1fr_auto_1fr] items-center px-5 h-14">
        <MangoLogo compact :height="24" />
        <p class="hidden sm:block mg-folio text-center">Seguro de auto · Argentina</p>
        <div class="flex items-center justify-end gap-4">
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
            <span aria-hidden="true">→</span>
          </a>
        </div>
      </div>
    </header>

    <!-- ─── Hero editorial con titular ─────────────────────────────────── -->
    <section class="relative overflow-hidden">
      <div class="hero-fruit absolute pointer-events-none select-none top-0 -right-2 sm:top-0 sm:right-6 w-[200px] h-[200px] sm:w-[320px] sm:h-[320px]">
        <MangoFruit :outline="true" class="w-full h-full" />
      </div>

      <div class="max-w-3xl mx-auto px-5 pt-24 pb-20 sm:pt-32 sm:pb-24 relative text-center">
        <p v-reveal class="mg-folio mb-8">El seguro, reescrito</p>

        <h1 v-reveal="80" class="mg-display text-[2.75rem] leading-[1.02] sm:text-7xl sm:leading-[0.98] mb-8">
          IA que cotiza.<br>
          <span class="hero-ink" :style="{ color: 'var(--mg-mango)' }">Humano que&nbsp;te&nbsp;cuida.</span>
        </h1>

        <p v-reveal="160" class="mx-auto max-w-xl text-lg sm:text-xl leading-relaxed mb-11" :style="{ color: 'var(--mg-fg-dim)' }">
          Cotizás tu seguro de auto en minutos, en un chat.
          Y cuando lo necesitás de verdad, hablás con una persona real.
          <span :style="{ color: 'var(--mg-fg)' }">Siempre la misma.</span>
        </p>

        <div v-reveal="240" class="flex flex-col sm:flex-row items-center justify-center gap-3">
          <a :href="primaryCta.href" :target="primaryCta.target" class="mg-btn-primary !px-9 !py-4 !text-sm w-full sm:w-auto">
            {{ primaryCta.label }}
          </a>
          <a :href="secondaryCta.href" :target="secondaryCta.target" class="mg-btn-ghost !px-9 !py-4 !text-sm w-full sm:w-auto">
            {{ secondaryCta.label }}
          </a>
        </div>
        <p v-reveal="320" class="mt-6 mg-folio" :style="{ color: 'var(--mg-fg-faint)' }">Rinde: 1 familia tranquila · Gratis</p>
      </div>
    </section>

    <!-- ─── Fig 1 · El chat (cotización, producto rápido) ────────────────── -->
    <section class="px-5 pb-8">
      <figure class="max-w-md mx-auto">
        <div class="chat-frame mg-card p-5">
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
          Fig. 1 — Cotizar. Así de simple, en serio.
        </figcaption>
      </figure>
    </section>

    <!-- ─── Bisagra · Acá no vendemos seguros ────────────────────────────── -->
    <section class="px-5 py-24">
      <div class="max-w-3xl mx-auto">
        <p v-reveal class="mg-folio mb-8 text-center">Cómo lo pensamos</p>
        <h2 v-reveal="80" class="mg-display text-3xl sm:text-[2.6rem] leading-[1.12] text-center mb-9">
          Acá no vendemos seguros.
          <span :style="{ color: 'var(--mg-mango)' }">Cuidamos lo tuyo.</span>
        </h2>
        <blockquote v-reveal="160" class="mx-auto max-w-xl text-center text-lg sm:text-xl leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
          La cotización te la resuelve la tecnología.
          <span :style="{ color: 'var(--mg-fg)' }" class="font-semibold">El mal momento te lo resuelve una persona.</span>
        </blockquote>

        <!-- Spread asimétrico: antes / ahora -->
        <div class="mt-16 grid sm:grid-cols-[0.85fr_1.15fr] gap-px rounded-2xl overflow-hidden" :style="{ background: 'var(--mg-hairline)' }">
          <div v-reveal class="p-8 sm:p-10" :style="{ background: 'var(--mg-surface)' }">
            <p class="mg-folio mb-5" :style="{ color: 'var(--mg-fg-faint)' }">El seguro de antes</p>
            <ul class="space-y-3 text-sm leading-relaxed" :style="{ color: 'var(--mg-fg-faint)' }">
              <li class="line-through">Un vendedor que aparecía una vez.</li>
              <li class="line-through">Un 0800 el día del choque.</li>
              <li class="line-through">Letra chica y sorpresas.</li>
              <li class="line-through">Nadie que te conociera.</li>
            </ul>
          </div>
          <div v-reveal="120" class="p-8 sm:p-11" :style="{ background: 'var(--mg-mango-tint)' }">
            <p class="mg-folio mb-5" :style="{ color: 'var(--mg-mango)' }">El seguro MANGO</p>
            <h3 class="mg-display text-2xl sm:text-3xl mb-5">Una IA que cotiza. Un humano que te cuida.</h3>
            <ul class="space-y-3 text-[15px] leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
              <li class="flex gap-2.5"><IconDot /> Cotizás en varias compañías en un solo chat, 24/7.</li>
              <li class="flex gap-2.5"><IconDot /> Tu productor asesor propio desde el día uno.</li>
              <li class="flex gap-2.5"><IconDot /> Por WhatsApp, directo. Siempre el mismo.</li>
              <li class="flex gap-2.5"><IconDot /> Sin vendedores, sin presión, sin letra chica.</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── La app · índice numerado ─────────────────────────────────────── -->
    <section class="px-5 py-24" :style="{ background: 'var(--mg-surface)' }">
      <div class="max-w-3xl mx-auto">
        <div class="mb-14 text-center">
          <p v-reveal class="mg-folio mb-6">La app MANGO</p>
          <h2 v-reveal="80" class="mg-display text-4xl sm:text-5xl leading-tight mb-5">Tu seguro, en el bolsillo.</h2>
          <p v-reveal="160" class="mx-auto max-w-lg leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
            La mayoría de las apps de seguros te sirven para pagar.
            La nuestra te sirve para el resto: los papeles, el granizo, el peor día.
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

    <!-- ─── Cuando se quema todo · emergencia (sin receta) ───────────────── -->
    <section class="relative overflow-hidden px-5 py-24" :style="{ background: 'var(--mg-mango-tint)' }">
      <div class="fruit-float-slow absolute pointer-events-none select-none -left-24 -bottom-24 w-[300px] h-[300px] opacity-[0.45] -rotate-12">
        <MangoFruit :outline="true" class="w-full h-full" />
      </div>
      <div class="max-w-4xl mx-auto relative">
        <div class="text-center mb-16">
          <p v-reveal class="mg-folio mb-6" :style="{ color: 'var(--mg-mango)' }">Cuando se quema todo</p>
          <h2 v-reveal="80" class="mg-display text-4xl sm:text-6xl leading-[1.04] mb-6">
            El peor día,<br>te atiende una persona.
          </h2>
          <p v-reveal="160" class="mx-auto max-w-lg leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
            Cotizar lo puede hacer una IA. Pero un choque, un robo, una duda a la noche —
            eso lo resuelve alguien que te conoce. Tu productor asesor. Siempre el mismo.
          </p>
        </div>

        <div class="grid lg:grid-cols-2 gap-10 lg:gap-14 items-center">
          <!-- Pasos calmos -->
          <ol class="space-y-6">
            <li
              v-for="(paso, i) in emergencySteps"
              :key="paso.t"
              v-reveal="i * 90"
              class="flex gap-4"
            >
              <span class="w-8 h-8 shrink-0 rounded-full flex items-center justify-center text-sm font-bold" :style="{ background: 'var(--mg-mango)', color: '#fff' }">
                {{ i + 1 }}
              </span>
              <div>
                <h3 class="mg-heading mb-1">{{ paso.t }}</h3>
                <p class="text-sm leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">{{ paso.b }}</p>
              </div>
            </li>
          </ol>

          <!-- Chat real de la productora -->
          <figure v-reveal="120">
            <div ref="pasChatEl" class="chat-frame mg-card p-5">
              <div class="flex items-center gap-3 pb-4 mb-4" :style="{ borderBottom: '1px solid var(--mg-hairline)' }">
                <div class="w-9 h-9 rounded-full flex items-center justify-center font-semibold text-sm" :style="{ background: 'var(--mg-mango)', color: '#fff' }">M</div>
                <div>
                  <p class="text-sm font-semibold leading-none mb-1">Martina · Tu productora</p>
                  <p class="text-[11px] flex items-center gap-1.5" :style="{ color: 'var(--mg-fg-dim)' }">
                    <span class="pulse-dot w-1.5 h-1.5 rounded-full inline-block" :style="{ background: 'var(--mg-ok)' }"></span>
                    En línea
                  </p>
                </div>
              </div>
              <div class="space-y-2.5 text-sm min-h-[220px]">
                <div
                  v-for="(msg, i) in pasSteps.slice(0, pasVisible)"
                  :key="i"
                  class="chat-pop rounded-2xl px-4 py-2.5"
                  :class="[msg.side === 'right' ? 'rounded-tr-md ml-auto' : 'rounded-tl-md', msg.wide ? 'max-w-[90%]' : 'w-fit']"
                  :style="{ background: msg.side === 'right' ? 'var(--mg-mango-tint)' : 'var(--mg-surface-2)' }"
                >
                  {{ msg.text }}
                </div>
                <div
                  v-if="pasTyping"
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
              Nunca un 0800, ni un formulario, ni un bot. Una persona.
            </figcaption>
          </figure>
        </div>
      </div>
    </section>

    <!-- ─── Banner · Fomentamos que hables con tu productor ──────────────── -->
    <section class="px-5 py-16">
      <div class="max-w-3xl mx-auto">
        <div v-reveal class="flex items-start gap-4 sm:gap-5 p-5 sm:p-7 rounded-2xl" :style="{ border: '2px solid var(--mg-mango)', background: 'var(--mg-surface)' }">
          <div class="advertencia-triangulo flex-shrink-0 w-10 h-10 flex items-center justify-center" :style="{ background: 'var(--mg-mango)' }">
            <span class="text-white font-bold text-lg leading-none">!</span>
          </div>
          <div>
            <p class="mg-folio mb-2" :style="{ color: 'var(--mg-mango)' }">Aviso importante</p>
            <h3 class="mg-heading text-lg sm:text-xl mb-2">No te quedes con ninguna duda.</h3>
            <p class="text-sm sm:text-base leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
              Para eso está tu productor: escribile por lo que sea, cuando sea. Una cobertura que no entendés,
              una duda de madrugada, un "che, ¿esto me conviene?". Preguntar es tuyo y es gratis —
              <span :style="{ color: 'var(--mg-fg)' }" class="font-semibold">y nosotros lo fomentamos.</span>
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Cierre firma ─────────────────────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 py-28">
      <div class="fruit-float absolute pointer-events-none select-none -right-16 -bottom-24 w-[300px] h-[300px] opacity-[0.18] rotate-12">
        <MangoFruit :outline="true" class="w-full h-full" />
      </div>
      <div class="max-w-2xl mx-auto text-center relative">
        <p v-reveal class="mg-folio mb-8">El mango es tuyo</p>
        <h2 v-reveal="80" class="mg-display text-6xl sm:text-8xl leading-[0.98] mb-9">
          Te cuidamos<br><span :style="{ color: 'var(--mg-mango)' }">el mango.</span>
        </h2>
        <p v-reveal="200" class="text-lg font-medium mb-11" :style="{ color: 'var(--mg-fg-dim)' }">
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
        <p v-reveal="360" class="mt-10 mg-folio" :style="{ color: 'var(--mg-fg-faint)' }">— El equipo MANGO</p>
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

// ── Chats animados ───────────────────────────────────────────────────────
type ChatMsg = { side: 'left' | 'right'; text: string; wide?: boolean }

const chatSteps: ChatMsg[] = [
  { side: 'left', text: 'Hola 👋 Decime qué auto tenés y te cotizo en varias compañías.' },
  { side: 'right', text: 'Un Cruze LT 2022' },
  { side: 'left', text: 'Listo ✓ Ya tengo 3 cotizaciones para mostrarte. ¿Las comparamos?' },
]
const chatVisible = ref(0)
const chatTyping = ref(false)

const pasSteps: ChatMsg[] = [
  { side: 'right', text: 'Hola Martina, tuve un choque 😟', wide: true },
  { side: 'left', text: 'Tranqui, estoy con vos. ¿Estás bien? ¿Hay alguien lastimado?', wide: true },
  { side: 'right', text: 'Estoy bien, sólo el auto' },
  { side: 'left', text: 'Perfecto. Sacale fotos a los daños. Yo ya te inicio el trámite y en 5 min te llamo. 📞', wide: true },
]
const pasVisible = ref(0)
const pasTyping = ref(false)
const pasChatEl = ref<HTMLElement | null>(null)

// ── Pasos del peor momento (de V4, sin lenguaje de receta) ───────────────
const emergencySteps = [
  { t: 'Respirá.', b: 'Lo material se arregla. Vos primero.' },
  { t: 'Sacá fotos.', b: 'Del choque, del lugar, de los daños. Sin apuro.' },
  { t: 'Escribile a tu productor.', b: 'Una persona real, que te conoce, te dice qué hacer.' },
  { t: 'Listo. Nos encargamos.', b: 'Iniciamos el trámite y te llamamos en 5 minutos.' },
]

// ── Palabra rotativa del cierre ─────────────────────────────────────────
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

let pasStarted = false
const maybePlayPas = () => {
  if (pasStarted || !pasChatEl.value || !isInViewport(pasChatEl.value)) {
    return
  }
  pasStarted = true
  let t = 400
  pasSteps.forEach((msg) => {
    if (msg.side === 'left') {
      after(t, () => (pasTyping.value = true))
      t += 1200
      after(t, () => {
        pasTyping.value = false
        pasVisible.value += 1
      })
    } else {
      after(t, () => (pasVisible.value += 1))
      t += 900
    }
  })
}

const onScroll = () => {
  checkPendingReveals()
  maybePlayPas()
}

onMounted(() => {
  isDark.value = document.documentElement.getAttribute('data-theme') === 'dark'

  if (reduceMotion) {
    chatVisible.value = chatSteps.length
    pasVisible.value = pasSteps.length
    return
  }

  window.addEventListener('scroll', onScroll, { passive: true })
  window.addEventListener('resize', checkPendingReveals)
  requestAnimationFrame(() =>
    requestAnimationFrame(() => {
      checkPendingReveals()
      maybePlayPas()
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
  window.removeEventListener('scroll', onScroll)
  window.removeEventListener('resize', checkPendingReveals)
})

// ── Bullet naranja ──────────────────────────────────────────────────────
const IconDot = () =>
  h('span', {
    class: 'mt-[7px] w-1.5 h-1.5 shrink-0 rounded-full',
    style: { background: 'var(--mg-mango)' },
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

.hero-ink {
  position: relative;
  display: inline-block;
}
.hero-ink::after {
  content: '';
  position: absolute;
  left: 2%;
  right: 2%;
  bottom: -0.12em;
  height: 0.09em;
  border-radius: 999px;
  background: var(--mg-mango);
  transform: scaleX(0);
  transform-origin: left;
}
.reveal-in .hero-ink::after {
  animation: ink 0.7s cubic-bezier(0.22, 1, 0.36, 1) forwards 0.35s;
}
@keyframes ink {
  to {
    transform: scaleX(1);
  }
}

.chat-frame {
  box-shadow:
    0 2px 4px rgba(120, 60, 10, 0.05),
    0 12px 28px rgba(120, 60, 10, 0.08),
    0 32px 64px rgba(120, 60, 10, 0.09);
  animation: card-breathe 7s ease-in-out infinite;
}
@keyframes card-breathe {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-5px);
  }
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
.index-row:hover .tabular-nums {
  color: var(--mg-mango) !important;
}

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
  0%,
  60%,
  100% {
    transform: translateY(0);
    opacity: 0.4;
  }
  30% {
    transform: translateY(-4px);
    opacity: 1;
  }
}

.pulse-dot {
  animation: pulse-dot 1.8s ease-in-out infinite;
}
@keyframes pulse-dot {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgba(0, 168, 107, 0.5);
  }
  50% {
    box-shadow: 0 0 0 4px rgba(0, 168, 107, 0);
  }
}

.hero-fruit {
  opacity: 0.8;
  animation: fruit-float 12s ease-in-out infinite;
}
.fruit-float {
  animation: fruit-float 11s ease-in-out infinite;
}
.fruit-float-slow {
  animation: fruit-float 15s ease-in-out infinite;
}
@keyframes fruit-float {
  0%,
  100% {
    transform: translate(0, 0) rotate(0deg);
  }
  25% {
    transform: translate(8px, -14px) rotate(2.5deg);
  }
  50% {
    transform: translate(0, -22px) rotate(0deg);
  }
  75% {
    transform: translate(-8px, -12px) rotate(-2.5deg);
  }
}

/* Triángulo del banner (de V5, en mango) */
.advertencia-triangulo {
  clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
  border-radius: 2px;
}

.rotator {
  display: inline-block;
  animation: rotator-in 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
}
@keyframes rotator-in {
  from {
    opacity: 0;
    transform: translateY(8px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
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
  .fruit-float,
  .fruit-float-slow,
  .hero-fruit,
  .chat-frame,
  .rotator {
    animation: none;
    transition: none;
  }
  .hero-ink::after {
    transform: scaleX(1);
    animation: none;
  }
}
</style>
