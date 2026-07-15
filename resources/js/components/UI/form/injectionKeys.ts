import type { InjectionKey, Ref } from "vue"

export interface FormItemContext {
  id: string
  name?: Ref<string | undefined>
  error?: Ref<string | undefined>
}

export const FORM_ITEM_INJECTION_KEY
  = Symbol() as InjectionKey<FormItemContext>
