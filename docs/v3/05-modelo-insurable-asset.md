# 05 — Modelo InsurableAsset (ACORD simplificado)

> **(Implementado, 2026-07-15).** Separa la identidad del bien asegurado (`InsurableAsset`)
> de la exposición sobre la que cuelgan las pólizas (`Risk`). Motivado por una deuda anotada
> en el `ROADMAP.md`: el import de reportes de cartera (entrada 2026-06-30) y la ingesta local
> de documentos (Descargas del productor) traían contratos de ramos no-vehiculares (AP de
> Galicia, combinado de Bassi) que el dominio no podía materializar — el `Risk` se llaveaba
> por `patente`, y esos ramos no tienen patente.

## Por qué (diagnóstico)

Antes de este cambio, `risks` mezclaba dos conceptos en una sola tabla STI: el bien asegurado
(auto con patente/marca/modelo) y el riesgo del que cuelgan las pólizas. La identidad estaba
hardcodeada a un solo tipo: la dedup se hacía con `where('metadata->patente', ...)` regada por
5 servicios distintos (`PolicyChainResolver`, `PolicyReferenceService`, `PolizaService`,
`IngestaConfirmacionService`, `PolicyReportImportService`, más el seeder de datos mobile).

## El modelo

Cercano a ACORD, simplificado (decisión del dueño del dominio):

```
Customer ──< InsurableAsset (bien estable, identidad)  ──< Risk (exposición) ──< Poliza
                type: vehicle|property|person|business|equipment
                natural_key: patente normalizada (vehicle); null (resto, por ahora)
```

- **`InsurableAsset`** es el bien relativamente estable y re-identificable (un auto con su
  dominio). Lleva la identidad (`natural_key`, derivada por tipo vía `AssetType::naturalKey()`)
  y los atributos del bien en `metadata` JSONB (patente, marca, modelo, ...).
- **`Risk`** captura la exposición (uso, guarda, factores de suscripción) que puede cambiar sin
  duplicar el activo. **Hoy es 1:1 transicional con su asset**: no hay datos de exposición con
  consumidor todavía, así que `risk.metadata` queda vacía (`{}`) en altas nuevas. Las filas
  migradas desde el modelo viejo conservan una copia de la metadata original (nadie la lee más).
- **Dos pólizas de AP del mismo cliente NUNCA comparten `Risk`** (decisión de dominio explícita).
  Como `Person` no tiene clave natural todavía, tampoco comparten `Asset`: cada contrato crea
  el suyo.

### Regla de identidad por tipo

`AssetType::naturalKey(array $metadata): ?string` — un `match` por caso:

- `Vehicle` → patente normalizada (mayúsculas, solo `A-Z0-9`, sin espacios/guiones).
- `Property`, `Person`, `Business`, `Equipment` → `null` (todavía). Sin clave natural, cada
  contrato crea su propio asset — no hay identidad re-identificable en el mundo que el sistema
  pueda usar hoy para deduplicar con seguridad.

### Único punto de dedup: `PolicyChainResolver::resolveRisk()`

```php
resolveRisk(Customer $customer, AssetType $type, array $assetMeta): Risk
```

Todos los creadores de `Risk` del sistema delegan acá: `IngestaConfirmacionService`,
`PolicyReportConfirmacionService`, `PolicyReferenceService` (emisión Visred), `PolizaService`
(alta manual desde el panel) y `MobileRiskSeeder` (datos de desarrollo). Si el tipo tiene clave
natural y coincide, reusa el asset (y su risk); si no, crea un asset nuevo.

## Qué se movió y qué no

- Las queries de dedup **exacto** por patente pasaron de `metadata->patente` (JSON path sobre
  `risks`) a `natural_key` (columna indexada sobre `insurable_assets`).
- Las búsquedas de **texto libre** (`ilike` en los buscadores de pólizas/documentos) siguen
  contra `risk.asset->metadata->patente` — mismo criterio, solo cambia la tabla.
- Índice de `natural_key` **NO único** a propósito: datos preexistentes pueden colisionar tras
  normalizar (mismo criterio que `customers.documento_key`).

## Pendiente (explícitamente diferido, no implementado en este cambio)

- **Mapeo producto/ramo → `AssetType`.** El import de reportes de cartera sigue exactamente
  igual: una fila sin patente es `Exception` con la nota `'Sin patente: ramo no-vehicular aún
  no soportado.'`. El parser ya extrae la columna `ramo` (vive en `policy_report_rows.payload`,
  sin columna dedicada), pero decidir cómo mapearla a `AssetType` quedó fuera de este cambio.
- **Materializar filas no-vehiculares del reporte.** Depende del punto anterior.
- **Claves naturales de `Property`/`Person`/`Business`/`Equipment`.** Hoy `null` (cada contrato
  crea su propio asset). Cuando haya consumidor: `Property` probablemente por ubicación del
  riesgo normalizada; `Person` por el documento del asegurado (**ojo:** el asegurado puede no
  ser el tomador — una AP colectiva/sobre terceros necesitaría modelar esa relación aparte, no
  asumir `documento_key` del `Customer` tomador).
- **Separar atributos de exposición del asset.** Cuando aparezca consumidor de uso/guarda/
  código postal como dato que cambia independientemente del bien, mover esos campos de
  `asset.metadata` a `risk.metadata` (hoy viven en el asset porque es lo único que existe).

## Archivos clave

- `app/Enums/AssetType.php` — el tipo + `naturalKey()` + `label()`.
- `app/Models/InsurableAsset.php` — el modelo nuevo (hook `saving` recalcula `natural_key`).
- `app/Models/Risk.php` — `belongsTo(InsurableAsset)`, `$with = ['asset']`.
- `app/Services/PolicyChainResolver.php` — único punto de dedup (`resolveRisk`/`resolveAsset`).
- `database/migrations/2026_07_15_000001_create_insurable_assets_table.php` — tabla nueva.
- `database/migrations/2026_07_15_000002_add_asset_id_to_risks_table.php` — backfill 1:1 desde
  el modelo viejo (normalización de patente idéntica a `AssetType::naturalKey`).
