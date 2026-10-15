<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Security;

use App\Module\Identity\Infrastructure\Security\FilePasswordList;
use PHPUnit\Framework\TestCase;

final class FilePasswordListTest extends TestCase
{
    private const string DATA_FILE = __DIR__.'/../../../../../src/Module/Identity/Infrastructure/Security/common-passwords.txt';

    public function testACommonPasswordIsRecognisedWhateverItsCase(): void
    {
        $list = new FilePasswordList();

        self::assertTrue($list->contains('motherfucker'));
        self::assertTrue($list->contains('MotherFucker'));
        self::assertTrue($list->contains('PORNOGRAPHIC'));
    }

    public function testAnUncommonPassphraseAndAPartialMatchAreNotRecognised(): void
    {
        $list = new FilePasswordList();

        self::assertFalse($list->contains('correct horse battery staple'));
        self::assertFalse($list->contains('motherfucker!'));
        self::assertFalse($list->contains(''));
    }

    public function testTheDataFileCitesItsSourceAndKeepsOnlyLowercaseEntriesWithinThePolicyLength(): void
    {
        $content = file_get_contents(self::DATA_FILE);
        self::assertIsString($content);
        self::assertMatchesRegularExpression('~SecLists.*xato-net-10-million-passwords-1000000\.txt~s', $content);
        self::assertMatchesRegularExpression('~Commit: [0-9a-f]{40}~', $content);

        $entries = array_filter(explode("\n", $content), static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#'));
        self::assertCount(3000, $entries);
        foreach ($entries as $entry) {
            self::assertSame(mb_strtolower($entry), $entry, 'An entry must be lowercase.');
            self::assertGreaterThanOrEqual(12, mb_strlen($entry));
            self::assertLessThanOrEqual(128, mb_strlen($entry));
        }
    }

    public function testAnUnreadableListFailsLoudlyInsteadOfAcceptingEveryPassword(): void
    {
        $this->expectException(\RuntimeException::class);

        @(new FilePasswordList('/nonexistent/common-passwords.txt'))->contains('anything long enough');
    }
}
