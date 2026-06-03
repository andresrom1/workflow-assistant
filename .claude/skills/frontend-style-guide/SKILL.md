---
  name: frontend-style-guide
  description: Maintain the frontend style guide for the admin panel. Activates when adding new styles, restyling components, or when the user mentions design, styles, CSS, or layout changes related to the frontend.
---

# Guía de Estilo Frontend v2.3

**Stack:** Laravel 12 · Vue 3 · Inertia.js · Tailwind CSS v4  
**Fuentes:** Inter (UI) · JetBrains Mono (datos)  
**Modos:** Light · Dark · System (toggle manual + fallback `prefers-color-scheme`, sin FOUC)

---

## Índice

1. [Filosofía de diseño](#1-filosofía-de-diseño)
2. [Paleta de colores](#2-paleta-de-colores)
3. [Modo dark](#3-modo-dark)
4. [Tipografía](#4-tipografía)
5. [Espaciado](#5-espaciado)
6. [Border radius](#6-border-radius)
7. [Sombras y elevación](#7-sombras-y-elevación)
8. [Componentes](#8-componentes)
9. [Patrones de página](#9-patrones-de-página)
10. [Responsive](#10-responsive)
11. [Implementación CSS (app.css)](#11-implementación-css-appcss)
12. [Tokens de referencia rápida](#12-tokens-de-referencia-rápida)
13. [Lo que no hacer](#13-lo-que-no-hacer)

---

## 1. Filosofía de diseño

workflow-assistant es un backoffice de uso intensivo. Los usuarios son productores de seguros que pasan horas en esta interfaz. El diseño responde a esas necesidades:

**Claridad sobre decoración.** Cada pixel tiene una función. No hay fondos degradados, ilustraciones de relleno ni animaciones ornamentales.

**Densidad cómoda.** La información debe ser densa pero no agotadora. Jerarquía visual fuerte para que el ojo encuentre lo que busca en menos de un segundo.

**Consistencia total.** El mismo componente en cualquier vista se ve y se comporta exactamente igual. Cero variaciones ad-hoc.

**Dark mode de primera clase.** No es un afterthought. Light y dark se diseñan en paralelo desde el principio, usando variables CSS que conmutan automáticamente.

**Mobile como ciudadano de primera clase.** Las tablas no se scrollean horizontalmente en mobile — se reemplazan por cards. La información se reorganiza, no se comprime.

---

## 2. Paleta de colores

### 2.1 Escala Ink — gris azulado

La escala base del sistema. Tonos fríos con un leve sesgo azul/violeta que dan coherencia visual y distinguen el producto del gris genérico de Tailwind.

| Token | Hex | Uso principal |
|-------|-----|---------------|
| `--c-ink-950` | `#0a0b0f` | Fondo sidebar, body dark mode |
| `--c-ink-900` | `#13151c` | Fondo card dark mode, sidebar hover activo |
| `--c-ink-800` | `#1e2130` | Separadores dark, hover de ítems sidebar |
| `--c-ink-700` | `#2d3148` | Texto secundario dark, bordes medios |
| `--c-ink-600` | `#454a66` | Texto deshabilitado light, iconos inactivos |
| `--c-ink-500` | `#6b7194` | Placeholder, texto terciario |
| `--c-ink-400` | `#9499b8` | Texto de labels, metadatos |
| `--c-ink-300` | `#c0c3d8` | Bordes sutiles |
| `--c-ink-200` | `#dfe1ed` | Borde estándar light mode |
| `--c-ink-100` | `#eff0f7` | Fondo de hover de fila, separadores light |
| `--c-ink-50`  | `#f7f8fc` | Fondo de app light mode |

### 2.2 Escala Accent — indigo eléctrico

El color de acción e identidad del producto. Un indigo ligeramente eléctrico que se distingue del azul genérico.

| Token | Hex | Uso principal |
|-------|-----|---------------|
| `--c-accent-600` | `#5b5ef6` | Botón primario, nav activo sidebar, focus ring |
| `--c-accent-500` | `#7375f8` | Hover de acento |
| `--c-accent-400` | `#9b9dfb` | Acento en dark mode, texto sobre fondo oscuro |
| `--c-accent-200` | `#d6d7fd` | Borde de elemento activo light |
| `--c-accent-100` | `#eeeeff` | Fondo de badge accent light |
| `--c-accent-50`  | `#f5f5ff` | Hover de RowActionMin light |

### 2.3 Estados semánticos

| Estado | Color | Fondo light | Texto light | Fondo dark | Texto dark |
|--------|-------|-------------|-------------|------------|------------|
| Éxito | `#16a349` | `#dcfce7` | `#15803d` | `#052e16` | `#4ade80` |
| Advertencia | `#d97706` | `#fef3c7` | `#92400e` | `#1c1009` | `#fbbf24` |
| Peligro | `#dc2626` | `#fee2e2` | `#991b1b` | `#1f0a0a` | `#f87171` |
| Info / Neutral | ink-600 | ink-100 | ink-700 | ink-800 | ink-300 |

### 2.4 Colores de tarjetas de crédito

```css
--cc-visa:       #1a56db;
--cc-mastercard: #dc2626;
--cc-amex:       #4338ca;
--cc-naranja:    #ea580c;
--cc-cabal:      #16a349;
--cc-maestro:    #0d9488;
```

---

## 3. Sistema de temas

### 3.1 Estrategia — tres modos con toggle manual

El sistema soporta tres modos seleccionables por el usuario:

| Modo | Comportamiento |
|------|----------------|
| `light` | Fuerza tema claro independientemente del SO |
| `dark` | Fuerza tema oscuro independientemente del SO |
| `system` | Sigue la preferencia del sistema operativo (`prefers-color-scheme`) |

La elección se persiste en `localStorage['pas-theme']` y se aplica mediante el atributo `data-theme` en el `<html>`.

**No** se usa una clase `.dark`. El control es 100% via `data-theme` + variables CSS.

### 3.2 Anti-FOUC — script inline en app.blade.php

Para evitar el flash del tema incorrecto en el primer render, `app.blade.php` incluye un script inline que corre **antes** de que Vite aplique el CSS. Lee `localStorage` y aplica `data-theme` antes del primer paint:

```html
<script>
  (function () {
    var stored = localStorage.getItem('pas-theme');
    var theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
  })();
</script>
```

Este script va **antes** del `@vite(...)` en el `<head>`.

### 3.3 Variables que conmutan

Las variables de la app conmutan con `data-theme`. Las del sidebar usan tokens propios `--sb-*` que permiten dos paletas (dark y light) sin duplicar componentes.

> **Nota de accesibilidad (astigmatismo):** los valores de dark mode difieren intencionalmente de los valores "máximos" posibles. Ver sección [3.8 Consideraciones para astigmatismo](#38-consideraciones-para-astigmatismo).

```css
/* Light mode — default */
:root,
[data-theme="light"] {
  --bg-app:    #f0f1f8;
  --bg-card:   #ffffff;
  --bg-raised: #f7f8fc;
  --text-1:    #13151c;
  --text-2:    #454a66;
  --text-3:    #9499b8;
  --border:    #dfe1ed;
  --border-sub:#eff0f7;

  --shadow-focus: 0 0 0 3px rgba(91,94,246,.25);

  /* Sidebar light */
  --sb-bg:              #ffffff;
  --sb-border:          #dfe1ed;
  --sb-divider:         #eff0f7;
  --sb-logo-text:       #13151c;
  --sb-group-label:     #9499b8;
  --sb-item-text:       #454a66;
  --sb-item-hover-bg:   #f0f1f8;
  --sb-item-hover-text: #13151c;
  --sb-collapse-text:   #9499b8;
  --sb-collapse-hover:  #f0f1f8;
}

/* Dark mode — valores ajustados para astigmatismo */
[data-theme="dark"] {
  --bg-app:    #0a0b0f;
  --bg-card:   #13151c;
  --bg-raised: #1e2130;
  --text-1:    #c0c3d8;  /* ← reducido vs #eff0f7 para evitar halación */
  --text-2:    #9499b8;
  --text-3:    #6b7194;  /* ← aclarado para cumplir WCAG AA ~4.6:1 */
  --border:    #2d3148;
  --border-sub:#2d3148;  /* ← elevado para visibilidad en tablas */

  --accent-50:  #1a1a2e;
  --accent-100: #1e1e3f;
  --accent-700: #4a4de8;
  --shadow-card: 0 1px 4px rgba(0,0,0,.3);
  --shadow-focus: 0 0 0 3px rgba(155,157,251,.45); /* ← más luminoso en dark */

  /* Sidebar dark */
  --sb-bg:              #0a0b0f;
  --sb-border:          #1e2130;
  --sb-divider:         #1e2130;
  --sb-logo-text:       #eff0f7;
  --sb-group-label:     #454a66;
  --sb-item-text:       #6b7194;
  --sb-item-hover-bg:   #1e2130;
  --sb-item-hover-text: #c0c3d8;
  --sb-collapse-text:   #454a66;
  --sb-collapse-hover:  #1e2130;
}
```

El fallback para el modo `system` usa `@media (prefers-color-scheme: dark)` como respaldo cuando no hay `data-theme` explícito.

### 3.4 Lógica del toggle en AppLayout.vue

```typescript
type Theme = 'light' | 'dark' | 'system'
const STORAGE_KEY = 'pas-theme'

const setTheme = (t: Theme) => {
  currentTheme.value = t
  localStorage.setItem(STORAGE_KEY, t)
  const html = document.documentElement
  if (t === 'system') {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
    html.setAttribute('data-theme', prefersDark ? 'dark' : 'light')
  } else {
    html.setAttribute('data-theme', t)
  }
}
```

El modo `system` además escucha cambios en `prefers-color-scheme` via `addEventListener('change', ...)` para actualizar en tiempo real si el usuario cambia la configuración del SO.

### 3.5 UI del selector de tema

**Sidebar expandido:** tres botones pill al fondo del sidebar, antes del botón de colapsar.
```
[ ☀ Claro ]  [ ⬜ Auto ]  [ ☾ Oscuro ]
```
El botón activo tiene fondo `#5b5ef6` y texto blanco. Los inactivos usan `--sb-collapse-text`.

**Sidebar colapsado (desktop):** un ícono que cambia entre sol / monitor / luna. Click cicla al siguiente modo.

**Mobile (topbar):** el mismo ícono cicla al final del topbar.

### 3.6 Cómo usar las variables en componentes Vue

Usar siempre inline styles con variables CSS. Las clases Tailwind `dark:` no son necesarias:

```html
<!-- ✅ Correcto — funciona en ambos modos automáticamente -->
<div style="background: var(--bg-card); color: var(--text-1); border: 1px solid var(--border);">

<!-- ✅ También válido con Tailwind v4 -->
<div class="bg-[var(--bg-card)] text-[var(--text-1)] border-[var(--border)]">

<!-- ❌ Incorrecto — no responde al toggle manual -->
<div class="bg-white dark:bg-[#13151c]">
```

### 3.7 Checklist para cada componente nuevo

- [ ] Fondo usa `--bg-card` o `--bg-app`, nunca `bg-white` o `bg-gray-*`
- [ ] Texto usa `--text-1`, `--text-2` o `--text-3`
- [ ] Bordes usan `--border` o `--border-sub`
- [ ] Hover/active funcionan en ambos modos
- [ ] Badges de estado usan hex inline (no clases Tailwind con `dark:`)
- [ ] Inputs: fondo `--bg-card`, borde `--border`

### 3.8 Consideraciones para astigmatismo

Cerca del 30% de los usuarios tienen algún grado de astigmatismo. En dark mode, este grupo experimenta **halación** (blooming): la luz de los caracteres sangra hacia el fondo oscuro, deformando las letras y acelerando la fatiga visual. Las siguientes decisiones de diseño mitigan este efecto:

| Problema | Token afectado | Valor incorrecto | Valor correcto | Razón |
|----------|---------------|-----------------|----------------|-------|
| Halación en texto principal | `--text-1` (dark) | `#eff0f7` | `#c0c3d8` | Reduce luminancia del texto de ~87% a ~56%, disminuyendo el contraste extremo que genera el halo |
| Ilegibilidad WCAG en texto terciario | `--text-3` (dark) | `#454a66` | `#6b7194` | `#454a66` sobre `#13151c` da ratio ~2.0:1 (falla AA); `#6b7194` da ~3.1:1 |
| Focus invisible en dark | `--shadow-focus` (dark) | `rgba(91,94,246,.25)` | `rgba(155,157,251,.45)` | La baja opacidad es absorbida por fondos oscuros; tono 400 + opacidad .45 lo hace visible |
| Filas de tabla sin delimitación | `--border-sub` (dark) | `#1e2130` | `#2d3148` | Diferencia de luminosidad <2% era imperceptible en monitores de rango dinámico bajo |
| Trazos consumidos por halo | `font-weight` (dark) | 400 | 500 | Mayor masa tipográfica resiste mejor el blooming sin cambiar el tamaño |

**Regla general para dark mode:** el texto no debe ser blanco puro ni casi blanco. La luminancia del texto principal en dark mode debe estar en el rango 50–60% (no 80–90% como en un texto sobre fondo blanco). Esto reduce el contraste extremo que genera halación sin sacrificar legibilidad.

**Hover de botón primario:** el estado hover debe **oscurecer** el fondo del botón, nunca aclararlo. Un hover más claro sobre texto blanco reduce el contraste por debajo de 3:1. El token correcto es `--accent-700: #4a4de8` (no `--accent-500: #7375f8`).

---

## 4. Tipografía

### 4.1 Fuentes

**Inter** — UI principal. Variable font que carga desde Google Fonts. Añadir al `app.blade.php`:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
```

**JetBrains Mono** — datos alfanuméricos. Para DNIs, patentes, números de tarjeta, IDs, CBUs, cualquier dato que el usuario necesite leer/copiar carácter a carácter.

```html
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
```

Declarar en `app.css`:

```css
@theme {
  --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', 'Fira Code', ui-monospace, monospace;
}
```

### 4.2 Escala tipográfica

| Nombre | Tailwind | Tamaño | Peso | Tracking | Uso |
|--------|----------|--------|------|----------|-----|
| Display | `text-3xl font-bold tracking-tight` | 28px | 700 | -0.02em | Pantallas de bienvenida, métricas grandes |
| H1 | `text-2xl font-semibold tracking-tight` | 22px | 600 | -0.015em | Título de página (`<h1>`) |
| H2 | `text-lg font-semibold` | 17px | 600 | 0 | Título de card o sección |
| H3 | `text-sm font-semibold` | 14px | 600 | 0 | Subtítulo interno de card |
| Body | `text-sm font-normal` | 14px | 400 | 0 | Contenido principal, valores de tabla |
| Small | `text-xs font-normal` | 12px | 400 | 0 | Metadatos, timestamps, subtítulos de fila |
| Label | `text-[11px] font-semibold uppercase tracking-wider` | 11px | 600 | +0.06em | `<th>` de tabla, títulos de sección en settings |
| Mono | `font-mono text-xs` | 12px | 400 | 0 | DNI, patente, tarjeta, IDs |

### 4.3 Jerarquía en celdas de tabla

La celda de dos líneas es el patrón más repetido del sistema:

```html
<td class="px-5 py-3">
  <p class="text-sm font-semibold text-[var(--text-1)] leading-tight">
    Juan Pérez
  </p>
  <p class="text-xs text-[var(--text-3)] mt-0.5 truncate font-mono">
    30.456.789 · juan@mail.com
  </p>
</td>
```

### 4.4 Reglas

- Máximo 3 niveles de peso en una misma vista: 400 (body) / 500 o 600 (semibold) / 700 (bold títulos)
- `font-bold` (700) solo para títulos de página y valores de stat cards
- `font-mono` para todo dato alfanumérico que el usuario necesite leer o copiar
- Los `<th>` son siempre uppercase, tracking más amplio, color terciario
- Line-height: `leading-tight` (1.25) para títulos, `leading-relaxed` (1.625) para descripciones

---

## 5. Espaciado

El sistema usa una escala de 4px base. Todos los valores son múltiplos de 4.

| Token Tailwind | px | Uso típico |
|---------------|-----|------------|
| `p-1` | 4px | Gap mínimo entre elementos inline |
| `p-2` | 8px | Gap entre icon y label, padding de badge |
| `p-3` | 12px | Padding de item sidebar, gap interno de cards pequeñas |
| `p-4` | 16px | Padding de card mobile, gap entre cards |
| `p-5` | 20px | Padding de card desktop |
| `p-6` | 24px | Padding de card grande, sección settings |
| `p-8` | 32px | Padding vertical de página desktop |
| `gap-2` / `gap-3` | 8-12px | Flex de botones, badge row |
| `gap-4` | 16px | Grid de stat cards |
| `space-y-5` | 20px | Stack vertical de cards |

### Padding de página

```html
<!-- Siempre este patrón — nunca hardcodear otro -->
<div class="py-6 px-4 sm:py-8">
  <div class="max-w-5xl mx-auto">
    <!-- contenido -->
  </div>
</div>
```

### Padding de celda de tabla

```
Desktop: px-5 py-3  (20px horizontal, 12px vertical)
Header:  px-5 py-3  (mismo que body — sin padding extra)
```

---

## 6. Border radius

| Nombre | Valor | Uso |
|--------|-------|-----|
| `rounded` / sm | 6px | Badges pequeños, tags, radio buttons, tooltips |
| `rounded-md` / md | 10px | Inputs, botones, RowActionMin, items de paginación |
| `rounded-lg` / lg | 14px | Cards, tablas, dropdowns, modales |
| `rounded-xl` / xl | 20px | Cards hero, panels principales, sidebar |
| `rounded-full` | 9999px | Avatares, pills de contador, toggles |

**Regla de coherencia:** en una misma jerarquía de componentes, el padre tiene `rounded-lg` y el hijo `rounded-md`. Nunca al revés.

```
Card (rounded-lg)
  └── Input (rounded-md)
  └── Badge (rounded / rounded-full)
  └── Button (rounded-md)
```

---

## 7. Sombras y elevación

El sistema usa sombras mínimas — la elevación se comunica principalmente con bordes y diferencia de fondo.

| Nivel | CSS | Uso |
|-------|-----|-----|
| 0 — Flat | `border: 1px solid var(--border)` | Inputs, botones ghost, separadores |
| 1 — Card | `box-shadow: 0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.04)` | Cards, tablas, dropdowns |
| Focus | `box-shadow: 0 0 0 3px rgba(91,94,246,.25)` | Focus ring de inputs y botones |

**En dark mode** las sombras son menos visibles — compensar con un borde ligeramente más visible (`--border` en dark es más contrastante que en light).

**No usar:** `shadow-lg`, `shadow-xl`, `drop-shadow`, efectos glow ni blur en ningún componente de UI funcional.

---

## 8. Componentes

### 8.1 Botones

#### Primario
```html
<button class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white
               bg-[#5b5ef6] rounded-[10px] transition-colors
               hover:bg-[#4a4de8] active:scale-[.98]
               focus:outline-none focus-visible:ring-3 focus-visible:ring-[#5b5ef6]/30
               disabled:opacity-50 disabled:cursor-not-allowed">
  Guardar cambios
</button>
```

#### Secundario (con borde)
```html
<button class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium
               text-[var(--text-1)] bg-[var(--bg-card)]
               border border-[var(--border)] rounded-[10px] transition-colors
               hover:bg-[var(--border-sub)]
               focus:outline-none focus-visible:ring-3 focus-visible:ring-[#5b5ef6]/30">
  Cancelar
</button>
```

#### Ghost (sin borde visible)
```html
<button class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium
               text-[var(--text-2)] rounded-[10px] transition-colors
               hover:bg-[var(--border-sub)] hover:text-[var(--text-1)]">
  ← Volver
</button>
```

#### Destructivo
```html
<button class="btn btn-danger">
  Eliminar
</button>
```

#### Ícono (RowActionMin)
```html
<Link :href="href" :title="label"
  class="inline-flex items-center justify-center w-7 h-7 rounded-[6px]
         text-[var(--text-3)] transition-all
         hover:bg-[var(--c-accent-50)] hover:text-[#5b5ef6]
         dark:hover:bg-[#1a1a2e]">
  <svg class="w-3.5 h-3.5" .../>
</Link>
```

#### Small (buscadores, paginación)
Agregar `text-xs py-1.5 px-3` sobre la clase base correspondiente.

---

### 8.2 Inputs y formularios

#### Input base
```html
<div class="flex flex-col gap-1.5">
  <label class="text-xs font-medium text-[var(--text-2)]">
    Nombre completo
  </label>
  <input
    type="text"
    class="w-full px-3 py-2 text-sm text-[var(--text-1)]
           bg-[var(--bg-card)] border border-[var(--border)] rounded-[10px]
           placeholder:text-[var(--text-3)] outline-none transition-all
           focus:border-[#5b5ef6] focus:ring-3 focus:ring-[#5b5ef6]/20"
    placeholder="Juan Pérez"
  />
  <p class="text-[11px] text-[var(--text-3)]">Ayuda opcional</p>
</div>
```

#### Input con error
```html
<input class="... border-red-500 focus:border-red-500 focus:ring-red-500/20" />
<p class="text-[11px] text-red-600 dark:text-red-400">Formato inválido</p>
```

#### Input monoespaciado (DNI, patente, tarjeta)
```html
<input class="... font-mono tracking-wider" placeholder="30.456.789" />
```

#### Select
```html
<select class="w-full px-3 py-2 text-sm text-[var(--text-1)]
               bg-[var(--bg-card)] border border-[var(--border)] rounded-[10px]
               outline-none appearance-none
               bg-[url(...chevron...)] bg-no-repeat bg-right-3 bg-[length:16px]
               focus:border-[#5b5ef6] focus:ring-3 focus:ring-[#5b5ef6]/20">
```

#### Toggle
```html
<label class="flex items-center gap-2.5 cursor-pointer">
  <div class="relative w-9 h-5 rounded-full transition-colors"
       :class="value ? 'bg-[#5b5ef6]' : 'bg-[var(--border)]'">
    <div class="absolute top-0.5 w-4 h-4 bg-white rounded-full shadow-sm transition-transform"
         :class="value ? 'translate-x-4 right-0.5' : 'left-0.5'"></div>
  </div>
  <span class="text-sm text-[var(--text-1)]">{{ value ? 'Activado' : 'Desactivado' }}</span>
</label>
```

---

### 8.3 Badges y estados

#### Badge de estado con dot
```html
<span :class="statusClass(status)"
  class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold">
  <span class="w-1.5 h-1.5 rounded-full" :class="dotClass(status)"></span>
  {{ statusLabel(status) }}
</span>
```

```typescript
const statusClass = (s: string) => ({
  pending:            'bg-amber-50  dark:bg-amber-950/40  text-amber-700  dark:text-amber-400',
  processed:          'bg-green-50  dark:bg-green-950/40  text-green-700  dark:text-green-400',
  failed:             'bg-red-50    dark:bg-red-950/40    text-red-700    dark:text-red-400',
  checkout_pending:   'bg-violet-50 dark:bg-violet-950/40 text-violet-700 dark:text-violet-400',
  checkout_submitted: 'bg-teal-50   dark:bg-teal-950/40   text-teal-700   dark:text-teal-400',
  expired:            'bg-[var(--border-sub)] text-[var(--text-3)]',
}[s] ?? 'bg-[var(--border-sub)] text-[var(--text-3)]')
```

#### Pill numérico (contadores)
```html
<!-- Vehículos — acento -->
<span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5
             rounded-full bg-[#eeeeff] dark:bg-[#1a1a2e]
             text-[#5b5ef6] dark:text-[#9b9dfb]
             text-[11px] font-bold font-mono tabular-nums">
  {{ count }}
</span>

<!-- Neutral -->
<span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5
             rounded-full bg-[var(--border-sub)] text-[var(--text-2)]
             text-[11px] font-bold font-mono tabular-nums">
  {{ count }}
</span>
```

---

### 8.4 Cards

#### Card estándar
```html
<div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-[14px] p-5
            shadow-[0_1px_3px_rgba(0,0,0,.07),0_1px_2px_rgba(0,0,0,.04)]">
  <h2 class="text-[11px] font-semibold uppercase tracking-wider text-[var(--text-3)] mb-4">
    Título de sección
  </h2>
  <!-- contenido -->
</div>
```

#### Card con acento izquierdo (dato inmutable / snapshot)
```html
<div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-[14px] p-5
            border-l-4 border-l-[#5b5ef6]">
```

#### Card stat
```html
<div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-[14px] p-4
            shadow-[0_1px_3px_rgba(0,0,0,.07)]">
  <div class="text-[27px] font-bold tracking-tight text-[var(--text-1)] leading-none">
    30
  </div>
  <div class="text-xs text-[var(--text-3)] mt-1.5">Timeout PAS (min)</div>
  <div class="text-xs font-semibold text-green-600 dark:text-green-400 mt-2">
    ↑ configurado
  </div>
</div>
```

#### Card mobile clickeable
```html
<Link :href="href"
  class="flex items-center gap-3 bg-[var(--bg-card)] border border-[var(--border)]
         rounded-[14px] px-4 py-3
         hover:border-[#9b9dfb] hover:shadow-[0_2px_8px_rgba(91,94,246,.1)]
         active:bg-[var(--border-sub)] transition-all">
  <!-- contenido -->
  <ChevronRight />
</Link>
```

---

### 8.5 Tablas

#### Estructura completa
```html
<!-- Wrapper — solo visible en desktop -->
<div class="hidden md:block bg-[var(--bg-card)] border border-[var(--border)]
            rounded-[14px] overflow-hidden
            shadow-[0_1px_3px_rgba(0,0,0,.07)]">
  <table class="min-w-full text-sm">

    <!-- Header -->
    <thead class="bg-[var(--border-sub)]">
      <tr class="border-b border-[var(--border)]">
        <th class="px-5 py-3 text-left text-[11px] font-semibold uppercase
                   tracking-wider text-[var(--text-3)]">
          Cliente
        </th>
        <!-- más columnas -->
        <th class="px-5 py-3 w-10"></th>
      </tr>
    </thead>

    <!-- Body -->
    <tbody class="divide-y divide-[var(--border-sub)]">
      <tr class="hover:bg-[var(--border-sub)] transition-colors cursor-pointer"
          @click="irA(href)">
        <td class="px-5 py-3">
          <!-- celda compacta de dos líneas -->
          <div class="flex items-center gap-3">
            <Avatar :name="name" />
            <div class="min-w-0">
              <p class="text-sm font-semibold text-[var(--text-1)] leading-tight">
                {{ name }}
              </p>
              <p class="text-xs text-[var(--text-3)] mt-0.5 truncate font-mono">
                {{ dni }} · {{ email }}
              </p>
            </div>
          </div>
        </td>
        <!-- más celdas -->
        <td class="px-5 py-3" @click.stop>
          <RowActionMin :href="href" label="Ver cliente" />
        </td>
      </tr>
    </tbody>

  </table>
</div>

<!-- Cards mobile — solo visible en mobile -->
<div class="md:hidden space-y-2">
  <Link v-for="item in items" :key="item.id" :href="`/.../${item.id}`"
    class="flex items-center gap-3 bg-[var(--bg-card)] border border-[var(--border)]
           rounded-[14px] px-4 py-3
           hover:border-[#9b9dfb] transition-all active:bg-[var(--border-sub)]">
    <Avatar :name="item.name" />
    <div class="flex-1 min-w-0">
      <p class="text-sm font-semibold text-[var(--text-1)] truncate">{{ item.name }}</p>
      <p class="text-xs text-[var(--text-3)] truncate mt-0.5 font-mono">{{ item.dni }}</p>
    </div>
    <ChevronRight />
  </Link>
</div>
```

---

### 8.6 Avatar

```html
<!-- Componente UI/Avatar.vue -->
<div class="w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0
            bg-[#eeeeff] dark:bg-[#1a1a2e]
            text-[#5b5ef6] dark:text-[#9b9dfb]
            text-xs font-bold font-sans">
  {{ initial }}
</div>
```

Variante colorizada por string hash (para distinguir visualmente contactos):
```typescript
const avatarColor = (name: string) => {
  const colors = [
    'bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-300',
    'bg-teal-100 dark:bg-teal-950 text-teal-700 dark:text-teal-300',
    'bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300',
    'bg-rose-100 dark:bg-rose-950 text-rose-700 dark:text-rose-300',
  ]
  const idx = name.charCodeAt(0) % colors.length
  return colors[idx]
}
```

---

### 8.7 Sidebar

El sidebar tiene **dos variantes** (dark y light) que conmutan automáticamente con el tema global. Todos los colores usan tokens `--sb-*` — nunca valores hardcodeados en los componentes.

| Propiedad | Valor |
|-----------|-------|
| Ancho expandido | 220px |
| Ancho colapsado | 56px (solo íconos) |
| Transición | `all 250ms ease-in-out` |
| Borde derecho | `1px solid var(--sb-border)` |
| Logo accent | `bg-[#5b5ef6]` fijo en ambos modos |
| Item activo | `background: #5b5ef6; color: #ffffff` (fijo) |
| Item inactivo | `color: var(--sb-item-text)` |
| Item hover | `background: var(--sb-item-hover-bg); color: var(--sb-item-hover-text)` |
| Label de grupo | `color: var(--sb-group-label)`, 9px, uppercase |
| Tooltip (colapsado) | `bg-[#13151c]` / `color: #eff0f7` — fijo oscuro en ambos modos |

#### Tokens --sb-* por variante

| Token | Dark mode | Light mode |
|-------|-----------|------------|
| `--sb-bg` | `#0a0b0f` | `#ffffff` |
| `--sb-border` | `#1e2130` | `#dfe1ed` |
| `--sb-divider` | `#1e2130` | `#eff0f7` |
| `--sb-logo-text` | `#eff0f7` | `#13151c` |
| `--sb-group-label` | `#454a66` | `#9499b8` |
| `--sb-item-text` | `#6b7194` | `#454a66` |
| `--sb-item-hover-bg` | `#1e2130` | `#f0f1f8` |
| `--sb-item-hover-text` | `#c0c3d8` | `#13151c` |
| `--sb-collapse-text` | `#454a66` | `#9499b8` |
| `--sb-collapse-hover` | `#1e2130` | `#f0f1f8` |

#### Selector de tema en el footer del sidebar

Al fondo del sidebar, antes del botón de colapsar:

```html
<!-- Expandido: tres botones pill -->
<div class="flex rounded-[8px] p-0.5 gap-0.5"
     style="background: var(--sb-item-hover-bg);">
  <button v-for="opt in themeOptions"
    @click="setTheme(opt.value)"
    :style="currentTheme === opt.value
      ? 'background:#5b5ef6; color:#fff;'
      : 'color: var(--sb-collapse-text);'"
    class="flex-1 flex items-center justify-center gap-1.5 h-7 rounded-[6px]
           text-[11px] font-medium transition-all">
    <!-- ícono SVG + label -->
  </button>
</div>

<!-- Colapsado: ícono que cicla -->
<button @click="cycleTheme" style="color: var(--sb-collapse-text);">
  <!-- sol / monitor / luna según currentTheme -->
</button>
```

---

### 8.8 Paginación

```html
<!-- Componente UI/Pagination.vue -->
<div class="bg-[var(--bg-card)] border border-[var(--border)] rounded-[14px]
            px-4 py-3 flex flex-col sm:flex-row items-center justify-between gap-3">
  <span class="text-xs text-[var(--text-3)]">
    {{ data.from }}–{{ data.to }} de {{ data.total }} resultados
  </span>
  <div class="flex flex-wrap gap-1 justify-center">
    <Link v-for="link in data.links" :key="link.label"
      :href="link.url ?? '#'"
      :class="[
        'px-3 py-1.5 rounded-[8px] text-xs font-medium transition-colors',
        link.active
          ? 'bg-[#5b5ef6] text-white'
          : 'text-[var(--text-2)] hover:bg-[var(--border-sub)]',
        !link.url ? 'opacity-40 pointer-events-none' : '',
      ]"
      v-html="link.label"
    />
  </div>
</div>
```

---

### 8.9 Alertas y feedback

#### Alert con borde izquierdo de color
```html
<div class="flex items-start gap-3 px-4 py-3 rounded-[10px] text-sm border-l-3"
     :class="alertClass(type)">
  <span class="text-base flex-shrink-0 mt-px">{{ alertIcon(type) }}</span>
  <span>{{ message }}</span>
</div>
```

```typescript
const alertClass = (t: string) => ({
  success: 'bg-green-50 dark:bg-green-950/30 text-green-700 dark:text-green-400 border-green-500',
  warning: 'bg-amber-50 dark:bg-amber-950/30 text-amber-700 dark:text-amber-400 border-amber-500',
  danger:  'bg-red-50   dark:bg-red-950/30   text-red-700   dark:text-red-400   border-red-500',
  info:    'bg-[var(--border-sub)] text-[var(--text-2)] border-[var(--border)]',
}[t] ?? '')
const alertIcon = (t: string) => ({ success: '✓', warning: '⚠', danger: '✕', info: 'ℹ' }[t])
```

#### Estado vacío
```html
<div class="bg-[var(--bg-card)] border border-dashed border-[var(--border)]
            rounded-[14px] p-12 text-center">
  <div class="w-12 h-12 rounded-full bg-[var(--border-sub)] mx-auto mb-4
              flex items-center justify-center">
    <svg class="w-5 h-5 text-[var(--text-3)]" .../>
  </div>
  <p class="text-sm font-medium text-[var(--text-2)]">Sin resultados</p>
  <p class="text-xs text-[var(--text-3)] mt-1">Ajustá los filtros de búsqueda</p>
</div>
```

---

## 9. Patrones de página

### Index (lista)
```
Padding: py-6 px-4 sm:py-8
Max-width: max-w-5xl (clientes) / max-w-6xl (checkout) / max-w-7xl (quotes)

[H1 de página]                     [contador total]
[Toolbar — buscador + selects]
[Tabla desktop / Cards mobile]
[Paginación]
```

### Show (detalle)
```
Padding: py-6 px-4 sm:py-8
Max-width: max-w-3xl o max-w-5xl

[BackLink] [H1]              [Badge de estado]
[Card resumen — acento izq.]
[Card datos principales]
[Cards adicionales]
[Card de acciones — al final]
```

### Settings
```
[H1 + descripción]           [Link volver]
[Card dashboard — stat grid]
[Card grupo 1]
  Header: ícono + nombre + "N parámetros"     [badge "✓ Guardado"]
  Campos: label / input / hint
  Footer: "Último cambio: fecha"              [Botón guardar]
[Card grupo 2...]
```

---

## 10. Responsive

### Breakpoints en uso

| Prefijo | Mínimo | Uso |
|---------|--------|-----|
| `sm:` | 640px | Stack flex: columna → fila (toolbars, forms) |
| `md:` | 768px | Tabla vs. card list |
| `lg:` | 1024px | Grids de 3 columnas, sidebar visible |

### La regla más importante

**Las tablas nunca tienen scroll horizontal en mobile.** Se reemplazan completamente por una lista de cards. Este es el patrón central y no tiene excepciones.

```html
<div class="hidden md:block">  <!-- tabla --></div>
<div class="md:hidden">        <!-- cards --></div>
```

### Ajustes responsive por breakpoint

```html
<!-- Padding de página -->
<div class="py-6 px-4 sm:py-8">

<!-- Título responsive -->
<h1 class="text-xl sm:text-2xl font-semibold tracking-tight">

<!-- Grid de stats: 2 cols mobile, 4 desktop -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">

<!-- Grid de cards: 1 → 2 → 3 -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

<!-- Formulario: columna → fila -->
<div class="flex flex-col sm:flex-row gap-3">

<!-- Header de vista detalle: columna → fila -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
```

---

## 11. Implementación CSS (app.css)

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';
@source '../**/*.vue';

@theme {
  --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', 'Fira Code', ui-monospace, monospace;
}

/* ══ LIGHT MODE (default + [data-theme="light"]) ═══════════════════════════ */
:root,
[data-theme="light"] {
  --bg-app:    #f0f1f8;
  --bg-card:   #ffffff;
  --bg-raised: #f7f8fc;
  --text-1:    #13151c;
  --text-2:    #454a66;
  --text-3:    #9499b8;
  --border:    #dfe1ed;
  --border-sub:#eff0f7;

  --accent-600: #5b5ef6;
  --accent-500: #7375f8;
  --accent-400: #9b9dfb;
  --accent-200: #d6d7fd;
  --accent-100: #eeeeff;
  --accent-50:  #f5f5ff;

  --shadow-card:  0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.04);
  --shadow-focus: 0 0 0 3px rgba(91,94,246,.25);

  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 14px;
  --radius-xl: 20px;

  /* Sidebar light */
  --sb-bg:              #ffffff;
  --sb-border:          #dfe1ed;
  --sb-divider:         #eff0f7;
  --sb-logo-text:       #13151c;
  --sb-group-label:     #9499b8;
  --sb-item-text:       #454a66;
  --sb-item-hover-bg:   #f0f1f8;
  --sb-item-hover-text: #13151c;
  --sb-collapse-text:   #9499b8;
  --sb-collapse-hover:  #f0f1f8;
}

/* ══ DARK MODE ([data-theme="dark"] + fallback OS) ═════════════════════════ */
/*
  Los valores de dark mode difieren intencionalmente de los máximos posibles.
  Son correcciones de accesibilidad para astigmatismo:
  - text-1 reducido para evitar halaón (luminancia ~56% en vez de ~87%)
  - text-3 aclarado para cumplir WCAG AA
  - border-sub elevado para visibilidad de filas en monitores de menor calidad
  - shadow-focus más luminoso y opaco para dark mode
  Ver sección 3.8 del STYLE_GUIDE.md para el razonamiento completo.
*/
[data-theme="dark"],
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --bg-app:    #0a0b0f;
    --bg-card:   #13151c;
    --bg-raised: #1e2130;
    --text-1:    #c0c3d8;  /* reducido vs #eff0f7: evita halaón */
    --text-2:    #9499b8;
    --text-3:    #6b7194;  /* aclarado: cumple WCAG AA ~4.6:1 */
    --border:    #2d3148;
    --border-sub:#2d3148;  /* elevado: filas visibles en monitores bajos */
    --accent-50:  #1a1a2e;
    --accent-100: #1e1e3f;
    --accent-700: #4a4de8;
    --shadow-card: 0 1px 4px rgba(0,0,0,.3);
    --shadow-focus: 0 0 0 3px rgba(155,157,251,.45); /* más luminoso en dark */

    /* Sidebar dark */
    --sb-bg:              #0a0b0f;
    --sb-border:          #1e2130;
    --sb-divider:         #1e2130;
    --sb-logo-text:       #eff0f7;
    --sb-group-label:     #454a66;
    --sb-item-text:       #6b7194;
    --sb-item-hover-bg:   #1e2130;
    --sb-item-hover-text: #c0c3d8;
    --sb-collapse-text:   #454a66;
    --sb-collapse-hover:  #1e2130;
  }
}

body {
  background: var(--bg-app);
  color: var(--text-1);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/*
  Dark mode: elevar peso tipográfico base a 500 (medium).
  Los trazos más gruesos resisten el halo luminoso (halaón)
  que afecta especialmente a personas con astigmatismo.
*/
[data-theme="dark"] body {
  font-weight: 500;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) body {
    font-weight: 500;
  }
}

@layer components {
  .field {
    @apply w-full px-3 py-2 text-sm outline-none rounded-[10px] transition-all;
    background: var(--bg-card);
    color: var(--text-1);
    border: 1px solid var(--border);
  }
  .field::placeholder { color: var(--text-3); }
  .field:focus {
    border-color: var(--accent-600);
    box-shadow: var(--shadow-focus);
  }
  .field-error { border-color: #dc2626 !important; }
  .field-error:focus { box-shadow: 0 0 0 3px rgba(220,38,38,.2) !important; }

  .btn {
    @apply inline-flex items-center justify-center gap-1.5 font-medium
           rounded-[10px] transition-all cursor-pointer outline-none select-none;
  }
  .btn:focus-visible { box-shadow: var(--shadow-focus); }
  .btn-primary {
    @apply px-4 py-2 text-sm text-white bg-[#5b5ef6]
           hover:bg-[#4a4de8] active:scale-[.98]
           disabled:opacity-50 disabled:cursor-not-allowed;
  }
  .btn-secondary {
    @apply px-4 py-2 text-sm;
    color: var(--text-1);
    background: var(--bg-card);
    border: 1px solid var(--border);
  }
  .btn-secondary:hover { background: var(--border-sub); }
  .btn-ghost {
    @apply px-4 py-2 text-sm;
    color: var(--text-2);
  }
  .btn-ghost:hover { background: var(--border-sub); color: var(--text-1); }
  .btn-submit {
    @apply px-5 py-2.5 text-sm font-semibold text-white bg-[#16a349]
           rounded-[10px] hover:bg-[#15803d] active:scale-[.98]
           disabled:opacity-50 disabled:cursor-not-allowed
           flex items-center justify-center gap-1.5;
  }
  .btn-danger {
    @apply px-4 py-2 text-sm;
    background: var(--badge-danger-bg);
    color: var(--badge-danger-txt);
  }
  .btn-danger:hover { filter: brightness(0.92); }
  .card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-card);
  }
}
```

### app.blade.php

Las dos adiciones clave respecto al scaffolding base de Laravel:

```html
<!-- 1. Fuentes antes del CSS -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

<!-- 2. Script anti-FOUC — ANTES del @vite() -->
<script>
  (function () {
    var stored = localStorage.getItem('pas-theme');
    var theme = stored || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', theme);
  })();
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
```

El `<body>` ya no lleva `class="bg-gray-50"` — el fondo lo maneja `var(--bg-app)` en el CSS.

---

## 12. Tokens de referencia rápida

### Fondos
```
App background:    bg-[var(--bg-app)]
Card / surface:    bg-[var(--bg-card)]    border border-[var(--border)]
Raised / thead:    bg-[var(--bg-raised)]
Sidebar:           var(--sb-bg)   ← conmuta con el tema
```

### Sidebar (tokens --sb-*)
```
Fondo:             var(--sb-bg)
Borde derecho:     var(--sb-border)
Divider:           var(--sb-divider)
Texto logo:        var(--sb-logo-text)
Label de grupo:    var(--sb-group-label)
Item texto:        var(--sb-item-text)
Item hover fondo:  var(--sb-item-hover-bg)
Item hover texto:  var(--sb-item-hover-text)
Colapsar texto:    var(--sb-collapse-text)
Colapsar hover:    var(--sb-collapse-hover)
Item activo:       background:#5b5ef6  color:#fff  (fijo en ambos modos)
Tooltip:           background:#13151c  color:#eff0f7  (fijo oscuro siempre)
```

### Texto
```
Principal:         text-[var(--text-1)]
Secundario:        text-[var(--text-2)]
Terciario:         text-[var(--text-3)]
Monospace:         font-mono text-[var(--text-2)]
```

### Bordes
```
Estándar:          border-[var(--border)]
Sutil:             border-[var(--border-sub)]
Acento:            border-[#5b5ef6]
```

### Interacción
```
Acento primario:   bg-[#5b5ef6]  text-white
Acento hover:      bg-[#4a4de8]
Focus ring:        ring-3 ring-[#5b5ef6]/25
Hover de fila:     hover:bg-[var(--border-sub)]
Botón danger:      btn btn-danger
```

### Radius frecuentes
```
Input / botón:     rounded-[10px]
Card / tabla:      rounded-[14px]
Badge / pill:      rounded-full
RowActionMin:      rounded-[6px]
```

### Padding de página
```
py-6 px-4 sm:py-8
max-w-5xl mx-auto    (clientes, settings)
max-w-6xl mx-auto    (checkout sessions)
max-w-7xl mx-auto    (quotes)
max-w-3xl mx-auto    (detail pages angostas)
```

---

## 13. Lo que no hacer

### Colores
- ❌ Usar colores Tailwind hardcoded como `bg-gray-100` — usar `bg-[var(--bg-app)]`
- ❌ Usar `bg-white` directamente — usar `bg-[var(--bg-card)]`
- ❌ Mezclar la escala Ink con la escala gray de Tailwind en el mismo componente
- ❌ Fondos de color saturado en secciones enteras — solo en badges y pills
- ❌ Degradados en ningún componente de UI funcional

### Tipografía
- ❌ `text-base` (16px) en contenido de tablas — siempre `text-sm`
- ❌ `font-bold` en texto de párrafo o valores de formulario
- ❌ Mezclar Inter y cualquier otra fuente de display

### Layout
- ❌ `overflow-x-auto` en tablas — las tablas se reemplazan por cards en mobile
- ❌ `min-h-screen` en páginas dentro del AppLayout
- ❌ Más de 3 niveles de weight tipográfico en la misma vista

### Componentes
- ❌ `<a href>` para navegación interna — siempre `<Link>` de Inertia
- ❌ `<form>` nativo — usar `@submit.prevent` con `router.post()`
- ❌ `shadow-lg`, `shadow-xl`, glow, blur en UI funcional
- ❌ Definir colores de estado inline en cada vista — usar las funciones `statusClass()`/`statusLabel()` centralizadas
- ❌ Hardcodear hex values fuera de `app.css` — si se necesita un color nuevo, primero añadirlo como variable

### Dark mode
- ❌ Olvidar la variante dark al crear un componente nuevo
- ❌ Usar `text-gray-*` o `bg-gray-*` Tailwind en componentes — usar las variables CSS del sistema
- ❌ Confiar en que "se ve bien en light mode" — siempre probar en ambos

---