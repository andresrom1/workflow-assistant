<template>
  <MangoLayout hide-header>
    <Head>
      <title>MANGO — IA que cotiza. Humano que te cuida.</title>
      <meta
        name="description"
        content="Cotizá tu seguro de auto en minutos, en un chat. Y después de contratar, tu productor asesor propio: una persona real, por WhatsApp, siempre la misma."
      />
    </Head>

    <!-- ─── Header ─────────────────────────────────────────────────────── -->
    <header
      class="sticky top-0 z-30 backdrop-blur"
      :style="{ background: 'color-mix(in srgb, var(--mg-bg) 88%, transparent)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="max-w-6xl mx-auto flex items-center justify-between px-5 py-3.5">
        <MangoLogo compact :height="26" />
        <div class="flex items-center gap-2 sm:gap-4">
          <button
            type="button"
            class="w-9 h-9 rounded-full flex items-center justify-center cursor-pointer transition-colors"
            :style="{ color: 'var(--mg-fg-dim)', border: '1px solid var(--mg-hairline)' }"
            :aria-label="isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
            @click="toggleTheme"
          >
            <svg v-if="isDark" class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <svg v-else class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
          </button>
          <a
            href="/login"
            class="hidden sm:block text-xs font-semibold tracking-wide hover:underline"
            :style="{ color: 'var(--mg-fg-dim)' }"
          >
            Ingresar
          </a>
          <a :href="primaryCta.href" class="mg-btn-primary !px-5 !py-2.5" :target="primaryCta.target">
            {{ primaryCta.shortLabel }}
          </a>
        </div>
      </div>
    </header>

    <!-- ─── Hero ───────────────────────────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 pt-14 pb-20 sm:pt-20 sm:pb-28">
      <!-- Fruta gigante de fondo (bob + deriva + balanceo) -->
      <div class="fruit-float absolute pointer-events-none select-none -right-24 -top-24 w-[420px] h-[420px] sm:w-[560px] sm:h-[560px] opacity-[0.08] rotate-12">
        <MangoFruit class="w-full h-full" />
      </div>

      <div class="max-w-6xl mx-auto grid lg:grid-cols-2 gap-12 lg:gap-8 items-center relative">
        <div class="text-center lg:text-left">
          <p v-reveal class="mg-overline mb-5">Seguro de auto · Argentina</p>
          <h1 v-reveal="80" class="mg-display text-4xl sm:text-6xl leading-[1.08] mb-6">
            IA que cotiza.<br>
            <span :style="{ color: 'var(--mg-mango)' }">Humano que te cuida.</span>
          </h1>
          <p v-reveal="160" class="text-base sm:text-lg leading-relaxed max-w-xl mx-auto lg:mx-0 mb-9" :style="{ color: 'var(--mg-fg-dim)' }">
            Cotizá tu seguro de auto en minutos, en un chat.
            Y cuando lo necesites de verdad, hablás con tu productor.
            Una persona real. Siempre la misma.
          </p>
          <div v-reveal="240" class="flex flex-col sm:flex-row items-center justify-center lg:justify-start gap-3">
            <a :href="primaryCta.href" class="mg-btn-primary !px-8 !py-4 !text-sm w-full sm:w-auto" :target="primaryCta.target">
              {{ primaryCta.label }}
            </a>
            <a :href="secondaryCta.href" class="mg-btn-ghost !px-8 !py-4 !text-sm w-full sm:w-auto" :target="secondaryCta.target">
              {{ secondaryCta.label }}
            </a>
          </div>
          <p v-reveal="320" class="text-xs mt-5" :style="{ color: 'var(--mg-fg-faint)' }">
            Sin llamados. Sin vueltas. Gratis.
          </p>
        </div>

        <!-- Mockup del chat del asistente (conversación animada en loop) -->
        <div v-reveal="240" class="max-w-sm w-full mx-auto lg:ml-auto">
          <div class="ai-glow" :style="{ boxShadow: '0 24px 64px rgba(0,0,0,0.10)' }">
            <span class="ai-glow-ring" aria-hidden="true"></span>
            <div class="ai-glow-body mg-card p-5">
            <div class="flex items-center gap-3 pb-4 mb-4" :style="{ borderBottom: '1px solid var(--mg-hairline)' }">
              <div class="w-9 h-9 rounded-full flex items-center justify-center" :style="{ background: 'var(--mg-mango-tint)' }">
                <MangoFruit class="w-5 h-5" />
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
            <p class="text-[11px] mt-4 text-center" :style="{ color: 'var(--mg-fg-faint)' }">
              Así de simple. En serio.
            </p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── El modelo invertido ────────────────────────────────────────── -->
    <section class="px-5 py-20" :style="{ background: 'var(--mg-surface)' }">
      <div class="max-w-5xl mx-auto">
        <div class="text-center max-w-2xl mx-auto mb-12">
          <p v-reveal class="mg-overline mb-4">El modelo, al revés</p>
          <h2 v-reveal="80" class="mg-display text-3xl sm:text-4xl leading-tight">
            Los seguros siempre fueron al revés.<br class="hidden sm:block">
            Nosotros los dimos vuelta.
          </h2>
          <p v-reveal="160" class="mt-5 leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
            Te vendía una persona que no conocías. Y cuando chocabas, te atendía un 0800.
            En MANGO la tecnología trabaja donde gana la tecnología, y las personas donde gana una persona.
          </p>
        </div>

        <div class="grid sm:grid-cols-2 gap-5">
          <div v-reveal class="mg-card card-hover p-7 sm:p-9" :style="{ background: 'var(--mg-mango-tint)', border: 'none' }">
            <p class="mg-overline mb-4" :style="{ color: 'var(--mg-mango)' }">Antes de comprar</p>
            <h3 class="mg-display text-2xl mb-3">Una IA que cotiza por vos</h3>
            <ul class="space-y-2.5 text-sm leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
              <li class="flex gap-2.5"><IconDot /> Disponible 24/7, respondé cuando quieras.</li>
              <li class="flex gap-2.5"><IconDot /> Cotizás en varias compañías en un solo chat.</li>
              <li class="flex gap-2.5"><IconDot /> Comparativa clara, respuesta inmediata.</li>
              <li class="flex gap-2.5"><IconDot /> Sin vendedores, sin presión.</li>
            </ul>
          </div>

          <div v-reveal="120" class="mg-card card-hover p-7 sm:p-9">
            <p class="mg-overline mb-4" :style="{ color: 'var(--mg-mango)' }">Después de comprar</p>
            <h3 class="mg-display text-2xl mb-3">Tu productor, una persona real</h3>
            <ul class="space-y-2.5 text-sm leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
              <li class="flex gap-2.5"><IconDot /> Tu productor asesor propio, desde el día uno.</li>
              <li class="flex gap-2.5"><IconDot /> Por WhatsApp o teléfono, directo.</li>
              <li class="flex gap-2.5"><IconDot /> Para siniestros, dudas y renovaciones.</li>
              <li class="flex gap-2.5"><IconDot /> Siempre el mismo. Te conoce.</li>
            </ul>
          </div>
        </div>

        <p v-reveal class="text-center mt-10 text-sm max-w-lg mx-auto" :style="{ color: 'var(--mg-fg-dim)' }">
          La cotización te la resuelve la tecnología.
          El mal momento te lo resuelve una persona.
          <span :style="{ color: 'var(--mg-fg)' }" class="font-semibold">Así debió ser siempre.</span>
        </p>
      </div>
    </section>

    <!-- ─── La app MANGO ───────────────────────────────────────────────── -->
    <section class="px-5 py-20" :style="{ background: 'var(--mg-surface)' }">
      <div class="max-w-5xl mx-auto">
        <div class="text-center max-w-2xl mx-auto mb-12">
          <p v-reveal class="mg-overline mb-4">La app MANGO</p>
          <h2 v-reveal="80" class="mg-display text-3xl sm:text-4xl leading-tight">
            Tu seguro, en el bolsillo.
          </h2>
          <p v-reveal="160" class="mt-5 leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
            La mayoría de las apps de seguros te sirven para pagar.
            La nuestra te sirve para el resto: los papeles, el granizo, el peor día.
          </p>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
          <div
            v-for="(feature, i) in appFeatures"
            :key="feature.title"
            v-reveal="i * 90"
            class="mg-card card-hover group p-6"
          >
            <div
              class="icon-breathe w-11 h-11 rounded-xl flex items-center justify-center mb-4 transition-transform duration-300 group-hover:scale-110 group-hover:-rotate-6"
              :style="{ background: 'var(--mg-mango-tint)', color: 'var(--mg-mango)', '--breathe-delay': `${i * 0.6}s` }"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" :d="feature.icon" />
              </svg>
            </div>
            <h3 class="font-semibold mb-1.5" :style="{ fontFamily: 'var(--mg-font-ui)' }">{{ feature.title }}</h3>
            <p class="text-sm leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">{{ feature.body }}</p>
          </div>
        </div>

        <div v-reveal class="text-center mt-10">
          <a :href="appDownloadUrl ?? '#'" class="mg-btn-primary !px-8 !py-4 !text-sm">
            Descargá la app
          </a>
        </div>
      </div>
    </section>

    <!-- ─── Postventa 100% humana ──────────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 py-20">
      <div class="fruit-float-slow absolute pointer-events-none select-none -left-28 -bottom-16 w-[360px] h-[360px] opacity-[0.06] -rotate-12">
        <MangoFruit class="w-full h-full" />
      </div>
      <div class="max-w-5xl mx-auto relative">
        <div class="text-center max-w-2xl mx-auto mb-12">
          <p v-reveal class="mg-overline mb-4">Postventa 100% humana</p>
          <h2 v-reveal="80" class="mg-display text-3xl sm:text-4xl leading-tight">
            Cuando algo pasa, te atiende una persona.
          </h2>
          <p v-reveal="160" class="mt-5 leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
            Cotizar lo puede hacer una IA. Pero un choque, un robo, una duda a la noche —
            eso lo resuelve alguien que te conoce. Tu productor asesor. Siempre el mismo.
          </p>
        </div>

        <div class="grid lg:grid-cols-2 gap-8 lg:gap-14 items-center">
          <!-- Lo que NUNCA vas a encontrar (lista con tachado animado) -->
          <div>
            <p v-reveal class="text-sm font-semibold mb-5" :style="{ color: 'var(--mg-fg)' }">
              Con MANGO nunca te vas a topar con:
            </p>
            <ul class="space-y-3 mb-8">
              <li
                v-for="(item, i) in nuncaItems"
                :key="item"
                v-reveal="i * 140"
                class="strike-item flex items-center gap-3 text-base"
              >
                <span
                  class="w-7 h-7 shrink-0 rounded-full flex items-center justify-center"
                  :style="{ background: 'color-mix(in srgb, var(--mg-bad) 14%, transparent)', color: 'var(--mg-bad)' }"
                >
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </span>
                <span class="strike-text relative" :style="{ color: 'var(--mg-fg-dim)' }">{{ item }}</span>
              </li>
            </ul>

            <div
              v-reveal="120"
              class="rounded-2xl p-5"
              :style="{ background: 'var(--mg-mango-tint)', borderLeft: '3px solid var(--mg-mango)' }"
            >
              <p class="font-semibold mb-1" :style="{ color: 'var(--mg-fg)' }">En su lugar: una persona real.</p>
              <p class="text-sm leading-relaxed" :style="{ color: 'var(--mg-fg-dim)' }">
                Tu productor asesor, con nombre y celular. Le escribís por WhatsApp o lo llamás.
                Nada de menús, ni esperas, ni "su llamada es importante para nosotros".
              </p>
            </div>
          </div>

          <!-- Chat real con la productora manejando un siniestro (animado al entrar) -->
          <div v-reveal="120">
            <div ref="pasChatEl" class="ai-glow" :style="{ boxShadow: '0 24px 64px rgba(0,0,0,0.10)' }">
              <span class="ai-glow-ring" aria-hidden="true"></span>
              <div class="ai-glow-body mg-card p-5">
              <div class="flex items-center gap-3 pb-4 mb-4" :style="{ borderBottom: '1px solid var(--mg-hairline)' }">
                <div
                  class="w-9 h-9 rounded-full flex items-center justify-center font-semibold text-sm"
                  :style="{ background: 'var(--mg-mango)', color: '#fff' }"
                >
                  M
                </div>
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
                  :class="[
                    msg.side === 'right' ? 'rounded-tr-md ml-auto' : 'rounded-tl-md',
                    msg.wide ? 'max-w-[90%]' : 'w-fit',
                  ]"
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
              <p class="text-[11px] mt-4 text-center" :style="{ color: 'var(--mg-fg-faint)' }">
                Una persona real, el día que más lo necesitás.
              </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ─── Cierre ─────────────────────────────────────────────────────── -->
    <section class="relative overflow-hidden px-5 py-24" :style="{ background: 'var(--mg-mango)' }">
      <div class="fruit-float absolute pointer-events-none select-none -right-16 -bottom-20 w-[340px] h-[340px] opacity-[0.14] rotate-12">
        <MangoFruit mono class="w-full h-full" />
      </div>
      <div class="fruit-float-slow absolute pointer-events-none select-none -left-24 -top-16 w-[240px] h-[240px] opacity-[0.10] -rotate-12">
        <MangoFruit mono class="w-full h-full" />
      </div>
      <div class="max-w-3xl mx-auto text-center relative">
        <p v-reveal class="text-white/70 text-sm font-semibold tracking-[0.18em] uppercase mb-6">
          El mango es tuyo
        </p>
        <h2 v-reveal="80" class="mg-display text-5xl sm:text-7xl leading-[1.02] mb-7 text-white">
          Te cuidamos<br>el mango.
        </h2>
        <p v-reveal="240" class="text-white text-lg font-semibold mb-10">
          Y cuando
          <span
            :key="rotatorWord"
            class="rotator"
            :style="{ borderBottom: '2px solid rgba(255,255,255,0.6)' }"
          >{{ rotatorWord }}</span>, te atiende una persona.
        </p>
        <div v-reveal="320" class="flex flex-col sm:flex-row items-center justify-center gap-3">
          <a
            :href="primaryCta.href"
            :target="primaryCta.target"
            class="mg-btn-primary btn-shine !px-10 !py-4 !text-sm w-full sm:w-auto !text-[#c94f00]"
            :style="{ background: '#ffffff', boxShadow: '0 8px 28px rgba(0,0,0,0.18)' }"
          >
            {{ primaryCta.label }}
          </a>
          <a
            :href="secondaryCta.href"
            :target="secondaryCta.target"
            class="mg-btn-ghost !px-8 !py-4 !text-sm w-full sm:w-auto !text-white"
            :style="{ borderColor: 'rgba(255,255,255,0.55)' }"
          >
            {{ secondaryCta.label }}
          </a>
        </div>
      </div>
    </section>

    <!-- ─── Footer ─────────────────────────────────────────────────────── -->
    <footer class="px-5 py-10" :style="{ borderTop: '1px solid var(--mg-hairline)' }">
      <div class="max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-5">
        <MangoLogo :height="34" />
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

// No molestar con movimiento si el usuario tiene reduce-motion activado.
const reduceMotion =
  typeof window !== 'undefined' &&
  window.matchMedia('(prefers-reduced-motion: reduce)').matches

// ── v-reveal ────────────────────────────────────────────────────────────
// Fade-up al entrar en viewport (una sola vez). El valor opcional es el
// retardo en ms para escalonar (stagger) elementos hermanos.
// Con reduce-motion, muestra todo de una sin animar.
//
// La visibilidad NO depende del IntersectionObserver: el mecanismo confiable
// es un chequeo por getBoundingClientRect disparado en el mount y en cada
// scroll/resize (el observer queda como optimización cuando existe). Así el
// contenido nunca puede quedar atascado en opacity:0 si el observer no corre.
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

// ── Alternativas de CTA primaria para evaluar en el grupo ──────────────
// 'cotizar' → el CTA principal lleva al chat de cotización por WhatsApp.
// 'app'     → el CTA principal lleva a descargar la app.
// Cambiar el valor y rebuildar para comparar.
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
// Misma mecánica que el panel admin (AppLayout): clave 'pas-theme' en
// localStorage + atributo data-theme en <html> (el blade lo aplica anti-FOUC).
const THEME_KEY = 'pas-theme'
const isDark = ref(false)

const toggleTheme = () => {
  isDark.value = !isDark.value
  const theme = isDark.value ? 'dark' : 'light'
  localStorage.setItem(THEME_KEY, theme)
  document.documentElement.setAttribute('data-theme', theme)
}

// ── Chat animado del hero (loop) ────────────────────────────────────────
type ChatMsg = { side: 'left' | 'right'; text: string; wide?: boolean; strong?: boolean }

const chatSteps: ChatMsg[] = [
  { side: 'left', text: 'Hola 👋 Decime qué auto tenés y te cotizo en varias compañías.' },
  { side: 'right', text: 'Un Cruze LT 2022' },
  { side: 'left', text: 'Listo ✓ Ya tengo 3 cotizaciones para mostrarte. ¿Las comparamos?' },
]
const chatVisible = ref(0)
const chatTyping = ref(false)

// ── Chat de la productora manejando un siniestro (one-shot al entrar) ────
// Refuerza la postventa 100% humana: una persona real, no un 0800 ni un bot.
const pasSteps: ChatMsg[] = [
  { side: 'right', text: 'Hola Martina, tuve un choque 😟', wide: true },
  { side: 'left', text: 'Tranqui, estoy con vos. ¿Estás bien? ¿Hay alguien lastimado?', wide: true },
  { side: 'right', text: 'Estoy bien, sólo el auto' },
  { side: 'left', text: 'Perfecto. Sacale fotos a los daños. Yo ya te inicio el trámite y en 5 min te llamo. 📞', wide: true },
]
const pasVisible = ref(0)
const pasTyping = ref(false)
const pasChatEl = ref<HTMLElement | null>(null)

// ── Palabra rotativa del cierre ─────────────────────────────────────────
const rotatorWords = ['tenes un choque', 'te roban', ' tenes una duda', 'cae granizo']
const rotatorWord = ref(rotatorWords[0])
let rotatorIdx = 0
let rotatorTimer: ReturnType<typeof setInterval> | null = null

let timers: ReturnType<typeof setTimeout>[] = []
const after = (ms: number, fn: () => void) => {
  timers.push(setTimeout(fn, ms))
}

// Un ciclo del chat del hero: escribe → responde → el usuario contesta →
// escribe → cierra; espera; y vuelve a empezar.
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

// El chat de la productora corre una sola vez, cuando la card entra en
// viewport. Alterna "escribiendo…" antes de cada mensaje de Martina.
let pasStarted = false
const maybePlayPas = () => {
  if (pasStarted || !pasChatEl.value || !isInViewport(pasChatEl.value)) {
    return
  }
  pasStarted = true
  let t = 400
  pasSteps.forEach((msg) => {
    if (msg.side === 'left') {
      const typingAt = t
      after(typingAt, () => (pasTyping.value = true))
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
    // Sin animación: mostrar las conversaciones completas de una.
    chatVisible.value = chatSteps.length
    pasVisible.value = pasSteps.length
    return
  }

  // Revelar lo que ya está en viewport (un frame después del primer paint,
  // así la transición de fade sí se ve) + fallback en scroll/resize.
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

// Bullet naranja para las listas de las cards del modelo invertido.
const IconDot = () =>
  h('span', {
    class: 'mt-[7px] w-1.5 h-1.5 shrink-0 rounded-full',
    style: { background: 'var(--mg-mango)' },
  })

// Features reales de mango-mobile (spec v3). Paths de iconos: líneas simples 24x24.
const appFeatures = [
  {
    title: 'Tus pólizas siempre a mano',
    body: 'La cobertura y la suma asegurada de cada vehículo, de un vistazo. Sin llamar a nadie.',
    icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  },
  {
    title: 'Los papeles del auto, sin señal',
    body: 'Licencia, póliza, tarjeta y cédula guardados en tu teléfono. Funcionan sin internet.',
    icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
  },
  {
    title: 'Un botón para el peor momento',
    body: '"Tuve un siniestro": guía paso a paso y llamada directa a tu productor. No a un call center.',
    icon: 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z',
  },
  {
    title: 'Te avisa si viene el granizo',
    body: 'Alertas del Servicio Meteorológico Nacional según dónde estés. Guardás el auto a tiempo.',
    icon: 'M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z',
  },
  {
    title: 'Ayuda de verdad en una emergencia',
    body: 'Compartís tu ubicación en vivo con hasta tres contactos, con un toque.',
    icon: 'M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z',
  },
  {
    title: 'Un auto, toda la familia',
    body: 'Compartís el vehículo en la app con quienes lo manejan. Todos ven la cobertura y el productor.',
    icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
  },
]

// Lo que la postventa de MANGO nunca te va a hacer pasar (lista tachada).
const nuncaItems = [
  'Un 0800 que te deja en espera',
  'Un formulario que nadie contesta',
  'Un bot que no entiende qué te pasó',
]
</script>

<style scoped>
/* Reveal al entrar en viewport (aplicado por v-reveal). */
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

/* Aparición de cada burbuja de chat. */
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

/* Indicador "escribiendo…". */
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

/* Punto verde de estado latiendo. */
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

/* Frutas de fondo: bob vertical + deriva horizontal + balanceo suave. */
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

/* ── Glow de borde tipo "prompt de IA nativo" ────────────────────────────
   Un cuadrado con conic-gradient mango gira detrás de la card; una superficie
   interior opaca lo tapa y deja ver solo un anillo fino en el borde. Usa
   transform:rotate (no @property) — más robusto y sin dependencias. */
.ai-glow {
  position: relative;
  border-radius: 17px;
  padding: 1.5px;
  overflow: hidden;
  isolation: isolate;
}
.ai-glow-ring {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 220%;
  aspect-ratio: 1;
  transform: translate(-50%, -50%);
  background: conic-gradient(
    from 0deg,
    transparent 0deg,
    var(--mg-mango) 50deg,
    var(--mg-mango-soft) 105deg,
    transparent 175deg,
    transparent 215deg,
    var(--mg-mango) 315deg,
    transparent 360deg
  );
  animation: ai-glow-spin 4.5s linear infinite;
  pointer-events: none;
  z-index: 0;
}
@keyframes ai-glow-spin {
  from {
    transform: translate(-50%, -50%) rotate(0deg);
  }
  to {
    transform: translate(-50%, -50%) rotate(360deg);
  }
}
.ai-glow-body {
  position: relative;
  z-index: 1;
  border-radius: 15.5px;
  border: none;
  height: 100%;
}

/* Hover de las cards. */
.card-hover {
  transition:
    transform 0.28s ease,
    box-shadow 0.28s ease;
}
.card-hover:hover {
  transform: translateY(-4px);
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.09);
}

/* Tile de ícono que "respira" un glow mango muy sutil (escalonado). */
.icon-breathe {
  animation: icon-breathe 4.5s ease-in-out infinite;
  animation-delay: var(--breathe-delay, 0s);
}
@keyframes icon-breathe {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgba(255, 107, 0, 0);
  }
  50% {
    box-shadow: 0 0 15px 1px rgba(255, 107, 0, 0.22);
  }
}

/* Lista "nunca vas a encontrar": el tachado se dibuja al entrar en viewport. */
.strike-text::after {
  content: '';
  position: absolute;
  left: 0;
  top: 50%;
  height: 2px;
  width: 100%;
  background: var(--mg-bad);
  transform: scaleX(0);
  transform-origin: left;
}
.reveal-in .strike-text::after {
  animation: strike 0.5s cubic-bezier(0.22, 1, 0.36, 1) forwards 0.35s;
}
@keyframes strike {
  to {
    transform: scaleX(1);
  }
}

/* Palabra rotativa del cierre: cada palabra (nuevo :key) entra con un fade-up.
   El :key en el template remonta el span en cada cambio, re-disparando esto. */
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

/* Barrido de brillo sobre el CTA principal del cierre. */
.btn-shine {
  position: relative;
  overflow: hidden;
}
.btn-shine::after {
  content: '';
  position: absolute;
  top: 0;
  left: -60%;
  width: 40%;
  height: 100%;
  background: linear-gradient(100deg, transparent, rgba(255, 107, 0, 0.28), transparent);
  animation: btn-shine 3.4s ease-in-out infinite;
}
@keyframes btn-shine {
  0% {
    left: -60%;
  }
  55%,
  100% {
    left: 130%;
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
  .card-hover,
  .rotator,
  .btn-shine::after,
  .ai-glow-ring,
  .icon-breathe {
    animation: none;
    transition: none;
  }
  .strike-text::after {
    transform: scaleX(1);
  }
}
</style>
