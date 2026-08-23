# Migración de tier de VM — cobrar el ahorro del refactor de colas

> **Estado:** planificado, sin ejecutar. Escrito el 2026-08-23 para abordarlo en otra sesión.
> Complementa [`despliegue.md`](despliegue.md), que documenta el despliegue actual en GCP.

---

## 1. Por qué

Dos cosas convergen:

1. **El trial de GCP se agota.** El despliegue corre sobre el crédito de $300 / 90 días. La VM
   `mango-prod` se creó alrededor del **2026-07-08**, así que la ventana se cierra a comienzos de
   octubre — **verificar la fecha exacta en la consola de facturación**, porque el trial cuenta desde
   la creación de la cuenta, no de la VM.
2. **El refactor de colas del 2026-08-22 ya liberó el margen.** De 7 procesos PHP residentes a 4.
   Esta migración es *cobrar* ese trabajo: sin él, la app no entraba cómoda en un tier chico.

**Objetivo:** pasar de ~$20-24/mes a **$0-5/mes**, sin degradar la experiencia del cliente en
WhatsApp.

### Estado actual

| | |
|---|---|
| VM | `mango-prod`, GCP `t2a-standard-2` (ARM Ampere, **2 vCPU / 8 GB**) |
| Zona | `us-central1-a` (Iowa) |
| IP externa | `104.154.53.130` |
| Disco | Ubuntu 24.04 LTS ARM64, 50 GB balanced |
| Costo | ~$20-24/mes (hoy cubierto por el trial) |
| Stack | Docker Compose: `app` (nginx + php-fpm + 3 workers + scheduler), `postgres` (pgvector), `caddy` |

---

## 2. Los datos que habilitan la decisión

Medidos en `mango-prod` el **2026-08-22**, antes y después del refactor de colas. **En reposo**
(load average 0.14).

| | Antes | Después | Δ |
|---|---|---|---|
| **Host usado** (`free -m`) | 1.104 MB | **920 MB** | −184 MB (−17 %) |
| Contenedor `app` | 299,1 MB | **190,6 MB** | −108,5 MB (−36 %) |
| Contenedor `postgres` | 57,3 MB | 34,7 MB* | |
| Contenedor `caddy` | 24,0 MB | 23,9 MB | |
| Procesos PHP residentes | 7 | **4** | −3 |
| RSS por queue worker | 47-48 MB | 51-52 MB | (sube por el `file_cache` de OPcache) |
| php-fpm | 2 hijos permanentes (~82 MB) | master 22 MB + hijos `ondemand` | |
| CPU del contenedor `app` | — | 0,10 % | |

\* **Los 34,7 MB de Postgres no son un ahorro**: es el buffer pool frío tras recrear el contenedor.
Va a volver a subir. No lo cuentes en el presupuesto.

### El hallazgo que ordena la decisión

**La VM está dominada por el sistema operativo, no por la aplicación.**

```
920 MB usados
├── ~724 MB   Ubuntu + docker daemon + snap
└── ~196 MB   todo el stack de la app (app 190 + caddy 24 + postgres 35 ≈ 249 con postgres frío)
```

Bajar de 8 GB a 2 GB no aprieta a la app: aprieta al margen entre el SO y el pico. Por eso lo que
falta medir no es el reposo (ya está) sino **el pico**.

---

## 3. Lo que hay que medir ANTES de elegir tamaño

Los números de arriba son **en reposo**. Antes de comprometerse a 2 GB hay que ver el pico, que se
da cuando coinciden:

- un turno de LLM con contexto grande (`CheckoutAgent` midió **69 s** y hay registro de turnos de
  **143.665 tokens** de entrada — ver ROADMAP 2026-08-13),
- un `ResolveQuote` corriendo contra Visred (30-360 s),
- una extracción de PDF por LLM en `background` (hasta 300 s),
- Postgres con el buffer pool caliente (`shared_buffers = 256MB` es un techo que se llena bajo
  demanda; con esta base chica no debería acercarse, pero no está medido).

**Cómo medirlo:** dejar corriendo un muestreo mientras se fuerza el escenario.

```bash
while true; do date +%H:%M:%S >> /tmp/mem.log; free -m | sed -n 2p >> /tmp/mem.log; sleep 5; done &
```

Y en paralelo: una conversación completa de WhatsApp hasta la presentación de alternativas, más
subir un PDF de coberturas desde `/coverage-documents` para disparar la extracción.

**Criterio:** el tier elegido tiene que dejar el pico por debajo del **70 %** de la RAM total. Sin
swap configurado (hoy `Swap: 0`), quedarse corto no degrada — mata procesos.

---

## 4. Opciones

| Opción | RAM / arch | Región · latencia a AR | Costo aprox. | Notas |
|---|---|---|---|---|
| **Oracle Always Free A1** | 1-4 OCPU ARM / 6-24 GB | São Paulo · **~30 ms** | **$0** | La mejor por lejos si aparece capacidad. Bloqueada por `Out of host capacity` desde el inicio; hay un script de reintento (ver `despliegue.md`). **Verificar primero si entró.** |
| **GCP `e2-small`** | 2 vCPU / 2 GB, x86 | us-central1 · ~130 ms (igual que hoy) | ~$13/mes | Migración más trivial de todas: mismo proyecto, misma consola, mismo firewall. Pero es la más cara de las pagas y **no mejora latencia**. |
| **GCP `e2-small` en `southamerica-east1`** | 2 vCPU / 2 GB, x86 | São Paulo · **~30 ms** | ~$15-17/mes | Mejora la latencia de forma notable. Verificar precio: las regiones de Sudamérica tienen recargo. |
| **Hetzner CAX11** | 2 vCPU ARM / 4 GB / 40 GB | **solo UE** (Falkenstein, Núremberg, Helsinki) · ~200-230 ms | ~€4/mes | La más barata. **El costo oculto es la latencia**: la línea ARM (CAX) de Hetzner no existe fuera de la UE. |
| **Hetzner CPX11** | 2 vCPU x86 / 2 GB | UE o **Ashburn / Hillsboro (US)** · ~130-150 ms | ~€5/mes | Si se quiere Hetzner con latencia comparable a hoy, hay que ir a x86 en US. |
| **Laravel Cloud Starter** | gestionado | — | ~$5 + uso | pgvector preinstalado, cero-ops. **Requiere validar el modelo de colas gestionadas** contra la topología de 3 workers + scheduler; no es un lift-and-shift. |

### El eje que se suele olvidar: la latencia geográfica

Hoy el servidor está en **Iowa**. Eso afecta tres cosas, y no todas por igual:

- **El panel de admin** — lo usás vos desde Argentina. Cada request paga el round-trip. Es lo más
  sensible en percepción.
- **La API de Visred** — es argentina. Cada llamada de cotización/emisión paga ida y vuelta a Iowa
  y de vuelta. Con `ResolveQuote` haciendo polling (un `while` con sleep por task), esto se
  multiplica.
- **El webhook de Meta** — Meta tiene infraestructura global; el impacto es menor.

**Conclusión práctica:** mudarse a la UE por €1 de diferencia empeora los dos primeros. Si el
presupuesto lo tolera, São Paulo (Oracle gratis o GCP pago) es estrictamente mejor que lo que hay
hoy, no solo más barato.

---

## 5. Procedimiento de migración

El orden importa. Los dos puntos donde esto se rompe si se improvisa están marcados.

### Preparación (sin downtime, se puede hacer días antes)

1. **Bajar el TTL del registro DNS en Cloudflare a 60 s**, al menos 24 h antes del corte. Con el TTL
   por defecto, el corte se arrastra horas.
2. **Provisionar la VM nueva** e instalar Docker + Compose (pasos 5 y 6 de `despliegue.md`).
3. **Abrir el firewall**: 80, 443 y SSH.
4. **Clonar el repo** y copiar lo que **no está en git**:
   - `.env.production` (gitignored)
   - `runtime/storage-app/` — certificados de AFIP y credenciales de Firebase. El
     `compose.prod.yaml` lo monta como volumen; sin esto, la facturación y el push no arrancan.
5. **⚠️ Copiar el volumen `caddy_data`.** Contiene el certificado TLS de Let's Encrypt.
   Si no se copia, hay un huevo-y-gallina: Caddy no puede emitir un certificado hasta que el DNS
   apunte a la VM nueva, pero no querés mover el DNS hasta que la VM nueva funcione. Copiando el
   volumen, la VM nueva arranca ya con el certificado válido.

   ```bash
   docker run --rm -v workflow-assistant_caddy_data:/data -v /tmp:/backup alpine tar czf /backup/caddy_data.tgz -C /data .
   ```

   La alternativa es configurar el challenge **DNS-01** de Caddy con un token de API de Cloudflare,
   que resuelve el problema de raíz pero es un cambio de configuración aparte.
6. **Ensayo en seco**: `pg_dump` de la VM vieja → restore en la nueva → `build` + `up -d` → verificar
   que todo levanta **sin tocar el DNS**. Acá se descubren los problemas, no en la ventana de corte.

### Ventana de corte (con downtime)

7. **Parar el contenedor `app` de la VM vieja.** Esto es lo que congela la base:

   ```bash
   docker compose --env-file .env.production -f compose.prod.yaml stop app
   ```

8. **`pg_dump` final** y restore en la nueva. La base es chica; deberían ser segundos.
9. **`up -d` en la VM nueva.**
10. **Cambiar el registro A en Cloudflare** a la IP nueva.
11. **Verificar** (sección 6).

### Después

12. **No borrar la VM vieja** por unos días. Es el rollback: se revierte el registro A y se levanta
    `app` de nuevo. Ojo — si se revierte, la base vieja quedó congelada en el paso 7 y perdés lo que
    haya entrado en la nueva.
13. **Actualizar `despliegue.md`** con la nueva VM, IP y presupuesto.

### Qué significa "downtime" acá

No es un sitio caído: es un bot de WhatsApp. Durante la ventana, **Meta reintenta los webhooks**, así
que un corte corto se absorbe solo. El riesgo real no es la indisponibilidad sino la **divergencia de
datos**: si la app vieja sigue recibiendo mensajes después del `pg_dump`, esos mensajes se pierden al
cambiar el DNS. Por eso el paso 7 (parar `app`) va **antes** del dump y no después.

---

## 6. Verificación post-migración

Los mismos chequeos que se usaron para validar el refactor de colas el 2026-08-22.

```bash
docker exec workflow-assistant-app-1 sh -c "ps -o rss,args -A | grep -E 'queue:work|schedule:work|php-fpm' | grep -v grep"
```

Tienen que aparecer exactamente **3 `queue:work`** (con sus conexiones `database_ai`,
`database_quotes`, `database`), **1 `schedule:work`**, y php-fpm **sin hijos** hasta que entre un
request.

> `supervisorctl status` **no funciona** en esta imagen: al `supervisord.conf` del proyecto le falta
> la sección `[supervisorctl]`. Usar `ps`.

```bash
docker exec workflow-assistant-app-1 php -i | grep -iE "opcache.enable|file_cache"
```

```bash
docker exec workflow-assistant-app-1 php artisan queue:monitor default,whatsapp-ai,whatsapp-outbound,quotes,media,background,documents
```

```bash
curl -s -o /dev/null -w "HTTP %{http_code} en %{time_total}s\n" https://mangobroker.com.ar/up
```

Referencia del 2026-08-22: **0,35 s en frío** (php-fpm levantando su hijo `ondemand`) y **0,043 s
tibio**.

Y el que realmente importa: **prueba de humo por WhatsApp** — mensaje y cronometrar la tilde azul,
nota de voz, y una cotización completa hasta ver las alternativas.

---

## 7. Riesgos

| Riesgo | Mitigación |
|---|---|
| El tier nuevo queda corto bajo carga y el OOM killer mata procesos | Medir el pico (sección 3) antes de elegir. Sin swap, quedarse corto no degrada: mata. Considerar habilitar un swapfile de 1-2 GB como red de contención. |
| Caddy no puede emitir el certificado tras el corte | Copiar el volumen `caddy_data` (paso 5), o configurar DNS-01. |
| Mensajes perdidos entre el dump y el cambio de DNS | Parar `app` **antes** del dump final (paso 7). TTL bajo desde el día anterior. |
| Se olvida `runtime/storage-app` | La facturación AFIP y el push de Firebase fallan en silencio hasta que alguien los usa. Verificarlo explícitamente en el ensayo en seco. |
| Cambio de arquitectura ARM → x86 | El `Dockerfile` es agnóstico y la imagen se reconstruye en destino. No debería haber sorpresas, pero el ensayo en seco lo confirma. |
| Latencia peor tras mudarse a la UE | Ver sección 4. Es una decisión, no un accidente — pero conviene tomarla a propósito. |

---

## 8. Lo que queda por decidir

1. **¿Se espera a Oracle?** Es la única opción de $0 **y** de mejor latencia. Chequear si el script
   de reintento consiguió capacidad antes de pagar por otra cosa.
2. **¿Región?** Mantener us-central1 (statu quo), mudarse a São Paulo (mejor, más caro) o a la UE
   (más barato, peor). Ver sección 4.
3. **¿2 GB o 4 GB?** Depende de la medición del pico. En reposo 2 GB sobra; el margen es para el pico,
   no para el idle.
4. **¿Se configura swap?** Hoy no hay (`Swap: 0`). En un box de 2 GB, 1-2 GB de swapfile es una red
   de contención barata contra el OOM killer, a costa de latencia si se usa.

---

## Referencias

- [`despliegue.md`](despliegue.md) — el despliegue actual, paso a paso, y el script de reintento de Oracle.
- [`../ROADMAP.md`](../ROADMAP.md), bitácora **2026-08-22** — el refactor de colas, los números medidos y las dos correcciones a las estimaciones.
- [`../CLAUDE.md`](../CLAUDE.md), sección *Colas y workers* — la topología de 3 workers + `background` bajo demanda, y las dos reglas para tocarla.
