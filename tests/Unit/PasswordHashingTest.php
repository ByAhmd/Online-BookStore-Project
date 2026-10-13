<?php

declare(strict_types=1);

namespace BookWise\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordHashingTest extends TestCase
{
    public function testHashPasswordUsesPasswordHash(): void
    {
        $hash = hash_password('s3cret-passphrase');

        self::assertStringStartsWith('$2y$', $hash, 'bcrypt (PASSWORD_DEFAULT)');
        self::assertNotSame(hash_password('s3cret-passphrase'), $hash, 'Salted');
        self::assertTrue(password_verify('s3cret-passphrase', $hash));
        self::assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
    }

    public function testCurrentBcryptHash(): void
    {
        $hash = hash_password('s3cret-passphrase');

        self::assertSame(['valid' => true, 'needs_rehash' => false], check_password('s3cret-passphrase', $hash));
        self::assertSame(['valid' => false, 'needs_rehash' => false], check_password('wrong-passphrase', $hash));
        self::assertSame(['valid' => false, 'needs_rehash' => false], check_password('S3CRET-PASSPHRASE', $hash));
    }

    public function testWeakerBcryptHashIsAcceptedAndFlaggedForRehash(): void
    {
        $hash = password_hash('s3cret-passphrase', PASSWORD_BCRYPT, ['cost' => 4]);

        self::assertSame(['valid' => true, 'needs_rehash' => true], check_password('s3cret-passphrase', $hash));
        self::assertSame(['valid' => false, 'needs_rehash' => false], check_password('nope', $hash));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function legacyHashCandidates(): iterable
    {
        yield 'md5 lower' => [md5('legacy'), true];
        yield 'md5 upper' => [strtoupper(md5('legacy')), true];
        yield '31 hex chars' => [substr(md5('legacy'), 1), false];
        yield '33 hex chars' => [md5('legacy') . 'a', false];
        yield 'non-hex' => [str_repeat('g', 32), false];
        yield 'bcrypt' => [password_hash('legacy', PASSWORD_BCRYPT, ['cost' => 4]), false];
        yield 'sha1' => [sha1('legacy'), false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('legacyHashCandidates')]
    public function testLegacyMd5Detection(string $hash, bool $expected): void
    {
        self::assertSame($expected, is_legacy_md5_hash($hash));
    }

    public function testLegacyMd5HashIsAcceptedOnceAndNeedsRehash(): void
    {
        self::assertSame(['valid' => true, 'needs_rehash' => true], check_password('legacy-pass', md5('legacy-pass')));
        self::assertSame(['valid' => true, 'needs_rehash' => true], check_password('legacy-pass', strtoupper(md5('legacy-pass'))));
        self::assertSame(['valid' => false, 'needs_rehash' => false], check_password('other-pass', md5('legacy-pass')));
    }

    public function testEmptyOrMissingHashNeverValidates(): void
    {
        self::assertSame(['valid' => false, 'needs_rehash' => false], check_password('', ''));
        self::assertSame(['valid' => false, 'needs_rehash' => false], check_password('anything', ''));
        self::assertSame(['valid' => false, 'needs_rehash' => false], check_password('anything', null));
    }

    public function testPlaintextStoredPasswordIsNotAccepted(): void
    {
        self::assertFalse(check_password('hunter22', 'hunter22')['valid']);
        self::assertFalse(check_password(md5('x'), md5('x'))['valid'], 'Submitting the stored digest itself does not log in');
    }
}
