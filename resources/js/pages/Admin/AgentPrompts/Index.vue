<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
          <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
            Prompts de Agentes IA
          </h1>
          <p class="text-sm mt-0.5" style="color: var(--text-3);">
            Editá las instrucciones de cada agente del flujo de cotización. Los cambios tienen efecto inmediato.
          </p>
        </div>
      </div>

      <!-- Bloques compartidos -->
      <section v-if="sharedBlocks && sharedBlocks.length" class="space-y-3">
        <div class="flex items-baseline justify-between">
          <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-2);">
            Bloques compartidos
          </h2>
          <p class="text-[11px]" style="color: var(--text-3);">
            Se inyectan al inicio de cada agente.
          </p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <Link
            v-for="block in sharedBlocks" :key="block.key"
            :href="`/admin/agent-prompts/${block.key}`"
            class="block rounded-[14px] p-5 transition-all"
            style="background: var(--bg-card); border: 1px dashed var(--border); box-shadow: var(--shadow-card);"
            @mouseenter="e => (e.currentTarget as HTMLElement).style.borderColor = '#5b5ef6'"
            @mouseleave="e => (e.currentTarget as HTMLElement).style.borderColor = 'var(--border)'"
          >
            <div class="flex items-start justify-between gap-3 mb-3">
              <div class="w-9 h-9 rounded-[10px] flex items-center justify-center flex-shrink-0 text-lg"
                style="background: var(--bg-raised);">
                {{ sharedIcon(block.key) }}
              </div>
              <span v-if="block.has_prompt"
                class="text-[10px] px-2 py-0.5 rounded-full font-semibold flex-shrink-0"
                style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);">
                v{{ block.version }}
              </span>
              <span v-else
                class="text-[10px] px-2 py-0.5 rounded-full font-semibold flex-shrink-0"
                style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);">
                sin prompt
              </span>
            </div>
            <h3 class="text-sm font-semibold mb-1" style="color: var(--text-1);">
              {{ block.label }}
            </h3>
            <p v-if="block.preview" class="text-[11px] leading-relaxed line-clamp-2" style="color: var(--text-3);">
              {{ block.preview }}
            </p>
            <p v-else class="text-[11px] italic" style="color: var(--text-3);">
              No hay contenido cargado.
            </p>
            <div class="flex items-center justify-between mt-4 pt-3"
              style="border-top: 1px solid var(--border-sub);">
              <p class="text-[10px]" style="color: var(--text-3);">
                {{ block.updated_at ? formatDate(block.updated_at) : '—' }}
              </p>
              <span class="text-[11px] font-medium" style="color: #5b5ef6;">Editar →</span>
            </div>
          </Link>
        </div>
      </section>

      <!-- Grid de agentes -->
      <section class="space-y-3">
        <h2 class="text-sm font-semibold uppercase tracking-wider" style="color: var(--text-2);">
          Agentes
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <Link
            v-for="agent in agents" :key="agent.key"
            :href="`/admin/agent-prompts/${agent.key}`"
            class="block rounded-[14px] p-5 transition-all group"
            style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);"
            @mouseenter="e => (e.currentTarget as HTMLElement).style.borderColor = '#5b5ef6'"
            @mouseleave="e => (e.currentTarget as HTMLElement).style.borderColor = 'var(--border)'"
          >
            <div class="flex items-start justify-between gap-3 mb-3">
              <div class="w-9 h-9 rounded-[10px] flex items-center justify-center flex-shrink-0 text-lg"
                style="background: var(--bg-raised);">
                {{ agentIcon(agent.key) }}
              </div>
              <span v-if="agent.has_prompt"
                class="text-[10px] px-2 py-0.5 rounded-full font-semibold flex-shrink-0"
                style="background: var(--badge-ok-bg); color: var(--badge-ok-txt);">
                v{{ agent.version }}
              </span>
              <span v-else
                class="text-[10px] px-2 py-0.5 rounded-full font-semibold flex-shrink-0"
                style="background: var(--badge-danger-bg); color: var(--badge-danger-txt);">
                sin prompt
              </span>
            </div>

            <h3 class="text-sm font-semibold mb-1" style="color: var(--text-1);">
              {{ agent.label }}
            </h3>

            <p v-if="agent.preview" class="text-[11px] leading-relaxed line-clamp-2" style="color: var(--text-3);">
              {{ agent.preview }}
            </p>
            <p v-else class="text-[11px] italic" style="color: var(--text-3);">
              No hay prompt cargado aún.
            </p>

            <div class="flex items-center justify-between mt-4 pt-3"
              style="border-top: 1px solid var(--border-sub);">
              <p class="text-[10px]" style="color: var(--text-3);">
                {{ agent.updated_at ? formatDate(agent.updated_at) : '—' }}
              </p>
              <span class="text-[11px] font-medium transition-colors"
                style="color: #5b5ef6;">
                Editar →
              </span>
            </div>
          </Link>
        </div>
      </section>

    </div>
  </div>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

interface PromptSummary {
  key: string
  label: string
  version: number | null
  updated_at: string | null
  preview: string | null
  has_prompt: boolean
}

defineProps<{ agents: PromptSummary[]; sharedBlocks?: PromptSummary[] }>()

const agentIcon = (key: string): string => ({
  customer_identifier: '👤',
  vehicle_identifier: '🚗',
  coverage_preference: '🛡️',
  quote_reception: '⏳',
  checkout_closer: '💰',
  coverage_check: '📋',
}[key] ?? '🤖')

const sharedIcon = (key: string): string => ({
  shared_style: '✨',
  shared_grounding: '⚓',
}[key] ?? '🧩')

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
</script>
