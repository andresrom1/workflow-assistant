# FINAL Database Entity-Relationship Diagram

Este diagrama refleja la estructura final de la base de datos tras las discusiones de escalabilidad e identificación explícita de vehículos.

```mermaid
erDiagram
    CUSTOMERS ||--o{ VEHICLES : "owns"
    CUSTOMERS ||--o{ CONVERSATIONS : "participates"
    CUSTOMERS ||--o{ RISK_SNAPSHOTS : "referenced_in"
    VEHICLES ||--o{ CONVERSATION_VEHICLE : "included_in"
    CONVERSATIONS ||--o{ CONVERSATION_VEHICLE : "contains"
    VEHICLES ||--o{ RISK_SNAPSHOTS : "referenced_in"
    CONVERSATIONS ||--o{ QUOTES : "has"
    RISK_SNAPSHOTS ||--o{ QUOTES : "basis_for"
    QUOTES ||--o{ QUOTE_ALTERNATIVES : "contains"
    CONVERSATIONS ||--o{ COVERAGE_PREFERENCES : "has"
    VEHICLES ||--o{ COVERAGE_PREFERENCES : "scoped_to"

    CUSTOMERS {
        bigint id PK
        string dni
        string email
        string phone
        string name
        boolean is_anonymous
        timestamp completed_at
        timestamps created_at
    }

    VEHICLES {
        bigint id PK
        bigint customer_id FK
        string patente
        string marca
        string modelo
        string version
        int year
        enum combustible
        enum uso
        boolean is_complete
        timestamps created_at
    }

    CONVERSATIONS {
        bigint id PK
        bigint customer_id FK
        string external_conversation_id
        string channel
        enum status
        timestamp last_message_at
        timestamp ended_at
    }

    CONVERSATION_VEHICLE {
        bigint id PK
        bigint conversation_id FK
        bigint vehicle_id FK
    }

    RISK_SNAPSHOTS {
        bigint id PK
        bigint vehicle_id FK
        bigint customer_id FK
        string marca
        string modelo
        string version
        int year
        string combustible
        string uso
        string codigo_postal
        string dni
        date edad_conductor
        string coverage_preference
        timestamps created_at
    }

    QUOTES {
        bigint id PK
        bigint risk_snapshot_id FK
        bigint conversation_id FK
        uuid session_uuid
        string status
        string external_ref_id
        json raw_response
        timestamp expires_at
        timestamps created_at
    }

    QUOTE_ALTERNATIVES {
        bigint id PK
        bigint quote_id FK
        string external_code
        string external_quote_id
        string aseguradora
        string descripcion
        string normalized_grade
        decimal precio
        string moneda
    }

    COVERAGE_PREFERENCES {
        bigint id PK
        bigint conversation_id FK
        bigint vehicle_id FK
        string preference
        json metadata
        timestamps created_at
    }
```

## Cambios Clave Incorporados

1.  **Eliminación de `is_primary`**: Se eliminó este atributo de la tabla pivote `CONVERSATION_VEHICLE`. El contexto ahora se resuelve explícitamente mediante la patente enviada en las herramientas.
2.  **Entidad `COVERAGE_PREFERENCES`**: Se agregó esta tabla para almacenar las preferencias de cobertura vinculadas a una combinación específica de conversación y vehículo.
3.  **Relaciones**: Se mantienen los vínculos fuertes entre `CONVERSATIONS`, `VEHICLES` y `COVERAGE_PREFERENCES`.
