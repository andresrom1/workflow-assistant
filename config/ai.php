<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'deepseek',
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'gemini',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic analysis (Tier 2 de auditoría de conversaciones)
    |--------------------------------------------------------------------------
    |
    | Controla el análisis semántico con IA que audita conversaciones en busca
    | de frustración, confusión, loops semánticos, pérdida de contexto, alucinaciones
    | y respuestas incorrectas. Gateado por feature flag para evitar costos
    | inesperados mientras se calibra.
    |
    | El modelo y el provider NO se configuran acá: los decide `ConversationAnalyzerAgent`
    | vía `#[UseSmartestModel]` + `ai.default` (ver `providers.deepseek.models.text`).
    |
    */

    'semantic_analysis' => [
        'enabled' => env('AI_SEMANTIC_ANALYSIS_ENABLED', false),
        'window_turns' => (int) env('AI_SEMANTIC_ANALYSIS_WINDOW_TURNS', 6),
        'throttle_minutes' => (int) env('AI_SEMANTIC_ANALYSIS_THROTTLE_MIN', 5),
        'trigger_every_n_turns' => (int) env('AI_SEMANTIC_ANALYSIS_TRIGGER_EVERY', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2024-10-21'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),

            // opcion-de-configuracion: modelo por tier del provider deepseek.
            // Lo consume Laravel\Ai\Providers\DeepSeekProvider. `default` aplica a los
            // agentes anonimos y a cualquier agente sin atributo de tier; `cheapest` y
            // `smartest` los eligen los atributos #[UseCheapestModel] / #[UseSmartestModel]
            // de cada agente. Unico lugar del repo con nombres de modelo.
            'models' => [
                'text' => [
                    'default' => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
                    'cheapest' => env('DEEPSEEK_MODEL_CHEAP', 'deepseek-v4-flash'),
                    'smartest' => env('DEEPSEEK_MODEL_SMART', 'deepseek-v4-pro'),
                ],
            ],
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
