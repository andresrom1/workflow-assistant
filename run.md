php artisan serve --host=0.0.0.0 --port=8001 

npm run dev -- --host (debe levantar ebn localhost:5147)

php artisan reverb:start --host=0.0.0.0 --port=8081 --debug   

php artisan queue:work o php artisan queue:work --sleep=3 --tries=3

<!-- php artisan queue:listen --queue=default --tries=3

php artisan queue:listen --queue=whatsapp-ai --tries=3 --timeout=180 -->

<!-- QUEUES — un proceso por conexión (cada worker bindea UNA conexión) -->

<!-- IA pesada (LLM). DEBE correr sobre la conexión database_ai (retry_after=200 > timeout=180)
     para que un job de LLM lento NO sea reclamado mientras corre → evita doble llamada al LLM. -->
php artisan queue:listen database_ai --queue=whatsapp-ai --tries=3 --timeout=180

<!-- Liviano: respuestas WhatsApp + jobs varios (conexión database, retry_after=90). -->
php artisan queue:listen --queue=whatsapp-outbound,default --tries=3 --timeout=60

<!-- Media (descarga + STT). Conexión database_media (retry_after=150 > timeout=120). -->
php artisan queue:listen database_media --tries=3 --timeout=120

<!-- Documentos (extracción PDF + chunking/embeddings RAG). Poco frecuente — solo al subir/
     actualizar un manual de producto. Conexión database_long (retry_after=360 > timeout=300).
     Ocioso casi siempre; aislado para no bloquear el worker liviano. -->
php artisan queue:listen database_long --tries=2 --timeout=300

ngrok http 8001

php artisan db:seed --class=CheckoutTestDataSeeder // para generar checkout de prueba

php artisan db:seed --class=OpportunityTestDataSeeder // para generar oportunity de prueba

// # 5. Visitar /admin/settings