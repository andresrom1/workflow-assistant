# Changelog

Todos los cambios notables de este proyecto se documentan en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y el versionado sigue la convención `vMAYOR.MENOR` (ver sección Releases en `AGENTS.md`).

## [Unreleased]

### Cambiado

- Migración a DeepSeek v4 (`deepseek-v4-flash` / `deepseek-v4-pro`): los modelos `deepseek-chat` y `deepseek-reasoner` quedaron deprecados el 2026-07-24.
- Los 8 agentes de `app/AI/Agents/` declaran el modelo con un atributo de tier uniforme (`#[UseCheapestModel]` / `#[UseSmartestModel]`) en vez de `#[Model('...')]`; el nombre del modelo vive solo en `config/ai.php` (`providers.deepseek.models.text.*`, env-able con `DEEPSEEK_MODEL`, `DEEPSEEK_MODEL_CHEAP`, `DEEPSEEK_MODEL_SMART`).

### Eliminado

- Claves de config sin consumidor que apuntaban a modelos deprecados: `ai.semantic_analysis.model`, `ai.semantic_analysis.provider` e `ingesta.extraction_model`.

## [v3.0] - 2026-07-20

### Cambios que rompen compatibilidad

- Canal único WhatsApp: se depreca ChatKit y se retira Laravel Reverb.
- Modelo de dominio: `InsurableAsset` separado de `Risk` (modelo ACORD simplificado), con backfill de atributos del asset.

### Agregado

- Facturación electrónica de comisiones (ARCA/AFIP) con PDF generado al vuelo, detalle de lote, nombre y peso del PDF.
- Ingesta v2: extracción server-side con LLM (`deepseek-chat`) y comando `ingesta:reextraer`.
- Centro de mantenimiento de cartera e ingesta local de documentos de póliza (F1–5) con contrato de app.
- Captura de BSUID + teléfono en WhatsApp y respuesta por BSUID.
- Obtención y recaptura de documentación de pólizas recientemente emitidas.
- Despliegue self-host en producción (Oracle Cloud, Docker Compose) con build multi-stage de Node para assets de Vite.
- Landing preview, recolección de DNI y mejoras de UX.
- Configuración mapeada: residuo `pas-mobile` extirpado de settings.

### Corregido

- Red de seguridad para que ningún `Customer` quede sin PAS.
- Índices únicos parciales en `customers` para evitar colisión con soft-deletes en checkout.
- `checkout_url` null en WhatsApp unificado con `route('checkout.show')`.
- Ingesta: detección de compañía por CUIT del emisor, cap de texto a 16k chars (pólizas empaquetadas) y agrupación de pendientes por compañía + número normalizado.
- Fuel type GNC para RUS y Galicia.
- Docker: `chown` de directorios tmp/log de nginx para uploads multipart.
- Seed: admin como superset de PAS, asignado a todos los customers.
- Certificado de cobertura ya no es documentación obligatoria.

[v3.0]: https://github.com/andresrom1/workflow-assistant/compare/v2.0...v3.0
