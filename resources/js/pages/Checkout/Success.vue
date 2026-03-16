<template>
  <div class="co-page">
    <div class="co-success-wrap">
      <div class="co-card-center">

        <!-- Check icon -->
        <div class="co-check-circle">
          <svg class="co-check-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
          </svg>
        </div>

        <h1 class="co-success-title">¡Solicitud recibida!</h1>

        <p class="co-success-body">
          Recibimos tus datos de contratación.<br>
          <span v-if="email">
            Te contactaremos a <strong class="co-success-em">{{ email }}</strong>
            para confirmar los detalles de la emisión.
          </span>
          <span v-else>
            Un asesor te contactará en breve para confirmar la emisión.
          </span>
        </p>

        <div class="co-success-footer">
          Podés cerrar esta ventana.
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  email: string | null
}>()
</script>

<style scoped>
/* ── Tokens ───────────────────────────────────────────────────────────── */
:root, .co-page {
  --co-bg:        #f5f4f2;
  --co-surface:   #ffffff;
  --co-border:    #e4e2de;
  --co-txt-1:     #1a1917;
  --co-txt-2:     #5c5a56;
  --co-txt-3:     #9c9991;
  --co-accent:    #5b5ef6;
  --co-ok-bg:     #edfaf2;
  --co-ok-txt:    #1a7a43;
  --co-ok-circle: #dcf7e8;
}

@media (prefers-color-scheme: dark) {
  :root, .co-page {
    --co-bg:        #141312;
    --co-surface:   #1e1d1b;
    --co-border:    #2e2d2a;
    --co-txt-1:     #e8e6e1;
    --co-txt-2:     #a09e99;
    --co-txt-3:     #6b6965;
    --co-accent:    #8183fa;
    --co-ok-bg:     #0e2018;
    --co-ok-txt:    #5ecea0;
    --co-ok-circle: #122a1d;
  }
}

/* ── Base ─────────────────────────────────────────────────────────────── */
.co-page {
  min-height: 100dvh;
  background: var(--co-bg);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px 16px;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
  -webkit-font-smoothing: antialiased;
}

.co-success-wrap {
  width: 100%;
  max-width: 380px;
}

.co-card-center {
  background: var(--co-surface);
  border: 1px solid var(--co-border);
  border-radius: 20px;
  padding: 40px 28px;
  text-align: center;
  box-shadow: 0 1px 4px rgba(0,0,0,.06);
}

/* ── Check circle ─────────────────────────────────────────────────────── */
.co-check-circle {
  width: 64px;
  height: 64px;
  background: var(--co-ok-circle);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 24px;
}
.co-check-icon {
  width: 32px;
  height: 32px;
  color: var(--co-ok-txt);
}

/* ── Texto ────────────────────────────────────────────────────────────── */
.co-success-title {
  font-size: 22px;
  font-weight: 700;
  color: var(--co-txt-1);
  margin: 0 0 12px;
  letter-spacing: -0.01em;
}

.co-success-body {
  font-size: 14px;
  line-height: 1.65;
  color: var(--co-txt-2);
  margin: 0;
}
.co-success-em {
  color: var(--co-txt-1);
  font-weight: 600;
}

.co-success-footer {
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid var(--co-border);
  font-size: 12px;
  color: var(--co-txt-3);
}
</style>
