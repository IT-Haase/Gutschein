<?php declare(strict_types=1);

namespace Jules\CartCouponValueValidation\Subscriber;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Event\CartProcessedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Promotion\Cart\PromotionProcessor;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\Checkout\Cart\Error\GenericCartError;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection; // Required for type hinting if used
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;

class CartValidationSubscriber implements EventSubscriberInterface
{
    private SystemConfigService $systemConfigService;
    private TranslatorInterface $translator;

    public function __construct(
        SystemConfigService $systemConfigService,
        TranslatorInterface $translator
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->translator = $translator;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CartProcessedEvent::class => 'onCartProcessed',
        ];
    }

    public function onCartProcessed(CartProcessedEvent $event): void
    {
        $cart = $event->getCart();
        $context = $event->getSalesChannelContext();
        $pluginDomain = 'JulesCartCouponValueValidation.config.'; // Domain for plugin config
        $debugMode = (bool) $this->systemConfigService->get($pluginDomain . 'debugMode', $context->getSalesChannel()->getId());

        $promotionLineItems = $cart->getLineItems()->filterType(PromotionProcessor::LINE_ITEM_TYPE);

        if ($promotionLineItems->count() === 0) {
            return;
        }

        // Calculate total value of actual product items
        $cartGoodsTotal = 0;
        foreach ($cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $productLineItem) {
            if ($productLineItem->getPrice() instanceof CalculatedPrice) {
                $cartGoodsTotal += $productLineItem->getPrice()->getTotalPrice();
            }
        }
        // Also consider custom products if they should count towards the total
        foreach ($cart->getLineItems()->filterType(LineItem::CUSTOM_LINE_ITEM_TYPE) as $customLineItem) {
             if ($customLineItem->getPrice() instanceof CalculatedPrice) {
                $cartGoodsTotal += $customLineItem->getPrice()->getTotalPrice();
            }
        }


        foreach ($promotionLineItems as $promotionItem) {
            if (!$promotionItem->getPrice() instanceof CalculatedPrice) {
                continue;
            }
            // The promotion item's total price is the discount value (negative)
            $couponDiscountAmount = abs($promotionItem->getPrice()->getTotalPrice());

            if ($couponDiscountAmount <= 0) {
                // Not an absolute discount or no value, skip.
                continue;
            }

            // The crucial check: Is the cart's goods value (before this coupon) less than the coupon's absolute value?
            // $cartGoodsTotal at this point is the sum of all product prices, *before any automatic promotions*
            // but *after product-specific discounts* if any are applied directly to product line items.
            // This should be the "Warenwert" the user is referring to.

            if ($debugMode) {
                $cart->add(new GenericCartError(
                    $promotionItem->getId() . '-debugCartVal',
                    $this->translator->trans('cartCouponValueValidation.debug.cartValue', ['%cartTotal%' => number_format($cartGoodsTotal, 2)]),
                    ['cartTotal' => $cartGoodsTotal],
                    GenericCartError::LEVEL_INFO,
                    false,
                    true
                ));
                $cart->add(new GenericCartError(
                    $promotionItem->getId() . '-debugCouponVal',
                    $this->translator->trans('cartCouponValueValidation.debug.couponValue', ['%couponValue%' => number_format($couponDiscountAmount, 2)]),
                    ['couponValue' => $couponDiscountAmount],
                    GenericCartError::LEVEL_INFO,
                    false,
                    true
                ));
            }

            if ($cartGoodsTotal < $couponDiscountAmount) {
                $errorMessage = $this->translator->trans('cartCouponValueValidation.error.cartValueTooLow', [
                    '%cartTotal%' => number_format($cartGoodsTotal, 2),
                    '%couponValue%' => number_format($couponDiscountAmount, 2)
                ]);

                // Add a blocking error to the cart. This will prevent checkout.
                $cart->add(new GenericCartError(
                    $promotionItem->getId() . '-couponValueTooHighError',
                    $errorMessage,
                    [
                        'cartTotal' => $cartGoodsTotal,
                        'couponValue' => $couponDiscountAmount
                    ],
                    GenericCartError::LEVEL_ERROR, // This makes it an error
                    true, // blockOrder - this should prevent proceeding to checkout
                    true // persistent
                ));

                // To ensure the promotion is visually removed or clearly marked as invalid,
                // we can try to remove it. If CartProcessedEvent is too late for removal to take effect
                // reliably in the same cycle for recalculation, the blocking error is the primary defense.
                // The `PromotionRedemptionPreventedError` might be more semantically correct if available/suitable.
                $cart->remove($promotionItem->getId());

                // Marking the cart modified *might* trigger another calculation round
                // but the added error should persist and block.
                 $cart->markModified(); // Request recalculation
            }
        }
    }
}
