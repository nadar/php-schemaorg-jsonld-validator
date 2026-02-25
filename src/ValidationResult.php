<?php

declare(strict_types=1);

namespace Nadar\Schema;

/**
 * Immutable result object returned by {@see JsonLdValidator::validate()}.
 *
 * Each call to `validate()` produces a fresh `ValidationResult`, so multiple
 * validations can be performed without any shared mutable state:
 *
 * ```php
 * $validator = new JsonLdValidator();
 *
 * $resultA = $validator->validate($documentA);
 * $resultB = $validator->validate($documentB);
 *
 * // $resultA and $resultB are independent — no state leaks between calls.
 * ```
 */
final readonly class ValidationResult
{
    /**
     * @param array<mixed>|null $jsonLd The decoded JSON-LD data that was validated,
     *                                  or `null` when the input could not be decoded.
     * @param list<string>      $errors Validation error messages (empty when valid).
     */
    public function __construct(
        private array|null $jsonLd = null,
        private array $errors = [],
    ) {}

    /**
     * Returns the decoded JSON-LD data that was validated.
     *
     * When the original input was a raw JSON string, this returns the decoded
     * PHP array. Returns `null` when the input could not be decoded or was not
     * a valid JSON object.
     *
     * @return array<mixed>|null
     */
    public function getJsonLd(): array|null
    {
        return $this->jsonLd;
    }

    /**
     * Returns `true` when the validated document contained no violations.
     */
    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * Returns `true` when at least one validation error was found.
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Returns all validation error messages.
     *
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns all validation errors as a single string, joined by `$separator`.
     *
     * @param string $separator Placed between consecutive error messages. Defaults to `"\n"`.
     */
    public function getErrorsAsString(string $separator = "\n"): string
    {
        return implode($separator, $this->errors);
    }

    /**
     * Returns a string representation of the validation result.
     *
     * When valid, returns `"Valid."`. Otherwise, returns all error messages
     * separated by newlines.
     */
    public function __toString(): string
    {
        return $this->isValid() ? 'Valid.' : $this->getErrorsAsString();
    }
}
