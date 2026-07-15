<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { computed, useSlots } from "vue"
import { ErrorMessage } from "vee-validate"
import { toValue } from "vue"
import { cn } from "@/lib/utils"
import { useFormField } from "./useFormField"

const props = defineProps<{
  class?: HTMLAttributes["class"]
  message?: string
}>()

const slots = useSlots()
const { name, error: ctxError, formMessageId } = useFormField()

const errorMessage = computed(() => props.message ?? ctxError.value)
const hasInertiaMessage = computed(() => !!slots.default || !!errorMessage.value)
</script>

<template>
  <p
    v-if="hasInertiaMessage"
    :id="formMessageId"
    data-slot="form-message"
    :class="cn('text-destructive text-sm', props.class)"
  >
    <slot>{{ errorMessage }}</slot>
  </p>
  <ErrorMessage
    v-else-if="name"
    :id="formMessageId"
    data-slot="form-message"
    as="p"
    :name="toValue(name)"
    :class="cn('text-destructive text-sm', props.class)"
  />
</template>
