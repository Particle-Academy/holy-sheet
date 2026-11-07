<?php

declare(strict_types=1);

namespace HolySheet\Exceptions;

use RuntimeException;

/**
 * Structured refusal without echoing archive names or document content.
 *
 * A refusal over a limit (`archive_limit`, and `read_limit` from
 * ReadLimitExceeded) names the limit, its value and what the file needs, so an
 * app can tell which one to raise: `limit`, `max`, `actual`. They are null for
 * every other reason. A bad limit SETTING (`archive_limits`, `read_limits`)
 * says which setting and why in the message.
 */
class ArchiveRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $limit = null,
        public readonly ?int $max = null,
        public readonly ?int $actual = null,
        ?string $detail = null,
    ) {
        if ($detail === null && $limit !== null && $max !== null && $actual !== null) {
            $detail = $limit.' is '.number_format($max).'; this file needs at least '.number_format($actual);
        }
        parent::__construct('Source archive refused: '.$reason.($detail !== null ? ' ('.$detail.')' : ''));
    }

    /** One archive limit exceeded: `archive_limit`, naming it. */
    public static function overLimit(string $limit, int $max, int $actual): self
    {
        return new self('archive_limit', $limit, $max, $actual);
    }

    /** A bad limit setting: `$reason` is `archive_limits` or `read_limits`. */
    public static function badSetting(string $reason, string|int $key, bool $known): self
    {
        return new self($reason, detail: $known
            ? $key.' must be a positive integer'
            : 'unknown limit "'.$key.'"');
    }
}
