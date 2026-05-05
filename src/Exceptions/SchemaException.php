<?php

declare(strict_types=1);

namespace HolySheet\Exceptions;

use RuntimeException;

/**
 * Thrown when a Holy Sheet schema fails validation.
 *
 * Wraps the structured error list (path, expected, got, value, hint)
 * exposed by `Schema\Validator::validate()`. Agents catching this
 * exception should read `getErrors()` rather than parsing the message —
 * each error is independently actionable.
 */
final class SchemaException extends RuntimeException
{
    /**
     * @param  list<array{path:string,expected:string,got:string,value:mixed,hint:string}>  $errors
     */
    public function __construct(
        private readonly array $errors,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $this->summarize());
    }

    /**
     * @param  list<array{path:string,expected:string,got:string,value:mixed,hint:string}>  $errors
     */
    public static function fromErrors(array $errors): self
    {
        return new self($errors);
    }

    /**
     * @return list<array{path:string,expected:string,got:string,value:mixed,hint:string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    private function summarize(): string
    {
        if (count($this->errors) === 1) {
            $e = $this->errors[0];
            return "[holy-sheet] schema invalid at {$e['path']}: expected {$e['expected']}, got {$e['got']}";
        }
        $first = $this->errors[0];
        $rest = count($this->errors) - 1;
        return "[holy-sheet] schema invalid at {$first['path']}: expected {$first['expected']}, got {$first['got']} (+{$rest} more)";
    }
}
