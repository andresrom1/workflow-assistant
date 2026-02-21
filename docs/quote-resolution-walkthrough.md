# Walkthrough: Quote Resolution Strategies (Refined)

He implementado un sistema de resolución de cotizaciones flexible que prioriza a los Productores de Seguros (PAS) vía una app móvil, con un fallback automático a una API.

## Cambios Realizados

### 1. Refinamiento del Flujo de Trabajo
- **Trigger de Cotización**: El proceso de cotización ya no se dispara al identificar el vehículo, sino al recibir la **Preferencia de Cobertura** en `AgentToolAdapter::coveragePreference`. Esto asegura que el PAS reciba el lead con toda la información necesaria.
- **Snapshot Completo**: Se actualizó el modelo `RiskSnapshot` y su repositorio para incluir el campo `coverage_preference`. El PAS ahora recibe: Cliente + Vehículo + Cobertura Deseada.

### 2. Patrón Strategy
- **Interface**: `QuoteResolutionStrategyInterface` define el contrato.
- **Estrategia API**: `ApiQuoteResolution` usa el `QuotingEngine` para resultados automáticos.
- **Estrategia Mobile**: `MobileAppQuoteResolution` envía la oportunidad con el snapshot completo a la app móvil.

### 3. Observer & Lógica de Fallback
- **Evento**: `QuoteOfferedToPas` se dispara al enviar la oferta a la app.
- **Listener**: `FallbackToApiListener` programa un chequeo diferido.
- **Job de Timeout**: `CheckQuoteAcceptance` se ejecuta a los 30 minutos. Si la cotización no fue resuelta por un PAS, activa el fallback a la estrategia `api`.

### 4. Base de Datos y Webhooks
- **Migraciones**: 
    - Se agregaron campos de rastreo y estados a la tabla `quotes`.
    - Se agregó `coverage_preference` a la tabla `risk_snapshots`.
    - Se creó la tabla `mobile_sync_logs`.
- **Webhook**: `QuoteWebhookController` (`POST /api/webhooks/quote-update`) permite a los PAS enviar cotizaciones manuales, resolviendo la `Quote` y notificando al sistema.

## Instrucciones de Verificación

### Verificación del Flujo Mobile (PAS)
1.  Identifica cliente y vehículo a través del chat/herramientas. (Verifica que **no** se cree la cotización aún).
2.  Envía la preferencia de cobertura (ej: "Todo Riesgo"). 
3.  Verifica en la BD que se haya creado la `Quote` con `status = 'offered_pas'`.
4.  Simula la respuesta del PAS mediante el Webhook:
    ```bash
    curl -X POST http://localhost/api/webhooks/quote-update \
    -H "Content-Type: application/json" \
    -d '{
        "quote_id": [ID_DE_LA_QUOTE],
        "opportunity_id": "opp_abc_123",
        "status": "resolved",
        "alternatives": [
            {
                "aseguradora": "San Cristobal",
                "precio": 52000,
                "titulo": "Todo Riesgo con Franquicia",
                "normalized_grade": "all_risk"
            }
        ]
    }'
    ```
5.  Verifica que la cotización cambie a `processed` y se guarden las alternativas.

### Verificación del Fallback
1.  Inicia el proceso hasta la preferencia de cobertura.
2.  No envíes el webhook.
3.  Verifica que pasados los 30 min (o ejecutando el job manualmente en desarrollo), la cotización se resuelva vía API.
