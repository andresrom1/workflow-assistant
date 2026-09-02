php artisan serve --host=0.0.0.0 --port=8001 

npm run dev -- --host (debe levantar ebn localhost:5147)

php artisan queue:listen database_quotes --queue=whatsapp-ai,default,whatsapp-outbound,media,quotes,background,documents --tries=3 --timeout=360

ngrok http 8001

php artisan db:seed --class=CheckoutTestDataSeeder // para generar checkout de prueba

php artisan db:seed --class=OpportunityTestDataSeeder // para generar oportunity de prueba

# ORACLE
powershell -ExecutionPolicy Bypass -File C:\Users\Andrés\.oci\retry-instance.ps1

# GCP - Conectarse a la VM
gcloud compute ssh mango-prod --zone=us-central1-a --project=project-1abe2eb8-c736-448d-bd8
docker exec -it workflow-assistant-app-1 php artisan tinker // para entrar a tinker

# Traer la ultima version de main
cd ~/workflow-assistant
git pull origin main
docker compose --env-file .env.production -f compose.prod.yaml build app
docker compose --env-file .env.production -f compose.prod.yaml up -d app

# Editar .env
cd ~/workflow-assistant
nano ./.env.production

# 1. Log de la app Laravel (el que buscabas)
docker compose --env-file .env.production -f compose.prod.yaml \exec app tail -f storage/logs/laravel.log

# 2. Logs del contenedor (nginx + php-fpm, arranque, migraciones)
docker compose --env-file .env.production -f compose.prod.yaml logs -f app

# 3. Logs de un worker de cola (ej. el de WhatsApp AI)
docker compose --env-file .env.production -f compose.prod.yaml \exec app tail -f /var/log/supervisor/worker-whatsapp-ai-out.log

# sonda de prueba para el agente: 21-08
sudo docker exec workflow-assistant-app-1 php artisan ai:probe-presentation --conversation=23 --runs=1

gcloud compute ssh mango-prod --zone=us-central1-a --command="sudo docker exec workflow-assistant-app-1 php artisan ai:probe-presentation --conversation=23 --runs=10 --json=/tmp/presentacion.json"
Turno de cobertura — ¿qué escribe y avisa de la espera?

gcloud compute ssh mango-prod --zone=us-central1-a --command="sudo docker exec workflow-assistant-app-1 php artisan ai:probe-coverage-turn --conversation=24 --runs=10 --json=/tmp/cobertura.json"
Caché de prefijos — ¿el prompt de sistema cachea y cuánto ahorra?

gcloud compute ssh mango-prod --zone=us-central1-a --command="sudo docker exec workflow-assistant-app-1 php artisan ai:probe-cache checkout_closer"

## Si ya estás dentro de la VM

docker exec workflow-assistant-app-1 php artisan ai:probe-coverage-turn --runs=10
Las opciones que valen la pena
Opción	Sondas	Para qué
--runs=N	presentación, cobertura	Menos corridas para tantear, más para concluir. Con menos de 5 no se decide nada.
--conversation=N	presentación, cobertura	Qué contexto histórico reproducir. Por defecto 23 y 24.
--model=deepseek-v4-flash	presentación, cobertura	Probar otro tier sin tocar la config.
--prompt-id=17	presentación	Reevaluar con una versión histórica del prompt del closer.
--tool-output="..."	cobertura	Probar otra redacción del tool_output sin desplegar nada.
--json=/tmp/x.json	presentación, cobertura	Volcar las corridas crudas — es donde van las razones completas.
checkout_closer	caché	Argumento posicional: cualquier agent_key.

## Bajar un volcado para leerlo
gcloud compute ssh mango-prod --zone=us-central1-a --command="sudo docker exec workflow-assistant-app-1 cat /tmp/cobertura.json" > cobertura.json