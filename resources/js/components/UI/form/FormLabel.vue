<script lang="ts" setup>
import type { LabelProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { computed } from "vue"
import { cn } from "@/lib/utils"
import { Label } from '@/components/UI/label'
import { useFormField } from "./useFormField"

const props = defineProps<LabelProps & { class?: HTMLAttributes["class"]; error?: boolean }>()

const { error: fieldError, formItemId } = useFormField()
const hasError = computed(() => props.error || !!fieldError.value)
</script>

<template>
  <Label
    data-slot="form-label"
    :data-error="hasError"
    :class="cn(
      'data-[error=true]:text-destructive',
      props.class,
    )"
    :for="formItemId"
  >
    <slot />
  </Label>
</template>
