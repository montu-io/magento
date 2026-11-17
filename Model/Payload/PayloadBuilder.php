<?php
/**
 * Montu Affiliate Tracking Extension - Payload Builder
 *
 * @category  Montu
 * @package   Montu_Affiliate
 */

declare(strict_types=1);

namespace Montu\Affiliate\Model\Payload;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\StatusFactory;
use Montu\Affiliate\Model\Config;
use Montu\Affiliate\Service\WebhookService;

/**
 * The JSON snapshot of one event, block by block in the order of WEBHOOKS.md.
 * Every event type carries the full order; creditmemo.created also carries
 * its credit memo. "timestamp" is not here: WebhookService adds it on send.
 */
class PayloadBuilder
{
    private const TYPE_CONFIGURABLE = 'configurable';

    /**
     * Digits of the phone number that are hashed
     */
    private const PHONE_DIGITS = 8;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var StatusFactory
     */
    private StatusFactory $statusFactory;

    /**
     * @param Config $config
     * @param StatusFactory $statusFactory
     */
    public function __construct(Config $config, StatusFactory $statusFactory)
    {
        $this->config = $config;
        $this->statusFactory = $statusFactory;
    }

    /**
     * @param EventContext $context
     * @return array<string, mixed>
     */
    public function build(EventContext $context): array
    {
        $order = $context->order;
        $store = $order->getStore();
        $mode = $this->config->getCustomerDataMode($context->getStoreId());

        return [
            'webhook_version' => WebhookService::WEBHOOK_VERSION,
            'event' => $context->eventType,
            'event_id' => $context->eventId,
            'occurred_at' => $context->occurredAt,
            'trigger' => $context->trigger,
            'store' => [
                'code' => $store->getCode(),
                'name' => $store->getName(),
                'base_url' => $store->getBaseUrl(),
                'currency' => $order->getOrderCurrencyCode(),
                'id' => (int) $store->getId(),
                'website_code' => $store->getWebsite()->getCode(),
            ],
            'order' => $this->order($order, $context->getStoreId()),
            'transition' => [
                'from_state' => $context->previousState,
                'from_status' => $context->previousStatus,
                'to_state' => $order->getState(),
                'to_status' => $order->getStatus(),
            ],
            'affiliate' => ['code' => $context->affiliateCode, 'link' => $context->referralLink],
            'customer' => $this->customer($order, $mode, $context->getStoreId()),
            'items' => $this->items($order),
            'billing_address' => $this->address($order->getBillingAddress(), $mode),
            'shipping_address' => $this->address($order->getShippingAddress(), $mode),
            'creditmemo' => $context->creditmemo !== null ? $this->creditmemo($context->creditmemo) : null,
        ];
    }

    /**
     * @param Order $order
     * @param int $storeId
     * @return array<string, mixed>
     */
    private function order(Order $order, int $storeId): array
    {
        $payment = $order->getPayment();

        return [
            'id' => (int) $order->getId(),
            'increment_id' => $order->getIncrementId(),
            'status' => $order->getStatus(),
            'state' => $order->getState(),
            'created_at' => $order->getCreatedAt(),
            'grand_total' => (float) $order->getGrandTotal(),
            'subtotal' => (float) $order->getSubtotal(),
            'tax_amount' => (float) $order->getTaxAmount(),
            'shipping_amount' => (float) $order->getShippingAmount(),
            'discount_amount' => (float) $order->getDiscountAmount(),
            'total_qty_ordered' => (float) $order->getTotalQtyOrdered(),
            'currency_code' => $order->getOrderCurrencyCode(),
            'payment_method' => $payment ? $payment->getMethod() : null,
            'shipping_method' => $order->getShippingMethod(),
            'coupon_code' => $order->getCouponCode(),
            'status_label' => $this->statusLabel((string) $order->getStatus(), $storeId),
            'updated_at' => $order->getUpdatedAt(),
            'customer_group_id' => (int) $order->getCustomerGroupId(),
            'base_currency_code' => $order->getBaseCurrencyCode(),
            'base_grand_total' => (float) $order->getBaseGrandTotal(),
            'subtotal_incl_tax' => (float) $order->getSubtotalInclTax(),
            'shipping_tax_amount' => (float) $order->getShippingTaxAmount(),
            'discount_description' => $order->getDiscountDescription(),
            'applied_rule_ids' => $order->getAppliedRuleIds(),
            'total_invoiced' => (float) $order->getTotalInvoiced(),
            'total_paid' => (float) $order->getTotalPaid(),
            'total_refunded' => (float) $order->getTotalRefunded(),
            'total_canceled' => (float) $order->getTotalCanceled(),
            'total_due' => (float) $order->getTotalDue(),
        ];
    }

    /**
     * The status label of the order's store view. Order::getStatusLabel() picks
     * the admin label when the event fires from the admin area.
     *
     * @param string $status
     * @param int $storeId
     * @return string
     */
    private function statusLabel(string $status, int $storeId): string
    {
        return (string) $this->statusFactory->create()->load($status)->getStoreLabel($storeId);
    }

    /**
     * As much of the customer as the "Customer data" setting allows. Hashed
     * (default) and full also carry keyed hashes of the email and phone.
     *
     * @param Order $order
     * @param string $mode
     * @param int $storeId
     * @return array<string, mixed>
     */
    private function customer(Order $order, string $mode, int $storeId): array
    {
        $full = $mode === Config::CUSTOMER_DATA_FULL;
        $secret = $mode === Config::CUSTOMER_DATA_NONE ? '' : $this->config->getSigningSecret($storeId);

        return [
            'id' => $order->getCustomerId() !== null ? (int) $order->getCustomerId() : null,
            'email' => $full ? $order->getCustomerEmail() : null,
            'firstname' => $full ? $order->getCustomerFirstname() : null,
            'lastname' => $full ? $order->getCustomerLastname() : null,
            'is_guest' => (bool) $order->getCustomerIsGuest(),
            'group_id' => (int) $order->getCustomerGroupId(),
            'email_hash' => $this->hash($this->normalizeEmail((string) $order->getCustomerEmail()), $secret),
            'phone_hash' => $this->hash($this->normalizePhone($order), $secret),
        ];
    }

    /**
     * @param string $email
     * @return string|null
     */
    private function normalizeEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * Last digits of the billing telephone (else the shipping one)
     *
     * @param Order $order
     * @return string|null
     */
    private function normalizePhone(Order $order): ?string
    {
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();
        $phone = (string) ($billing ? $billing->getTelephone() : '');
        if (trim($phone) === '') {
            $phone = (string) ($shipping ? $shipping->getTelephone() : '');
        }

        $digits = (string) preg_replace('/\D+/', '', $phone);

        return strlen($digits) < self::PHONE_DIGITS ? null : substr($digits, -self::PHONE_DIGITS);
    }

    /**
     * HMAC-SHA256 with the signing secret; null without a value or a secret
     *
     * @param string|null $value
     * @param string $secret
     * @return string|null
     */
    private function hash(?string $value, string $secret): ?string
    {
        if ($value === null || $secret === '') {
            return null;
        }

        return hash_hmac('sha256', $value, $secret);
    }

    /**
     * The visible order items, with the product and variant ids
     *
     * @param Order $order
     * @return array<int, array<string, mixed>>
     */
    private function items(Order $order): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (float) $item->getQtyOrdered(),
                'price' => (float) $item->getPrice(),
                'row_total' => (float) $item->getRowTotal(),
                'tax_amount' => (float) $item->getTaxAmount(),
                'discount_amount' => (float) $item->getDiscountAmount(),
                'product_type' => $item->getProductType(),
                'item_id' => (int) $item->getItemId(),
                'product_id' => (int) $item->getProductId(),
                'variant_product_id' => $this->variantProductId($order, $item),
                'original_price' => (float) $item->getOriginalPrice(),
                'price_incl_tax' => (float) $item->getPriceInclTax(),
                'row_total_incl_tax' => (float) $item->getRowTotalInclTax(),
                'qty_invoiced' => (float) $item->getQtyInvoiced(),
                'qty_canceled' => (float) $item->getQtyCanceled(),
                'qty_refunded' => (float) $item->getQtyRefunded(),
            ];
        }

        return $items;
    }

    /**
     * Product id of the chosen simple child of a configurable item, else null
     *
     * @param Order $order
     * @param Item $item
     * @return int|null
     */
    private function variantProductId(Order $order, Item $item): ?int
    {
        if ($item->getProductType() !== self::TYPE_CONFIGURABLE) {
            return null;
        }

        foreach ($order->getAllItems() as $candidate) {
            if ((int) $candidate->getParentItemId() === (int) $item->getItemId()) {
                return (int) $candidate->getProductId();
            }
        }

        return null;
    }

    /**
     * An address, as much of it as the "Customer data" setting allows
     *
     * @param Address|null $address
     * @param string $mode
     * @return array<string, mixed>|null
     */
    private function address(?Address $address, string $mode): ?array
    {
        if ($address === null || $mode === Config::CUSTOMER_DATA_NONE) {
            return null;
        }

        $full = $mode === Config::CUSTOMER_DATA_FULL;
        $street = $address->getStreet();

        return [
            'firstname' => $full ? $address->getFirstname() : null,
            'lastname' => $full ? $address->getLastname() : null,
            'street' => $full ? implode(', ', is_array($street) ? $street : []) : null,
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'postcode' => $full ? $address->getPostcode() : null,
            'country_id' => $address->getCountryId(),
            'telephone' => $full ? $address->getTelephone() : null,
        ];
    }

    /**
     * The credit memo of a creditmemo.created event
     *
     * @param Creditmemo $creditmemo
     * @return array<string, mixed>
     */
    private function creditmemo(Creditmemo $creditmemo): array
    {
        $order = $creditmemo->getOrder();
        $items = [];

        // One entry per refunded order line; child rows of configurable /
        // bundle lines are folded into their parent line.
        foreach ($creditmemo->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            if ($orderItem === null || $orderItem->getParentItemId()) {
                continue;
            }

            $items[] = [
                'order_item_id' => (int) $item->getOrderItemId(),
                'sku' => $item->getSku(),
                'product_id' => $this->variantProductId($order, $orderItem) ?? (int) $item->getProductId(),
                'qty' => (float) $item->getQty(),
                'row_total' => (float) $item->getRowTotal(),
                'tax_amount' => (float) $item->getTaxAmount(),
                'discount_amount' => (float) $item->getDiscountAmount(),
            ];
        }

        return [
            'id' => (int) $creditmemo->getId(),
            'increment_id' => $creditmemo->getIncrementId(),
            'created_at' => $creditmemo->getCreatedAt(),
            'state' => (int) $creditmemo->getState(),
            'grand_total' => (float) $creditmemo->getGrandTotal(),
            'subtotal' => (float) $creditmemo->getSubtotal(),
            'tax_amount' => (float) $creditmemo->getTaxAmount(),
            'shipping_amount' => (float) $creditmemo->getShippingAmount(),
            'discount_amount' => (float) $creditmemo->getDiscountAmount(),
            'adjustment_positive' => (float) $creditmemo->getAdjustmentPositive(),
            'adjustment_negative' => (float) $creditmemo->getAdjustmentNegative(),
            'items' => $items,
        ];
    }
}
