php artisan serve --host=0.0.0.0 --port=8001 

npm run dev -- --host (debe levantar ebn localhost:5147)

php artisan reverb:start --host=0.0.0.0 --port=8081 --debug   

php artisan queue:work o php artisan queue:work --sleep=3 --tries=3

php artisan queue:listen --queue=default --tries=3

php artisan queue:listen --queue=whatsapp-ai --tries=3 --timeout=180

php artisan queue:listen --queue=whatsapp-outbound --tries=5 --timeout=30

php artisan queue:listen --queue=whatsapp-ai,whatsapp-outbound,default --tries=3 --timeout=180

php artisan queue:listen database_media

ngrok http 8001

php artisan db:seed --class=CheckoutTestDataSeeder // para generar checkout de prueba

php artisan db:seed --class=OpportunityTestDataSeeder // para generar oportunity de prueba

// # 5. Visitar /admin/settings