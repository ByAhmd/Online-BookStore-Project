<?php

declare(strict_types=1);

namespace BookWise\Tests\Integration;

use BookWise\Tests\Support\DatabaseTestCase;
use PDOException;

final class ReviewsTest extends DatabaseTestCase
{
    private const BOOK = 7;
    private const OTHER_BOOK = 8;

    public function testOneReviewPerUserAndBook(): void
    {
        $this->createUser('critic@example.com');

        self::assertFalse(user_has_reviewed('critic@example.com', self::BOOK));
        self::assertTrue(add_review(self::BOOK, 'critic@example.com', 'First take', 4));
        self::assertTrue(user_has_reviewed('critic@example.com', self::BOOK));
        self::assertTrue(user_has_reviewed('CRITIC@example.com', self::BOOK), 'Email comparison is case-insensitive');

        self::assertFalse(add_review(self::BOOK, 'critic@example.com', 'Second take', 1));
        self::assertTrue(add_review(self::OTHER_BOOK, 'critic@example.com', 'Another book', 3));
        self::assertSame(1, $this->countRows('comments', 'book_id = ?', [self::BOOK]));
        self::assertSame('First take', db_value('SELECT comment FROM comments WHERE book_id = ?', [self::BOOK]));
    }

    public function testDatabaseRejectsOutOfRangeRatings(): void
    {
        $this->expectException(PDOException::class);

        add_review(self::BOOK, 'critic@example.com', 'Off the scale', 6);
    }

    public function testCommentsShowNamesAndCountsButNeverEmails(): void
    {
        $this->createUser('author@example.com', 'Visible Name');
        $fan = (int) $this->createUser()['id'];
        $critic = (int) $this->createUser()['id'];
        add_review(self::BOOK, 'author@example.com', "Line one\n<b>bold?</b>", 5);
        add_review(self::BOOK, 'removed-account@example.com', 'Orphaned review', 2);
        $commentId = (int) db_value('SELECT id FROM comments WHERE username = ?', ['author@example.com']);
        toggle_comment_reaction($fan, self::BOOK, $commentId, 'like');
        toggle_comment_reaction($critic, self::BOOK, $commentId, 'dislike');

        $comments = book_comments(self::BOOK, $fan);

        self::assertCount(2, $comments);
        $byAuthor = array_column($comments, null, 'author_name');
        $names = array_keys($byAuthor);
        sort($names);
        self::assertSame(['Reader', 'Visible Name'], $names);
        $mine = $byAuthor['Visible Name'];
        self::assertSame(['id', 'comment', 'rating', 'created_at', 'author_name', 'likes', 'dislikes', 'my_reaction'], array_keys($mine));
        self::assertSame("Line one\n<b>bold?</b>", $mine['comment'], 'Stored raw; escaping happens on output');
        self::assertSame([5, 1, 1, 'like'], [(int) $mine['rating'], (int) $mine['likes'], (int) $mine['dislikes'], $mine['my_reaction']]);
        self::assertSame('Reader', $byAuthor['Reader']['author_name'], 'Reviews of deleted accounts are anonymous');

        foreach ([$fan, null] as $viewer) {
            $flat = json_encode(book_comments(self::BOOK, $viewer), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('@example.com', $flat, 'Author emails are never returned');
        }
        self::assertNull(array_column(book_comments(self::BOOK, null), 'my_reaction', 'author_name')['Visible Name'], 'Guests have no reaction');
    }

    public function testReactionsToggleSwitchAndUndo(): void
    {
        add_review(self::BOOK, 'author@example.com', 'Review', 4);
        $commentId = (int) db_value('SELECT id FROM comments');
        $user = (int) $this->createUser()['id'];
        $reaction = static fn (): ?string => db_value('SELECT reaction_type FROM comment_interactions WHERE comment_id = ? AND user_id = ?', [$commentId, $user]);

        self::assertTrue(toggle_comment_reaction($user, self::BOOK, $commentId, 'like'));
        self::assertSame('like', $reaction());

        self::assertTrue(toggle_comment_reaction($user, self::BOOK, $commentId, 'dislike'));
        self::assertSame('dislike', $reaction(), 'Switches');
        self::assertSame(1, $this->countRows('comment_interactions'));

        self::assertTrue(toggle_comment_reaction($user, self::BOOK, $commentId, 'dislike'));
        self::assertNull($reaction(), 'Same reaction again removes it');
        self::assertSame(0, $this->countRows('comment_interactions'));
    }

    public function testReactionsAreRejectedForTheWrongBookUnknownCommentsAndTypes(): void
    {
        add_review(self::BOOK, 'author@example.com', 'Review', 4);
        $commentId = (int) db_value('SELECT id FROM comments');
        $user = (int) $this->createUser()['id'];

        self::assertFalse(toggle_comment_reaction($user, self::OTHER_BOOK, $commentId, 'like'), 'Comment belongs to another book');
        self::assertFalse(toggle_comment_reaction($user, self::BOOK, $commentId + 1000, 'like'));
        self::assertFalse(toggle_comment_reaction($user, self::BOOK, $commentId, 'love'));
        self::assertFalse(toggle_comment_reaction($user, self::BOOK, $commentId, 'LIKE'));
        self::assertSame(0, $this->countRows('comment_interactions'));
    }

    public function testReactionsArePerUser(): void
    {
        add_review(self::BOOK, 'author@example.com', 'Review', 4);
        $commentId = (int) db_value('SELECT id FROM comments');
        $alice = (int) $this->createUser()['id'];
        $bob = (int) $this->createUser()['id'];

        toggle_comment_reaction($alice, self::BOOK, $commentId, 'like');
        toggle_comment_reaction($bob, self::BOOK, $commentId, 'like');
        toggle_comment_reaction($alice, self::BOOK, $commentId, 'like');

        $row = book_comments(self::BOOK, $bob)[0];
        self::assertSame([1, 0, 'like'], [(int) $row['likes'], (int) $row['dislikes'], $row['my_reaction']]);
        self::assertNull(book_comments(self::BOOK, $alice)[0]['my_reaction']);
    }
}
