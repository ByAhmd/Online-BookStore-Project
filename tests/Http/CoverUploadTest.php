<?php

declare(strict_types=1);

namespace BookWise\Tests\Http;

use BookWise\Tests\Support\HtmlDocument;
use BookWise\Tests\Support\HttpClient;
use BookWise\Tests\Support\HttpResponse;
use BookWise\Tests\Support\HttpTestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Book cover uploads on the admin add and edit forms: stored re-encoded in
 * public/uploads/covers, rejected when not an image, and the old file is
 * removed when a cover is replaced or its book deleted.
 */
#[RequiresPhpExtension('gd')]
final class CoverUploadTest extends HttpTestCase
{
    private const COVERS = BASE_PATH . '/public/uploads/covers';
    private const PHP_PAYLOAD = "<?php echo 'bookwise-cover-test'; ?>\n";

    /** @var list<string> Files in uploads/covers before the test */
    private array $coversBefore = [];

    /** @var list<string> Temporary source files */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->coversBefore = self::coverFiles();
    }

    protected function tearDown(): void
    {
        // Remove every cover this test stored (the application writes into the checkout).
        foreach (array_diff(self::coverFiles(), $this->coversBefore) as $file) {
            @unlink(self::COVERS . '/' . $file);
        }
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private static function coverFiles(): array
    {
        return array_values(array_map('basename', glob(self::COVERS . '/*') ?: []));
    }

    private function imageFile(string $type = 'png', string $extra = ''): string
    {
        $image = imagecreatetruecolor(40, 64);
        imagefilledrectangle($image, 0, 0, 39, 63, (int) imagecolorallocate($image, 198, 40, 40));
        ob_start();
        $type === 'jpeg' ? imagejpeg($image) : imagepng($image);
        return $this->tempFile((string) ob_get_clean() . $extra);
    }

    private function tempFile(string $contents): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'bookwise-cover-');
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;
        return $file;
    }

    /**
     * @return array{cover_upload: array{path: string, filename: string, type: string}}
     */
    private static function upload(string $path, string $filename = 'cover.png', string $type = 'image/png'): array
    {
        return ['cover_upload' => ['path' => $path, 'filename' => $filename, 'type' => $type]];
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private static function book(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Uploaded Cover Book',
            'author' => 'Cover Artist',
            'genre' => 'Drama',
            'description' => 'A book whose cover was uploaded.',
            'price' => '15.00',
            'stock' => '9',
            'cover_image' => '',
        ];
    }

    /**
     * @param array<string, string> $values
     * @param array<string, array{path: string, filename: string, type: string}> $files
     */
    private function addBook(HttpClient $admin, array $values, array $files): HttpResponse
    {
        return $admin->submitForm($admin->get('admin_dashboard.php'), 'admin_dashboard.php', $values, ['action' => 'add_book'], $files);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, array{path: string, filename: string, type: string}> $files
     */
    private function editBook(HttpClient $admin, int $id, array $values, array $files = []): HttpResponse
    {
        return $admin->submitForm($admin->get('edit_book.php?id=' . $id), 'edit_book.php?id=' . $id, $values, ['action' => 'update_book'], $files);
    }

    private static function cover(int $bookId): string
    {
        return (string) db_value('SELECT cover_image FROM books WHERE id = ?', [$bookId]);
    }

    public function testABookWhoseCoverFileWasLostCanStillBeEdited(): void
    {
        [$admin] = $this->signedInAdmin();
        $this->assertRedirect($this->addBook($admin, self::book(), self::upload($this->imageFile())), 'admin_dashboard.php');
        $id = (int) db_value('SELECT id FROM books WHERE title = ?', ['Uploaded Cover Book']);
        $cover = self::cover($id);
        // Free hosts lose uploaded files on restart; the database keeps the path.
        unlink(BASE_PATH . '/public/' . $cover);

        // Saving other fields with the stored (missing) cover works.
        $form = $admin->get('edit_book.php?id=' . $id);
        self::assertSame($cover, $form->document()->form('edit_book.php?id=' . $id, ['action' => 'update_book'])->field('cover_image'));
        $this->assertRedirect($admin->submitForm($form, 'edit_book.php?id=' . $id, ['title' => 'Renamed After Restart'], ['action' => 'update_book']), 'admin_dashboard.php#manage-books');
        self::assertSame(['Renamed After Restart', $cover], [(string) db_value('SELECT title FROM books WHERE id = ?', [$id]), self::cover($id)]);

        // Another missing file is still refused.
        $page = $this->editBook($admin, $id, ['cover_image' => 'uploads/covers/' . str_repeat('a', 32) . '.png']);
        self::assertSame(422, $page->status);
        self::assertSame($cover, self::cover($id));
    }

    public function testStockChangesMadeWhileTheEditFormWasOpenAreKept(): void
    {
        [$admin] = $this->signedInAdmin();
        $this->assertRedirect($this->addBook($admin, self::book(['cover_image' => 'imges/All_section/biology.png']), []), 'admin_dashboard.php');
        $id = (int) db_value('SELECT id FROM books WHERE title = ?', ['Uploaded Cover Book']);

        $form = $admin->get('edit_book.php?id=' . $id);
        self::assertSame('9', $form->document()->form('edit_book.php?id=' . $id, ['action' => 'update_book'])->field('stock'));
        db_run('UPDATE books SET stock = 7 WHERE id = ?', [$id]); // two copies sold meanwhile

        $this->assertRedirect($admin->submitForm($form, 'edit_book.php?id=' . $id, ['price' => '16.00'], ['action' => 'update_book']), 'admin_dashboard.php#manage-books');
        self::assertSame(7, (int) db_value('SELECT stock FROM books WHERE id = ?', [$id]), 'The sale is not undone');

        $form = $admin->get('edit_book.php?id=' . $id);
        db_run('UPDATE books SET stock = 6 WHERE id = ?', [$id]);
        $this->assertRedirect($admin->submitForm($form, 'edit_book.php?id=' . $id, ['stock' => '17'], ['action' => 'update_book']), 'admin_dashboard.php#manage-books');
        self::assertSame(16, (int) db_value('SELECT stock FROM books WHERE id = ?', [$id]), 'Ten copies added to what is there now');
    }

    public function testAddingABookWithAnUploadedCover(): void
    {
        [$admin] = $this->signedInAdmin();
        $dashboard = $admin->get('admin_dashboard.php')->document();
        self::assertSame('multipart/form-data', $dashboard->first('//form[.//input[@name="action"][@value="add_book"]]')?->getAttribute('enctype'));
        self::assertSame('image/jpeg,image/png,image/gif,image/webp', $dashboard->first('//input[@id="book-cover-upload"]')?->getAttribute('accept'));

        $response = $this->addBook($admin, self::book(), self::upload($this->imageFile(), 'my cover.php.png', 'application/x-php'));
        $this->assertRedirect($response, 'admin_dashboard.php');
        $this->assertFlash($admin->follow($response), 'success', '“Uploaded Cover Book” was added.');

        $book = db_one('SELECT * FROM books WHERE title = ?', ['Uploaded Cover Book']);
        self::assertNotNull($book);
        self::assertMatchesRegularExpression('#^uploads/covers/[a-f0-9]{32}\.png$#', $book['cover_image'], 'Random name, extension from the content');
        self::assertSame(9, (int) $book['stock']);
        $stored = (string) file_get_contents(BASE_PATH . '/public/' . $book['cover_image']);
        self::assertStringStartsWith("\x89PNG", $stored);

        $served = $this->client()->get($book['cover_image']);
        $this->assertStatus(200, $served);
        self::assertStringStartsWith('image/png', $served->contentType());

        [$customer] = $this->signedInCustomer();
        $page = $this->bookPage($customer, (int) $book['id']);
        $this->assertHtmlPage($page);
        self::assertSame($book['cover_image'], $page->document()->first('//img[' . HtmlDocument::hasClass('pic') . ']')?->getAttribute('src'));
    }

    public function testAnUploadReplacesThePathFieldAndTheJpegKeepsItsType(): void
    {
        [$admin] = $this->signedInAdmin();
        $response = $this->addBook($admin, self::book(['cover_image' => 'imges/All_section/dark.png']), self::upload($this->imageFile('jpeg'), 'photo.jpg', 'image/jpeg'));
        $this->assertRedirect($response, 'admin_dashboard.php');
        self::assertMatchesRegularExpression('#^uploads/covers/[a-f0-9]{32}\.jpg$#', (string) db_value('SELECT cover_image FROM books WHERE title = ?', ['Uploaded Cover Book']));
    }

    public function testPhpDisguisedAsAnImageIsRejected(): void
    {
        [$admin] = $this->signedInAdmin();
        $books = $this->countRows('books');

        foreach ([
            'PHP named .png' => [self::PHP_PAYLOAD, 'cover.png', 'image/png'],
            'PHP named .php' => [self::PHP_PAYLOAD, 'shell.php', 'application/x-php'],
            'HTML' => ['<html><script>alert(1)</script></html>', 'cover.gif', 'image/gif'],
        ] as $label => [$contents, $filename, $type]) {
            $response = $this->addBook($admin, self::book(), self::upload($this->tempFile($contents), $filename, $type));
            $this->assertRedirect($response, 'admin_dashboard.php#add-book', 303, $label);
            $document = $admin->follow($response)->document();
            self::assertSame('Please upload a JPEG, PNG, GIF or WebP image.', $document->fieldError('book-cover_upload-error'), $label);
            self::assertSame('true', $document->first('//input[@id="book-cover-upload"]')?->getAttribute('aria-invalid'));
            self::assertSame('Uploaded Cover Book', $document->first('//input[@id="book-title"]')?->getAttribute('value'), 'The other values are kept');
        }

        self::assertSame($books, $this->countRows('books'), 'No book is added');
        self::assertSame($this->coversBefore, self::coverFiles(), 'No file is stored');
    }

    public function testAPayloadAppendedToARealImageIsStripped(): void
    {
        [$admin] = $this->signedInAdmin();
        $this->assertRedirect($this->addBook($admin, self::book(), self::upload($this->imageFile('png', self::PHP_PAYLOAD))), 'admin_dashboard.php');

        $cover = (string) db_value('SELECT cover_image FROM books WHERE title = ?', ['Uploaded Cover Book']);
        self::assertStringNotContainsString('<?php', (string) file_get_contents(BASE_PATH . '/public/' . $cover));
    }

    public function testNothingIsStoredWhenOtherFieldsAreInvalid(): void
    {
        [$admin] = $this->signedInAdmin();
        $response = $this->addBook($admin, self::book(['price' => 'free', 'stock' => '-1']), self::upload($this->imageFile()));
        $this->assertRedirect($response, 'admin_dashboard.php#add-book');
        $document = $admin->follow($response)->document();
        self::assertSame('Price must be a number with at most two decimals.', $document->fieldError('book-price-error'));
        self::assertSame('Stock must be a whole number from 0 to 100000.', $document->fieldError('book-stock-error'));
        self::assertSame('Please choose the cover file again: files are not kept when the form has errors.', $document->fieldError('book-cover_upload-error'));
        self::assertSame($this->coversBefore, self::coverFiles());

        // Without an upload the path field is required as before.
        $response = $this->addBook($admin, self::book(), []);
        self::assertSame('Cover image is required.', $admin->follow($response)->document()->fieldError('book-cover_image-error'));
    }

    public function testReplacingACoverRemovesTheOldFile(): void
    {
        [$admin] = $this->signedInAdmin();
        $this->assertRedirect($this->addBook($admin, self::book(), self::upload($this->imageFile())), 'admin_dashboard.php');
        $id = (int) db_value('SELECT id FROM books WHERE title = ?', ['Uploaded Cover Book']);
        $first = self::cover($id);
        self::assertFileExists(BASE_PATH . '/public/' . $first);

        // A new upload on the edit page replaces the file.
        $response = $this->editBook($admin, $id, [], self::upload($this->imageFile('jpeg'), 'new.jpg', 'image/jpeg'));
        $this->assertRedirect($response, 'admin_dashboard.php#manage-books');
        $second = self::cover($id);
        self::assertNotSame($first, $second);
        self::assertFileDoesNotExist(BASE_PATH . '/public/' . $first, 'The replaced upload is deleted');
        self::assertFileExists(BASE_PATH . '/public/' . $second);

        // Saving without a new file keeps the current upload.
        $this->assertRedirect($this->editBook($admin, $id, ['title' => 'Renamed Cover Book']), 'admin_dashboard.php#manage-books');
        self::assertSame($second, self::cover($id));
        self::assertFileExists(BASE_PATH . '/public/' . $second);

        // An invalid upload on the edit page changes nothing.
        $invalid = $this->editBook($admin, $id, [], self::upload($this->tempFile(self::PHP_PAYLOAD)));
        $document = $this->assertHtmlPage($invalid, 422);
        self::assertSame('Please upload a JPEG, PNG, GIF or WebP image.', $document->fieldError('book-cover_upload-error'));
        self::assertSame($second, self::cover($id));

        // Switching to a catalog image deletes the upload too.
        $this->assertRedirect($this->editBook($admin, $id, ['cover_image' => 'imges/All_section/dark.png']), 'admin_dashboard.php#manage-books');
        self::assertSame('imges/All_section/dark.png', self::cover($id));
        self::assertFileDoesNotExist(BASE_PATH . '/public/' . $second);
        self::assertFileExists(BASE_PATH . '/public/imges/All_section/dark.png', 'Catalog images are never deleted');
    }

    public function testDeletingABookRemovesItsUploadedCoverUnlessAnotherBookUsesIt(): void
    {
        [$admin] = $this->signedInAdmin();
        $this->assertRedirect($this->addBook($admin, self::book(), self::upload($this->imageFile())), 'admin_dashboard.php');
        $first = (int) db_value('SELECT id FROM books WHERE title = ?', ['Uploaded Cover Book']);
        $cover = self::cover($first);
        // A second book points at the same uploaded file through the path field.
        $this->assertRedirect($this->addBook($admin, self::book(['title' => 'Shared Cover Book', 'cover_image' => $cover]), []), 'admin_dashboard.php');
        $second = (int) db_value('SELECT id FROM books WHERE title = ?', ['Shared Cover Book']);

        $delete = static fn (int $id): HttpResponse => $admin->submitForm($admin->get('admin_dashboard.php'), 'admin_dashboard.php', [], ['action' => 'delete_book', 'id' => (string) $id]);
        $this->assertRedirect($delete($first), 'admin_dashboard.php#manage-books');
        self::assertFileExists(BASE_PATH . '/public/' . $cover, 'Still used by the other book');

        $this->assertRedirect($delete($second), 'admin_dashboard.php#manage-books');
        self::assertFileDoesNotExist(BASE_PATH . '/public/' . $cover);
        self::assertSame($this->coversBefore, self::coverFiles());
    }
}
