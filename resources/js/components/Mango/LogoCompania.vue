<template>
  <!--
    El logo apoya directo sobre el fondo, sin ninguna caja atrás. Las compañías cuya tinta no se
    lee en noir se resuelven con su propio negativo (ver LOGOS_DARK), no poniéndoles una placa
    clara debajo.

    El swap de variante es CSS puro contra [data-theme="dark"] — el mismo selector con el que
    app.css conmuta los tokens --mg-*. Se renderizan las dos y se muestra una: sin JS, sin
    MutationObserver, y sin parpadeo al cambiar de tema.
  -->
  <span
    v-if="logo"
    class="lc-marca"
    :class="{ 'lc-con-negativo': logoDark }"
    :style="{ height: `${alto}px` }"
    role="img"
    :aria-label="nombre"
  >
    <img :src="logo" alt="" class="lc-img lc-claro" />
    <img v-if="logoDark" :src="logoDark" alt="" class="lc-img lc-oscuro" />
  </span>

  <!-- Sin archivo: el monograma de siempre, círculo de color con la inicial. -->
  <span
    v-else
    class="lc-monograma"
    :style="{
      width: `${alto}px`,
      height: `${alto}px`,
      fontSize: `${Math.round(alto * 0.42)}px`,
      background: colorDeCompania(slug),
    }"
    role="img"
    :aria-label="nombre"
  >{{ nombre.charAt(0) }}</span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { colorDeCompania, logoDeCompania, logoDeCompaniaDark } from '@/lib/companias'

const props = withDefaults(
  defineProps<{
    /** El slug que arma el backend con `Str::slug($aseguradora)`. */
    slug: string
    /** Nombre de la compañía: es el nombre accesible y da la inicial del monograma. */
    nombre: string
    /** Altura renderizada en px. El ancho del logo se ajusta por aspecto; el monograma es cuadrado. */
    alto?: number
  }>(),
  { alto: 24 },
)

const logo = computed(() => logoDeCompania(props.slug))
const logoDark = computed(() => logoDeCompaniaDark(props.slug))
</script>

<style scoped>
.lc-marca {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
}

.lc-img {
  height: 100%;
  width: auto;
  object-fit: contain;
  display: block;
}

/* Sin negativo no hay nada que conmutar: la de color va en los dos temas. */
.lc-con-negativo .lc-oscuro {
  display: none;
}

[data-theme='dark'] .lc-con-negativo .lc-claro {
  display: none;
}

[data-theme='dark'] .lc-con-negativo .lc-oscuro {
  display: block;
}

.lc-monograma {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  font-weight: 700;
  color: #fff;
}
</style>
