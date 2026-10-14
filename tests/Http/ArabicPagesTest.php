<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;

/**
 * The shop and admin pages in Arabic: right-to-left documents, Arabic text
 * (including flashes, errors and the JSON API) and no untranslated keys or
 * leftover English interface text.
 */
final class ArabicPagesTest extends HttpTestCase
{
    /** English interface phrases that must not appear on an Arabic page. */
    private const ENGLISH = [
        'Add to cart', 'Proceed to Pay', 'Showing ', 'In stock', 'Out of stock', 'Only ', 'Manage Books', 'Recent Orders',
        'Add New Book', 'Book Title', 'Copies in stock', 'Cover Image', 'Order total', 'Refund order', 'Signed in as',
        'Continue exploring', 'View my orders', 'Grand Total', 'Rate this Book', 'Similar Books', 'Meet the Team',
        'Discover', 'Explore books', 'Search by title', 'Order summary', 'Payment not found', 'Checkout canceled',
        'Proceed to Checkout', 'Continue to payment', 'Delivery address', 'Recipient', 'Mobile number', 'Postal code',
        'Buy again', 'Receipt', 'Print receipt', 'Order status', 'Processing', 'Not yet', 'Mark as shipped', 'Tracking number',
    ];

    private static function arabic(HttpClient $client): HttpClient
    {
        $client->setCookie(LOCALE_COOKIE, 'ar');
        return $client;
    }

    /**
     * Interface text of a page: visible text and labelling attributes, but not
     * catalog or customer text (always marked dir="auto"), which stays as entered.
     */
    private static function interfaceText(HttpResponse $page): string
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $page->body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $outsideCatalogText = '[not(ancestor-or-self::*[@dir="auto"])][not(ancestor::script)]';
        $parts = [];
        foreach ($xpath->query('//title | //body//text()' . $outsideCatalogText) ?: [] as $node) {
            $parts[] = $node->textContent;
        }
        foreach ($xpath->query('//*' . $outsideCatalogText . '/@*[name()="aria-label" or name()="placeholder" or name()="title" or name()="alt"]') ?: [] as $node) {
            $parts[] = $node->textContent;
        }
        return HtmlDocument::normalize(implode(' ', $parts));
    }

    /**
     * The page contract, an Arabic right-to-left document, the expected
     * heading, and neither raw translation keys nor English interface text.
     */
    private function assertArabicPage(HttpResponse $page, string $heading, int $status = 200): HtmlDocument
    {
        $document = $this->assertHtmlPage($page, $status);
        $html = $document->first('/html');
        self::assertSame(['ar', 'rtl'], [$html?->getAttribute('lang'), $html?->getAttribute('dir')], $page->url);
        self::assertSame($heading, $document->text('//h1'), $page->url);

        self::assertDoesNotMatchRegularExpression('/\b(?:shop|admin|common)\.[a-z_]+\.[a-z_]/', $page->body, "Untranslated key on {$page->url}");
        $text = self::interfaceText($page);
        self::assertStringContainsString('رفيقك الشخصي في القراءة', $text, 'The scan sees the interface (footer tagline)');
        foreach (self::ENGLISH as $english) {
            self::assertStringNotContainsString($english, $text, "English interface text on {$page->url}");
        }
        return $document;
    }

    public function testTheLanguageSwitchRemembersArabic(): void
    {
        $guest = $this->client();
        $switch = $guest->get('About_us.php?lang=ar');
        $this->assertRedirect($switch, 'About_us.php');
        $document = $this->assertArabicPage($guest->follow($switch), 'من نحن');
        self::assertSame(['أحمد مساعد', 'نواف الزهراني', 'حسن الحربي', 'سلمان الفقيه', 'ضيف الله نعمان'], $document->texts('//li[' . HtmlDocument::hasClass('about-member') . ']'));
        $this->assertPageText('بإشراف د. خالد رياض', $guest->get('About_us.php'));

        $home = $guest->get('Home_Page.php');
        $document = $this->assertArabicPage($home, 'اكتشف كتابك القادم مع BookWise');
        self::assertSame('55 كتابًا متاحًا لك للاستكشاف', $document->text('//p[' . HtmlDocument::hasClass('home-hero-count') . ']'));
        self::assertSame(['أنشئ حسابًا مجانيًا', 'تسجيل الدخول'], $document->texts('//div[' . HtmlDocument::hasClass('home-hero-actions') . ']/a'));
        self::assertContains('متوفر', $document->texts('//*[' . HtmlDocument::hasClass('stock-badge') . ']'));
    }

    public function testTheLanguageSwitchStaysOnPagesThatCanBeOpenedAgain(): void
    {
        $guest = $this->client();
        $switch = static fn (HtmlDocument $document): string => (string) $document->first('//a[' . HtmlDocument::hasClass('lang-switch') . '][@hreflang="ar"]')?->getAttribute('href');

        // The site root is the sign-in page, not the home page.
        self::assertSame('index.php?lang=ar', $switch($this->assertHtmlPage($guest->get('/'))));
        self::assertSame('index.php?form=signup&lang=ar', $switch($this->assertHtmlPage($guest->get('index.php?form=signup'))));
        $this->assertRedirect($guest->get('/?lang=ar'), 'index.php');
        $guest->setCookie(LOCALE_COOKIE, 'en');

        // An error shown for a form submission links to the home page: the
        // action itself only accepts POST and would answer 405.
        $this->assertHtmlPage($guest->get('index.php'));
        $error = $this->assertHtmlPage($guest->post('Register.php', ['action' => 'login', 'email' => 'x@example.com', 'password' => 'whatever-1']), 419);
        self::assertSame('Home_Page.php?lang=ar', $switch($error));
    }

    public function testCustomerPagesInArabic(): void
    {
        [$customer] = $this->signedInCustomer('قارئة عربية');
        self::arabic($customer);

        $home = $this->assertArabicPage($customer->get('Home_Page.php'), 'اكتشف كتابك القادم مع BookWise');
        self::assertSame('مرحبًا، قارئة عربية!', $home->text('//p[' . HtmlDocument::hasClass('home-hero-greeting') . ']'));

        $explore = $this->assertArabicPage($customer->get('Explore_Page.php'), 'اكتشف قراءات جديدة');
        self::assertSame('عرض 1–24 من أصل 55 كتابًا في جميع التصنيفات.', $explore->text('//*[@id="explore-status"]'));
        self::assertSame(['السابق', '1', '2', '3', 'التالي'], $explore->texts('//nav[' . HtmlDocument::hasClass('pager') . ']//li'));
        self::assertSame('الصفحة 2', $explore->first('//nav[' . HtmlDocument::hasClass('pager') . ']//a[@data-page="2"][' . HtmlDocument::hasClass('pager-number') . ']')?->getAttribute('aria-label'));
        $root = $explore->first('//*[@data-explore]');
        self::assertSame(['جارٍ تحميل الكتب…', 'الصفحة :page', 'صفحات النتائج'], [$root?->getAttribute('data-i18n-loading'), $root?->getAttribute('data-i18n-page'), $root?->getAttribute('data-i18n-pager-label')]);
        $romantic = $customer->get('Explore_Page.php?genre=Romantic')->document();
        self::assertSame('عرض 16 كتابًا في رومانسي.', $romantic->text('//*[@id="explore-status"]'));
        // Genre names are shown in Arabic; links keep the stored name.
        self::assertSame('رومانسي', $romantic->text('//a[@data-genre="Romantic"]'));
        self::assertContains('غموض', $explore->texts('//nav[' . HtmlDocument::hasClass('genre-tabs') . ']//a'));
        self::assertContains('رعب', $home->texts('//a[' . HtmlDocument::hasClass('genre-chip') . ']'));
        self::assertStringContainsString('genre=Horror', (string) $home->first('//a[' . HtmlDocument::hasClass('genre-chip') . ' and normalize-space()="رعب"]')?->getAttribute('href'));
        $search = $this->assertArabicPage($customer->get('Explore_Page.php?q=love'), 'اكتشف قراءات جديدة');
        self::assertSame('عرض 6 كتب في جميع التصنيفات تطابق «love».', $search->text('//*[@id="explore-status"]'));
        $none = $this->assertArabicPage($customer->get('Explore_Page.php?q=zzz-nothing'), 'اكتشف قراءات جديدة');
        self::assertSame('لا توجد كتب في جميع التصنيفات تطابق «zzz-nothing».', $none->text('//*[@id="explore-status"]'));
        self::assertSame('لا توجد كتب تطابق بحثك', $none->text('//p[' . HtmlDocument::hasClass('explore-empty-title') . ']'));

        $book = $this->assertArabicPage($this->bookPage($customer, 7), 'Biology');
        self::assertSame('أضف إلى السلة', $book->text('//button[' . HtmlDocument::hasClass('cart-button') . ']'));
        self::assertSame('متوفر', $book->text('//*[@id="stock-status"]'));
        self::assertSame('تأليف anishtain', $book->text('//p[' . HtmlDocument::hasClass('book-author') . ']'));

        $this->assertArabicPage($customer->get('cart.php'), 'سلتي');
        $this->assertArabicPage($customer->get('success_pay.php?session_id=cs_test_unknown'), 'الدفعة غير موجودة', 404);
        $this->assertArabicPage($customer->get('cancel_pay.php'), 'أُلغيت عملية الدفع');
    }

    public function testFlashesErrorsAndTheJsonApiInArabic(): void
    {
        [$customer, $user] = $this->signedInCustomer();
        self::arabic($customer);

        $added = $customer->follow($this->addToCart($customer, 7, 2));
        $this->assertFlash($added, 'success', 'أُضيف 2 × Biology إلى سلتك.');
        $document = $this->assertArabicPage($added, 'سلتي');
        self::assertSame(['الكتاب', 'السعر', 'الكمية', 'الإجمالي', 'الإجراءات'], $document->texts('//table[' . HtmlDocument::hasClass('cart-table') . ']/thead//th'));

        $line = $this->cartLineId((int) $user['id'], 7);
        $this->assertFlash($customer->follow($customer->postWithToken('update_cart.php', ['cart_id' => (string) $line, 'quantity' => '3'])), 'success', 'حُدّثت كمية «Biology» إلى 3 نسخ.');
        $this->assertFlash($customer->follow($customer->postWithToken('update_cart.php', ['cart_id' => (string) $line, 'quantity' => '0'])), 'error', 'اختر كمية بين 1 و10.');

        db_run('UPDATE books SET stock = 1 WHERE id = 7');
        $cart = $customer->get('cart.php');
        $this->assertArabicPage($cart, 'سلتي');
        $this->assertPageText('تبقّت نسخة واحدة فقط. قلّل الكمية لإتمام الشراء.', $cart);
        $refused = $customer->follow($customer->postWithToken('checkout.php'));
        $this->assertFlash($refused, 'error', 'لا تتوفر نسخ كافية من «Biology» في المخزون. يُرجى تقليل الكمية أو إزالة الكتاب من سلتك ثم المحاولة مرة أخرى.');

        $json = $customer->get('fetch_books.php?page=3')->json();
        self::assertSame('عرض 49–55 من أصل 55 كتابًا في جميع التصنيفات.', $json['summary']);
        self::assertContains('متوفر', array_column($json['books'], 'availability'));
        $favorites = $this->client();
        $favorites->setCookie(LOCALE_COOKIE, 'ar');
        self::assertSame(['error' => 'يُرجى تسجيل الدخول بحساب عميل لعرض مفضلتك.'], $favorites->get('fetch_books.php?genre=Favorites')->json());
    }

    public function testAdminPagesInArabic(): void
    {
        [$customer] = $this->signedInCustomer('Arabic Buyer');
        $purchase = $this->purchase($customer, [51 => 1]);
        $orderId = (int) $purchase['order']['id'];
        [$admin] = $this->signedInAdmin('مشرف المتجر');
        self::arabic($admin);

        $dashboard = $this->assertArabicPage($admin->get('admin_dashboard.php'), 'لوحة تحكم المشرف');
        self::assertSame(['الكتب', '55', 'التصنيفات', '8', 'الطلبات المدفوعة', '1', 'الإيرادات', '19.99 ر.س'], $dashboard->texts('//section[' . HtmlDocument::hasClass('admin-stats') . ']//p'));
        self::assertSame('مسجّل الدخول باسم مشرف المتجر', $dashboard->text('//p[' . HtmlDocument::hasClass('admin-welcome') . ']'));
        self::assertSame('مدفوع', $dashboard->text('//table[' . HtmlDocument::hasClass('orders-table') . ']//span[' . HtmlDocument::hasClass('status-badge') . ']'));
        self::assertSame('عرض 1–20 من أصل 55 كتابًا.', $dashboard->text('//p[' . HtmlDocument::hasClass('table-summary') . ']'));

        $this->assertArabicPage($admin->get('edit_book.php?id=7'), 'تعديل الكتاب');
        $orders = $this->assertArabicPage($admin->get('admin_orders.php'), 'الطلبات');
        self::assertSame('عرض 1–1 من أصل طلب واحد.', $orders->text('//p[' . HtmlDocument::hasClass('table-summary') . ']'));
        $detail = $this->assertArabicPage($admin->get('admin_order.php?id=' . $orderId), 'الطلب رقم ' . $orderId);
        self::assertSame('استرداد مبلغ الطلب', $detail->text('//form[@action="admin_order.php?id=' . $orderId . '"][.//input[@value="refund"]]//button'));

        $invalid = $admin->submitForm($admin->get('admin_dashboard.php'), 'admin_dashboard.php', ['title' => '', 'stock' => 'x'], ['action' => 'add_book']);
        $form = $admin->follow($invalid);
        $this->assertFlash($form, 'error', 'لم يُضف الكتاب. يُرجى تصحيح الحقول المميزة.');
        self::assertSame('حقل العنوان مطلوب.', $form->document()->fieldError('book-title-error'));
        self::assertSame('يجب أن يكون المخزون عددًا صحيحًا من 0 إلى 100000.', $form->document()->fieldError('book-stock-error'));

        $refund = $admin->submitForm($admin->get('admin_order.php?id=' . $orderId), 'admin_order.php?id=' . $orderId, [], ['action' => 'refund']);
        $this->assertFlash($admin->follow($refund), 'success', 'استُرد مبلغ الطلب رقم ' . $orderId . '.');
    }

    public function testCheckoutOrderAndAddressPagesInArabic(): void
    {
        [$customer, $user] = $this->signedInCustomer('قارئ عربي');
        $address = ['recipient_name' => 'سارة أحمد', 'city' => 'الرياض', 'district' => 'العليا', 'street' => 'طريق الملك فهد'];
        $this->createAddress((int) $user['id'], $address);
        $orderId = (int) $this->purchase($customer, [51 => 1])['order']['id'];
        self::arabic($customer);
        $this->assertRedirect($this->addToCart($customer, 7, 1), 'cart.php');

        $cart = $this->assertArabicPage($customer->get('cart.php'), 'سلتي');
        self::assertSame('المتابعة لإتمام الطلب', $cart->text('//a[@href="checkout.php"]'));

        $checkout = $this->assertArabicPage($customer->get('checkout.php'), 'إتمام الطلب');
        self::assertSame(['السلة', 'التوصيل والمراجعة', 'الدفع'], $checkout->texts('//ol[' . HtmlDocument::hasClass('checkout-steps') . ']/li'));
        self::assertSame('الإجمالي (شامل الضريبة) 5.50 ر.س', $checkout->text('//div[' . HtmlDocument::hasClass('summary-total') . ']'));
        self::assertSame('يشمل ضريبة القيمة المضافة (15%) 0.72 ر.س', $checkout->text('//div[' . HtmlDocument::hasClass('summary-vat') . ']'));
        self::assertStringContainsString('سارة أحمد 1234 طريق الملك فهد العليا الرياض 12345-6789 العنوان المختصر: RRRD2929', $checkout->text('//fieldset//label'));
        self::assertSame('التوصيل إلى عنوان جديد', $checkout->text('//label[@for="address-new"]'));

        // A mistake in a new address is explained in Arabic.
        $refused = $customer->follow($customer->postWithToken('checkout.php', ['address' => 'new', 'phone' => '123'] + self::SAMPLE_ADDRESS));
        $document = $this->assertArabicPage($refused, 'إتمام الطلب');
        $this->assertFlash($refused, 'error', 'يُرجى تصحيح عنوان التوصيل. لم تبدأ عملية الدفع.');
        self::assertSame('يُرجى إدخال رقم جوال سعودي، مثل 0512345678.', $document->fieldError('new-address-phone-error'));

        $order = $this->assertArabicPage($customer->get('order.php?id=' . $orderId), 'الطلب رقم ' . $orderId);
        self::assertSame('قيد التجهيز', $order->text('//p[' . HtmlDocument::hasClass('order-meta') . ']/span[' . HtmlDocument::hasClass('status-badge') . ']'));
        self::assertSame(
            ['مدفوع', 'قيد التجهيز', 'تم الشحن (لم يتم بعد)', 'تم التوصيل (لم يتم بعد)'],
            $order->texts('//ol[' . HtmlDocument::hasClass('order-timeline') . ']//p[' . HtmlDocument::hasClass('timeline-label') . ']')
        );
        self::assertSame('الإيصال', $order->text('//h2[@id="receipt-title"]'));
        self::assertSame(
            ['الإجمالي قبل الضريبة 17.38 ر.س', 'ضريبة القيمة المضافة (15%) 2.61 ر.س', 'التوصيل مجاني', 'الإجمالي (شامل الضريبة) 19.99 ر.س'],
            $order->texts('//table[' . HtmlDocument::hasClass('receipt-table') . ']/tfoot/tr')
        );

        $profile = $this->assertArabicPage($customer->get('profilePage.php?section=addresses'), 'إعدادات الحساب');
        self::assertSame('عناوين التوصيل', $profile->text('//section[@id="section-addresses"]/h2'));
        self::assertSame('الافتراضي', $profile->text('//li[' . HtmlDocument::hasClass('address-card') . ']//p[' . HtmlDocument::hasClass('address-badge') . ']'));
        self::assertSame('الرمز البريدي', $profile->text('//label[@for="address-postal_code"]'));

        [$admin] = $this->signedInAdmin();
        self::arabic($admin);
        $detail = $this->assertArabicPage($admin->get('admin_order.php?id=' . $orderId), 'الطلب رقم ' . $orderId);
        self::assertSame('تحديد الطلب كمشحون', $detail->text('//form[.//input[@value="ship"]]//button'));
        self::assertSame('قيد التجهيز', $detail->text('//span[' . HtmlDocument::hasClass('fulfilment-badge') . ']'));
        $shipped = $admin->follow($admin->submitForm($admin->get('admin_order.php?id=' . $orderId), 'admin_order.php?id=' . $orderId, ['tracking_number' => 'SA1'], ['action' => 'ship']));
        $this->assertFlash($shipped, 'success', 'حُدّد أن الطلب رقم ' . $orderId . ' قد شُحن.');
        $this->assertArabicPage($admin->get('admin_orders.php?fulfilment=shipped'), 'الطلبات');
    }

    public function testNoTranslationKeysLeakInEnglish(): void
    {
        [$customer] = $this->signedInCustomer();
        $this->assertRedirect($this->addToCart($customer, 7, 1), 'cart.php');
        [$admin] = $this->signedInAdmin();
        foreach ([
            $customer->get('Home_Page.php'), $customer->get('About_us.php'), $customer->get('Explore_Page.php'),
            $customer->get('Selected.php?id=7'), $customer->get('cart.php'), $customer->get('cancel_pay.php'),
            $admin->get('admin_dashboard.php'), $admin->get('admin_orders.php'), $admin->get('edit_book.php?id=7'),
        ] as $page) {
            $document = $this->assertHtmlPage($page);
            self::assertSame('en', $document->first('/html')?->getAttribute('lang'));
            self::assertDoesNotMatchRegularExpression('/\b(?:shop|admin|common)\.[a-z_]+\.[a-z_]/', $page->body, "Untranslated key on {$page->url}");
        }
    }
}
