<template>
  <MangoLayout :hide-header="isMobile && quote.status === 'checkout_pending'">

  <!-- ══════ FALLBACK: no es dispositivo móvil ══════ -->
  <div v-if="!isMobile" class="flex items-center justify-center p-6 min-h-[calc(100dvh-58px)]">
    <div class="text-center max-w-sm">
      <div class="text-6xl mb-6">📱</div>
      <h1 class="mg-display text-2xl mb-3">No podés ingresar desde este dispositivo</h1>
      <p class="text-sm leading-relaxed" style="color: var(--mg-fg-dim)">
        Por favor, continuá desde un dispositivo móvil
        <strong style="color: var(--mg-fg)">(Android o iOS)</strong>.<br><br>
        Copiá el link y abrilo desde tu teléfono.
      </p>
      <div class="mt-8 flex justify-center gap-3">
        <div class="mg-card flex items-center gap-2 px-4 py-3">
          <span class="text-2xl">🤖</span>
          <span class="text-sm font-medium" style="color: var(--mg-fg)">Android</span>
        </div>
        <div class="mg-card flex items-center gap-2 px-4 py-3">
          <span class="text-2xl">🍎</span>
          <span class="text-sm font-medium" style="color: var(--mg-fg)">iOS</span>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════ ESTADO INVÁLIDO ══════ -->
  <div v-else-if="quote.status !== 'checkout_pending'"
    class="flex items-center justify-center p-6 min-h-[calc(100dvh-58px)]">
    <div class="text-center max-w-sm">
      <div class="text-6xl mb-6">⚠️</div>
      <h1 class="mg-display text-2xl mb-3">Link no disponible</h1>
      <p class="text-sm leading-relaxed" style="color: var(--mg-fg-dim)">
        Este link de checkout ya fue procesado, expiró o no es válido para cargar información.
      </p>
    </div>
  </div>

  <!-- ══════ FORMULARIO PRINCIPAL (mobile only) ══════ -->
  <div v-else>

    <!-- Header sticky con cobertura -->
    <div class="px-4 py-4 sticky top-0 z-20"
      :style="{ background: 'var(--mg-bg)', borderBottom: '1px solid var(--mg-hairline)' }">
      <div class="flex items-center justify-between mb-3">
        <MangoLogo compact :height="22" />
        <p class="mg-overline">Cobertura</p>
      </div>
      <div class="flex items-end justify-between gap-3">
        <div class="min-w-0">
          <p class="mg-heading text-[15px] truncate">{{ alternative.aseguradora }} — {{ alternative.titulo }}</p>
          <p class="text-xs mt-0.5 truncate" style="color: var(--mg-fg-dim)">{{ vehicle.marca }} {{ vehicle.modelo }}
            {{ vehicle.year }} <span v-if="vehicle.patente">· {{ vehicle.patente }}</span></p>
        </div>
        <div class="text-right flex-shrink-0 leading-none">
          <span class="mg-display text-3xl">${{ formatPrice(alternative.precio) }}</span>
          <span class="text-xs block mt-0.5"
            style="color: var(--mg-fg-dim); font-style: italic; font-family: var(--mg-font-display)">/mes</span>
        </div>
      </div>
    </div>

    <!-- Indicador de pasos -->
    <div class="px-4 py-3.5" :style="{ borderBottom: '1px solid var(--mg-hairline)' }">
      <div class="flex items-center justify-between">
        <div v-for="(label, i) in stepLabels" :key="i" class="flex items-center">
          <div class="flex flex-col items-center">
            <div
              class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-all duration-200"
              :style="stepCircleStyle(i + 1)">
              <span v-if="step > i + 1">✓</span>
              <span v-else>{{ i + 1 }}</span>
            </div>
            <span class="text-[10px] mt-1.5 font-semibold uppercase tracking-wide"
              :style="{ color: step === i + 1 ? 'var(--mg-mango)' : 'var(--mg-fg-dim)' }">{{ label }}</span>
          </div>
          <div v-if="i < stepLabels.length - 1" class="w-5 h-px mb-4 mx-1"
            :style="{ background: 'var(--mg-hairline-strong)' }" />
        </div>
      </div>
    </div>

    <div ref="formRef" class="pb-10 max-w-md mx-auto">

      <!-- ══════════ PASO 1: Datos personales ══════════ -->
      <div v-show="step === 1" class="px-4 pt-6 space-y-4">
        <h2 class="mg-heading text-lg">Datos del tomador</h2>

        <div class="mg-card p-4 space-y-4">
          <div class="grid grid-cols-2 gap-3 items-start">
            <Field label="Nombre *" :error="errors.first_name">
              <input v-model="form.first_name" type="text" name="first_name" placeholder="Juan Alberto" class="mg-field"
                :class="{ 'mg-field-error': errors.first_name }" autocomplete="given-name" />
            </Field>
            <Field label="Apellido *" :error="errors.last_name">
              <input v-model="form.last_name" type="text" name="last_name" placeholder="Pérez" class="mg-field"
                :class="{ 'mg-field-error': errors.last_name }" autocomplete="family-name" />
            </Field>
          </div>

          <Field label="DNI *" :error="errors.dni">
            <input v-model="form.dni" type="text" name="dni" placeholder="30000000" inputmode="numeric" class="mg-field"
              :class="{ 'mg-field-error': errors.dni }" />
          </Field>

          <div class="grid grid-cols-2 gap-3 items-start">
            <Field label="Fecha de nacimiento *" :error="errors.birthdate">
              <input v-model="form.birthdate" type="date" name="birthdate" class="mg-field"
                :class="{ 'mg-field-error': errors.birthdate }" autocomplete="bday" />
            </Field>
            <Field label="Sexo *" :error="errors.sex_id">
              <Select v-model="form.sex_id">
                <SelectTrigger class="w-full h-[38px]" :aria-invalid="!!errors.sex_id || undefined">
                  <SelectValue placeholder="Seleccioná" />
                </SelectTrigger>
                <SelectContent>
                  <SelectGroup>
                    <SelectItem value="M">Masculino</SelectItem>
                    <SelectItem value="F">Femenino</SelectItem>
                  </SelectGroup>
                </SelectContent>
              </Select>
            </Field>
          </div>

          <Field label="Condición fiscal *" :error="errors.tax_condition_id">
            <Select v-model="form.tax_condition_id">
              <SelectTrigger class="w-full h-[38px]" :aria-invalid="!!errors.tax_condition_id || undefined">
                <SelectValue placeholder="Seleccioná la condición frente al IVA" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem v-for="tc in taxConditions" :key="tc.ref" :value="tc.ref">{{ tc.label }}</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
            <p v-if="!taxConditions.length" class="text-xs mt-1" style="color: var(--mg-warn)">
              No se pudieron cargar las condiciones fiscales. Reintentá más tarde.
            </p>
          </Field>

          <Field label="Email *" :error="errors.email">
            <input v-model="form.email" type="email" name="email" placeholder="juan@ejemplo.com" class="mg-field"
              :class="{ 'mg-field-error': errors.email }" autocomplete="email" />
          </Field>

          <Field label="Teléfono *" :error="errors.phone_prefix || errors.phone_number">
            <div class="grid grid-cols-[90px_1fr] gap-3">
              <input v-model="form.phone_prefix" type="tel" name="phone_prefix" placeholder="11" maxlength="3"
                inputmode="numeric" class="mg-field" :class="{ 'mg-field-error': errors.phone_prefix }"
                @input="form.phone_prefix = form.phone_prefix.replace(/\D/g, '').slice(0, 3)" />
              <input v-model="form.phone_number" type="tel" name="phone_number" placeholder="1234567" maxlength="9"
                inputmode="numeric" class="mg-field" :class="{ 'mg-field-error': errors.phone_number }" autocomplete="tel-national"
                @input="form.phone_number = form.phone_number.replace(/\D/g, '').slice(0, 9)" />
            </div>
            <p class="text-xs mt-1" style="color: var(--mg-fg-dim)">Característica (sin 0) y número (sin 15).</p>
          </Field>
        </div>

        <h2 class="mg-heading text-lg pt-2">Domicilio</h2>
        <div class="mg-card p-4 space-y-4">
          <Field label="Calle *" :error="errors.domicilio_calle">
            <input v-model="form.domicilio_calle" type="text" name="domicilio_calle" placeholder="Av. Siempreviva"
              class="mg-field" :class="{ 'mg-field-error': errors.domicilio_calle }" autocomplete="street-address" />
          </Field>

          <div class="grid grid-cols-2 gap-3 items-start">
            <Field label="Número *" :error="errors.domicilio_numero">
              <input v-model="form.domicilio_numero" type="text" name="domicilio_numero" placeholder="742" class="mg-field"
                :class="{ 'mg-field-error': errors.domicilio_numero }" inputmode="numeric" />
            </Field>
            <Field label="Código Postal *" :error="errors.domicilio_cp">
              <input v-model="form.domicilio_cp" type="text" name="domicilio_cp" placeholder="1414" class="mg-field"
                :class="{ 'mg-field-error': errors.domicilio_cp }" inputmode="numeric" autocomplete="postal-code" />
            </Field>
          </div>

          <Field label="Provincia *" :error="errors.domicilio_provincia">
            <Select v-model="form.domicilio_provincia">
              <SelectTrigger class="w-full h-[38px]" :aria-invalid="!!errors.domicilio_provincia || undefined">
                <SelectValue placeholder="Seleccioná la provincia" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem v-for="p in provincias" :key="p" :value="p">{{ p }}</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
          </Field>

          <Field label="Localidad *" :error="errors.domicilio_localidad">
            <input v-model="form.domicilio_localidad" type="text" name="domicilio_localidad" placeholder="Buenos Aires"
              class="mg-field" :class="{ 'mg-field-error': errors.domicilio_localidad }" autocomplete="address-level2" />
          </Field>
        </div>

        <div class="flex justify-end pt-2">
          <button type="button" @click="goToStep(2)" class="mg-btn-primary">Siguiente →</button>
        </div>
      </div>

      <!-- ══════════ PASO 2: Datos de pago ══════════ -->
      <div v-show="step === 2" class="px-4 pt-6 space-y-4">
        <h2 class="mg-heading text-lg">Datos de pago</h2>

        <div class="mg-card p-4 space-y-4">
          <Field label="Marca de tarjeta *" :error="errors.cc_brand">
            <Select v-model="form.cc_brand">
              <SelectTrigger class="w-full h-[38px]" :aria-invalid="!!errors.cc_brand || undefined">
                <SelectValue placeholder="Seleccioná la marca" />
              </SelectTrigger>
              <SelectContent>
                <SelectGroup>
                  <SelectItem value="visa">Visa</SelectItem>
                  <SelectItem value="mastercard">Mastercard</SelectItem>
                  <SelectItem value="amex">American Express</SelectItem>
                  <SelectItem value="naranja">Naranja</SelectItem>
                  <SelectItem value="cabal">Cabal</SelectItem>
                  <SelectItem value="maestro">Maestro</SelectItem>
                </SelectGroup>
              </SelectContent>
            </Select>
          </Field>

          <Field label="Número de tarjeta *" :error="errors.cc_pan">
            <input v-model="form.cc_pan" type="text" name="cc_pan" placeholder="4111 1111 1111 1111" maxlength="19"
              inputmode="numeric" @input="formatPan" @blur="validatePanLuhn" class="mg-field font-mono"
              :class="{ 'mg-field-error': errors.cc_pan }" autocomplete="off" />
          </Field>

          <div class="max-w-[150px]">
            <Field label="Vencimiento *" :error="errors.cc_expiry">
              <input v-model="form.cc_expiry" type="text" name="cc_expiry" placeholder="MM/AA" maxlength="5"
                inputmode="numeric" @input="formatExpiry" class="mg-field font-mono"
                :class="{ 'mg-field-error': errors.cc_expiry }" autocomplete="off" />
            </Field>
          </div>

          <Field label="Nombre del titular *" :error="errors.cc_holder_name">
            <input v-model="form.cc_holder_name" type="text" name="cc_holder_name" placeholder="Juan Alberto Pérez"
              class="mg-field" :class="{ 'mg-field-error': errors.cc_holder_name }" autocomplete="off" />
          </Field>

          <Field label="DNI del titular *" :error="errors.cc_holder_dni">
            <input v-model="form.cc_holder_dni" type="text" name="cc_holder_dni" placeholder="30000000"
              inputmode="numeric" class="mg-field" :class="{ 'mg-field-error': errors.cc_holder_dni }" autocomplete="off" />
            <p class="text-xs mt-1" style="color: var(--mg-fg-dim)">Puede diferir del tomador del seguro.</p>
          </Field>
        </div>

        <div class="flex justify-between pt-2">
          <button type="button" @click="step = 1" class="mg-btn-ghost">← Atrás</button>
          <button type="button" @click="goToStep(3)" class="mg-btn-primary">Siguiente →</button>
        </div>
      </div>

      <!-- ══════════ PASO 3: Verificación del vehículo ══════════ -->
      <div v-show="step === 3" class="px-4 pt-6 space-y-4">
        <h2 class="mg-heading text-lg">Verificación del vehículo</h2>
        <p class="text-xs" style="color: var(--mg-fg-dim)">Confirmá que los datos del vehículo sean correctos. Son
          inmutables: vienen del snapshot de la cotización.</p>

        <!-- Datos inmutables del snapshot -->
        <div class="mg-card p-4 space-y-3" :style="{ background: 'var(--mg-mango-tint)', borderColor: 'transparent' }">
          <div class="flex items-center justify-between">
            <span class="mg-overline" style="color: var(--mg-mango)">Datos del snapshot</span>
            <span class="text-[10px] rounded-full px-2 py-0.5 font-semibold uppercase tracking-wide"
              :style="{ background: 'var(--mg-mango)', color: '#fff' }">Solo lectura</span>
          </div>
          <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <ReadOnlyField v-if="vehicle.patente" label="Patente" :value="vehicle.patente" />
            <ReadOnlyField label="Marca" :value="vehicle.marca" />
            <ReadOnlyField label="Modelo" :value="vehicle.modelo" />
            <ReadOnlyField label="Año" :value="String(vehicle.year)" />
            <ReadOnlyField label="Versión" :value="vehicle.version" />
            <ReadOnlyField label="Combustible" :value="vehicle.combustible" />
          </div>
        </div>

        <!-- Datos adicionales que ingresa el cliente -->
        <h3 class="mg-heading text-sm pt-1">Datos adicionales</h3>
        <div class="mg-card p-4 space-y-4">
          <Field label="Uso del vehículo *" :error="errors.vehiculo_uso">
            <div class="grid grid-cols-2 gap-3 mt-1">
              <label class="flex items-center gap-2 rounded-xl px-3 py-2.5 cursor-pointer transition-colors"
                :style="selectableStyle(form.vehiculo_uso === 'particular')">
                <input type="radio" v-model="form.vehiculo_uso" value="particular" name="vehiculo_uso" class="hidden" />
                <span class="text-xl">🚗</span>
                <span class="text-sm font-medium" style="color: var(--mg-fg)">Particular</span>
              </label>
              <label class="flex items-center gap-2 rounded-xl px-3 py-2.5 cursor-pointer transition-colors"
                :style="selectableStyle(form.vehiculo_uso === 'otro')">
                <input type="radio" v-model="form.vehiculo_uso" value="otro" name="vehiculo_uso" class="hidden" />
                <span class="text-xl">🚕</span>
                <span class="text-sm font-medium" style="color: var(--mg-fg)">Otro</span>
              </label>
            </div>
            <p v-if="errors.vehiculo_uso" class="text-xs mt-1" style="color: var(--mg-bad)">{{ errors.vehiculo_uso }}</p>
          </Field>

          <Field label="Nro. de chasis *" :error="errors.vehiculo_nro_chasis">
            <input v-model="form.vehiculo_nro_chasis" type="text" name="vehiculo_nro_chasis"
              placeholder="9BWZZZ377VT004251" class="mg-field font-mono text-sm"
              :class="{ 'mg-field-error': errors.vehiculo_nro_chasis }" style="text-transform: uppercase"
              @input="form.vehiculo_nro_chasis = form.vehiculo_nro_chasis.toUpperCase()" />
          </Field>

          <Field label="Nro. de motor *" :error="errors.vehiculo_nro_motor">
            <input v-model="form.vehiculo_nro_motor" type="text" name="vehiculo_nro_motor" placeholder="AZD5789"
              class="mg-field font-mono text-sm" :class="{ 'mg-field-error': errors.vehiculo_nro_motor }"
              style="text-transform: uppercase"
              @input="form.vehiculo_nro_motor = form.vehiculo_nro_motor.toUpperCase()" />
          </Field>

          <label class="flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 cursor-pointer transition-colors"
            :style="selectableStyle(form.has_gnc)">
            <span class="flex items-center gap-2">
              <span class="text-xl">⛽</span>
              <span class="text-sm font-medium" style="color: var(--mg-fg)">¿Tiene equipo de GNC?</span>
            </span>
            <input type="checkbox" v-model="form.has_gnc" name="has_gnc" class="w-5 h-5"
              style="accent-color: var(--mg-mango)" />
          </label>
          <p class="text-xs -mt-2" style="color: var(--mg-fg-dim)">Si tiene gas, te pedimos fotos del tubo y la oblea en
            el paso de inspección.</p>
        </div>

        <div class="flex justify-between pt-2">
          <button type="button" @click="step = 2" class="mg-btn-ghost">← Atrás</button>
          <button type="button" @click="goToStep(4)" class="mg-btn-primary">Siguiente →</button>
        </div>
      </div>

      <!-- ══════════ PASO 4: Inspección fotográfica ══════════ -->
      <div v-show="step === 4" class="px-4 pt-6 space-y-4">
        <h2 class="mg-heading text-lg">Inspección fotográfica</h2>
        <p class="text-xs leading-relaxed" style="color: var(--mg-fg-dim)">
          Sacá cada foto <strong style="color: var(--mg-fg)">en este momento</strong> con la cámara de tu teléfono.
          No se pueden subir imágenes desde la galería.
        </p>

        <div class="space-y-3">
          <div v-for="(slot, i) in photoSlots" :key="slot.key"
            class="mg-card overflow-hidden transition-colors" :style="photoSlotStyle(slot.key)">
            <div class="flex items-center gap-3 p-3">
              <!-- Preview / ícono -->
              <div class="flex-shrink-0 w-16 h-16 rounded-xl overflow-hidden flex items-center justify-center"
                :style="{ background: 'var(--mg-surface-2)' }">
                <img v-if="photos[slot.key]" :src="photos[slot.key]" class="w-full h-full object-cover"
                  :alt="slot.label" />
                <div v-else-if="uploading[slot.key]"
                  class="animate-spin w-6 h-6 border-2 rounded-full"
                  :style="{ borderColor: 'var(--mg-mango)', borderTopColor: 'transparent' }"></div>
                <span v-else class="text-2xl">{{ slot.icon }}</span>
              </div>

              <div class="flex-1 min-w-0">
                <p class="font-medium text-sm" style="color: var(--mg-fg)">{{ slot.label }}</p>
                <p v-if="uploading[slot.key]" class="text-xs mt-0.5" style="color: var(--mg-mango)">Subiendo foto…</p>
                <p v-else class="text-xs mt-0.5" style="color: var(--mg-fg-dim)">{{ slot.hint }}</p>
                <p v-if="errors[`photo_${slot.key}`]" class="text-xs mt-0.5" style="color: var(--mg-bad)">{{
                  errors[`photo_${slot.key}`] }}</p>
              </div>

              <!-- Botón eliminar (solo si hay foto subida) -->
              <button v-if="photoIds[slot.key] && !uploading[slot.key]"
                type="button"
                @click="removePhoto(slot.key)"
                class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center transition-colors"
                :style="{ background: 'var(--mg-mango-tint)' }">
                <svg class="w-4 h-4" style="color: var(--mg-bad)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                </svg>
              </button>

              <!-- Botón cámara -->
              <label class="flex-shrink-0 cursor-pointer"
                :class="{ 'pointer-events-none opacity-50': uploading[slot.key] }"
                @click.stop>
                <input type="file" accept="image/*" capture="environment" class="hidden"
                  @change="onPhotoCapture($event, slot.key)" :disabled="!!uploading[slot.key]" />
                <div class="w-10 h-10 rounded-full flex items-center justify-center transition-colors"
                  :style="cameraBtnStyle(slot.key)">
                  <span v-if="photoIds[slot.key]" class="text-white text-sm">✓</span>
                  <svg v-else class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                </div>
              </label>
            </div>
          </div>
        </div>

        <!-- Progreso de fotos -->
        <div class="mg-card p-3">
          <div class="flex items-center justify-between mb-2">
            <span class="mg-overline">Progreso</span>
            <span class="text-sm font-bold"
              :style="{ color: photoCount === photoSlots.length ? 'var(--mg-ok)' : 'var(--mg-fg-dim)' }">{{ photoCount
              }}/{{ photoSlots.length }}</span>
          </div>
          <div class="flex gap-1">
            <div v-for="(slot, i) in photoSlots" :key="slot.key" class="h-1.5 flex-1 rounded-full transition-colors"
              :style="{ background: photoIds[slot.key] ? 'var(--mg-ok)' : 'var(--mg-hairline-strong)' }" />
          </div>
        </div>

        <!-- Resumen antes del envío -->
        <div class="mg-card p-4 text-sm" :style="{ background: 'var(--mg-surface-2)' }">
          <p class="mg-overline mb-2">Resumen final</p>
          <div class="space-y-1" style="color: var(--mg-fg)">
            <p><span style="color: var(--mg-fg-dim)">Tomador:</span> {{ `${form.first_name} ${form.last_name}`.trim() ||
              '—' }}</p>
            <p><span style="color: var(--mg-fg-dim)">Vehículo:</span> {{ vehicle.marca }} {{ vehicle.modelo }} {{
              vehicle.year }}</p>
            <p><span style="color: var(--mg-fg-dim)">Cobertura:</span> {{ alternative.aseguradora }} — {{
              alternative.titulo }}</p>
            <p><span style="color: var(--mg-fg-dim)">Prima:</span> ${{ formatPrice(alternative.precio) }}/mes</p>
            <p><span style="color: var(--mg-fg-dim)">Fotos:</span> {{ photoCount }}/{{ photoSlots.length }}</p>
          </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
          <button type="button" @click="step = 3" class="mg-btn-ghost flex-shrink-0">← Atrás</button>
          <button type="button" :disabled="submitting || photoCount < photoSlots.length" class="mg-btn-submit"
            @click="submitForm">
            <span v-if="submitting">Enviando…</span>
            <span v-else-if="photoCount < photoSlots.length">Fotos incompletas ({{ photoCount }}/{{ photoSlots.length
            }})</span>
            <span v-else>Confirmar y enviar ✓</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════ MODAL DE AVISO (reemplaza window.alert) ══════ -->
  <Transition name="fade">
    <div v-if="notice" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-6"
      @click.self="closeNotice">
      <div class="mg-card w-full max-w-sm p-6 text-center" :style="{ boxShadow: '0 20px 60px rgba(0,0,0,.35)' }">
        <h3 class="mg-heading text-lg mb-2">{{ notice.title }}</h3>
        <p class="text-sm leading-relaxed mb-6" style="color: var(--mg-fg-dim)">{{ notice.message }}</p>
        <button type="button" class="mg-btn-primary w-full" @click="closeNotice">Entendido</button>
      </div>
    </div>
  </Transition>

  </MangoLayout>
</template>

<script setup lang="ts">
import { ref, reactive, computed, defineComponent, h, onMounted, onUnmounted, watch } from 'vue'
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/UI/select'
import MangoLayout from '@/layouts/MangoLayout.vue'
import MangoLogo from '@/components/Mango/MangoLogo.vue'

// ─── Componentes inline ────────────────────────────────────────────────────────
const Field = defineComponent({
  props: { label: String, error: String },
  setup(props, { slots }) {
    return () => h('div', [
      h('span', {
        class: 'block text-sm font-medium mb-1.5',
        style: 'color: var(--mg-fg); font-family: var(--mg-font-ui)',
      }, props.label),
      slots.default?.(),
      props.error ? h('p', { class: 'mt-1 text-xs', style: 'color: var(--mg-bad)' }, props.error) : null,
    ])
  }
})

const ReadOnlyField = defineComponent({
  props: { label: String, value: String },
  setup(props) {
    return () => h('div', [
      h('p', { class: 'mg-overline' }, props.label),
      h('p', {
        class: 'font-semibold truncate mt-0.5',
        style: 'color: var(--mg-fg); font-family: var(--mg-font-ui)',
      }, props.value || '—'),
    ])
  }
})

// ─── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
  quote: { id: number; status: string }
  alternative: {
    id: number; aseguradora: string; titulo: string; descripcion: string
    precio: number; moneda: string; marketing_title: string
    features_tags: string[]; normalized_grade: string
  }
  vehicle: {
    patente: string | null; marca: string; modelo: string
    version: string; year: number; combustible: string
  }
  taxConditions: { ref: string; label: string }[]
  checkoutToken: string
  submitUrl: string
  uploadPhotoUrl: string
  deletePhotoUrl: string
}>()

// ─── Mobile detection ──────────────────────────────────────────────────────────
// El form solo se completa desde un celular (cámara + flujo de inspección).
const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
// ─── CSRF ──────────────────────────────────────────────────────────────────────
const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''

// ─── Prevenir que Inertia recargue el componente al volver de la cámara ────────
// Android dispara popstate cuando vuelve de la cámara, Inertia lo intercepta
// y destruye el estado de Vue. Capturamos el evento antes que Inertia (fase capture).
const stopPopState = (e: PopStateEvent) => {
  e.stopImmediatePropagation()
}

// También prevenimos visibilitychange que puede triggear una visita de Inertia
const stopVisibilityChange = (e: Event) => {
  e.stopImmediatePropagation()
}

onMounted(() => {
  window.addEventListener('popstate', stopPopState, true)
  document.addEventListener('visibilitychange', stopVisibilityChange, true)
})

onUnmounted(() => {
  window.removeEventListener('popstate', stopPopState, true)
  document.removeEventListener('visibilitychange', stopVisibilityChange, true)
})

// ─── Wizard ────────────────────────────────────────────────────────────────────
const step = ref(1)
const stepLabels = ['Personal', 'Pago', 'Vehículo', 'Inspección']
const submitting = ref(false)

const stepCircleStyle = (s: number) => {
  if (step.value > s) return { background: 'var(--mg-ok)', color: '#fff' }
  if (step.value === s) return { background: 'var(--mg-mango)', color: '#fff' }
  return { background: 'transparent', color: 'var(--mg-fg-dim)', border: '1px solid var(--mg-hairline-strong)' }
}

// ─── Estilos de marca para elementos seleccionables / fotos ─────────────────────
const selectableStyle = (active: boolean) =>
  active
    ? { background: 'var(--mg-mango-tint)', border: '1px solid var(--mg-mango)' }
    : { background: 'transparent', border: '1px solid var(--mg-hairline-strong)' }

const photoSlotStyle = (key: string) => {
  if (photoIds[key]) return { borderColor: 'var(--mg-ok)' }
  if (uploading[key]) return { borderColor: 'var(--mg-mango)' }
  if (errors[`photo_${key}`]) return { borderColor: 'var(--mg-bad)' }
  return {}
}

const cameraBtnStyle = (key: string) => {
  if (photoIds[key]) return { background: 'var(--mg-ok)' }
  if (uploading[key]) return { background: 'var(--mg-fg-faint)' }
  return { background: 'var(--mg-mango)' }
}

// ─── Modal de aviso (reemplaza window.alert — diálogos nativos prohibidos) ──────
const notice = ref<{ title: string; message: string; onClose?: () => void } | null>(null)
const closeNotice = () => {
  const cb = notice.value?.onClose
  notice.value = null
  cb?.()
}

// ─── Form data ─────────────────────────────────────────────────────────────────
// Titular completo para la emisión Visred (PreSaleVehicleRequest.person_holder):
// nombre/teléfono se capturan partidos (first/last, prefix/number) y se agregan
// birthdate/sex_id/tax_condition_id. `person_type_id`/`document_type_id` los
// defaultea el adapter (física/DNI). Ver ROADMAP (D1, WS-B).
const form = reactive({
  first_name: '', last_name: '', dni: '', email: '',
  birthdate: '', sex_id: '' as 'M' | 'F' | '',
  tax_condition_id: '',
  phone_prefix: '', phone_number: '',
  domicilio_calle: '', domicilio_numero: '', domicilio_cp: '',
  domicilio_provincia: '', domicilio_localidad: '',
  cc_brand: '', cc_pan: '', cc_expiry: '', cc_holder_name: '', cc_holder_dni: '',
  vehiculo_uso: '' as 'particular' | 'otro' | '',
  vehiculo_nro_chasis: '', vehiculo_nro_motor: '',
  // Default: si el snapshot ya dice GNC, arranca tildado (igual es editable).
  has_gnc: props.vehicle.combustible?.toLowerCase() === 'gnc',
})

const errors = reactive<Record<string, string>>({})

// ─── Provincias ────────────────────────────────────────────────────────────────
const provincias = [
  'Buenos Aires', 'CABA', 'Catamarca', 'Chaco', 'Chubut', 'Córdoba',
  'Corrientes', 'Entre Ríos', 'Formosa', 'Jujuy', 'La Pampa', 'La Rioja',
  'Mendoza', 'Misiones', 'Neuquén', 'Río Negro', 'Salta', 'San Juan',
  'San Luis', 'Santa Cruz', 'Santa Fe', 'Santiago del Estero',
  'Tierra del Fuego', 'Tucumán',
]

// ─── Fotos ─────────────────────────────────────────────────────────────────────
// Slots base (incluye velocímetro, que algunas compañías exigen — D2). Los slots
// de GNC se agregan solo si el vehículo tiene equipo (form.has_gnc).
const baseSlots = [
  { key: 'tarjeta_verde', label: 'Frente Tarjeta Verde', icon: '📝', hint: 'Foto de frente' },
  { key: 'frente', label: 'Frente del vehículo', icon: '🚗', hint: 'Vista frontal completa' },
  { key: 'atras', label: 'Atrás del vehículo', icon: '🔙', hint: 'Vista trasera completa' },
  { key: 'lateral_i', label: 'Lateral izquierdo', icon: '◀️', hint: 'Desde el lado izquierdo' },
  { key: 'lateral_d', label: 'Lateral derecho', icon: '▶️', hint: 'Desde el lado derecho' },
  { key: 'auxilio', label: 'Rueda de auxilio', icon: '🔧', hint: 'En su habitáculo / baúl' },
  { key: 'parabrisas', label: 'Parabrisas desde el interior', icon: '🪟', hint: 'Sentado adentro, mirando adelante' },
  { key: 'velocimetro', label: 'Velocímetro', icon: '⏱️', hint: 'Tablero con el kilometraje visible' },
]

const gncSlots = [
  { key: 'tubo_gnc', label: 'Tubo de GNC', icon: '🛢️', hint: 'Cilindro de gas en el baúl' },
  { key: 'oblea_gnc', label: 'Oblea de GNC', icon: '🏷️', hint: 'Oblea de la revisión vigente' },
]

const photoSlots = computed(() => form.has_gnc ? [...baseSlots, ...gncSlots] : baseSlots)

// Al destildar GNC, soltar las fotos de GNC ya tomadas (evita conteo inconsistente).
watch(() => form.has_gnc, (hasGnc) => {
  if (!hasGnc) {
    gncSlots.forEach(slot => {
      if (photoIds[slot.key]) removePhoto(slot.key)
    })
  }
})

const photos = reactive<Record<string, string>>({})     // key → preview URL (R2 URL)
const photoIds = reactive<Record<string, string>>({})   // key → R2 storage_path
const uploading = reactive<Record<string, boolean>>({}) // key → upload in progress
const photoCount = computed(() => Object.keys(photoIds).length)

/**
 * Redimensiona a máx 1024px, convierte a JPEG, y genera un micro-thumbnail de 64px
 * para preview en el DOM (evita que el browser decodifique la imagen completa como bitmap).
 * Liberación agresiva de memoria en cada paso — crítico para no crashear en mobile.
 */
const processPhoto = (file: File): Promise<{ file: File; thumb: string }> =>
  new Promise((resolve, reject) => {
    const img = new Image()
    const objectUrl = URL.createObjectURL(file)

    const destroyImage = () => {
      img.onload = null
      img.onerror = null
      img.src = ''
    }

    img.onerror = () => {
      URL.revokeObjectURL(objectUrl)
      destroyImage()
      reject(new Error('No se pudo procesar la imagen'))
    }

    img.onload = () => {
      URL.revokeObjectURL(objectUrl)
      try {
        const MAX = 1024
        let w = img.width, h = img.height
        if (w > MAX || h > MAX) {
          if (w >= h) { h = Math.round((h * MAX) / w); w = MAX }
          else { w = Math.round((w * MAX) / h); h = MAX }
        }
        const canvas = document.createElement('canvas')
        canvas.width = w
        canvas.height = h
        const ctx = canvas.getContext('2d')
        ctx?.drawImage(img, 0, 0, w, h)

        // Destruir imagen original para liberar RAM lo antes posible
        destroyImage()

        // Generar micro-thumbnail 64px desde el canvas (antes de destruirlo)
        // El preview en el DOM usa esto en vez de la URL completa → 16KB bitmap vs 4MB
        const THUMB = 64
        const thumbCanvas = document.createElement('canvas')
        thumbCanvas.width = THUMB
        thumbCanvas.height = THUMB
        const tCtx = thumbCanvas.getContext('2d')
        const min = Math.min(w, h)
        const sx = (w - min) / 2
        const sy = (h - min) / 2
        tCtx?.drawImage(canvas, sx, sy, min, min, 0, 0, THUMB, THUMB)
        const thumb = thumbCanvas.toDataURL('image/jpeg', 0.4)
        thumbCanvas.width = 0
        thumbCanvas.height = 0

        canvas.toBlob(blob => {
          // Liberar canvas de la memoria
          canvas.width = 0
          canvas.height = 0

          if (!blob) { reject(new Error('Error al generar JPEG')); return }
          resolve({ file: new File([blob], 'photo.jpg', { type: 'image/jpeg' }), thumb })
        }, 'image/jpeg', 0.7)
      } catch (err) {
        destroyImage()
        reject(err)
      }
    }
    img.src = objectUrl
  })

/** Lock para serializar compresiones — evita dos bitmaps simultáneos en memoria. */
let processingLock = false

/** Sube una foto al servidor (que la persiste en R2), liberando la memoria inmediatamente. */
const onPhotoCapture = async (e: Event, key: string) => {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || processingLock) return
  // Limpiar el input para liberar la referencia al archivo original
  input.value = ''
  delete errors[`photo_${key}`]
  processingLock = true
  uploading[key] = true

  try {
    console.log(`[onPhotoCapture] Iniciando proceso para foto: ${key}`)
    const { file: processedFile, thumb } = await processPhoto(file)
    console.log(`[onPhotoCapture] Foto procesada correctamente.`)

    // Subir al servidor (que persiste en R2)
    const fd = new FormData()
    fd.append('_token', csrfToken)
    fd.append('checkout_token', props.checkoutToken)
    fd.append('photo_key', key)
    fd.append('photo', processedFile)

    const res = await fetch(props.uploadPhotoUrl, { method: 'POST', body: fd })
    
    let data;
    try {
      data = await res.json()
    } catch(err) {
      console.error("[onPhotoCapture] Error leyendo JSON de respuesta", err)
      throw new Error('Respuesta del servidor no es JSON')
    }

    if (!res.ok || !data.success) {
      console.error("[onPhotoCapture] Error del servidor:", data)
      throw new Error(data.error || 'Error al subir la foto')
    }

    // Guardar storage_path y thumbnail para preview (no la URL completa de R2)
    photoIds[key] = data.public_id
    photos[key] = thumb  // micro-thumbnail 64px data URL (~3KB) — no la imagen completa
    console.log(`[onPhotoCapture] Éxito: ${data.public_id}`, data.url)
    // El File ya fue enviado y no se almacena en memoria
  } catch (err: any) {
    console.error(`[onPhotoCapture] Gran error capturado para ${key}:`, err)
    errors[`photo_${key}`] = err.message || 'Error al subir la foto. Intentá de nuevo.'
  } finally {
    uploading[key] = false
    processingLock = false
  }
}

/** 
 * Elimina una foto mediante Optimistic UI. 
 * Primero limpia la interfaz y luego pide la eliminación del asset en backend. 
 */
const removePhoto = async (key: string) => {
  // 1. Optimistic UI: Limpiar localmente la foto instantáneamente
  if (photos[key] && photos[key].startsWith('blob:')) {
    URL.revokeObjectURL(photos[key])
  }
  delete photos[key]
  delete photoIds[key]
  delete errors[`photo_${key}`]

  // 2. Ejecutar borrado asíncrono hacia el backend
  try {
    const res = await fetch(props.deletePhotoUrl, {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({
        checkout_token: props.checkoutToken,
        photo_key: key
      })
    })

    let data = {}
    try {
      data = await res.json()
    } catch {
      // Ignorar error si la respuesta no trae body (ej 204 No Content)
    }

    if (!res.ok) {
      throw new Error((data as any).error || 'Error de red al borrar')
    }
  } catch (err) {
    // 3. Fallo en red: Como es Optimistic UI, informamos el problema pero lo hemos ocultado.  
    // Si bien no existe local, la CleanupTempPhotos lo borrará 24h más tarde.
    console.error(`Fallo borrado silencioso de foto ${key}`, err)
    notice.value = {
      title: 'No se pudo limpiar la foto',
      message: 'Hubo un error de conexión, pero podés continuar completando el formulario.',
    }
  }
}

// ─── Luhn ──────────────────────────────────────────────────────────────────────
const luhn = (num: string): boolean => {
  const digits = num.replace(/\D/g, '')
  if (digits.length === 0) return false
  let sum = 0, isEven = false
  for (let i = digits.length - 1; i >= 0; i--) {
    let n = parseInt(digits[i])
    if (isEven) { n *= 2; if (n > 9) n -= 9 }
    sum += n; isEven = !isEven
  }
  return sum % 10 === 0
}

// ─── Formateo de tarjeta ───────────────────────────────────────────────────────
const formatPan = () => {
  const raw = form.cc_pan.replace(/\D/g, '').slice(0, 16)
  form.cc_pan = raw.replace(/(.{4})/g, '$1 ').trim()
}

const validatePanLuhn = () => {
  const raw = form.cc_pan.replace(/\s/g, '')
  if (raw.length > 0 && !luhn(raw))
    errors.cc_pan = 'Número de tarjeta inválido'
  else
    delete errors.cc_pan
}

const formatExpiry = () => {
  const raw = form.cc_expiry.replace(/\D/g, '').slice(0, 4)
  form.cc_expiry = raw.length > 2 ? `${raw.slice(0, 2)}/${raw.slice(2)}` : raw
}

// ─── Validación por paso ───────────────────────────────────────────────────────
const validateStep = (s: number): boolean => {
  const clear = (keys: string[]) => keys.forEach(k => delete errors[k])

  if (s === 1) {
    clear(['first_name', 'last_name', 'dni', 'birthdate', 'sex_id', 'tax_condition_id', 'email', 'phone_prefix', 'phone_number', 'domicilio_calle', 'domicilio_numero', 'domicilio_cp', 'domicilio_provincia', 'domicilio_localidad'])
    if (!form.first_name.trim()) errors.first_name = 'Requerido'
    if (!form.last_name.trim()) errors.last_name = 'Requerido'
    if (!form.dni.trim()) errors.dni = 'Requerido'
    if (!form.birthdate) errors.birthdate = 'Requerido'
    if (!form.sex_id) errors.sex_id = 'Requerido'
    if (!form.tax_condition_id) errors.tax_condition_id = 'Requerido'
    if (!form.email.trim()) errors.email = 'Requerido'
    if (!form.phone_prefix.trim()) errors.phone_prefix = 'Característica requerida'
    if (!form.phone_number.trim()) errors.phone_number = 'Número requerido'
    if (!form.domicilio_calle.trim()) errors.domicilio_calle = 'Requerido'
    if (!form.domicilio_numero.trim()) errors.domicilio_numero = 'Requerido'
    if (!form.domicilio_cp.trim()) errors.domicilio_cp = 'Requerido'
    if (!form.domicilio_provincia) errors.domicilio_provincia = 'Requerido'
    if (!form.domicilio_localidad.trim()) errors.domicilio_localidad = 'Requerido'
  }

  if (s === 2) {
    clear(['cc_brand', 'cc_pan', 'cc_expiry', 'cc_holder_name', 'cc_holder_dni'])
    if (!form.cc_brand) errors.cc_brand = 'Seleccioná la marca'
    const pan = form.cc_pan.replace(/\s/g, '')
    if (pan.length !== 16) errors.cc_pan = 'Ingresá los 16 dígitos'
    else if (!luhn(pan)) errors.cc_pan = 'Número de tarjeta inválido'
    if (!/^\d{2}\/\d{2}$/.test(form.cc_expiry)) errors.cc_expiry = 'Formato MM/AA'
    if (!form.cc_holder_name.trim()) errors.cc_holder_name = 'Requerido'
    if (!form.cc_holder_dni.trim()) errors.cc_holder_dni = 'Requerido'
  }

  if (s === 3) {
    clear(['vehiculo_uso', 'vehiculo_nro_chasis', 'vehiculo_nro_motor'])
    if (!form.vehiculo_uso) errors.vehiculo_uso = 'Seleccioná el tipo de uso'
    if (!form.vehiculo_nro_chasis.trim()) errors.vehiculo_nro_chasis = 'Requerido'
    if (!form.vehiculo_nro_motor.trim()) errors.vehiculo_nro_motor = 'Requerido'
  }

  if (s === 4) {
    photoSlots.value.forEach(slot => {
      if (!photoIds[slot.key]) errors[`photo_${slot.key}`] = 'Foto requerida'
    })
  }

  return !Object.keys(errors).some(k => {
    const step1keys = ['first_name', 'last_name', 'dni', 'birthdate', 'sex_id', 'tax_condition_id', 'email', 'phone_prefix', 'phone_number', 'domicilio_calle', 'domicilio_numero', 'domicilio_cp', 'domicilio_provincia', 'domicilio_localidad']
    const step2keys = ['cc_brand', 'cc_pan', 'cc_expiry', 'cc_holder_name', 'cc_holder_dni']
    const step3keys = ['vehiculo_uso', 'vehiculo_nro_chasis', 'vehiculo_nro_motor']
    if (s === 1) return step1keys.includes(k)
    if (s === 2) return step2keys.includes(k)
    if (s === 3) return step3keys.includes(k)
    return k.startsWith('photo_')
  })
}

const goToStep = (s: number) => {
  if (validateStep(step.value)) step.value = s
}

// ─── Submit ────────────────────────────────────────────────────────────────────
const submitForm = async () => {
  if (!validateStep(4) || photoCount.value < photoSlots.value.length) return
  submitting.value = true

  // Construir el payload JSON (liviano — solo strings, no archivos)
  const payload: Record<string, any> = {
    checkout_token: props.checkoutToken,
    first_name: form.first_name,
    last_name: form.last_name,
    dni: form.dni,
    birthdate: form.birthdate,
    sex_id: form.sex_id,
    tax_condition_id: form.tax_condition_id,
    email: form.email,
    phone_prefix: form.phone_prefix,
    phone_number: form.phone_number,
    domicilio_calle: form.domicilio_calle,
    domicilio_numero: form.domicilio_numero,
    domicilio_cp: form.domicilio_cp,
    domicilio_provincia: form.domicilio_provincia,
    domicilio_localidad: form.domicilio_localidad,
    vehiculo_uso: form.vehiculo_uso,
    vehiculo_nro_chasis: form.vehiculo_nro_chasis,
    vehiculo_nro_motor: form.vehiculo_nro_motor,
    has_gnc: form.has_gnc,
    cc_brand: form.cc_brand,
    cc_pan: form.cc_pan.replace(/\s/g, ''),
    cc_expiry: form.cc_expiry,
    cc_holder_name: form.cc_holder_name,
    cc_holder_dni: form.cc_holder_dni,
    photo_ids: { ...photoIds },
  }

  try {
    const res = await fetch(props.submitUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify(payload),
    })

    const data = await res.json().catch(() => ({}))

    if (res.ok && data.redirect_url) {
      window.location.href = data.redirect_url
      return
    }

    if (res.status === 409) {
      notice.value = {
        title: 'Link no disponible',
        message: 'El link de checkout expiró o ya fue procesado.',
        onClose: () => window.location.reload(),
      }
      return
    }

    // Errores de validación
    if (data.errors) {
      Object.assign(errors, data.errors)
      const s1 = ['first_name', 'last_name', 'dni', 'birthdate', 'sex_id', 'tax_condition_id', 'email', 'phone_prefix', 'phone_number', 'domicilio_calle', 'domicilio_numero', 'domicilio_cp', 'domicilio_provincia', 'domicilio_localidad']
      const s2 = ['cc_brand', 'cc_pan', 'cc_expiry', 'cc_holder_name', 'cc_holder_dni']
      const s3 = ['vehiculo_uso', 'vehiculo_nro_chasis', 'vehiculo_nro_motor']
      if (Object.keys(data.errors).some((k: string) => s1.includes(k))) step.value = 1
      else if (Object.keys(data.errors).some((k: string) => s2.includes(k))) step.value = 2
      else if (Object.keys(data.errors).some((k: string) => s3.includes(k))) step.value = 3
      else step.value = 4
    }
  } catch {
    notice.value = {
      title: 'Error de conexión',
      message: 'Verificá tu internet e intentá de nuevo.',
    }
  } finally {
    submitting.value = false
  }
}

// ─── Precio ────────────────────────────────────────────────────────────────────
const formatPrice = (n: number) =>
  new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0 }).format(n)
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
