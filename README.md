# Montu Affiliate — Extensión de Magento 2 (`montu/module-affiliate`)

Paquete Composer del módulo **`Montu_Affiliate`**: captura códigos de afiliados en el
storefront y envía webhooks firmados con la información de las órdenes a la plataforma Montu.

Este directorio **es el paquete Composer** (raíz = raíz del módulo). No contiene una
instalación de Magento ni entorno Docker — solo el código del módulo, instalable vía
Composer en cualquier proyecto Magento 2.

## ✨ Características

- ✅ **No Invasivo** — no modifica tablas core de Magento (crea las suyas)
- ✅ **Asíncrono** — no bloquea el checkout; los eventos se guardan en una cola (outbox) y se
  entregan por cron
- ✅ **Eventos de pedido** — `order.placed`, `order.updated` y `creditmemo.created`; cada uno
  se activa o desactiva por separado
- ✅ **Configurable por store view** — Stores → Configuration → Montu (eventos, filtros de pedidos,
  datos personales, cola, logs)
- ✅ **Fail-safe** — nunca toca la transacción del cliente ni la del pedido; los observers
  nunca dejan escapar excepciones
- ✅ **Reintentos automáticos** — por defecto 5 intentos, espera fija de 1/5/15/30/60 min
- ✅ **Orden por pedido (FIFO)** — un evento no se envía mientras un evento anterior del mismo
  pedido no haya terminado (entregado o sin reintentos restantes)
- ✅ **Registro de eventos en el admin** — Marketing → Montu Affiliate → Event Log, con la acción
  *Requeue / resend*
- ✅ **White-label** — el storefront del afiliado adopta los colores y la tipografía de
  cualquier tema de la tienda (o se configuran a mano en el admin)

---

## 📦 Instalación vía Composer

El paquete está publicado en **Packagist público**, así que la instalación es directa,
sin configurar repositorios:

```bash
composer require montu/module-affiliate
```

Requisitos: Magento 2.4.x y PHP 8.1+.

<details>
<summary>Instalación para desarrollo (monorepo / repositorio <code>path</code>)</summary>

Este paquete vive como subcarpeta `magento/` del monorepo de Montu. Para trabajar contra
el código en disco (cambios en vivo), apunta Composer a la carpeta con un repo `path`:

```jsonc
// composer.json del proyecto Magento
{
  "repositories": [
    { "type": "path", "url": "../monorepo/magento", "options": { "symlink": true } }
  ]
}
```

```bash
composer require montu/module-affiliate:@dev
```

`symlink: true` enlaza la carpeta; usa `"symlink": false` para que Composer **copie** el
paquete (recomendado en builds/deploys).

</details>

> El `composer.json` **no fija `version`**: Composer toma la versión de los **tags git** del
> repo publicado en Packagist (`git tag v2.0.0`), así que puedes fijar `^2.0` si lo necesitas.

### Activar el módulo (después de `composer require`)

```bash
bin/magento module:enable Montu_Affiliate
bin/magento setup:upgrade            # crea/actualiza montu_affiliate_tracking + montu_affiliate_webhook_log
bin/magento setup:di:compile         # tras cambios de DI / constructores
bin/magento cache:clean
```

### Actualizar desde 1.x

```bash
composer require montu/module-affiliate:^2.0
bin/magento setup:upgrade            # migra las tablas
bin/magento setup:di:compile         # producción
bin/magento cache:flush
```

- Los webhooks que la v1 dejó pendientes o fallidos **no se envían**: la v1 no guardaba el
  payload, así que el cron los cierra como `max_retries` con el mensaje "Recorded by module v1
  without a payload; not sent". Los pedidos que reportó la v1 no quedan rastreados por la v2
  (sus cambios posteriores no generan `order.updated`).
- El cron ahora corre en su **propio grupo** `montu_affiliate`. Si tu cron de Magento ejecuta
  grupos por separado, agrega `bin/magento cron:run --group=montu_affiliate`; con el
  `cron:run` habitual (todos los grupos) no hace falta nada.
- Los valores guardados en `webhook/*`, `general/*`, `storefront/*` y `appearance/*` se
  mantienen. Nuevo por defecto: si Montu no responde, el storefront muestra una
  página vacía (*When Montu Returns No Products* = *Show an empty page*).

---

## ⚙️ Configuración

**Stores → Configuration → Montu → Affiliate Tracking**

Cada grupo se puede sobrescribir por website / store view (salvo los marcados *global*).

**General Settings**

| Campo | Descripción | Default |
|-------|-------------|---------|
| Enable Module | Activa la extensión | No |
| URL Parameter Name | Parámetro a capturar | `_m_aff` |
| Cookie Name | Nombre de la cookie | `_montu_aff_ref_code` |
| Cookie Lifetime (Days) | Días de duración | 15 |

**Webhook Connection**

| Campo | Descripción | Default |
|-------|-------------|---------|
| Enable Webhook | Interruptor general; apagado no se registra ni envía nada | No |
| Webhook Endpoint URL | URL de tu endpoint | - |
| Key ID | Id público de la clave (empieza con `kid_`); se envía en `X-Montu-Key-Id` | - |
| Signing Secret | Secreto de firma (almacenado **encriptado**); nunca se envía por la red | - |

**Events to Send**

| Campo | Ruta | Default |
|-------|------|---------|
| Send Order Placed | `webhook/send_on_order_placed` | Sí |
| Send Order Updated | `events/send_order_updated` | Sí |
| Send Credit Memo Created | `events/send_creditmemo_created` | Sí |

Para dejar de enviar un tipo de evento usa su interruptor *Send ...*.

**Order Filters** (`filters/*`)

| Campo | Descripción | Default |
|-------|-------------|---------|
| Orders to Send | `Only orders with an affiliate code`, `... or any coupon`, `... or a coupon with a configured prefix`, `All orders` | código o cupón |
| Coupon Prefixes | Prefijos separados por coma (sin distinguir mayúsculas) | - |
| Excluded Customer Groups | Los pedidos de estos grupos nunca se envían | - |

**Data Sent** (`data/*`)

| Campo | Descripción | Default |
|-------|-------------|---------|
| Customer Data | `Hashed` (solo hashes HMAC del email/teléfono), `None`, `Full` | `Hashed` |

**Delivery Queue & Logging** (`queue/*`)

| Campo | Descripción | Default |
|-------|-------------|---------|
| Delivery Schedule (global) | Expresión cron del envío (se valida al guardar) | `*/5 * * * *` |
| Batch Size (global) | Eventos máximos por ejecución (una ejecución además se detiene a los 4 minutos, o tras 5 envíos seguidos que no logran llegar a Montu) | 500 |
| Max Attempts | Intentos por evento (se guarda en cada fila al encolarla) | 5 |
| Request Timeout (seconds) | Timeout de la solicitud (`webhook/timeout`) | 30 |
| Delivered Events Retention (days, global) | Una tarea diaria borra los eventos de pedidos entregados más antiguos que esto, en lotes (0 = conservar). Los de catálogo se borran a los 7 días y los fallidos definitivos a los 90 | 30 |
| Log Level (global) | Nivel mínimo de `var/log/montu_affiliate.log` | `warning` |

> **Catálogo y cargas masivas (CSV):** el módulo solo envía los productos cuyo contenido cambió (los que no cambiaron se marcan como *omitidos*). Cada ejecución del cron envía como máximo *Batch Size* eventos, así que el volumen por hora es *Batch Size* × ejecuciones por hora (por defecto 500 cada 5 minutos = 6.000 por hora). Los eventos de pedidos siempre se envían primero.

> **Key ID y Signing Secret** los emite Montu por tienda y por ambiente (staging y
> producción tienen pares distintos). El Key ID identifica a la tienda; el secreto solo
> se usa localmente para firmar cada request con HMAC-SHA256.

### Storefront del afiliado (sección **Affiliate Storefront**)

| Campo | Descripción | Default |
|-------|-------------|---------|
| Enable Storefront Page | Activa la ruta `/storefront/{code}` | No |
| Route Path Prefix | Prefijo de la URL pública | `storefront` |
| Page Heading | Título de la página | `Recommended products` |
| Recommendations Endpoint URL | Endpoint Montu de productos recomendados | - |
| Storefront Language | Idioma de la página: `en_US`, `es_ES` o `es_CL` (Chile) | `en_US` |
| Max Products | Máximo de productos a mostrar | 12 |
| When Montu Returns No Products | Si Montu falla o no tiene productos: página vacía o redirigir al inicio | Página vacía |
| Hide Out-of-Stock Products | No lista productos sin stock | Sí |

> **Idioma:** Magento **no** hace fallback de `es_CL` → `es_ES`, por eso el módulo
> incluye traducciones propias para Chile (`i18n/es_CL.csv`). Para una tienda chilena,
> selecciona **Spanish (Chile)**.

### Apariencia / White-label (sección **Appearance**)

El storefront es **white-label**: adopta los colores y la tipografía de la tienda
automáticamente. Todos los colores, bordes, radios de tarjeta y la fuente se controlan
por variables CSS.

| Campo | Descripción | Default |
|-------|-------------|---------|
| Theme Mode | `Auto` (muestrea el tema de la tienda en runtime), `Manual` (usa los colores de abajo) u `Off` (estilo neutro) | `Auto` |
| Accent / Primary Color | Color principal (botones, lista activa, resaltados). Acepta `#hex`, `rgb()`/`rgba()` o nombre CSS | (muestreado) |
| Accent Text Color | Color de texto legible sobre el acento | `#ffffff` |
| Body Text Color | Color de texto del storefront (vacío → hereda el tema) | (heredado) |
| Muted Text Color | Color de texto secundario (conteos, subtítulos) | `#6d6d6d` |
| Border Color | Color de bordes/divisores | `#e3e3e3` |
| Card Background Color | Fondo de las tarjetas de producto | `#ffffff` |
| Card Corner Radius | Radio de esquina de las tarjetas (`10px`, `0`, `0.5rem`) | `10px` |
| Font Family | Stack de fuentes del storefront (vacío → hereda la fuente del tema) | (heredado) |

- **Auto:** sin configurar nada, el storefront muestrea el color del botón primario, el
  color de texto y la fuente del tema vivo de la tienda (Luma, custom o re-skin) y se
  adapta. Los valores de arriba sirven como semilla/fallback.
- **Manual:** fija exactamente los colores configurados, sin muestreo runtime. Los campos
  vacíos usan el default neutro.
- **Off:** estilo neutro por defecto (sin muestreo, ignora los colores).

---

## 🛍️ Tienda del Afiliado (`/storefront/{code}`)

Un visitante puede entrar con un código de afiliado de **dos formas**, y en ambas se
guarda la cookie con el código:

1. **Query param** — `https://tienda.com/?_m_aff=ABC123` (capturado por JS).
2. **Ruta storefront** — `https://tienda.com/storefront/ABC123`, que además **renderiza
   una página con los productos que ese afiliado recomienda**.

La ruta la resuelve un **router personalizado** (`Controller/Router.php`) que toma el
código del path y reenvía a `Controller/Storefront/Index.php`. El controlador valida el
código (`^[a-zA-Z0-9_\-]{1,100}$`), **escribe la cookie en el servidor** (mismo
nombre/duración que la captura por JS) y renderiza la página, declarada
**`cacheable="false"`** para que el Full Page Cache / Fastly nunca la sirva cacheada.

Los productos se obtienen vía `Service/RecommendationClient.php` desde el endpoint de
recomendaciones de Montu (`GET /montu/api/v1/ecommerce/magento/recommendations/{store_id}/{code}`),
firmado con HMAC-SHA256 (igual que el webhook). Configura *Recommendations Endpoint URL* en
admin con la URL terminada en el id de tienda. La forma esperada del JSON está documentada en
`RecommendationClient::parseResponse()`. Si el endpoint falla o no responde, la página degrada
con elegancia según *When Montu Returns No Products* (por defecto una página vacía, o redirigir al
inicio). Como la llamada ocurre dentro de la petición de la página del visitante, usa timeouts
cortos propios (2 s en total, 1 s de conexión).

> **Full Page Cache / Fastly:** la ruta `/storefront/{code}` está declarada
> `cacheable="false"`, así que el FPC/Fastly nunca la cachea (es por-afiliado y escribe la
> cookie). El JS de captura (`tracking.js`) lee el parámetro `?_m_aff=` en el cliente, así
> que funciona aunque el resto de las páginas estén cacheadas.
>
> **Queue-it / Klevu / reglas de path:** si la tienda tiene una sala de espera virtual o un
> proxy de búsqueda delante del storefront, agrega el prefijo de la ruta (`/storefront/...`)
> a la lista de paths permitidos para que no sea interceptado.

---

## 🔄 Cómo Funciona

```
?_m_aff=ABC123 → JS guarda cookie
   → (carrito / pedido) → código guardado por quote
   → (pedido guardado / cambios) → RecordOrderEvents → EventRecorder → fila pending en montu_affiliate_webhook_log
   → Cron/ProcessWebhooks (grupo montu_affiliate) → POST firmado (HMAC-SHA256) a tu endpoint
```

1. **Captura** — `view/frontend/web/js/tracking.js` guarda `?_m_aff=` en cookie (la configuración
   viaja en un atributo `data-*` del `<script>`, compatible con el CSP estricto de Magento 2.4.7+).
2. **Persistir al carrito** — `Observer/SaveAffiliateToQuote`, en `checkout_cart_save_after` y en
   `sales_model_service_quote_submit_before` (este último guarda el código cuando ningún guardado
   del carrito lo vio, antes de que exista el pedido). Solo en el storefront y el checkout REST
   (`etc/frontend/events.xml`, `etc/webapi_rest/events.xml`), nunca en el admin: un pedido creado
   desde el admin no toma la cookie del propio administrador. Lee la cookie primero (un visitante
   sin cookie no cuesta ninguna consulta) y conserva el primer código capturado en el carrito.
3. **Vincular a orden** — `Observer/CopyAffiliateToOrder` (`sales_model_service_quote_submit_success`)
   vincula la fila del quote con el pedido (normalmente ya está vinculada).
4. **Registrar eventos** — `Observer/RecordOrderEvents`, declarado en `etc/events.xml` sobre dos
   eventos de commit, entrega el pedido o la nota de crédito a `Service/EventRecorder`, que resuelve
   el código de afiliado, califica el pedido, deduplica, toma el snapshot del payload
   (`Model/Payload/PayloadBuilder`) y lo encola. Solo registra cuando no hay una transacción abierta,
   usa una transacción corta propia para insertar en la cola y actualizar el rastreo, y el observer
   captura cualquier `\Throwable` y lo registra en el log.
5. **Entregar async** — `Cron/ProcessWebhooks` → `Service/WebhookService` (solo transporte:
   timestamp, firma, POST).

### Eventos

| Evento | Se registra cuando | Una vez por |
|--------|--------------------|-------------|
| `order.placed` | se guarda un pedido que califica y aún no está rastreado | pedido |
| `order.updated` | cambia estado, status o totales pagado / reembolsado / cancelado de un pedido rastreado | cambio (fingerprint) |
| `creditmemo.created` | se crea una nota de crédito de un pedido rastreado | nota de crédito |

Un pedido está **rastreado** una vez registrado su `order.placed`. El contrato completo del
payload está en [`WEBHOOKS.md`](WEBHOOKS.md).

Solo hay dos eventos de Magento de **commit** (el pedido y la nota de crédito ya están guardados,
así que el snapshot nunca trae ids sin asignar):

- `sales_order_save_commit_after` → `order.placed` (pedido no rastreado) u `order.updated`
  (solo si cambió el fingerprint)
- `sales_order_creditmemo_save_commit_after` → `creditmemo.created`

Cancelar, facturar, enviar o reembolsar desde el admin, la API o servicios del core guardan el
pedido, así que llegan por `sales_order_save_commit_after`: Magento ejecuta los callbacks de commit
aunque el servicio confirme la transacción directamente (`ExecuteCommitCallbacks`). El módulo no
usa plugins sobre servicios del core. El campo `trigger` del payload es siempre uno de esos dos
nombres de evento.

---

## 📊 Sistema de Reintentos

Por defecto: 5 intentos (configurable en *Delivery Queue & Logging*), esperando 1 / 5 / 15 / 30 / 60
minutos tras cada fallo (espera fija; el último valor se repite y la espera efectiva se redondea al
siguiente cron). Si se agotan los intentos el evento queda en `max_retries` y no se
reintenta solo.

Para re-encolar: **Marketing → Montu Affiliate → Event Log**, selecciona los eventos y usa la
acción masiva **Requeue / resend** (vuelve a `pending`, reinicia los intentos y usa el límite
actual; también sirve para reenviar un evento ya entregado a propósito. Los eventos que se están
enviando en ese momento se omiten).
El Event Log permite filtrar por estado, tipo de evento y fechas; no edita payloads ni borra
filas (los eventos entregados se limpian solos tras *Delivered Events Retention*).

Otras garantías del envío:

- **Sin solapamiento** — el cron de Magento ya bloquea cada job, dos ejecuciones no se solapan.
- **Store views apagados** — los eventos de un store view con el módulo o el webhook desactivado
  quedan pendientes y no ocupan lugar en el lote ni bloquean a los demás.
- **Eventos atascados** — un evento en `processing` más de 15 minutos cuenta como intento
  fallido y se reintenta.
- **FIFO por pedido** — no se envía un evento mientras un evento anterior del mismo pedido no haya
  terminado; `success` y `max_retries` cuentan ambos como terminados, así que un evento sin
  reintentos restantes no bloquea para siempre los siguientes del pedido.

---

## 📨 Formato del Webhook

El contrato completo (campos, modos de datos del cliente, hashes) está en
[`WEBHOOKS.md`](WEBHOOKS.md); este es el resumen.

### Headers

```
Content-Type: application/json
X-Montu-Webhook-Version: 2.0
X-Montu-Event: order.placed | order.updated | creditmemo.created
X-Montu-Event-Id: <uuid del evento>
X-Montu-Module-Version: <versión instalada del módulo>
X-Montu-Key-Id: <tu_key_id>
X-Montu-Store-Domain: <url del store view del pedido>
X-Montu-Store-Code: <código del store view del pedido>
X-Montu-Timestamp: <unix_timestamp del envío>
X-Montu-Signature: <hmac_sha256 del body crudo>
```

La firma es `hash_hmac('sha256', <body JSON crudo>, <signing_secret>)`. El secreto **nunca
viaja** en el request: solo el `X-Montu-Key-Id` (público) identifica la clave. Las
peticiones de recomendaciones (GET) usan los mismos headers `X-Montu-Key-Id`,
`X-Montu-Timestamp` y `X-Montu-Signature`, firmando `"{timestamp}.{code}"` con el mismo
secreto. Si falta el Key ID o el secreto, no se envía nada y el intento cuenta como
fallido: una tienda sin configurar agota los reintentos y sus eventos quedan en
`max_retries` (re-encolarlos desde el Event Log después de configurar la clave). El
Key ID y el secreto se leen del **store view de la orden**, así que una instalación
multi-tienda puede tener una clave por tienda.

### Body (JSON, resumen)

```json
{
    "webhook_version": "2.0",
    "event": "order.updated",
    "event_id": "6f1c...-uuid4",
    "timestamp": "2026-11-12T13:09:00+00:00",
    "occurred_at": "2026-11-12T13:05:00.123456+00:00",
    "trigger": "sales_order_save_commit_after",
    "store": { "code": "default", "name": "Mi Tienda", "base_url": "https://tutienda.com/", "currency": "CLP", "id": 1, "website_code": "base" },
    "order": { "increment_id": "000000123", "grand_total": 150.00, "status": "pagado", "state": "processing" },
    "transition": { "from_state": "new", "from_status": "pending", "to_state": "processing", "to_status": "pagado" },
    "affiliate": { "code": "ABC123" },
    "customer": { "id": 55, "email": null, "email_hash": "9f86d0...", "phone_hash": "2c26b4..." },
    "items": [],
    "billing_address": {},
    "shipping_address": {},
    "creditmemo": null
}
```

El payload es el **snapshot completo del pedido al momento del evento** (se arma al registrarlo,
no al enviarlo); solo `timestamp` se completa al enviar. El backend valida la firma **y** rechaza
timestamps de más de 5 minutos (anti-replay). El receptor vive en el backend Django:
`back/montu/rest_api/v1/views/ecommerce/magento/`. **Cualquier cambio al payload aquí requiere el
cambio correspondiente allá, en sincronía.**

---

## 🧪 Testing Local

1. URL de prueba en [webhook.site](https://webhook.site).
2. En admin → Webhook URL: `https://webhook.site/tu-uuid`, Key ID: `kid_test`, Signing Secret: `test-secret-123`.
   Usa valores ficticios: **nunca** pegues credenciales reales con webhook.site (es un
   endpoint público de terceros).
3. Visitar `https://tu-tienda.test/?_m_aff=TEST123`, verificar cookie, crear una orden, y:

```bash
bin/magento cron:run --group=montu_affiliate   # fuerza Cron/ProcessWebhooks
tail -f var/log/montu_affiliate.log           # logs del módulo (nivel configurable, por defecto warning)
# o mira Marketing → Montu Affiliate → Event Log
# SELECT event_type, status, retry_count FROM montu_affiliate_webhook_log;
```

---

## 📁 Estructura del paquete

```
magento/                       ← raíz del paquete Composer (montu/module-affiliate)
├── composer.json              # name + type magento2-module + autoload PSR-4
├── registration.php           # ComponentRegistrar::register(MODULE, 'Montu_Affiliate', __DIR__)
├── etc/                       # module.xml, di.xml, events.xml (+ frontend/, webapi_rest/), crontab.xml, cron_groups.xml,
│                              # db_schema.xml, adminhtml/{system.xml, system/*.xml, menu.xml, routes.xml}
├── Model/                     # Config, OrderQualifier, OrderFingerprint, Payload/ (PayloadBuilder, EventContext), repositories, ResourceModels
├── Api/                       # interfaces de repositorios
├── Observer/                  # SaveAffiliateToQuote, CopyAffiliateToOrder, RecordOrderEvents
├── Service/                   # EventRecorder (registra), WebhookService (transporte), RecommendationClient
├── Cron/                      # ProcessWebhooks (entrega)
├── Logger/                    # handler del log con nivel configurable
├── Setup/Patch/Data/          # RemoveLegacyApiKeyConfig
├── Controller/Adminhtml/      # Event Log (grid + Requeue)
├── Controller/               # Router + Storefront/Index (ruta /storefront/{code})
├── Block/ + ViewModel/        # adminhtml order view, storefront config
└── view/                      # frontend (tracking.js/.phtml, storefront) + adminhtml
```

### Tablas propias (nunca toca core)

- `montu_affiliate_tracking` — código de afiliado por quote/orden (opcional: pedidos solo con
  cupón) y último snapshot (`fingerprint`, `last_state`, `last_status`) del pedido rastreado
- `montu_affiliate_webhook_log` — outbox de eventos: tipo, `event_id`, `dedupe_key`, payload
  JSON, status, intentos y último error

---

## 🗑️ Desinstalación

```bash
bin/magento module:disable Montu_Affiliate
composer remove montu/module-affiliate
bin/magento setup:upgrade
```

---

## Requisitos

- Magento 2.4.x, PHP 8.1+ (ver `composer.json` para los módulos Magento requeridos)
- Cron de Magento funcionando (`* * * * * php bin/magento cron:run`); el módulo usa el grupo `montu_affiliate`

## Licencia

Open Software License 3.0 (OSL-3.0) — ver [`LICENSE.txt`](LICENSE.txt).

## Soporte

dev@montu.io
