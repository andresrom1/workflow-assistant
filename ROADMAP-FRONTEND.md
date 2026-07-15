# Roadmap / Log de Refactor Frontend

> Branch: `refactor/shadcn-vue-ui`
> Objetivo: Estandarizar todo el frontend del panel/admin y herramientas internas usando componentes shadcn-vue (estilo `reka-nova`) sobre reka-ui, manteniendo la paleta actual del proyecto. Excluye landing y checkout.

## Decisiones aprobadas

- **Style de shadcn-vue:** `reka-nova` (ya configurado en `components.json`).
- **Base de primitives:** reka-ui.
- **Paleta:** mantener variables CSS actuales (`--bg-card`, `--sb-*`, `--accent-600`, etc.).
- **Iconos:** `@lucide/vue`.
- **Wrappers de aplicación:** permitidos (`DataTable`, `AppPagination`, `AppSidebar`, etc.) siempre sobre componentes shadcn-vue.
- **Sorting:** opción B (backend) para tablas paginadas.
- **Tests:** PHPUnit para sorting y regresiones.

## Fases del refactor

### Fase 0 — Branch y base shadcn-vue
- [x] Crear branch `refactor/shadcn-vue-ui`.
- [x] Instalar componentes shadcn-vue necesarios.
- [x] Verificar imports y tokens.

### Fase 1 — Wrappers de aplicación
- [x] Crear `DataTable.vue` con sorting backend, columnas configurables, versión mobile automática, empty state y skeleton.
- [x] Crear `AppPagination.vue` sobre `Pagination` de shadcn.
- [x] Crear `AppSidebar.vue`/`AppSidebarNav.vue` sin perder funcionalidad actual.
- [x] Crear `AppBackLink.vue` sobre `Button` de shadcn-vue.

### Fase 2 — Backend sorting
- [x] Agregar soporte de `sort`/`direction` en controllers de índices paginados.
- [x] Whitelist de columnas sorteables.

### Fase 3 — Migrar tablas
- [x] `Conversations/Index`, `Customers/Index`
- [x] `Polizas/Index`, `Quotes/Index`
- [x] `CoverageDocuments/Index`, `PolicyDocuments/Index`
- [x] `Admin/Conversations/Index`, `Admin/CheckoutSessions/Index`
- [x] `Facturacion/Index`, `Facturacion/BatchShow`, `Facturacion/Configuracion`
- [x] `Admin/Analytics/Funnel`

### Fase 4 — Layout, forms y modales
- [x] Migrar `AppLayout.vue` + `Sidebar/*` al sistema `Sidebar` de shadcn-vue.
- [x] Migrar forms de gestión a componentes shadcn.
- [x] Migrar modales/confirmaciones a `Dialog`/`AlertDialog`.
- [x] Reemplazar componentes propios restantes (`Pagination.vue`, `BackLink.vue`, `ChevronRight.vue`, `RowAction.vue`, `RowActionMin.vue`, `Sidebar/NavItem.vue`, `Sidebar/NavGroup.vue`).

### Fase 5 — Tests y calidad
- [x] Tests PHPUnit para sorting, filtros + paginación.
- [x] `vendor/bin/pint --dirty`.
- [x] `npm run build`.

### Fase 6 — Documentación y merge
- [x] Actualizar `AGENTS.md`.
- [ ] Merge cuando se apruebe.

---

## Log de acciones

### 2026-07-14
- Creada branch `refactor/shadcn-vue-ui` desde `main` manteniendo el cambio local de IP HMR en `vite.config.js`.
- Creado este archivo `ROADMAP-FRONTEND.md` para trazabilidad.
- Instalados componentes shadcn-vue vía CLI: `table`, `button`, `input`, `card`, `badge`, `pagination`, `dropdown-menu`, `tooltip`, `skeleton`, `empty`, `separator`, `dialog`, `sheet`, `sidebar`, `avatar`, `form`, `label`, `checkbox`, `textarea`, `switch`, `tabs`, `breadcrumb`.
- Se instalaron dependencias transitivas esperadas: `@tanstack/vue-table`, `@vee-validate/zod`, `vee-validate`, `zod`.
- Se actualizaron `@lucide/vue` y `reka-ui`.
- Se eliminó la importación de fuente `Geist` agregada automáticamente por shadcn-vue, ya que el proyecto usa `Inter`. Se mantuvo `--font-heading: var(--font-sans)` apuntando a Inter.
- Se decidió no actualizar `select` por ahora (funciona correctamente con la versión existente).
- Creado wrapper `resources/js/components/App/DataTable.vue` sobre componentes shadcn-vue `Table`, con sorting backend, headers configurables, alineación, empty state y versión mobile vía slot.
- Creado wrapper `resources/js/components/App/Pagination.vue` sobre `Button` de shadcn-vue, usando `Link` de Inertia y los links de paginación de Laravel.
- Creado wrapper `resources/js/components/App/Sidebar.vue` usando componentes `Sidebar*` de shadcn-vue, manteniendo la funcionalidad del sidebar actual.
- Implementado sorting backend en controllers:
  - `CustomerRepository::getAllWithRelations` y `CustomerController::index`
  - `ConversationController::index`
  - `PolizaController::index` (soporta columnas propias y relacionales: patente, cliente)
  - `QuoteController::index` (soporta customer_name y alternatives_count)
  - `Admin\ConversationController::index` (soporta customer_name y messages_count)
  - `CoverageDocumentController::index`
  - `PolicyDocumentController::index` (soporta patente, label, cliente, last_document_at)
  - `Admin\CheckoutAuditController::index` (soporta columnas de quote_alternative)
- Corregido error de CSS en `resources/css/app.css` (selector `[data-theme="dark"], @media` era inválido; se separaron en dos bloques).
- Corregido `AppPagination.vue` (problema de comillas en atributo `:href`).
- Migradas todas las tablas del panel a `DataTable.vue`.
- Migrados forms de gestión (`Customers/Create`, `Customers/Edit`, `Profile/Edit`, `Polizas/Create`, `Polizas/Edit`, `Polizas/Renovar`, `Admin/Users/Create`) y `Polizas/PolizaFields.vue` a componentes shadcn-vue (`Form`, `Input`, `Select`, `Checkbox`, `Textarea`, `Switch`, `Tabs`, `Dialog`).
- Migrado `AppLayout.vue` para usar `AppSidebar` y componentes shadcn-vue (`Breadcrumb`, `Separator`, `DropdownMenu`, `Avatar`).
- Ajustados componentes shadcn `FormItem`/`FormMessage` para mostrar errores de validación de Inertia (`error` prop + slot de error).
- Eliminados componentes propios obsoletos: `BackLink.vue`, `Pagination.vue`, `RowAction.vue`, `RowActionMin.vue`, `ChevronRight.vue`, `Sidebar/NavItem.vue`, `Sidebar/NavGroup.vue`.
- Creada factory `CoverageDocumentFactory` y agregado trait `HasFactory` a `App\Models\CoverageDocument`.
- Creados tests PHPUnit de sorting backend:
  - `tests/Feature/CustomerIndexSortingTest.php`
  - `tests/Feature/PolizaIndexSortingTest.php`
  - `tests/Feature/AdminConversationIndexSortingTest.php`
  - `tests/Feature/PolicyDocumentIndexSortingTest.php`
  - `tests/Feature/CoverageDocumentIndexSortingTest.php`
- Todos los tests de sorting pasan; tests de regresión relevantes (Customer, Poliza, PolicyDocument, Conversation, Admin Conversation, Facturación) pasan.
- `vendor/bin/pint --dirty` ejecutado y limpio.
- Build de frontend exitoso tras la limpieza de componentes propios.

### 2026-07-14 — Correcciones post-build
- Habilitado sorting faltante:
  - `/conversations`: Vehículos y Convs. (`CustomerRepository` con `withCount`).
  - `/customers`: Pólizas, Vehículos y Convs. (`CustomerRepository` con `withCount` y subquery para pólizas vigentes).
  - `/quotes`: Vehículo (`QuoteController` con join a `risk_snapshots`).
- Creada `QuoteFactory` y agregado `HasFactory` a `App\Models\Quote`.
- Creado `tests/Feature/QuoteIndexSortingTest.php` y ampliado `CustomerIndexSortingTest.php` con tests para las nuevas columnas sorteables.
- Reestructurada tabla `/admin/conversations`:
  - Eliminada columna Canal (badge movido a Mensajes).
  - Badge "Archivada" compactado y movido junto al `#id` en la columna Cliente.
  - Badges de estado de flujo compactados (solo activos, con ancho máximo).
  - Columna Acciones reducida a iconos.
- Reestructurada tabla `/admin/checkout-sessions`:
  - Eliminada columna Tarjeta (`cc_brand`) del index; datos mantenidos en el detalle.
  - Columnas agrupadas: Cliente (nombre+email) y Plan/Cotización (aseguradora+título+precio).
  - Actualizado `CheckoutAuditController` para no enviar `cc_brand` en el index.
- Normalizado input de archivo en `PolicyDocuments/Show.vue` usando componentes shadcn-vue (`Input`, `Label`, `Button`).
- Agregados estilos globales de scrollbar para modo oscuro en `resources/css/app.css`.
- Ajustado `DataTable.vue`: soporte de `table-fixed`, clases de ancho en headers y celdas, y propiedad `wrap` para controlar `whitespace-normal`.
- Tests de sorting y regresión pasando; `vendor/bin/pint --dirty` limpio; build exitoso.

---

## Estado actual — WIP

> **Branch `refactor/shadcn-vue-ui` queda como work in progress.** No se mergea a `main` hasta nueva revisión.

### Lo que funciona
- Backend de sorting implementado y testeado (26 tests de sorting pasan).
- Wrappers `DataTable`, `AppPagination`, `AppSidebar`, `AppBackLink` creados.
- Build de frontend exitoso.
- `vendor/bin/pint --dirty` limpio.

### Problemas visuales pendientes identificados
1. **Badges cortan texto** en múltiples páginas por falta de `leading-none` al reducir altura (`h-3`/`h-4`).
2. **Colores hardcodeados para modo claro** se ven mal en dark mode (`/admin/conversations`, `/customers`, `/quotes`, `/policy-documents/show`).
3. **Variable CSS `--bg-subtle` no definida** en `app.css`, usada en el estilo del input file.
4. **Nombres largos no se truncan** en `/admin/conversations` y `/quotes`.
5. **Celda de estado en `/admin/conversations`** con `max-h-[44px] overflow-hidden` corta badges si hay más de 2 renglones.
6. **Sidebar**: colores hardcodeados (`bg-[#5b5ef6]`) y padding redundante.
7. **Radios de Tailwind** inconsistentes por `@theme inline` que sobrescribe `--radius-lg` a `10px` en lugar de `14px`.
8. **Modal de eliminación en `PolicyDocuments/Show.vue`** sigue siendo custom, no usa shadcn Dialog.
9. **Mensajes vacíos en `/conversations`** hablan de "clientes" en lugar de "conversaciones".
10. **Tablas en general** tienen problemas de ancho/padding/wrap que requieren ajuste fino página por página.

### Lecciones aprendidas
- Refactorar todo el frontend de una sola pasada genera demasiados problemas visuales acumulados.
- Es necesario poder ver el resultado de cada cambio (screenshots o dev server funcional) para no iterar a ciegas.
- El approach correcto sería migrar una página a la vez, validar visualmente, y recién ahí pasar a la siguiente.

### Próximos pasos sugeridos (para continuar en otro momento)
1. Decidir si se continúa el refactor o se revierte el frontend.
2. Si se continúa: arreglar los 10 problemas listados arriba con screenshots de referencia.
3. Si se revierte: descartar los cambios de frontend y mantener solo el backend de sorting + tests.
4. Revisar el servidor de desarrollo (`npm run dev` / HMR) ya que la configuración actual tiene `hmr.host: '192.168.0.14'` mientras el `.env` apunta a un dominio ngrok.
