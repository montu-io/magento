# Montu Affiliate — Extensión de Magento 2 (`montu/module-affiliate`)

Paquete Composer del módulo **`Montu_Affiliate`**: captura códigos de afiliados en el
storefront y envía webhooks firmados con la información de las órdenes a la plataforma Montu.

Este directorio **es el paquete Composer** (raíz = raíz del módulo). No contiene una
instalación de Magento ni entorno Docker — solo el código del módulo, instalable vía
Composer en cualquier proyecto Magento 2.

## ✨ Características

- ✅ **No Invasivo** — no modifica tablas core de Magento (crea las suyas)
- ✅ **Asíncrono** — no bloquea el checkout; los webhooks se entregan por cron
- ✅ **Fail-safe** — los observers nunca dejan escapar excepciones
- ✅ **Reintentos automáticos** — 5 intentos con backoff (1/5/15/30 min entre intentos)
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
> repo publicado en Packagist (`git tag v1.0.0`), así que puedes fijar `^1.0` si lo necesitas.

### Activar el módulo (después de `composer require`)

```bash
bin/magento module:enable Montu_Affiliate
bin/magento setup:upgrade            # crea montu_affiliate_tracking + montu_affiliate_webhook_log
bin/magento setup:di:compile         # tras cambios de DI / constructores
bin/magento cache:clean
```

---

## ⚙️ Configuración

**Stores → Configuration → Montu → Affiliate Tracking**

| Campo | Descripción | Default |
|-------|-------------|---------|
| Enable Module | Activa la extensión | No |
| URL Parameter | Parámetro a capturar | `_m_aff` |
| Cookie Name | Nombre de la cookie | `_montu_aff_ref_code` |
| Cookie Lifetime | Días de duración | 15 |
| Enable Webhook | Activa webhooks | No |
| Webhook URL | URL de tu endpoint | - |
| Key ID | Id público de la clave (empieza con `kid_`); se envía en `X-Montu-Key-Id` | - |
| Signing Secret | Secreto de firma (almacenado **encriptado**); nunca se envía por la red | - |

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
con elegancia (datos de ejemplo) en vez de romperse.

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
   → (compra) → Observer encola fila pending en montu_affiliate_webhook_log
   → Cron/ProcessWebhooks (cada 5 min) → POST firmado (HMAC-SHA256) a tu endpoint
```

1. **Captura** — `view/frontend/web/js/tracking.js` guarda `?_m_aff=` en cookie.
2. **Persistir al carrito** — `Observer/SaveAffiliateToQuote` (`checkout_cart_save_after`).
3. **Vincular a orden** — `Observer/CopyAffiliateToOrder` (`sales_model_service_quote_submit_success`).
4. **Encolar webhook** — `Observer/SendOrderWebhook` (`checkout_submit_all_after`) si la
   orden tiene código de afiliado **o** cupón.
5. **Entregar async** — `Cron/ProcessWebhooks` → `Service/WebhookService` (Curl + HMAC + reintentos).

---

## 📊 Sistema de Reintentos

| Intento | 1º | 2º | 3º | 4º | 5º |
|---------|----|----|----|----|----|
| Espera antes del intento | — (próximo cron) | 1 min | 5 min | 15 min | 30 min |

Son 5 intentos en total (cerca de una hora, considerando que el cron corre cada 5 minutos,
así que esperas menores a 5 minutos se redondean al siguiente cron). Si el 5º intento
falla, el webhook se marca como `max_retries` y no se reintenta solo; se re-encola con:

```sql
UPDATE montu_affiliate_webhook_log
SET status = 'pending', retry_count = 0, next_retry_at = NULL
WHERE status = 'max_retries';
```

---

## 📨 Formato del Webhook

### Headers

```
Content-Type: application/json
X-Montu-Webhook-Version: 1.0
X-Montu-Event: order.placed
X-Montu-Key-Id: <tu_key_id>
X-Montu-Store-Domain: <url_tienda>
X-Montu-Store-Code: <codigo_tienda>
X-Montu-Timestamp: <unix_timestamp>
X-Montu-Signature: <hmac_sha256 del body crudo>
```

La firma es `hash_hmac('sha256', <body JSON crudo>, <signing_secret>)`. El secreto **nunca
viaja** en el request: solo el `X-Montu-Key-Id` (público) identifica la clave. Las
peticiones de recomendaciones (GET) usan los mismos headers `X-Montu-Key-Id`,
`X-Montu-Timestamp` y `X-Montu-Signature`, firmando `"{timestamp}.{code}"` con el mismo
secreto. Si falta el Key ID o el secreto, no se envía nada y el intento cuenta como
fallido: una tienda sin configurar agota los reintentos y sus webhooks quedan en
`max_retries` (re-encolarlos con el SQL de arriba después de configurar la clave). El
Key ID y el secreto se leen del **store view de la orden**, así que una instalación
multi-tienda puede tener una clave por tienda.

### Body (JSON)

```json
{
    "webhook_version": "1.0",
    "event": "order.placed",
    "timestamp": "2024-01-15T10:30:00+00:00",
    "store": { "code": "default", "name": "Mi Tienda", "base_url": "https://tutienda.com/" },
    "order": { "increment_id": "000000123", "grand_total": 150.00, "currency_code": "USD" },
    "affiliate": { "code": "ABC123" },
    "customer": { "email": "customer@example.com" },
    "items": [],
    "billing_address": {},
    "shipping_address": {}
}
```

El backend valida la firma **y** rechaza timestamps de más de 5 minutos (anti-replay). El
receptor vive en el backend Django: `back/montu/rest_api/v1/views/ecommerce/magento/`.
**Cualquier cambio al payload aquí requiere el cambio correspondiente allá, en sincronía.**

---

## 🧪 Testing Local

1. URL de prueba en [webhook.site](https://webhook.site).
2. En admin → Webhook URL: `https://webhook.site/tu-uuid`, Key ID: `kid_test`, Signing Secret: `test-secret-123`.
   Usa valores ficticios: **nunca** pegues credenciales reales con webhook.site (es un
   endpoint público de terceros).
3. Visitar `https://tu-tienda.test/?_m_aff=TEST123`, verificar cookie, crear una orden, y:

```bash
bin/magento cron:run                  # fuerza Cron/ProcessWebhooks
tail -f var/log/montu_affiliate.log   # logs del módulo
# SELECT * FROM montu_affiliate_webhook_log;
```

---

## 📁 Estructura del paquete

```
magento/                       ← raíz del paquete Composer (montu/module-affiliate)
├── composer.json              # name + type magento2-module + autoload PSR-4
├── registration.php           # ComponentRegistrar::register(MODULE, 'Montu_Affiliate', __DIR__)
├── etc/                       # module.xml, di.xml, events.xml, crontab.xml, db_schema.xml, adminhtml/system.xml …
├── Model/                     # Config, AffiliateTracking, WebhookLog, repositories, ResourceModels
├── Api/                       # interfaces de repositorios
├── Observer/                  # SaveAffiliateToQuote, CopyAffiliateToOrder, SendOrderWebhook
├── Service/                   # WebhookService (Curl + HMAC + reintentos), RecommendationClient
├── Cron/                      # ProcessWebhooks
├── Controller/               # Router + Storefront/Index (ruta /storefront/{code})
├── Block/ + ViewModel/        # adminhtml order view, storefront config
└── view/                      # frontend (tracking.js/.phtml, storefront) + adminhtml
```

### Tablas propias (nunca toca core)

- `montu_affiliate_tracking` — código de afiliado por quote/orden
- `montu_affiliate_webhook_log` — cola de webhooks con status + retry count

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
- Cron de Magento funcionando (`* * * * * php bin/magento cron:run`)

## Licencia

Open Software License 3.0 (OSL-3.0) — ver [`LICENSE.txt`](LICENSE.txt).

## Soporte

dev@montu.io
