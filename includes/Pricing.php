<?php
declare(strict_types=1);

/**
 * Single source of truth for money maths.
 *
 * The same quote() is used by the cart page, checkout, order creation and the
 * payment gateways, so the amount charged can never drift from the amount shown.
 */
final class Pricing
{
    /**
     * @param array{items:list<array<string,mixed>>,subtotal:float} $cart
     * @param array<string,mixed>|null $coupon validated coupon (see Coupons::validate)
     * @return array{subtotal:float,shipping:float,discount:float,tax:float,total:float,free_shipping:bool,item_count:int,weight:float}
     */
    public static function quote(array $cart, ?array $coupon = null, ?string $paymentMethod = null): array
    {
        $subtotal = (float) ($cart['subtotal'] ?? 0);
        $items = $cart['items'] ?? [];

        $weight = 0.0;
        $allFreeShipping = $items !== [];
        $itemCount = 0;
        foreach ($items as $item) {
            $product = $item['product'] ?? [];
            $weight += ((float) ($product['weight_grams'] ?? 0)) * (int) ($item['quantity'] ?? 0);
            $itemCount += (int) ($item['quantity'] ?? 0);
            if (empty($item['free_shipping'])) {
                $allFreeShipping = false;
            }
        }

        $threshold = Settings::float('free_shipping_threshold', 15000);
        $baseShipping = Settings::float('default_shipping_fee', 450);

        $freeShipping = false;
        if ($subtotal <= 0) {
            $shipping = 0.0;
        } else {
            $freeShipping = $allFreeShipping || ($threshold > 0 && $subtotal >= $threshold);
            $shipping = $freeShipping ? 0.0 : $baseShipping;
        }

        $discount = 0.0;
        if ($coupon !== null && !empty($coupon['discount'])) {
            $discount = (float) $coupon['discount'];
        }
        if ($coupon !== null && !empty($coupon['free_shipping']) && $subtotal > 0) {
            $freeShipping = true;
            $shipping = 0.0;
        }

        $taxPercent = Settings::float('tax_percent', 0);
        $taxable = max(0.0, $subtotal - $discount);
        $tax = $taxPercent > 0 ? round($taxable * ($taxPercent / 100), 2) : 0.0;

        if ($paymentMethod === 'COD') {
            $codFee = (float) Config::get('payments.cod.fee', 0);
            $shipping += max(0.0, $codFee);
        }

        $total = max(0.0, $subtotal + $shipping + $tax - $discount);

        return [
            'subtotal' => round($subtotal, 2),
            'shipping' => round($shipping, 2),
            'discount' => round($discount, 2),
            'tax' => round($tax, 2),
            'total' => round($total, 2),
            'free_shipping' => $freeShipping,
            'item_count' => $itemCount,
            'weight' => round($weight, 2),
        ];
    }

    /** Amount still needed for free delivery (for the cart progress bar). */
    public static function amountToFreeShipping(float $subtotal): float
    {
        $threshold = Settings::float('free_shipping_threshold', 15000);
        return max(0.0, round($threshold - $subtotal, 2));
    }

    /** Round to cents consistently (avoids float drift in totals). */
    public static function round(float $amount): float
    {
        return round($amount, 2);
    }
}
