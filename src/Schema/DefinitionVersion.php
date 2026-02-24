<?php

declare(strict_types=1);

namespace Nadar\Schema;

/**
 * Represents a bundled schema.org vocabulary version.
 *
 * Each case corresponds to a JSON-LD vocabulary file shipped inside the
 * `src/definitions/` directory of this package. Pass the desired version to
 * {@see JsonLdValidator::__construct()} to select which vocabulary is used for
 * validation.
 *
 * Usage:
 * ```php
 * // Use the default (latest bundled) version:
 * $validator = new \Nadar\Schema\JsonLdValidator();
 *
 * // Pin to a specific bundled version:
 * $validator = new \Nadar\Schema\JsonLdValidator(
 *     version: DefinitionVersion::V20260226,
 * );
 * ```
 */
enum DefinitionVersion: string
{
    /** schema.org vocabulary v29.4 (bundled 2026-02-26). */
    case V20260226 = '2026-02-26';

    /**
     * Returns the absolute path to this version's bundled JSON-LD file.
     */
    public function filePath(): string
    {
        return dirname(__DIR__) . '/definitions/' . $this->value . '.jsonld';
    }
}
