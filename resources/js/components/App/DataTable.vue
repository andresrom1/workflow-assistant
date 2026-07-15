<script setup lang="ts" generic="T extends Record<string, any>">
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/UI/table'
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue'
import { computed } from 'vue'

export type SortDirection = 'asc' | 'desc'

export interface DataTableColumn<T> {
  key: string
  label: string
  sortable?: boolean
  align?: 'left' | 'center' | 'right'
  class?: string
  cellClass?: string | ((item: T) => string)
  wrap?: boolean
}

const props = defineProps<{
  columns: DataTableColumn<T>[]
  data: T[]
  sort?: string | null
  direction?: SortDirection
  emptyMessage?: string
  compact?: boolean
}>()

const emit = defineEmits<{
  (e: 'sort', column: string, direction: SortDirection): void
  (e: 'row-click', item: T): void
}>()

const alignClass = (align?: 'left' | 'center' | 'right') => {
  switch (align) {
    case 'center':
      return 'text-center'
    case 'right':
      return 'text-right'
    default:
      return 'text-left'
  }
}

const nextDirection = (column: string): SortDirection => {
  if (props.sort === column) {
    return props.direction === 'asc' ? 'desc' : 'asc'
  }
  return 'asc'
}

const handleSort = (column: DataTableColumn<T>) => {
  if (!column.sortable) return
  emit('sort', column.key, nextDirection(column.key))
}

const isSorted = (key: string) => props.sort === key
const sortIconFor = (key: string) => {
  if (!isSorted(key)) return ArrowUpDown
  return props.direction === 'asc' ? ArrowUp : ArrowDown
}

const resolveCellClass = (column: DataTableColumn<T>, item: T): string => {
  const base = typeof column.cellClass === 'function'
    ? column.cellClass(item)
    : column.cellClass
  return base ?? ''
}
</script>

<template>
  <div>
    <!-- Desktop table -->
    <div class="hidden md:block rounded-[14px] overflow-hidden" style="background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-card);">
      <Table class="table-fixed">
        <TableHeader>
          <TableRow class="border-b" style="background: var(--bg-raised);">
            <TableHead
              v-for="column in columns"
              :key="column.key"
              :class="[
                'text-[11px] font-semibold uppercase tracking-wider',
                compact ? 'py-2 px-3' : 'py-3 px-5',
                alignClass(column.align),
                column.class,
                column.wrap ? 'whitespace-normal' : 'whitespace-nowrap',
                column.sortable ? 'cursor-pointer select-none' : '',
              ]"
              style="color: var(--text-3);"
              @click="handleSort(column)"
            >
              <div class="flex items-center gap-1" :class="column.align === 'center' ? 'justify-center' : column.align === 'right' ? 'justify-end' : 'justify-start'">
                {{ column.label }}
                <component
                  :is="sortIconFor(column.key)"
                  v-if="column.sortable"
                  class="size-3 opacity-60"
                  :class="isSorted(column.key) ? 'opacity-100' : ''"
                />
              </div>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow
            v-for="(item, index) in data"
            :key="(item as any).id ?? index"
            class="cursor-pointer transition-colors"
            style="border-bottom: 1px solid var(--border-sub);"
            @click="emit('row-click', item)"
          >
            <TableCell
              v-for="column in columns"
              :key="column.key"
              :class="[
                compact ? 'py-2 px-3' : 'py-3 px-5',
                alignClass(column.align),
                column.class,
                column.wrap ? 'whitespace-normal' : 'whitespace-nowrap',
                resolveCellClass(column, item),
              ]"
            >
              <slot :name="`cell-${column.key}`" :item="item" :index="index">
                {{ (item as any)[column.key] }}
              </slot>
            </TableCell>
          </TableRow>
          <TableRow v-if="data.length === 0">
            <TableCell :colspan="columns.length" class="h-24 text-center text-sm" style="color: var(--text-3);">
              {{ emptyMessage ?? 'No se encontraron resultados.' }}
            </TableCell>
          </TableRow>
        </TableBody>
      </Table>
    </div>

    <!-- Mobile cards -->
    <div v-if="$slots['mobile-row']" class="md:hidden space-y-2">
      <slot name="mobile-row" v-for="(item, index) in data" :key="(item as any).id ?? index" :item="item" :index="index" />
    </div>
    <div v-else-if="data.length === 0" class="md:hidden rounded-[14px] p-12 text-center text-sm" style="background: var(--bg-card); border: 1px dashed var(--border); color: var(--text-3);">
      {{ emptyMessage ?? 'No se encontraron resultados.' }}
    </div>
  </div>
</template>
