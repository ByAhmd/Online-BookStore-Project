<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\AccountHelpers;
use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpTestCase;

/**
 * The sign-in, reset, confirmation and profile pages in Arabic: right-to-left
 * document, translated headings, messages and script strings, and no
 * untranslated English left in the page content.
 */
final class AccountLocalizationTest extends HttpTestCase
{
    use AccountHelpers;

    /** Latin-script words that legitimately stay in Arabic pages (names of brands, formats and languages). */
    private const LATIN_ALLOWED = ['BookWise', 'JPEG', 'PNG', 'GIF', 'WebP', 'Stripe', 'Apple', 'Google', 'Pay', 'English'];

    private function arabicVisitor(): HttpClient
    {
        $client = $this->client();
        $client->setCookie(LOCALE_COOKIE, 'ar');
        return $client;
    }

    private function assertArabicDocument(HtmlDocument $document, string $where): void
    {
        self::assertSame('ar', $document->first('//html')?->getAttribute('lang'), $where);
        self::assertSame('rtl', $document->first('//html')?->getAttribute('dir'), $where);
        preg_match_all('/[A-Za-z]{2,}/', $document->text('//main'), $matches);
        $untranslated = array_values(array_diff(array_unique($matches[0]), self::LATIN_ALLOWED));
        self::assertSame([], $untranslated, "Untranslated text in the main content of $where");
    }

    public function testSignInPagesInArabic(): void
    {
        $visitor = $this->arabicVisitor();
        $expected = ['signup' => 'إنشاء حساب', 'login' => 'تسجيل الدخول', 'reset' => 'إعادة تعيين كلمة المرور'];
        foreach ($expected as $panel => $heading) {
            $page = $visitor->get('index.php?form=' . $panel);
            $document = $this->assertHtmlPage($page);
            $this->assertArabicDocument($document, "index.php?form=$panel");
            self::assertSame($heading, $document->text('//section[@id="panel-' . $panel . '"]//h2'));
            self::assertSame($heading . ' · BookWise', $document->title());
            self::assertSame($heading . ' · BookWise', $document->first('//section[@id="panel-' . $panel . '"]')?->getAttribute('data-title'));
        }
        $document = $visitor->get('index.php')->document();
        self::assertSame('أنشئ حسابًا أو سجّل الدخول إلى BookWise', $document->text('//h1'));
        self::assertSame('كلمتا المرور غير متطابقتين.', $document->first('//div[@class="auth-card"]')?->getAttribute('data-i18n-mismatch'));
        self::assertSame('يجب ألا يزيد طول كلمة المرور على :max بايتًا.', $document->first('//div[@class="auth-card"]')?->getAttribute('data-i18n-too-long'));
        self::assertSame('إظهار كلمة المرور', $document->first('//button[@aria-controls="login-password"]')?->getAttribute('aria-label'));
        self::assertSame('8 أحرف على الأقل.', $document->text('//p[@id="signup-password-hint"]'));
        self::assertSame('تنتهي صلاحية الرابط خلال 60 دقيقة.', $document->text('//p[@id="reset-email-hint"]'));
    }

    public function testMessagesAndValidationErrorsInArabic(): void
    {
        $visitor = $this->arabicVisitor();

        $page = $visitor->follow($this->signUp($visitor, 'not-an-email', '<b>', 'short'));
        $document = $this->assertHtmlPage($page);
        self::assertSame('يُرجى إدخال بريد إلكتروني صالح.', $document->fieldError('signup-email-error'));
        self::assertSame('يحتوي الاسم على أحرف غير مسموح بها.', $document->fieldError('signup-name-error'));
        self::assertSame('يجب ألا تقل كلمة المرور عن 8 أحرف.', $document->fieldError('signup-password-error'));

        $page = $visitor->follow($this->logIn($visitor, self::uniqueEmail('nobody'), 'wrong-password-1'));
        $this->assertFlash($page, 'error', 'البريد الإلكتروني أو كلمة المرور غير صحيحة.');

        $page = $visitor->follow($this->logIn($visitor, '', ''));
        self::assertSame('يُرجى إدخال بريدك الإلكتروني.', $page->document()->fieldError('login-email-error'));
        self::assertSame('يُرجى إدخال كلمة المرور.', $page->document()->fieldError('login-password-error'));

        $form = $visitor->get('index.php?form=reset');
        $page = $visitor->follow($visitor->submitForm($form, 'reset_password.php', ['email' => self::uniqueEmail('someone')]));
        $this->assertFlash($page, 'success', 'إذا كان هناك حساب مرتبط بهذا البريد الإلكتروني، فقد أرسلنا إليه رابطًا لإعادة تعيين كلمة المرور. تنتهي صلاحيته خلال 60 دقيقة.');

        // Signed in, then out.
        $user = $this->createUser(self::uniqueEmail('arabic'), 'قارئة عربية');
        $response = $this->logIn($visitor, (string) $user['Eaddress'], self::DEFAULT_PASSWORD);
        $this->assertFlash($visitor->follow($response), 'success', 'مرحبًا بعودتك يا قارئة عربية!');
        $page = $visitor->follow($visitor->submitForm($visitor->get('Home_Page.php'), 'logout.php'));
        $this->assertFlash($page, 'info', 'تم تسجيل خروجك.');
    }

    public function testResetAndConfirmationPagesInArabic(): void
    {
        $this->configureServer(['REQUIRE_EMAIL_VERIFICATION' => 'true']);
        $visitor = $this->arabicVisitor();

        $document = $this->assertHtmlPage($visitor->get('index.php?form=verify'));
        $this->assertArabicDocument($document, 'index.php?form=verify');
        self::assertSame('أكّد عنوان بريدك الإلكتروني', $document->text('//section[@id="panel-verify"]//h2'));

        $document = $this->assertHtmlPage($visitor->get('verify_email.php?token=' . str_repeat('a', 64)), 404);
        $this->assertArabicDocument($document, 'verify_email.php');
        self::assertSame('رابط التأكيد هذا غير صالح أو انتهت صلاحيته', $document->text('//h1'));

        $document = $this->assertHtmlPage($visitor->get('reset_form.php?token=' . str_repeat('a', 64)), 404);
        $this->assertArabicDocument($document, 'reset_form.php (expired)');
        self::assertSame('رابط إعادة التعيين هذا غير صالح أو انتهت صلاحيته', $document->text('//h1'));

        $user = $this->createUser(self::uniqueEmail('reset-ar'));
        $raw = bin2hex(random_bytes(32));
        db_run('INSERT INTO reset_tokens (email, token, expiry) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)', [$user['Eaddress'], hash('sha256', $raw)]);
        $document = $this->assertHtmlPage($visitor->get('reset_form.php?token=' . $raw));
        self::assertSame('اختر كلمة مرور جديدة', $document->text('//h1'));
        self::assertSame('عيّن كلمة مرور جديدة للحساب ' . $user['Eaddress'] . '.', $document->text('//p[@class="auth-intro"]'));
        self::assertSame('ltr', $document->first('//p[@class="auth-intro"]/strong')?->getAttribute('dir'), 'The address stays left-to-right');
        self::assertSame('كلمة المرور الجديدة', $document->text('//label[@for="new-password"]'));
    }

    public function testProfileInArabic(): void
    {
        [$customer] = $this->signedInCustomer('قارئ الملف');
        $customer->get('Home_Page.php'); // shows (and clears) the English "Welcome back" flash from signing in
        $customer->setCookie(LOCALE_COOKIE, 'ar');

        $expected = [
            'account' => 'معلومات الحساب',
            'addresses' => 'عناوين التوصيل',
            'payment' => 'طريقة الدفع المفضّلة',
            'orders' => 'طلباتي',
            'security' => 'الأمان',
            'delete' => 'حذف الحساب',
        ];
        foreach ($expected as $section => $heading) {
            $page = $customer->get('profilePage.php?section=' . $section);
            $document = $this->assertHtmlPage($page);
            $this->assertArabicDocument($document, "profilePage.php?section=$section");
            self::assertSame('إعدادات الحساب', $document->text('//h1'));
            self::assertSame($heading, $document->text('//section[@id="section-' . $section . '"]/h2'));
            self::assertSame($heading . ' · BookWise', $document->title());
        }

        $document = $customer->get('profilePage.php?section=account')->document();
        self::assertSame(['الأمان', 'حذف الحساب'], array_slice($document->texts('//a[contains(@class, "profile-menu-link")]'), 4));
        self::assertSame('اللغة', $document->text('//h3[@id="language-title"]'));
        self::assertSame('العربية', $document->text('//select[@id="locale"]/option[@lang="ar"]'));
        self::assertSame('هل تريد حذف حسابك نهائيًا؟ لا يمكن التراجع عن ذلك.', $document->first('//form[contains(@class, "delete-form")]')?->getAttribute('data-confirm'));
        $input = $document->first('//input[@id="profile_image"]');
        self::assertSame('يُرجى اختيار صورة بصيغة JPEG أو PNG أو GIF أو WebP.', $input?->getAttribute('data-i18n-type-error'));
        self::assertSame('صورتك الشخصية الحالية', $document->first('//img[@id="avatar-preview"]')?->getAttribute('alt'));

        // Validation and flash messages follow the visitor's language.
        $page = $customer->get('profilePage.php?section=security');
        $response = $customer->submitForm($page, 'profilePage.php', ['current_password' => '', 'new_password' => 'short', 'new_password_confirmation' => 'short'], ['action' => 'change_password']);
        $page = $customer->follow($response);
        $this->assertFlash($page, 'error', 'لم تُغيَّر كلمة المرور.');
        self::assertSame('يُرجى إدخال كلمة المرور الحالية.', $page->document()->fieldError('current_password-error'));
        self::assertSame('يجب ألا تقل كلمة المرور عن 8 أحرف.', $page->document()->fieldError('new_password-error'));
    }

    public function testOrderHistoryInArabic(): void
    {
        [$customer] = $this->signedInCustomer('قارئ الطلبات');
        $purchase = $this->purchase($customer, [51 => 2]);
        $orderId = (int) $purchase['order']['id'];
        $customer->setCookie(LOCALE_COOKIE, 'ar');

        $document = $this->assertHtmlPage($customer->get('profilePage.php?section=orders'));
        self::assertSame(['الطلب رقم ' . $orderId], $document->texts('//h3[contains(@class, "order-number")]'));
        self::assertSame(['قيد التجهيز'], $document->texts('//span[contains(@class, "status-badge")]'));
        self::assertStringStartsWith('تاريخ الطلب ', $document->text('//p[@class="order-date"]'));
        self::assertSame('الإجمالي ' . number_format((float) $purchase['order']['total_amount'], 2) . ' ر.س', $document->text('//p[@class="order-total"]'));
    }
}
