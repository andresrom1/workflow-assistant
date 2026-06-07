# Hallazgos — verificación `task-type` Visred + documentos oficiales

**Fecha:** Jun 2026 · **Contexto:** sesión de verificación del sandbox Visred (mango-mobile chat).
**Naturaleza:** registro de hallazgos y decisiones. **No introduce cambios en Documentación (Fase 5) ni en ningún spec.**

---

## Verificado en vivo

- **El sandbox de Visred volvió a responder** tras una migración del lado de ellos (durante la migración, un GET autenticado con Bearer válido devolvía el HTML de "Iniciar sesión" en vez de JSON). Si el síntoma reaparece, es bug de Visred → reportarlo con ese detalle concreto.
- **El catálogo de `task-type` NO está en el schema.** El OpenAPI documenta que `GET /v1/params/task-type/` devuelve `BaseParam[]` (`{id, description}`), pero los **valores** son dato vivo — solo visibles consultando el sandbox. Confirmado.
- **`GET /v1/params/task-type/` devuelve 12 tipos.** De ésos, **5 son descargas de documento**:

  | `task_type_id` | Documento |
  |---|---|
  | `download-poliza` | Póliza |
  | `download-certificate` | Certificado |
  | `download-non-repetition` | Certificado de no repetición |
  | `download-cupon` | Cupón de pago |
  | `download-circulation-card` | Tarjeta de circulación |

  Los otros 7 son **acciones de flujo**, no descargas: `quote`, `issue`, `condiciones-emision`, `estado-propuesta`, `liberar-poliza`, `envio-documento-inspeccion`, `inspeccion-post-emision`.

- **Este catálogo es global, no por póliza.** Que existan los 5 tipos NO significa que toda póliza tenga los 5 disponibles — depende de compañía/producto.

---

## Decisiones de producto (de esta sesión)

- **"Documentos oficiales" NO se implementa ahora. Se difiere** hasta tener emisión real contra Visred. Razón: sin póliza emitida por Visred no hay `presale_id`, y `download-*` no tiene de dónde traer nada. Se diseña el estante para mercadería que todavía no se fabrica.
- **Documentación (Fase 5): sin cambios.** Sigue como está — autogestión, slots locales (Personal + N vehículos), offline-first, sin sync ni auditoría. El cliente organiza sus papeles a mano. El valor central es **el orden** (cada cosa en su lugar, una vez), no la fuente del archivo.
- **Se descarta el botón "descargar póliza" suelto en la card de la Home.** Un documento descargado fuera del sistema de slots es el "papel suelto en la raíz" que la app justamente evita.
- **mango-mobile nunca habla con Visred.** Cualquier descarga oficial, el día que se implemente, va por el backend (patrón túnel). Invariante.

---

## Pregunta abierta que bloquea el diseño de "documentos oficiales"

**¿Cómo vienen empaquetados los documentos oficiales por compañía?** Caso que lo dispara: una compañía podría entregar **tarjeta de circulación + póliza en un mismo PDF**. Eso rompe cualquier mapeo 1-a-1 documento→slot, y define dónde viven los oficiales en la app (¿colgados de la póliza? ¿en slots? ¿nada?).

**No decidir esto ahora.** Se resuelve mirando un PDF real de Visred cuando exista emisión — no inventando una taxonomía por adelantado. Los slots de Fase 5 son la organización *del cliente* (para lo que carga a mano); los documentos de Visred vienen empaquetados como la *compañía* decide. Forzar uno dentro del otro es donde aparecen los casos rotos.

---

## Pendientes de sandbox (para cuando se retome la integración)

- Si el `result.url` de `POST /v1/documents/` **abre sin `Authorization`** (inferencia: el link está pensado para distribuirse a clientes sin cuenta → sería pre-firmado).
- **Vigencia:** si el `result.url` refleja el estado actual de la póliza (que no entregue el PDF de una vencida/dada de baja). Crítico antes de prometerle al cliente "esto te sirve en el control".
- Shape 200 de `sales/*` y `policy/stats` (ya inferido como reporting agregado, no cartera).
- Al pedir credenciales/integrar: confirmar **usuario de servicio** (el de workflow-assistant) vs personal — `discovery/companies` es origin-aware, el catálogo depende del usuario.

---

*Registro, no spec. La integración de cotización/emisión y los documentos oficiales se trabajan en sus propios documentos cuando se retomen.*
