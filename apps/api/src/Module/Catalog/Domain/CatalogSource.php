<?php

declare(strict_types=1);

namespace App\Module\Catalog\Domain;

/**
 * The official publication a rule was read from.
 *
 * Every displayed regulatory value carries one: without a publisher and a
 * reachable URL, a figure on screen is indistinguishable from a guess.
 */
final readonly class CatalogSource
{
    public const int MAX_PUBLISHER_LENGTH = 120;
    public const int MAX_TITLE_LENGTH = 200;
    public const int MAX_URL_LENGTH = 512;

    public function __construct(
        public string $publisher,
        public string $title,
        public string $url,
        public ?\DateTimeImmutable $publishedOn,
        public \DateTimeImmutable $retrievedOn,
    ) {
        self::assertText($publisher, self::MAX_PUBLISHER_LENGTH, 'publisher');
        self::assertText($title, self::MAX_TITLE_LENGTH, 'title');

        if (strlen($url) > self::MAX_URL_LENGTH || !str_starts_with($url, 'https://')) {
            // An official source is fetched over TLS or not at all: a plain
            // http reference could be rewritten in transit by whoever the
            // reader's network belongs to.
            throw new InvalidCatalogEntry('A catalogue source URL is an https URL.');
        }

        if (null !== $publishedOn && $publishedOn > $retrievedOn) {
            throw new InvalidCatalogEntry('A catalogue source cannot be retrieved before it was published.');
        }
    }

    private static function assertText(string $value, int $maxLength, string $field): void
    {
        if ($value !== trim($value) || '' === $value || mb_strlen($value) > $maxLength) {
            throw new InvalidCatalogEntry(sprintf('A catalogue source %s contains between 1 and %d characters.', $field, $maxLength));
        }
    }
}
