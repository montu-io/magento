# Montu Affiliate Tracking Extension for Magento 2

Extensión de Magento 2 para capturar códigos de afiliados y enviar webhooks con información de órdenes.

## ✨ Características Principales

- ✅ **Instalación Simple** - Solo `setup:upgrade` y listo
- ✅ **Sin Configuración Extra** - Funciona automáticamente con el cron de Magento
- ✅ **No Invasivo** - No modifica tablas core de Magento
- ✅ **Asíncrono** - No bloquea el checkout
- ✅ **Reintentos Automáticos** - 5 intentos con backoff exponencial

## 🚀 Instalación (3 comandos)

```bash
# 1. Copiar la extensión
cp -r Montu /path/to/magento/app/code/

# 2. Instalar
bin/magento module:enable Montu_Affiliate
bin/magento setup:upgrade

# 3. Limpiar cache (producción)
bin/magento setup:di:compile
bin/magento cache:clean
```

**¡Listo!** La extensión funciona automáticamente. Solo necesitas configurarla en el admin.

## ⚙️ Configuración

1. Ve a **Stores → Configuration → Montu → Affiliate Tracking**
2. Configura:

| Campo | Descripción | Default |
|-------|-------------|---------|
| Enable Module | Activa la extensión | No |
| URL Parameter | Parámetro a capturar | `_m_aff` |
| Cookie Name | Nombre de la cookie | `_montu_aff_ref_code` |
| Cookie Lifetime | Días de duración | 15 |
| Enable Webhook | Activa webhooks | No |
| Webhook URL | URL de tu endpoint | - |
| API Key | Tu API key | - |

### Storefront del afiliado (sección **Affiliate Storefront**)

| Campo | Descripción | Default |
|-------|-------------|---------|
| Enable Storefront Page | Activa la ruta `/storefront/{code}` | No |
| Route Path Prefix | Prefijo de la URL pública | `storefront` |
| Page Heading | Título de la página | `Recommended products` |
| Recommendations Endpoint URL | Endpoint Montu de productos recomendados (dejar vacío mientras la API no exista → datos de ejemplo) | - |
| Max Products | Máximo de productos a mostrar | 12 |

## 🛍️ Tienda del Afiliado (`/storefront/{code}`)

Un visitante puede entrar a la tienda con un código de afiliado de **dos formas**,
y en ambas se guarda la cookie con el código:

1. **Query param** — `https://tienda.com/?aff=ABC123` (capturado por JS).
2. **Ruta storefront** — `https://tienda.com/storefront/ABC123`, que además
   **renderiza una página con los productos que ese afiliado recomienda**.

La ruta la resuelve un **router personalizado** (`Controller/Router.php`) que
toma el código del path y reenvía a `Controller/Storefront/Index.php`. El
controlador valida el código (`^[a-zA-Z0-9_\-]{1,100}$`), **escribe la cookie en
el servidor** (mismo nombre/duración que la captura por JS) y renderiza la
página. La página se declara **`cacheable="false"`**, por lo que el Full Page
Cache / Fastly nunca la sirve cacheada (es por-afiliado y escribe cookie).

Los productos se obtienen vía `Service/RecommendationClient.php`. **El endpoint
de Montu aún no existe**, así que por ahora el cliente devuelve **datos de
ejemplo** (`USE_STUB_DATA = true`). Cuando la API esté lista: configurar
*Recommendations Endpoint URL* en el admin y poner `USE_STUB_DATA = false`; el
cliente llamará al endpoint (firmado con la misma API key) y caerá de vuelta a
los datos de ejemplo ante cualquier error. La forma esperada del JSON está
documentada en `RecommendationClient::parseResponse()`.

```
GET /storefront/ABC123
  → Router toma "ABC123" del path → forward a storefront/index
  → valida código + setea cookie _montu_aff_ref_code=ABC123 (servidor)
  → RecommendationClient->getRecommendedProducts("ABC123")  (stub por ahora)
  → renderiza grilla de productos (no cacheable)
```

## 🔄 Cómo Funciona

```
┌─────────────┐     ┌──────────────┐     ┌─────────────────┐
│ Usuario     │     │   Magento    │     │    Tu SaaS     │
│ visita URL  │     │              │     │                │
│ ?_m_aff=ABC │     │              │     │                │
└──────┬──────┘     └──────────────┘     └────────────────┘
       │
       ▼
┌──────────────┐
│ JS captura   │
│ → Cookie     │
└──────┬───────┘
       │
       ▼ (usuario compra)
┌──────────────┐     ┌─────────────────┐
│   Orden      │────►│  webhook_log    │
│   creada     │     │ status=pending  │
└──────────────┘     └────────┬────────┘
                              │
                              ▼ (cron cada 1 min)
                     ┌─────────────────┐     ┌────────────────┐
                     │ Cron procesa    │────►│   Tu SaaS      │
                     │ webhooks        │     │   recibe POST  │
                     └─────────────────┘     └────────────────┘
```

## 📊 Sistema de Reintentos

Si un webhook falla, se reintenta automáticamente:

| Intento | Espera |
|---------|--------|
| 1º      | 1 min  |
| 2º      | 5 min  |
| 3º      | 15 min |
| 4º      | 30 min |
| 5º      | 1 hora |

Después del 5º intento, el webhook se marca como `max_retries`.

## 📨 Formato del Webhook

### Headers

```
Content-Type: application/json
X-Montu-Api-Key: <tu_api_key>
X-Montu-Store-Domain: <url_tienda>
X-Montu-Store-Code: <codigo_tienda>
X-Montu-Timestamp: <unix_timestamp>
X-Montu-Signature: <hmac_sha256>
```

### Body (JSON)

```json
{
    "webhook_version": "1.0",
    "event": "order.placed",
    "timestamp": "2024-01-15T10:30:00+00:00",
    "store": {
        "code": "default",
        "name": "Mi Tienda",
        "base_url": "https://tutienda.com/"
    },
    "order": {
        "increment_id": "000000123",
        "grand_total": 150.00,
        "currency_code": "USD",
        ...
    },
    "affiliate": {
        "code": "ABC123"
    },
    "customer": {
        "email": "customer@example.com",
        ...
    },
    "items": [...],
    "billing_address": {...},
    "shipping_address": {...}
}
```

## 🧪 Testing Local

### 1. Obtener URL de prueba

Usa [webhook.site](https://webhook.site) para obtener una URL de prueba.

### 2. Configurar en admin

- Webhook URL: `https://webhook.site/tu-uuid`
- API Key: `test-key-123`

### 3. Probar

```bash
# Visitar tienda con parámetro
open "https://tu-tienda.test/?_m_aff=TEST123"

# Verificar cookie en DevTools → Application → Cookies

# Crear una orden de prueba

# Ejecutar cron manualmente
bin/magento cron:run

# Ver logs
tail -f var/log/montu_affiliate.log

# Ver webhooks en BD
mysql -e "SELECT * FROM montu_affiliate_webhook_log;" magento_db
```

## 📁 Estructura

```
app/code/Montu/Affiliate/
├── registration.php
├── etc/
│   ├── module.xml
│   ├── config.xml          # Valores default
│   ├── di.xml              # Inyección de dependencias
│   ├── events.xml          # Observers
│   ├── crontab.xml         # Cron cada 1 minuto
│   ├── db_schema.xml       # Tablas propias
│   └── adminhtml/
│       └── system.xml      # Config admin
├── Model/
│   ├── Config.php
│   ├── AffiliateTracking.php
│   ├── WebhookLog.php
│   └── ...
├── Observer/
│   ├── SaveAffiliateToQuote.php
│   ├── CopyAffiliateToOrder.php
│   └── SendOrderWebhook.php
├── Cron/
│   └── ProcessWebhooks.php  # Procesa webhooks pendientes
├── Service/
│   └── WebhookService.php
└── view/
    └── frontend/
        └── templates/
            └── tracking.phtml  # JS para cookie
```

## 📋 Tablas Creadas

La extensión crea 2 tablas propias (no modifica tablas de Magento):

- `montu_affiliate_tracking` - Códigos de afiliados por orden
- `montu_affiliate_webhook_log` - Log de webhooks con status y reintentos

## 🔐 Validación en tu SaaS

```php
// Validar API Key
$apiKey = $_SERVER['HTTP_X_MONTU_API_KEY'];
$store = Store::where('api_key', $apiKey)->first();
if (!$store) {
    http_response_code(401);
    exit;
}

// Validar Signature (opcional)
$timestamp = $_SERVER['HTTP_X_MONTU_TIMESTAMP'];
$signature = $_SERVER['HTTP_X_MONTU_SIGNATURE'];
$expected = hash_hmac('sha256', $timestamp, $store->api_key);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit;
}

// Procesar webhook
$payload = json_decode(file_get_contents('php://input'), true);
```

## 📊 Monitoreo

```sql
-- Estadísticas de webhooks
SELECT status, COUNT(*) FROM montu_affiliate_webhook_log GROUP BY status;

-- Webhooks fallidos
SELECT order_id, error_message, retry_count 
FROM montu_affiliate_webhook_log 
WHERE status IN ('failed', 'max_retries');
```

## 📝 Logs

```bash
# Ver logs
tail -f var/log/montu_affiliate.log
```

## 🗑️ Desinstalación

```bash
bin/magento module:disable Montu_Affiliate
bin/magento setup:upgrade
rm -rf app/code/Montu
```

## Requisitos

- Magento 2.4.x
- PHP 8.1+
- Cron de Magento funcionando (`* * * * * php bin/magento cron:run`)

## Soporte

dev@montu.io
