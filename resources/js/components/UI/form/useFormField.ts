import { FieldContextKey } from "vee-validate"
import { computed, inject, toValue, unref } from "vue"
import { FORM_ITEM_INJECTION_KEY, type FormItemContext } from "./injectionKeys"

export function useFormField() {
  const fieldContext = inject(FieldContextKey, undefined)
  const fieldItemContext = inject<FormItemContext | undefined>(FORM_ITEM_INJECTION_KEY, undefined)

  if (!fieldItemContext) {
    throw new Error("useFormField should be used within a <FormItem>")
  }

  const name = computed(() => toValue(fieldContext?.name) ?? fieldItemContext.name?.value)
  const error = computed(() => unref(fieldContext?.errorMessage) ?? fieldItemContext.error?.value ?? "")
  const meta = computed(() => fieldContext?.meta)

  const fieldState = {
    valid: computed(() => meta.value?.valid ?? true),
    isDirty: computed(() => meta.value?.dirty ?? false),
    isTouched: computed(() => meta.value?.touched ?? false),
    error,
  }

  return {
    id: fieldItemContext.id,
    name,
    formItemId: `${fieldItemContext.id}-form-item`,
    formDescriptionId: `${fieldItemContext.id}-form-item-description`,
    formMessageId: `${fieldItemContext.id}-form-item-message`,
    ...fieldState,
  }
}
