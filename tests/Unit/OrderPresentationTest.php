<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * How orders are presented, without a database (src/fulfilment.php): the
 * VAT contained in a total, the state and progress a customer sees, and the
 * text of the order emails in both languages.
 */
final class OrderPresentationTest extends TestCase
{
    protected function tearDown(): void
    {
        use_locale(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function order(array $overrides = []): array
    {
        return $overrides + [
            'id' => 12,
            'status' => 'paid',
            'currency' => 'sar',
            'amount_minor' => 4548,
            'total_amount' => '45.48',
            'created_at' => '2026-10-14 09:00:00',
            'paid_at' => '2026-10-14 09:05:00',
            'refunded_at' => null,
            'fulfilment_status' => 'processing',
            'shipped_at' => null,
            'delivered_at' => null,
            'carrier' => null,
            'tracking_number' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(): array
    {
        return [
            ['book_id' => 51, 'title' => 'Esabella', 'unit_price' => '19.99', 'quantity' => 2],
            ['book_id' => 7, 'title' => 'Biology', 'unit_price' => '5.50', 'quantity' => 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function address(): array
    {
        return [
            'recipient_name' => 'Sara Ahmed', 'phone' => '+966512345678', 'city' => 'Riyadh', 'district' => 'Al Olaya',
            'street' => 'King Fahd Road', 'building_number' => '1234', 'postal_code' => '12345',
            'additional_number' => '6789', 'short_address' => null,
        ];
    }

    public function testPricesIncludeFifteenPercentVat(): void
    {
        self::assertSame(15, VAT_RATE_PERCENT);
        self::assertSame(1500, vat_included_minor(11500), '115.00 contains 15.00');
        self::assertSame(593, vat_included_minor(4548), '45.48 x 15/115 = 5.932');
        self::assertSame(261, vat_included_minor(1999), '19.99 x 15/115 = 2.607');
        self::assertSame(26, vat_included_minor(199), '1.99 x 15/115 = 0.2596');
        self::assertSame(0, vat_included_minor(0));
        self::assertSame(['total' => 4548, 'vat' => 593, 'net' => 3955], order_vat_breakdown(self::order()));
    }

    public function testTheCustomerSeesTheDeliveryStageOfPaidOrders(): void
    {
        self::assertSame('processing', order_display_status(self::order()));
        self::assertSame('shipped', order_display_status(self::order(['fulfilment_status' => 'shipped'])));
        self::assertSame('delivered', order_display_status(self::order(['fulfilment_status' => 'delivered'])));
        self::assertSame('paid', order_display_status(self::order(['fulfilment_status' => null])), 'Placed before deliveries were tracked');
        self::assertSame('refunded', order_display_status(self::order(['status' => 'refunded', 'fulfilment_status' => 'shipped'])), 'A refund wins at any stage');
        self::assertSame('pending', order_display_status(self::order(['status' => 'pending', 'fulfilment_status' => null])));

        self::assertSame('Shipped', order_status_label('shipped'));
        self::assertSame('Refunded', order_status_label('refunded'));
        use_locale('ar');
        self::assertSame('قيد التجهيز', order_status_label('processing'));
        self::assertSame('مسترد', order_status_label('refunded'));
    }

    public function testProgressListsTheStepsReachedAndTheStepsAhead(): void
    {
        self::assertSame([
            ['step' => 'paid', 'at' => '2026-10-14 09:05:00'],
            ['step' => 'processing', 'at' => '2026-10-14 09:05:00'],
            ['step' => 'shipped', 'at' => null],
            ['step' => 'delivered', 'at' => null],
        ], order_progress(self::order()));

        $delivered = self::order(['fulfilment_status' => 'delivered', 'shipped_at' => '2026-10-15 10:00:00', 'delivered_at' => '2026-10-16 12:00:00']);
        self::assertSame(['2026-10-15 10:00:00', '2026-10-16 12:00:00'], array_column(array_slice(order_progress($delivered), 2), 'at'));

        // A refund ends the progress: the steps never reached are left out.
        $refunded = self::order(['status' => 'refunded', 'fulfilment_status' => 'shipped', 'shipped_at' => '2026-10-15 10:00:00', 'refunded_at' => '2026-10-17 08:00:00']);
        self::assertSame(['paid', 'processing', 'shipped', 'refunded'], array_column(order_progress($refunded), 'step'));
        self::assertSame('2026-10-17 08:00:00', order_progress($refunded)[3]['at']);

        self::assertSame(['paid'], array_column(order_progress(self::order(['fulfilment_status' => null])), 'step'), 'Untracked deliveries');
        self::assertSame([], order_progress(self::order(['status' => 'pending', 'paid_at' => null, 'fulfilment_status' => null])));
        self::assertSame([], order_progress(self::order(['status' => 'canceled', 'paid_at' => null, 'fulfilment_status' => null])));
    }

    public function testTheConfirmationEmailListsTheBooksTotalsAndAddress(): void
    {
        [$subject, $html, $text] = order_email_content('confirmation', self::order(), self::items(), self::address(), 'Sara', 'http://shop.test/order.php?id=12');

        self::assertSame('Your BookWise order #12 is confirmed', $subject);
        self::assertSame(
            "Hello Sara,\n\n"
            . "Thank you for your order #12. We have received your payment and are preparing your books for delivery.\n\n"
            . "Your books\n2 × \u{2068}Esabella\u{2069}: SAR 39.98\n1 × \u{2068}Biology\u{2069}: SAR 5.50\n\n"
            . "Delivery: free\nTotal: SAR 45.48\nIncludes VAT (15%): SAR 5.93\n\n"
            . "Delivery address\nSara Ahmed\n1234 King Fahd Road\nAl Olaya\nRiyadh 12345-6789\n\u{2066}+966 51 234 5678\u{2069}\n\n"
            . "We will email you again when your books are on their way.\n\n"
            . "View your order:\nhttp://shop.test/order.php?id=12\n\n"
            . "Best regards,\nBookWise Team",
            $text
        );
        self::assertStringStartsWith('<div lang="en" dir="ltr"><p>Hello Sara,</p>', $html);
        self::assertStringContainsString("<p><strong>Your books</strong><br>2 × \u{2068}Esabella\u{2069}: SAR 39.98<br>1 × \u{2068}Biology\u{2069}: SAR 5.50</p>", $html);
        self::assertStringContainsString('<a href="http://shop.test/order.php?id=12">View your order</a>', $html);
    }

    public function testEmailsAreWrittenInArabic(): void
    {
        use_locale('ar');
        $shipped = self::order(['fulfilment_status' => 'shipped', 'carrier' => 'SMSA Express', 'tracking_number' => 'SA123456']);

        [$subject, $html, $text] = order_email_content('shipped', $shipped, self::items(), null, '', 'http://shop.test/order.php?id=12');
        self::assertSame('طلبك رقم 12 من BookWise في الطريق إليك', $subject);
        self::assertStringStartsWith('<div lang="ar" dir="rtl">', $html);
        self::assertStringContainsString("خبر سار: شُحن طلبك رقم 12.\n\nشركة الشحن: SMSA Express\nرقم التتبع: SA123456\n\n", $text);
        self::assertStringNotContainsString('عنوان التوصيل', $text, 'No address was given');

        [$subject, , $text] = order_email_content('confirmation', self::order(), self::items(), null, '', 'http://shop.test/order.php?id=12');
        self::assertSame('تم تأكيد طلبك رقم 12 من BookWise', $subject);
        // The Latin title is isolated (FSI … PDI): without that, its left-to-right run
        // would take the price along, and the line would read in the wrong order.
        self::assertStringContainsString("2 × «\u{2068}Esabella\u{2069}»: 39.98 ر.س", $text);
        self::assertStringContainsString('يشمل ضريبة القيمة المضافة (15%): 5.93 ر.س', $text);

        $delivered = self::order(['fulfilment_status' => 'delivered', 'delivered_at' => '2026-10-16 12:00:00']);
        [$subject, , $text] = order_email_content('delivered', $delivered, self::items(), null, '', 'http://shop.test/order.php?id=12');
        self::assertSame('تم توصيل طلبك رقم 12 من BookWise', $subject);
        self::assertStringContainsString('سُلّم طلبك رقم 12 بتاريخ 16 أكتوبر 2026.', $text);

        [$subject, , $text] = order_email_content('refunded', self::order(['status' => 'refunded']), self::items(), null, '', 'http://shop.test/order.php?id=12');
        self::assertSame('تم استرداد مبلغ طلبك رقم 12 من BookWise', $subject);
        self::assertStringContainsString('يُعاد 45.48 ر.س إلى وسيلة الدفع الأصلية', $text);
    }

    public function testTheRefundEmailOfASimulatedPaymentPromisesNoMoneyBack(): void
    {
        $refunded = self::order(['status' => 'refunded']);
        $link = 'http://shop.test/order.php?id=12';

        [, , $text] = order_email_content('refunded', $refunded, self::items(), null, '', $link);
        self::assertStringContainsString("Your order #12 was refunded in full: SAR 45.48 is being returned to your original payment method.\n\nIt can take a few business days to appear on your statement.\n\n", $text);

        [$subject, $html, $text] = order_email_content('refunded', $refunded, self::items(), null, '', $link, true);
        self::assertSame('Your BookWise order #12 was refunded', $subject);
        self::assertStringContainsString("Hello,\n\nYour order #12 was refunded in full (SAR 45.48).\n\nPayments in this store are simulated: no money was taken, so none needs to be returned to you.\n\nView your order:", $text);
        self::assertStringNotContainsString('payment method', $text);
        self::assertStringNotContainsString('statement', $html);

        use_locale('ar');
        [$subject, , $text] = order_email_content('refunded', $refunded, self::items(), null, '', $link, true);
        self::assertSame('تم استرداد مبلغ طلبك رقم 12 من BookWise', $subject);
        self::assertStringContainsString("استُرد مبلغ طلبك رقم 12 كاملًا (45.48 ر.س).\n\nعمليات الدفع في هذا المتجر محاكاة: لم يُخصم منك أي مبلغ، لذلك لا يلزم إعادة أي مبلغ إليك.\n\n", $text);
        self::assertStringNotContainsString('وسيلة الدفع الأصلية', $text);

        // The other emails read the same either way.
        self::assertSame(
            order_email_content('shipped', self::order(['fulfilment_status' => 'shipped']), self::items(), null, '', $link),
            order_email_content('shipped', self::order(['fulfilment_status' => 'shipped']), self::items(), null, '', $link, true)
        );
    }
}
