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
- ✅ **Reintentos automáticos** — 5 intentos con backoff exponencial

---

## 📦 Instalación vía Composer

> **Nota:** este paquete vive como subcarpeta `magento/` del monorepo de Montu. Composer
> no puede instalar una subcarpeta con un repositorio `vcs` directo (necesita el
> `composer.json` en la raíz del repo). Por eso se ofrecen dos vías.

### Opción A — Repositorio `path` (desarrollo / monorepo / CI)

Si tienes el monorepo en disco (o lo clonas en tu pipeline), apunta Composer a la carpeta:

```jsonc
// composer.json del proyecto Magento
{
  "repositories": [
    {
      "type": "path",
      "url": "../monorepo/magento",
      "options": { "symlink": true }
    }
  ]
}
```

```bash
composer require montu/module-affiliate:@dev
```

`symlink: true` enlaza la carpeta (cambios en vivo, ideal para desarrollo). Usa
`"symlink": false` para que Composer **copie** el paquete (recomendado en builds/deploys).

### Opción B — Repositorio `vcs` (remoto, producción)

Para instalar desde git de forma remota, el paquete debe estar en la **raíz** de un
repositorio. Publica esta carpeta a un repo dedicado mediante un *subtree split*:

```bash
# desde la raíz del monorepo, publica magento/ a un repo dedicado
git subtree push --prefix=magento git@gitlab.com:montu/module-affiliate.git main
# (o usa splitsh-lite en CI para mantener el split actualizado y con tags)
```

Luego, en el proyecto Magento:

```jsonc
{
  "repositories": [
    { "type": "vcs", "url": "git@gitlab.com:montu/module-affiliate.git" }
  ]
}
```

```bash
composer require montu/module-affiliate:^1.0   # usa un tag git como versión
```

> El `composer.json` **no fija `version`**: Composer toma la versión de los **tags git**
> (`git tag v1.0.0`). Etiqueta los releases en el repo split para poder requerir `^1.0`.

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
| API Key | Tu API key (almacenada **encriptada**) | - |

### Storefront del afiliado (sección **Affiliate Storefront**)

| Campo | Descripción | Default |
|-------|-------------|---------|
| Enable Storefront Page | Activa la ruta `/storefront/{code}` | No |
| Route Path Prefix | Prefijo de la URL pública | `storefront` |
| Page Heading | Título de la página | `Recommended products` |
| Recommendations Endpoint URL | Endpoint Montu de productos recomendados (vacío → datos de ejemplo) | - |
| Max Products | Máximo de productos a mostrar | 12 |

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

Los productos se obtienen vía `Service/RecommendationClient.php`. **El endpoint de Montu
aún no existe**, así que por ahora devuelve **datos de ejemplo** (`USE_STUB_DATA = true`).
Cuando la API esté lista: configurar *Recommendations Endpoint URL* y poner
`USE_STUB_DATA = false`. La forma esperada del JSON está en
`RecommendationClient::parseResponse()`.

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
| Espera  | 1 min | 5 min | 15 min | 30 min | 1 hora |

Tras el 5º intento el webhook se marca como `max_retries`.

---

## 📨 Formato del Webhook

### Headers

```
Content-Type: application/json
X-Montu-Api-Key: <tu_api_key>
X-Montu-Store-Domain: <url_tienda>
X-Montu-Store-Code: <codigo_tienda>
X-Montu-Timestamp: <unix_timestamp>
X-Montu-Signature: <hmac_sha256 del body crudo>
```

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
2. En admin → Webhook URL: `https://webhook.site/tu-uuid`, API Key: `test-key-123`.
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

## Soporte

dev@montu.io
