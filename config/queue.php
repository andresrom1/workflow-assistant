<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        /*
        |----------------------------------------------------------------------
        | Una conexión por worker — para qué sirve realmente `retry_after`
        |----------------------------------------------------------------------
        |
        | Las cuatro conexiones de abajo apuntan a la MISMA tabla `jobs` de la MISMA base. No
        | aíslan nada: lo único que las distingue es `retry_after`, o sea a los cuántos segundos
        | la cola da por abandonado un job reservado y lo vuelve a entregar.
        |
        | Y `retry_after` NO viaja con el job: lo aplica la conexión del worker que lo SACA, no
        | la del código que lo despachó. Por eso cada worker de `.docker/start.sh` recibe su
        | conexión como primer argumento (`queue:work database_ai --queue=...`). Sin ese
        | argumento, Laravel cae a `queue.default` y todos los workers usan el `retry_after` de
        | `database` — que es lo que pasaba hasta el refactor de colas: los valores finos de acá
        | abajo estaban escritos pero no los aplicaba nadie.
        |
        | INVARIANTE, para cada worker:
        |
        |     retry_after de su conexión  >  el $timeout más largo de las colas que atiende
        |
        | Si se viola, la cola re-reserva un job que todavía está corriendo y queda ejecutándose
        | dos veces en paralelo. Lo verifica `tests/Feature/Queue/WorkerConfigTest.php`.
        |
        | Del lado del despacho no se usa `onConnection()`: la cola la fija el constructor del
        | job con `onQueue()`, y con eso alcanza.
        |
        */

        /*
         * Camino caliente y corto: ingesta de WhatsApp, envíos salientes a Meta y transcripción
         * de notas de voz. El job más largo que atiende es ProcessMediaAttachment (120s).
         */
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 200),
            'after_commit' => false,
        ],

        /*
         * El turno conversacional. ProcessConversationInbox y NotifyClientQuoteReady declaran
         * timeout=400: el margen hasta 450 es lo que evita que la cola dé por abandonado un
         * turno mientras el LLM todavía está respondiendo.
         *
         * Eran 180/200 hasta el 2026-09-02. Un turno encadenado son DOS llamadas al LLM, y el
         * par no entraba: el alarm mataba el proceso a mitad de la segunda, sin excepción, sin
         * log y sin fila en `failed_jobs`. Ver ROADMAP.
         */
        'database_ai' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'whatsapp-ai',
            'retry_after' => 450,
            'after_commit' => false,
        ],

        /*
         * Cotización contra el proveedor. Es la operación más larga del sistema: el POST a
         * Visred más el polling de una task por compañía llegó a medirse en 174s en prod.
         * `retry_after` tiene que quedar POR ENCIMA del timeout del job (360s), si no la cola
         * lo re-reserva mientras sigue corriendo y quedan dos consultas en paralelo.
         */
        'database_quotes' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'quotes',
            'retry_after' => 420,
            'after_commit' => false,
        ],

        /*
         * Jobs largos y poco frecuentes: extracción de PDF por LLM + chunking/embeddings de
         * documentación de coberturas (ExtractCoverageDocumentText declara timeout=300) e
         * ingesta local de pólizas. Fuera del hot-path de WhatsApp.
         */
        'database_long' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'documents',
            'retry_after' => 360,
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
