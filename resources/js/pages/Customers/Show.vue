<template>
  <div class="py-6 px-4 sm:py-8">
    <div class="max-w-5xl mx-auto space-y-5">

      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div class="flex items-center gap-3">
          <BackLink href="/customers" label="Clientes" />
          <div class="w-px h-4" style="background: var(--border);"></div>
          <Avatar :name="customer.name" />
          <div>
            <h1 class="text-xl sm:text-2xl font-semibold tracking-tight" style="color: var(--text-1);">
              {{ customer.name }}
            </h1>
            <p class="text-xs font-mono mt-0.5" style="color: var(--text-3);">DNI {{ customer.dni }}</p>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <!-- Info -->
        <div class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
          <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">Información</h2>
          <dl class="space-y-3">
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Email</dt>
              <dd class="text-sm font-medium mt-0.5 break-all" style="color: var(--text-1);">{{ customer.email ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Teléfono</dt>
              <dd class="text-sm font-medium mt-0.5" style="color: var(--text-1);">{{ customer.phone ?? '—' }}</dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wide" style="color: var(--text-3);">Registro</dt>
              <dd class="text-sm font-medium mt-0.5" style="color: var(--text-1);">{{ formatDate(customer.created_at) }}</dd>
            </div>
          </dl>

          <div class="mt-5 pt-4 grid grid-cols-2 gap-4 text-center" style="border-top: 1px solid var(--border-sub);">
            <div>
              <p class="text-2xl font-bold tracking-tight" style="color: var(--accent-600);">
                {{ customer.vehicles.length }}
              </p>
              <p class="text-[11px] mt-0.5" style="color: var(--text-3);">Vehículos</p>
            </div>
            <div>
              <p class="text-2xl font-bold tracking-tight" style="color: var(--text-2);">
                {{ customer.conversations.length }}
              </p>
              <p class="text-[11px] mt-0.5" style="color: var(--text-3);">Conversaciones</p>
            </div>
          </div>
        </div>

        <!-- Vehículos + Conversaciones -->
        <div class="lg:col-span-2 space-y-5">

          <!-- Vehículos -->
          <div class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
              Vehículos ({{ customer.vehicles.length }})
            </h2>
            <div v-if="customer.vehicles.length" class="space-y-2.5">
              <div v-for="v in customer.vehicles" :key="v.id"
                class="rounded-[10px] p-4"
                style="border: 1px solid var(--border-sub);">
                <div class="flex items-center gap-2 mb-3">
                  <span class="text-sm font-bold font-mono" style="color: var(--text-1);">{{ v.patente }}</span>
                  <span
                    class="text-[10px] px-2 py-0.5 rounded-full font-semibold"
                    :style="v.is_complete
                      ? 'background: #dcfce7; color: #15803d;'
                      : 'background: #fef3c7; color: #92400e;'"
                  >
                    {{ v.is_complete ? 'Completo' : 'Incompleto' }}
                  </span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                  <div v-for="(val, key) in { Marca: v.marca, Modelo: v.modelo, Año: v.year, Uso: v.uso }"
                    :key="key">
                    <p class="text-[10px] uppercase tracking-wide" style="color: var(--text-3);">{{ key }}</p>
                    <p class="text-xs font-semibold mt-0.5 capitalize" style="color: var(--text-1);">{{ val ?? '—' }}</p>
                  </div>
                </div>
              </div>
            </div>
            <p v-else class="text-sm text-center py-6" style="color: var(--text-3);">Sin vehículos registrados.</p>
          </div>

          <!-- Conversaciones -->
          <div class="rounded-[14px] p-5" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider mb-4" style="color: var(--text-3);">
              Conversaciones ({{ customer.conversations.length }})
            </h2>
            <div v-if="customer.conversations.length" class="space-y-2.5">
              <div v-for="conv in customer.conversations" :key="conv.id"
                class="rounded-[10px] p-4"
                style="border: 1px solid var(--border-sub);">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                  <p class="text-[11px] font-mono break-all" style="color: var(--text-3);">{{ conv.external_conversation_id }}</p>
                  <p class="text-[11px] flex-shrink-0" style="color: var(--text-3);">{{ formatDate(conv.created_at) }}</p>
                </div>
                <p class="text-sm mt-1.5" style="color: var(--text-2);">
                  Última actividad:
                  <span class="font-medium" style="color: var(--text-1);">{{ conv.last_message_at ?? '—' }}</span>
                </p>
              </div>
            </div>
            <p v-else class="text-sm text-center py-6" style="color: var(--text-3);">Sin conversaciones registradas.</p>
          </div>

        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import BackLink from '@/components/UI/BackLink.vue'
import Avatar from '@/components/UI/Avatar.vue'

defineProps<{
  customer: {
    id: number; name: string; dni: string
    email: string | null; phone: string | null; created_at: string
    vehicles: Array<{ id: number; patente: string; marca: string | null; modelo: string | null; year: number | null; uso: string | null; is_complete: boolean }>
    conversations: Array<{ id: number; external_conversation_id: string; last_message_at: string | null; created_at: string }>
  }
}>()

const formatDate = (iso: string) =>
  new Date(iso).toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' })
</script>
