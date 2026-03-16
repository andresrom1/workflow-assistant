---
  name: nav-bar
  description: >-
  Maintain the navigation bar for the admin panel. Activates when adding new links to the nav bar, restyling the nav bar, or when the user mentions navigation, nav bar, links, menu, sidebar, or layout changes related to navigation.
---

## Nav Bar

### Para agregar un nuevo link en el futuro
Solo agregás un <NavItem> en AppLayout.vue dentro del grupo correspondiente:
<NavItem :open="open" href="/admin/nueva-ruta" :active="isActive('/admin/nueva-ruta')" label="Nueva Vista">
  <template #icon>
    <svg class="w-5 h-5" ...>...</svg>
  </template>
</NavItem>
Para excluir una página nueva del layout
Agregás el prefijo a LAYOUT_EXCLUDED en app.js:
jsconst LAYOUT_EXCLUDED = ['Checkout/', 'Auth/', 'Public/'];