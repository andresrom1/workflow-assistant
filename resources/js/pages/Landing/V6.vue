<template>
  <MangoLayout hide-header>
    <Head>
      <title>MANGO — Una carta</title>
      <meta
        name="description"
        content="Una carta abierta de un productor amigo. Sobre seguros, choques y por qué hacemos lo que hacemos."
      />
    </Head>

    <!-- ─── Header mínimo ─────────────────────────────────────────────── -->
    <header
      class="sticky top-0 z-30"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }"
    >
      <div class="max-w-3xl mx-auto flex items-center justify-between px-5 h-14">
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

    <!-- ─── La carta ──────────────────────────────────────────────────── -->
    <article class="carta px-5 pt-16 pb-20 sm:pt-24 sm:pb-32">
      <div class="max-w-2xl mx-auto">
        <!-- Fecha y lugar -->
        <p class="font-mono text-xs uppercase tracking-widest text-right mb-12" :style="{ color: 'var(--mg-fg-faint)' }">
          Buenos Aires, {{ fechaCarta }}
        </p>

        <!-- Saludo -->
        <p v-reveal class="font-serif text-3xl sm:text-4xl mb-12 leading-snug" :style="{ fontFamily: 'var(--mg-font-display)', color: 'var(--mg-fg)' }">
          Hola.
        </p>

        <!-- Primer párrafo -->
        <div v-reveal="80" class="space-y-6 text-lg leading-[1.7]" :style="{ color: 'var(--mg-fg)' }">
          <p>
            Te escribo porque me enteré que la semana pasada tuviste un choque. Y me quedé pensando en vos.
            No te voy a mentir: cuando me contaron, lo primero que sentí fue esa cosa en la panza que todos
            sentimos cuando alguien conocido se lleva un susto así. Después, en seguida, me acordé de todo
            lo que viene después: el 0800, los formularios, la gente que te pide los mismos datos una y
            otra vez, las horas en una cola virtual, la impotencia.
          </p>

          <p>
            Y ahí pensé: <em class="italic" :style="{ color: 'var(--mg-mango)' }">esto no puede seguir así.</em>
          </p>
        </div>

        <!-- Polaroid 1 -->
        <figure v-reveal="200" class="my-16 flex justify-center">
          <div class="polaroid rotate-2">
            <div class="p-3 pb-12">
              <div class="aspect-[4/3] w-72 sm:w-80 rounded-sm flex items-center justify-center" :style="{ background: 'var(--mg-mango-tint)' }">
                <div class="text-center">
                  <MangoFruit :outline="true" class="w-20 h-20 mx-auto" />
                  <p class="font-mono text-[10px] uppercase tracking-widest mt-3" :style="{ color: 'var(--mg-fg-faint)' }">Foto: MANGO</p>
                </div>
              </div>
            </div>
            <p class="absolute bottom-3 left-0 right-0 text-center text-xs" :style="{ color: 'var(--mg-fg-dim)' }">
              Un día común en la oficina
            </p>
          </div>
        </figure>

        <!-- Segundo bloque -->
        <div v-reveal="240" class="space-y-6 text-lg leading-[1.7]" :style="{ color: 'var(--mg-fg)' }">
          <p>
            Trabajo en seguros hace varios años. Empecé como todos: atendiendo a la gente por teléfono,
            leyendo letra chica, lidiando con sistemas que parecen diseñados para confundir en vez de
            ayudar. Lo que más me chocaba —y me sigue chocando— era la distancia entre la empresa y la
            persona. Una distancia enorme, fría, llena de "su llamada es importante para nosotros" que
            no le cree nadie.
          </p>

          <p>
            Por eso, hace un tiempo, junto con un grupo de gente copada, decidimos armar algo distinto.
            Lo llamamos MANGO, que es el nombre de la empresa pero también es una forma de decir
            "te cuidamos lo tuyo, en serio".
          </p>
        </div>

        <!-- Separador -->
        <div v-reveal="320" class="my-12 flex items-center gap-4">
          <div class="flex-1 h-px" :style="{ background: 'var(--mg-hairline)' }"></div>
          <MangoFruit :outline="true" class="w-8 h-8" />
          <div class="flex-1 h-px" :style="{ background: 'var(--mg-hairline)' }"></div>
        </div>

        <!-- Tercer bloque: cómo funciona -->
        <div v-reveal="360" class="space-y-6 text-lg leading-[1.7]" :style="{ color: 'var(--mg-fg)' }">
          <p>
            La idea es simple. Cotizar un seguro no requiere un humano. Es repetir datos, comparar precios,
            cruzar información. Eso lo puede hacer una máquina. Y la verdad, lo hace mejor que nosotros:
            más rápido, sin equivocarse, sin cansarse.
          </p>

          <p>
            Por eso en MANGO la parte aburrida la hace la IA. Vos le escribís por WhatsApp, le contás qué
            auto tenés, y en un par de minutos te muestra las mejores opciones de varias aseguradoras.
            Sin vendedores, sin presión, sin esperar.
          </p>

          <p>
            <em class="italic" :style="{ color: 'var(--mg-mango)' }">Pero un choque, un robo, una duda a las
            tres de la mañana, una señora que no sabe si denunciar o no — eso no lo resuelve una máquina.</em>
            Eso lo resuelve una persona. Alguien que te conoce. Alguien con nombre y apellido, que te
            atiende por WhatsApp y te devuelve la llamada. Alguien que sabe que vos tenés un Cruze gris
            y que tu señora usa el auto para llevar a los chicos al colegio.
          </p>
        </div>

        <!-- Polaroid 2 -->
        <figure v-reveal="440" class="my-16 flex justify-center">
          <div class="polaroid -rotate-1">
            <div class="p-3 pb-12">
              <div class="aspect-square w-64 sm:w-72 rounded-sm flex items-center justify-center" :style="{ background: 'var(--mg-mango)' }">
                <span class="mg-display text-8xl text-white">M</span>
              </div>
            </div>
            <p class="absolute bottom-3 left-0 right-0 text-center text-xs" :style="{ color: 'var(--mg-fg-dim)' }">
              Martina, tu productora
            </p>
          </div>
        </figure>

        <!-- Cuarto bloque -->
        <div v-reveal="520" class="space-y-6 text-lg leading-[1.7]" :style="{ color: 'var(--mg-fg)' }">
          <p>
            Y te aclaro algo: no te estoy escribiendo esto para venderte nada. No te voy a pedir tu
            número de auto, ni mandarte un link, ni "aprovechá esta oportunidad única". Si querés,
            cuando quieras, me escribís. Y si no querés, también está bien. A lo mejor te sirve leer
            esto y nada más.
          </p>

          <p>
            Pero si te quedaste con ganas de saber más, abajo te dejo cómo contactarnos. Y si te chocaste
            y todavía no llamaste a nadie, escribinos hoy. Mañana, el problema va a seguir ahí.
          </p>

          <p>
            Un abrazo enorme. Y que te mejores del susto.
          </p>
        </div>

        <!-- Firma -->
        <div v-reveal="600" class="mt-16">
          <p class="font-serif text-2xl italic" :style="{ fontFamily: 'var(--mg-font-display)', color: 'var(--mg-fg)' }">
            Andrés
          </p>
          <p class="font-mono text-xs uppercase tracking-widest mt-2" :style="{ color: 'var(--mg-fg-faint)' }">
            Productor · MANGO
          </p>
        </div>

        <!-- PS -->
        <div v-reveal="680" class="mt-20 pt-10 border-t-2 border-dashed" :style="{ borderColor: 'var(--mg-hairline)' }">
          <p class="font-serif text-base italic leading-relaxed" :style="{ fontFamily: 'var(--mg-font-display)', color: 'var(--mg-fg-dim)' }">
            <span :style="{ color: 'var(--mg-mango)' }">P.D.</span> Si te sirve, te dejo un dato: en Argentina,
            la mayoría de los siniestros se resuelven en menos de 48 horas. Y un productor que te conoce
            puede hacer que esas 48 horas sean mucho menos angustiantes. Acordate de eso la próxima vez
            que renueves.
          </p>
        </div>

        <!-- CTA al final, como si fuera un papelito adjunto -->
        <div v-reveal="760" class="mt-20 flex flex-col sm:flex-row gap-3">
          <a :href="primaryCta.href" :target="primaryCta.target" class="mg-btn-primary !px-8 !py-4 !text-sm w-full sm:w-auto text-center">
            {{ primaryCta.label }}
          </a>
          <a :href="secondaryCta.href" :target="secondaryCta.target" class="mg-btn-ghost !px-8 !py-4 !text-sm w-full sm:w-auto text-center">
            {{ secondaryCta.label }}
          </a>
        </div>

        <!-- Cierre de carta -->
        <p v-reveal="820" class="mt-16 text-center font-mono text-xs uppercase tracking-widest" :style="{ color: 'var(--mg-fg-faint)' }">
          Fin · Gracias por leer
        </p>
      </div>
    </article>

    <!-- ─── Footer ─────────────────────────────────────────────────────── -->
    <footer class="px-5 py-10" :style="{ borderTop: '1px solid var(--mg-hairline)' }">
      <div class="max-w-3xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-5">
        <MangoLogo :height="30" />
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
    label: 'Escribinos',
    shortLabel: 'Escribir',
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

const fechaCarta = new Date().toLocaleDateString('es-AR', { year: 'numeric', month: 'long', day: 'numeric' })

onMounted(() => {
  isDark.value = document.documentElement.getAttribute('data-theme') === 'dark'

  window.addEventListener('scroll', checkPendingReveals, { passive: true })
  window.addEventListener('resize', checkPendingReveals)
  requestAnimationFrame(() =>
    requestAnimationFrame(() => {
      checkPendingReveals()
    }),
  )
})

onBeforeUnmount(() => {
  revealObserver?.disconnect()
  window.removeEventListener('scroll', checkPendingReveals)
  window.removeEventListener('resize', checkPendingReveals)
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

/* Polaroid */
.polaroid {
  position: relative;
  background: var(--mg-bg);
  border-radius: 2px;
  box-shadow:
    0 2px 4px rgba(0,0,0,0.04),
    0 12px 28px rgba(0,0,0,0.08);
  border: 1px solid var(--mg-hairline);
  transition: transform 0.3s ease;
}
.polaroid:hover {
  transform: rotate(0deg) scale(1.02);
}

@media (prefers-reduced-motion: reduce) {
  .reveal {
    opacity: 1;
    transform: none;
    transition: none;
  }
}
</style>
