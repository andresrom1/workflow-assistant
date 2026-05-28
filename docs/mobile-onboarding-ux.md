# Onboarding mobile — UX del email en checkout

> **Audiencia:** equipo de producto + diseño del chat de cotización.
> **Estado:** decisión cerrada en Fase 1 de la app móvil (mayo 2026).
> **Documento vivo** — actualizar si cambia la política de identidad.

---

## Qué cambió

A partir de Fase 1 del MANGO App (mayo 2026), **el email que el cliente
informa en el checkout del chat de cotización tiene dos roles nuevos**
además del rol histórico de canal de comunicación:

1. **Llave de acceso a la app móvil.** Cuando el cliente descarga la app
   y se loguea con Google o Apple, el backend matchea su identidad OAuth
   contra el registro de cliente (`customers`) por **email + DNI**. Si el
   email del OAuth no coincide con el email del checkout, la vinculación
   falla y el cliente queda sin acceso a sus pólizas hasta que un PAS lo
   resuelva manualmente.

2. **Canal de invitaciones a Cuenta Compartida.** Si un titular invita a
   un conductor adicional (Fase 9), la invitación viaja por email. El
   invitado solo puede aceptar si su login OAuth usa **ese mismo email**.

Esto requiere que el cliente lo entienda **antes** de informarlo, no
después.

---

## Implicancias para el chat / checkout

### 1. Comunicar el doble rol del email

Cuando el agente del chat solicita el email en el checkout, agregar (en
voz de marca) que ese email será:

- El que use para entrar a la app MANGO.
- El que sus contactos vean si comparte un vehículo con ellos.

**Ejemplo de fraseo (no copy final, solo dirección):**

> Pasame tu email para mandarte la póliza y los comprobantes. Ese mismo
> email te va a servir para entrar a la app MANGO cuando la descargues.

### 2. Validar que sea el email que el cliente usa habitualmente

El error más común va a ser que el cliente informe un email "para
papeles" (ej. el laboral) y después intente loguearse en la app con su
Gmail/iCloud personal. El chat debería preguntar explícitamente:

> ¿Es el mismo email que usás en tu celular (Google/Apple)?

Si el cliente duda, preferir el de su celular sobre el laboral. El email
del checkout es la llave; el laboral lo pueden agregar como contacto
secundario más adelante si hace falta.

### 3. Apple "Ocultar mi correo"

Los clientes con iPhone que usan Sign in with Apple pueden generar un
email de relay (`@privaterelay.appleid.com`). **Ese email no sirve para
la vinculación de la app** — la app rechaza relay-emails en el login.

El chat no necesita explicar esto: el problema solo aparece cuando el
cliente intenta loguearse en la app, y ahí la app le pide usar Google o
deshabilitar la opción "Ocultar mi correo".

### 4. Cambio de email después del checkout

Si el cliente cambia su email principal (mudanza de proveedor, etc.),
hoy **no hay flujo self-service** para actualizarlo. El cliente le
escribe al PAS, el PAS lo edita en el panel admin, la app vuelve a
matchear en el próximo intento de login.

A futuro (no incluido en Fase 2): endpoint `PUT /auth/email` con
confirmación por email viejo + nuevo. Por ahora, intervención manual.

---

## Para el equipo de diseño del chat

Sugerencias concretas para el flow de checkout:

- **Pedir el email antes del DNI**, no después. El cliente está más
  predispuesto a informar email "el bueno" antes que el DNI, donde ya
  entró en modo "trámite".
- **Mostrar feedback visible** ("guardamos `xxx@gmail.com` como tu email
  para la app y la póliza"). Eco explícito antes de avanzar.
- **No permitir avanzar con email vacío o sintácticamente inválido.** La
  validación de existencia real es responsabilidad del proveedor OAuth;
  el chat solo valida formato.

---

## Para el equipo de producto

Esta decisión tiene una contrapartida importante: **el cliente solo
puede tener una identidad MANGO por email**. Si dos personas comparten
un email (matrimonio, familia), solo una puede ser titular en la app.
La otra puede ser conductor adicional vía Cuenta Compartida.

Si en producción se detecta que esto bloquea casos reales (clientes que
comparten email histórico), evaluar:

1. Pedir email único en el checkout (rechazar duplicados con un mensaje
   claro).
2. Permitir múltiples `mobile_accounts` por `customer` con la misma
   identidad de cliente pero distinto email — requiere refactor del
   linking actual.

Hoy no es un problema observado. Documentado para revisión cuando haya
volumen.
