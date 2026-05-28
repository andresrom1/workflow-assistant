<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Credenciales del Service Account
    |--------------------------------------------------------------------------
    |
    | Ruta al JSON del service account de Firebase (Project Settings →
    | Service accounts → Generate new private key). Se usa para verificar los
    | ID Tokens que llegan desde la app móvil con el Admin SDK (kreait).
    |
    | NO commitear el JSON. Guardarlo fuera del repo o en storage/app/firebase/
    | (ya ignorado) y apuntar FIREBASE_CREDENTIALS a su ruta absoluta.
    |
    */
    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/service-account.json')),

    /*
    | project_id del proyecto Firebase. kreait lo deriva del service account,
    | pero lo dejamos disponible por si hace falta validar el claim `aud`.
    */
    'project_id' => env('FIREBASE_PROJECT_ID'),
];
