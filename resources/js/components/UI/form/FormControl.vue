<script lang="ts" setup>
import type { HTMLAttributes } from "vue"
import { computed } from "vue"
import { Slot } from "reka-ui"
import { useFormField } from "./useFormField"

const props = defineProps<{ class?: HTMLAttributes["class"]; ariaInvalid?: boolean }>()

const { error: fieldError, formItemId, formDescriptionId, formMessageId } = useFormField()
const invalid = computed(() => props.ariaInvalid || !!fieldError.value)
</script>

<template>
  <Slot
    :id="formItemId"
    data-slot="form-control"
    :aria-describedby="!invalid ? `${formDescriptionId}` : `${formDescriptionId} ${formMessageId}`"
    :aria-invalid="invalid"
    :class="props.class"
  >
    <slot />
  </Slot>
</template>
