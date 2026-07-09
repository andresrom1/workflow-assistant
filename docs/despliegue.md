# Despliegue de producción — workflow-assistant

Documento que registra el despliegue del backend `workflow-assistant` en producción.
Fecha: 2026-07-08.

---

## Contexto

Piloto/MVP del asistente de seguros MANGO por WhatsApp. Dos proyectos desacoplados:

- **workflow-assistant** (Laravel 13, PHP 8.4) — backend con panel admin (Inertia/Vue), landing MANGO, webhook público HTTPS, workers de cola siempre-encendidos + scheduler. Depende de PostgreSQL con pgvector (RAG de coberturas) y servicios externos (Cloudflare R2, OpenAI, WhatsApp Cloud API, Firebase, Visred, AFIP).
- **mango-mobile** (Flutter) — no requiere hosting. Vive en su propio proyecto Firebase (`mango-broker`).

Restricciones: infra $5/mes ideal / $10 máx; no acoplar app y backend; evitar fees de tiendas por ahora (iOS fuera de scope).

---

## Decisión de plataforma

### Track inicial planificado: Oracle Cloud Always Free ($0)

VM ARM Always Free (2 OCPU / 12 GB RAM, tras recorte de jun-2026) con Docker Compose. La home region elegida fue **São Paulo (`sa-saopaulo-1`)** para ~30ms de latencia a Argentina.

**Problema:** Oracle reportó `Out of host capacity` para el shape `VM.Standard.A1.Flex` en São Paulo de forma persistente (varias horas, múltiples reintentos a 1/6/12 GB y 1/2 OCPU). No se consiguió capacidad.

**Mitigaciones intentadas:**
- Reintentos manuales desde consola web (múltiples intentos).
- Reintentos a 1 OCPU / 6 GB RAM (menos demanda, mismo error).
- Script de reintento automático vía OCI CLI (ver más abajo).

**Estado:** Script de reintento dejado corriendo en PC local. Si eventualmente entra, se migra desde GCP (la app es portable, solo Laravel).

### Track ejecutado: Google Cloud Platform ($300 / 90 días)

Se utilizó el crédito de trial de GCP ($300 USD por 90 días) para desplegar inmediatamente y no bloquear el piloto.

**VM:**
- Nombre: `mango-prod`
- Región: `us-central1` (Iowa)
- Zona: `us-central1-a`
- Shape: `t2a-standard-2` (ARM Ampere Altra, 2 vCPU / 8 GB RAM)
- Boot disk: Ubuntu 24.04 LTS (Noble) ARM64, 50 GB balanced
- External IP: `104.154.53.130`
- Provisioning model: Standard (sin límite de tiempo)
- Mantenimiento: Migrate (recomendado)
- Reinicio automático: Activado

**Migración futura:** Cuando Oracle São Paulo libere capacidad (script de reintento corriendo), o cuando se agote el trial de GCP (90 días), se evalúa migrar a Laravel Cloud Starter ($5+uso con spend cap, pgvector pre-instalado, cero-ops) o a Hetzner (€4-7/mes, ARM). La decisión se basa en el uso real medido durante el piloto.

---

## Arquitectura (un solo VM ARM)

```
Internet ──HTTPS──> Caddy (TLS auto Let's Encrypt) ──> nginx+php-fpm (contenedor app)
                                                        └─ supervisor: 5 workers + schedule:work
   Docker Compose (todo arm64) en el VM GCP:
     - app       (Dockerfile del repo, multi-stage Node+PHP)
     - postgres  (pgvector/pgvector:pg16, init CREATE EXTENSION vector;)
     - caddy     (reverse proxy TLS)
   Externos (no se hospedan): Cloudflare R2, OpenAI, WhatsApp Cloud API, Firebase, Visred, AFIP
```

---

## Fixes de código previos al deploy

Estos son los puntos del plan original (A.1) y su estado en el repo:

1. **`.dockerignore`** — ya existía en el repo. Excluye `.env*`, `node_modules`, `vendor`, `storage/app/*`, `.git`. ✓
2. **`php.ini` de prod** — ya existía en `docker/prod/php.ini` con `upload_max_filesize=100M`, `post_max_size=100M`, `memory_limit=512M`. El `Dockerfile` lo copia a `/usr/local/etc/php/conf.d/zz-prod.ini`. ✓
3. **Scheduler** — ya en `.docker/start.sh` como `[program:scheduler]` con `php artisan schedule:work`. ✓
4. **Cola `documents` sin worker** — ya en `.docker/start.sh` con `[program:worker-documents]` (cola `documents`, `--sleep=5 --timeout=300`). ✓
5. **Rutas `/api/dev/*`** — ya gateadas en `routes/api.php` con `if (! app()->isProduction())`. ✓
6. **Assets Vite** — **fix aplicado en este deploy.** El `Dockerfile` original no compilaba assets Vite. Se agregó un stage multi-stage con Node:
   ```dockerfile
   # Stage 1: Build Vite assets con Node
   FROM node:22-alpine AS node-build
   WORKDIR /var/www
   COPY package.json package-lock.json* ./
   RUN npm install
   COPY . .
   RUN npm run build

   # Stage 2: App PHP
   FROM php:8.4-fpm-alpine
   ...
   COPY --chown=www:www --from=node-build /var/www/public/build /var/www/public/build
   ```
   Commit: `101e02b feat: multi-stage Node build for Vite assets in Dockerfile`.
7. **Permisos del dir temporal de nginx** — **fix post-deploy (2026-07-09).** El `Dockerfile` cambia nginx para correr como `www` (vía `sed`), pero `/var/lib/nginx/tmp/` seguía perteneciendo al usuario `nginx` original del paquete. Resultado: todo `POST` con `multipart/form-data` (subida de documentos, edición de prompts) devolvía 500 porque nginx no podía escribir el body temp. Síntomas: `500 Internal Server Error` sin stack trace de Laravel, error de nginx `[crit] open() "/var/lib/nginx/tmp/client_body/..." failed (13: Permission denied)`. Fix en el `Dockerfile`:
   ```dockerfile
   RUN sed -i 's/user = www-data/user = www/g' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/group = www-data/group = www/g' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/user nginx;/user www;/g' /etc/nginx/nginx.conf \
    && chown -R www:www /var/lib/nginx \
    && chown -R www:www /var/log/nginx
   ```
   Mitigación inmediata aplicada en prod (sin rebuild): `docker exec -u root workflow-assistant-app-1 chown -R www:www /var/lib/nginx /var/log/nginx` + `nginx -s reload`.

Archivos relevantes: `Dockerfile`, `.dockerignore`, `docker/prod/php.ini`, `.docker/start.sh`, `.docker/supervisord.conf`, `routes/api.php`, `compose.prod.yaml`, `Caddyfile`, `docker/postgres/init/01-init-extensions.sql`.

---

## compose.prod.yaml

Servicios:
- **app** — build del `Dockerfile`, `env_file: .env.production`, volumen persistente para `storage/app/` (certs AFIP, JSON Firebase, disco local privado).
- **postgres** — `pgvector/pgvector:pg16`, volumen de datos, init `CREATE EXTENSION vector;`, healthcheck `pg_isready`.
- **caddy** — `caddy:2-alpine`, Caddyfile con `{$DOMAIN} → app:80`, TLS automático Let's Encrypt, `request_body max_size 100MB`.

Red interna entre los tres. Volúmenes: `pgdata`, `caddy_data`, `caddy_config`.

Uso:
```bash
docker compose --env-file .env.production -f compose.prod.yaml build
docker compose --env-file .env.production -f compose.prod.yaml up -d
```

---

## Configuración de DNS y TLS

### Dominio
- `mangobroker.com.ar` registrado en nic.ar.
- Nameservers delegados a Cloudflare: `houston.ns.cloudflare.com` y `ulla.ns.cloudflare.com`.

### Registros DNS en Cloudflare
- `A @ 104.154.53.130` (DNS only — nube gris)
- `A www 104.154.53.130` (DNS only — nube gris)

**Importante:** El proxy de Cloudflare está desactivado (DNS only) porque Caddy necesita obtener certificados TLS directamente de Let's Encrypt mediante challenge HTTP/TLS-ALPN. Si se activa el proxy (nube naranja), Caddy no puede completar el challenge y los certificados no se renuevan.

### TLS
- Caddy obtiene automáticamente el certificado de Let's Encrypt al primer request HTTPS.
- Renovación automática cada 60 días.
- Logs de Caddy confirman: `certificate obtained successfully` para `mangobroker.com.ar`.

### Activar proxy de Cloudflare (futuro)
Para activar el proxy (WAF/CDN/DDoS protection) sin romper TLS:
1. Cloudflare → SSL/TLS → Origin Server → Create Certificate (15 años, sin renovación).
2. Instalar el cert en Caddy como cert fijo.
3. Cloudflare → SSL/TLS mode → Full (strict).
4. Activar proxy (nube naranja).

---

## .env.production

Basado en el `.env.example` real (Postgres, no MySQL). Valores clave de producción:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mangobroker.com.ar
APP_DOMAIN=mangobroker.com.ar

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=mango
DB_USERNAME=mango
DB_PASSWORD=<generado con openssl rand -base64 24>

SESSION_DRIVER=database
SESSION_DOMAIN=mangobroker.com.ar
QUEUE_CONNECTION=database
QUEUE_CONNECTION_LONG=database_long
CACHE_STORE=database
BROADCAST_CONNECTION=null
FILESYSTEM_DISK=cloudflare-r2

CORS_ALLOWED_ORIGINS=https://mangobroker.com.ar

LOG_CHANNEL=stderr
LOG_STACK=stderr
LOG_LEVEL=warning
```

Secretos (no commiteados): `OPENAI_API_KEY`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_WABA_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`, `CLOUDFLARE_R2_*`, `FIREBASE_CREDENTIALS`, `VISRED_*`, `AFIP_*`, `ELEVENLABS_API_KEY`, `DEEPSEEK_API_KEY`, `GEMINI_API_KEY`.

### AFIP (facturación real)
- `AFIP_HOMOLOGACION=false` → usa `afip-prod.crt` (cert de producción) y endpoints de prod.
- `AFIP_CUIT=20301237279`
- `AFIP_PUNTO_VENTA=2`
- `AFIP_CERT_PATH` y `AFIP_KEY_PATH` no se setean — usan defaults del `config/afip.php` que resuelve `storage/app/certs/afip-prod.crt` y `storage/app/certs/afip.key` cuando `AFIP_HOMOLOGACION=false`.
- El CUIT del emisor se guarda en la BD (tabla `system_settings`) desde la UI de configuración. El seed inicial toma el valor de `.env`. El `config/afip.php` tiene fallback a `env('AFIP_CUIT')` si no está en BD.

### Firebase
- `FIREBASE_CREDENTIALS=/var/www/storage/app/firebase.json` — JSON del service account del Admin SDK (kreait). Se usa para:
  1. Verificar Firebase ID Tokens de la app móvil (login).
  2. Enviar push notifications (FCM).
- El archivo se sube al VM en `runtime/storage-app/firebase.json` (se monta como `/var/www/storage/app/firebase.json`).

---

## Archivos subidos al VM (fuera de git)

Estructura en el VM:
```
~/workflow-assistant/
├── .env.production              # secretos (gitignored)
├── compose.prod.yaml
├── Dockerfile
├── runtime/
│   └── storage-app/              # volumen persistente montado como /var/www/storage/app/
│       ├── firebase.json         # creds Firebase Admin SDK
│       └── certs/
│           ├── afip-prod.crt     # cert AFIP producción
│           └── afip.key          # private key AFIP
└── docker/                       # configs PostgreSQL init, php.ini, etc.
```

---

## Pasos de despliegue ejecutados (GCP)

### 1. Crear cuenta y proyecto en GCP
- Trial de $300 USD / 90 días activado.
- Proyecto: `project-1abe2eb8-c736-448d-bd8`.

### 2. Firewall rules (Cloud Shell)
```bash
gcloud compute firewall-rules create allow-http --project=project-1abe2eb8-c736-448d-bd8 --action=ALLOW --rules=tcp:80 --target-tags=http-server
gcloud compute firewall-rules create allow-https --project=project-1abe2eb8-c736-448d-bd8 --action=ALLOW --rules=tcp:443 --target-tags=https-server
gcloud compute firewall-rules create allow-ssh-custom --project=project-1abe2eb8-c736-448d-bd8 --action=ALLOW --rules=tcp:22 --source-ranges=0.0.0.0/0
```

### 3. Crear instancia VM
```bash
gcloud compute instances create mango-prod \
    --project=project-1abe2eb8-c736-448d-bd8 \
    --zone=us-central1-a \
    --machine-type=t2a-standard-2 \
    --network-interface=network-tier=PREMIUM,stack-type=IPV4_ONLY,subnet=default \
    --metadata=enable-osconfig=TRUE \
    --maintenance-policy=MIGRATE \
    --provisioning-model=STANDARD \
    --service-account=175481689498-compute@developer.gserviceaccount.com \
    --scopes=https://www.googleapis.com/auth/devstorage.read_only,https://www.googleapis.com/auth/logging.write,https://www.googleapis.com/auth/monitoring.write,https://www.googleapis.com/auth/service.management.readonly,https://www.googleapis.com/auth/servicecontrol,https://www.googleapis.com/auth/trace.append \
    --tags=http-server,https-server \
    --create-disk=auto-delete=yes,boot=yes,device-name=mango-prod,image=projects/ubuntu-os-cloud/global/images/ubuntu-2404-noble-arm64-v20260707,mode=rw,size=50,type=pd-balanced \
    --no-shielded-secure-boot \
    --shielded-vtpm \
    --shielded-integrity-monitoring \
    --labels=goog-ops-agent-policy=v2-template-1-7-0,goog-ec-src=vm_add-gcloud \
    --reservation-affinity=any
```

### 4. Conectarse por SSH
```bash
gcloud compute ssh mango-prod --zone=us-central1-a --project=project-1abe2eb8-c736-448d-bd8
```

### 5. Instalar Docker + Compose
```bash
sudo apt install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=arm64 signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu noble stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker $USER
newgrp docker
```

### 6. Clonar repo
```bash
cd ~
git clone https://github.com/andresrom1/workflow-assistant.git
cd workflow-assistant
```

### 7. Crear estructura de directorios y subir archivos
```bash
mkdir -p runtime/storage-app/certs
```

Subir desde la PC local (vía nano en el VM o gcloud compute scp):
- `runtime/storage-app/certs/afip-prod.crt`
- `runtime/storage-app/certs/afip.key`
- `runtime/storage-app/firebase.json`

### 8. Generar secrets
```bash
# DB password
openssl rand -base64 24

# APP_KEY
echo "base64:$(openssl rand -base64 32 | tr -d '\n')"
```

### 9. Crear .env.production
```bash
cat > ~/workflow-assistant/.env.production << 'EOF'
# ... (ver sección .env.production arriba)
EOF
```

### 10. Build y up del stack
```bash
cd ~/workflow-assistant
docker compose --env-file .env.production -f compose.prod.yaml build
docker compose --env-file .env.production -f compose.prod.yaml up -d
```

### 11. Migraciones (corren automáticamente en start.sh, pero verificar)
```bash
docker compose --env-file .env.production -f compose.prod.yaml exec app php artisan migrate --force
```

---

## Configuración de WhatsApp Cloud API

En el dashboard de Meta/WhatsApp:
1. **Webhook URL:** `https://mangobroker.com.ar/api/webhooks/whatsapp`
2. **Verify Token:** `AndMan8317`
3. **Campo suscrito:** `messages` (mensajes entrantes)
4. Validación de firma HMAC con `WHATSAPP_APP_SECRET`.

---

## Presupuesto mensual (infra)

| Ítem | Costo/mes |
|---|---|
| GCP VM t2a-standard-2 (2 vCPU ARM / 8 GB) | ~$20-24 (cubierto por trial $300/90 días) |
| PostgreSQL + pgvector (self-host en el VM) | $0 |
| Cloudflare DNS | $0 |
| TLS Let's Encrypt (Caddy) | $0 |
| Firebase Auth + FCM | $0 (Spark) |
| Dominio `mangobroker.com.ar` (nic.ar) | <$1 (amortizado) |
| **Total infra fija** | **$0 durante el trial** |

**Costos variables (a medir):** OpenAI (embeddings + tokens), WhatsApp Cloud API (Meta por conversación; ARS desde 2026-04), STT/TTS (ElevenLabs).

---

## Verificación end-to-end (estado al 2026-07-08)

| Componente | Estado |
|---|---|
| VM GCP ARM | ✓ Running |
| Docker + Compose | ✓ |
| PostgreSQL + pgvector | ✓ Healthy, extensión `vector` instalada |
| App (nginx + PHP-FPM 8.4) | ✓ Serving |
| 5 workers (default, documents, media, whatsapp-ai, whatsapp-outbound) | ✓ Running |
| Scheduler (`schedule:work`) | ✓ Running |
| Caddy + TLS Let's Encrypt | ✓ Cert obtenido para `mangobroker.com.ar` |
| DNS `mangobroker.com.ar` | ✓ Propagado |
| HTTP → HTTPS redirect | ✓ 308 |
| HTTPS landing | ✓ 200 |
| Webhook WhatsApp challenge (`GET`) | ✓ Devuelve `hub.challenge` |
| Webhook WhatsApp mensajes (`POST`) | ✓ `ProcessWhatsAppMessage` → `ProcessConversationInbox` → `SendWhatsAppMessage` |
| AFIP certs prod | ✓ Subidos |
| Firebase JSON | ✓ Subido |
| Assets Vite | ✓ Compilados (multi-stage build) |

### Logs de verificación del pipeline de WhatsApp
```
2026-07-08 22:26:39 App\Jobs\ProcessWhatsAppMessage ................ RUNNING
2026-07-08 22:26:39 App\Jobs\ProcessWhatsAppMessage ........... 40.55ms DONE
2026-07-08 22:26:48 App\Jobs\ProcessConversationInbox .............. RUNNING
2026-07-08 22:26:51 App\Jobs\ProcessConversationInbox .............. 3s DONE
2026-07-08 22:26:54 App\Jobs\SendWhatsAppMessage ................... RUNNING
2026-07-08 22:26:56 App\Jobs\SendWhatsAppMessage ................... 2s DONE
2026-07-08 22:26:54 App\Jobs\AnalyzeConversationHealthJob .......... RUNNING
2026-07-08 22:26:54 App\Jobs\AnalyzeConversationHealthJob ...... 9.46ms DONE
2026-07-08 22:27:00 App\Jobs\UpdateMessageStatus ................... RUNNING
2026-07-08 22:27:00 App\Jobs\UpdateMessageStatus ............... 2.29ms DONE
```

---

## Script de reintento Oracle Cloud (corriendo en PC local)

Mientras el piloto corre en GCP, un script de PowerShell reintenta crear la instancia ARM Always Free en Oracle Cloud São Paulo cada 90 segundos. Si eventualmente entra, se puede migrar desde GCP (la app es portable).

**Archivos del script (en la PC local):**
- `C:\Users\Andrés\.oci\retry-instance.ps1` — script de reintento.
- `C:\oracle-deploy\shape-config.json` — config del shape (1 OCPU / 6 GB).
- `C:\oracle-deploy\metadata.json` — SSH key pública.
- `C:\Users\Andrés\.oci\config` — config de OCI CLI (API keys, región `sa-saopaulo-1`).
- `C:\Development\oracle-cli\bin\oci.exe` — OCI CLI.

**Ejecución:**
```powershell
powershell -ExecutionPolicy Bypass -File C:\Users\Andrés\.oci\retry-instance.ps1
```

**Parámetros del script:**
- Compartment: tenancy OCID (root)
- Subnet: `ocid1.subnet.oc1.sa-saopaulo-1.aaaaaaaazi7epyhzsng67q4zpoe2cxijapwifron2o7volmvqijxcxwklhwq`
- Availability Domain: `mbrc:SA-SAOPAULO-1-AD-1`
- Image: Ubuntu 22.04 ARM (`ocid1.image.oc1.sa-saopaulo-1.aaaaaaaaemf52b7af7ncncxz6pdc6hrlkdmylvwejfzpwnpbuhlfxwhrno6a`)
- Shape: `VM.Standard.A1.Flex` (1 OCPU / 6 GB RAM)
- Boot disk: 50 GB

---

## Pendientes

- [ ] Migración de datos desde la BD local a prod (dump selectivo, excluir `policy_documents`, `policy_report_batches`, `policy_report_rows`, `ingested_documents`; incluir `coverage_documents`, `coverage_chunks`).
- [ ] Backups: configurar cron de `pg_dump` → R2.
- [ ] App mobile: build APK apuntando a `https://mangobroker.com.ar` y distribución por Firebase App Distribution.
- [ ] Evaluar activación de proxy Cloudflare (Origin Certificate + Full strict).
- [ ] Medición para decidir migración: registrar gasto OpenAI/día, costo WhatsApp/conversación, uso de CPU/RAM del VM.

---

## Fuera de scope / diferido

- iOS ($99/año Apple Developer).
- Play Store público ($25 único).
- Postgres HA (el piloto no lo necesita; alcanza `pg_dump` a R2).
- Laravel Forge (rompe el tope de presupuesto).