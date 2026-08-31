<?php

declare(strict_types=1);

namespace App\Module\Audit\Domain;

/**
 * The bounded, redacted before/after picture attached to an audit event.
 *
 * An audit trail is read long after the fact by whoever investigates an
 * incident, so it must never become a second copy of the sensitive data it
 * describes. Three rules hold here: attribute names are structural identifiers,
 * values are short scalars, and any attribute whose name suggests a credential,
 * a banking identifier or a financial value is stored as {@see self::REDACTED}
 * instead of its value. Redacting rather than rejecting keeps a mistaken caller
 * from aborting the business transaction the event belongs to.
 */
final readonly class AuditDiff
{
    public const int MAX_ATTRIBUTES = 25;
    public const int MAX_VALUE_LENGTH = 256;

    /**
     * Ceiling on one encoded side, measured with {@see json_encode}. The
     * migration's CHECK repeats it at 4096 per side as a backstop against a
     * writer that bypasses this object; the gap absorbs the extra spacing
     * PostgreSQL adds when it renders `jsonb` back to text. Exceeding it is a
     * programming error, not a data condition, so it throws.
     */
    public const int MAX_ENCODED_LENGTH = 4000;

    public const string REDACTED = '[redacted]';
    public const string TRUNCATED_SUFFIX = '…';

    private const string ATTRIBUTE_PATTERN = '/^[a-z][a-zA-Z0-9_]{0,48}$/';

    /**
     * Matched against the whole words of an attribute name, not as a raw
     * substring: `accountNumber`, `account_number` and `AccountNumber` all
     * redact, while `discarded` and `drawdown` do not lose their value to a
     * chance match on `card` or `raw`.
     *
     * Financial values are on the list because an amount belongs in its own
     * record with its currency, never in a free-form trail. Free-text fields
     * are on it because they are where a user puts whatever they like.
     */
    private const array SENSITIVE_WORDS = [
        // Credentials and secrets
        'password', 'secret', 'token', 'hash', 'credential', 'credentials',
        'salt', 'cookie', 'key', 'signature', 'otp', 'pin', 'nonce',
        // Banking and payment identifiers
        'iban', 'bic', 'rib', 'card', 'pan', 'account', 'sepa',
        // Financial values
        'amount', 'balance', 'price', 'cost', 'value', 'total', 'fee', 'fees',
        'tax', 'rate', 'quantity', 'yield', 'premium', 'ceiling', 'interest',
        // Free text and personal data
        'label', 'note', 'notes', 'memo', 'comment', 'comments', 'description',
        'content', 'payload', 'raw', 'body', 'message', 'email', 'phone',
        'address', 'payee', 'counterparty', 'merchant', 'beneficiary', 'holder',
    ];

    /**
     * @param array<string, string|null> $before
     * @param array<string, string|null> $after
     */
    private function __construct(
        public array $before,
        public array $after,
    ) {
    }

    /**
     * @param array<string, scalar|null> $after
     */
    public static function creation(array $after): self
    {
        return new self([], self::sanitize($after));
    }

    /**
     * @param array<string, scalar|null> $before
     * @param array<string, scalar|null> $after
     */
    public static function change(array $before, array $after): self
    {
        return new self(self::sanitize($before), self::sanitize($after));
    }

    /**
     * For an event whose meaning is the act itself — signing in, defining a
     * password — where every attribute worth naming would have to be redacted.
     */
    public static function none(): self
    {
        return new self([], []);
    }

    public function isEmpty(): bool
    {
        return [] === $this->before && [] === $this->after;
    }

    /**
     * @param array<string, scalar|null> $attributes
     *
     * @return array<string, string|null>
     */
    private static function sanitize(array $attributes): array
    {
        if (count($attributes) > self::MAX_ATTRIBUTES) {
            throw new \InvalidArgumentException(sprintf('An audit diff carries at most %d attributes.', self::MAX_ATTRIBUTES));
        }

        $sanitized = [];
        foreach ($attributes as $name => $value) {
            $name = (string) $name;
            if (1 !== preg_match(self::ATTRIBUTE_PATTERN, $name)) {
                throw new \InvalidArgumentException(sprintf('Unsupported audit attribute name "%s".', $name));
            }

            $sanitized[$name] = self::isSensitive($name) ? self::REDACTED : self::normalize($name, $value);
        }

        $encodedLength = mb_strlen(json_encode($sanitized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '8bit');
        if ($encodedLength > self::MAX_ENCODED_LENGTH) {
            throw new \InvalidArgumentException(sprintf('One side of an audit diff encodes to at most %d bytes, got %d.', self::MAX_ENCODED_LENGTH, $encodedLength));
        }

        return $sanitized;
    }

    /**
     * Splits the attribute name into words on underscores and camelCase
     * boundaries, then matches each word against {@see self::SENSITIVE_WORDS}.
     */
    private static function isSensitive(string $name): bool
    {
        $words = preg_split('/_+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $name) ?: [];
        foreach ($words as $word) {
            if (in_array(mb_strtolower($word), self::SENSITIVE_WORDS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param scalar|null $value
     */
    private static function normalize(string $name, mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value)) {
            // Binary floating point never describes a Cadran value; a decimal
            // reaches the trail as its canonical string.
            throw new \InvalidArgumentException(sprintf('Audit attribute "%s" must not carry a float.', $name));
        }

        // Control characters would let a crafted value forge structure in a
        // downstream viewer or export.
        $text = preg_replace('/[\p{C}]/u', '', (string) $value) ?? '';

        return mb_strlen($text) > self::MAX_VALUE_LENGTH
            ? mb_substr($text, 0, self::MAX_VALUE_LENGTH).self::TRUNCATED_SUFFIX
            : $text;
    }
}
