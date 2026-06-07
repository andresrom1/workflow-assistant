<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PAS por default
    |--------------------------------------------------------------------------
    |
    | Email del PAS de último recurso para resolver el aviso de Siniestro
    | (spec v2 §4.2). Prelación: PAS propio → PAS del titular del vehículo
    | compartido (mayor sum_asegurada) → este default. Mejor un PAS de MANGO
    | que un 0800. En dev suele ser el email del PAS/cliente de pruebas.
    |
    | Si no se define `MANGO_DEFAULT_PAS_EMAIL`, cae al PAS de dev
    | (`MANGO_DEV_PAS_EMAIL`). Así el aviso de siniestro nunca queda sin destino:
    | el PAS por default es siempre el de pruebas (Andrés en dev).
    |
    */
    'default_pas_email' => env('MANGO_DEFAULT_PAS_EMAIL', env('MANGO_DEV_PAS_EMAIL')),

    /*
    |--------------------------------------------------------------------------
    | Mock laxo de matcheo de Customer
    |--------------------------------------------------------------------------
    |
    | Cuando está en true (fase mock), `MobileAccount::resolveCustomer()` cae a
    | matchear el Customer por email si la cuenta no tiene linking estricto por
    | DNI. Con la API real se apaga (MANGO_MOCK_CUSTOMER_MATCHING=false) y solo
    | vale el `customer_id` linkeado. Único switch del des-mockeo (ROADMAP F10).
    |
    */
    'mock_customer_matching' => env('MANGO_MOCK_CUSTOMER_MATCHING', true),
];
