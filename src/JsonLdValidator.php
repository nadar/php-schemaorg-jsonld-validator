<?php

declare(strict_types=1);

namespace Nadar\Schema;

use RuntimeException;
use Throwable;

/**
 * Schema.org JSON-LD validator.
 *
 * Validates JSON-LD structured data against the official schema.org vocabulary,
 * replicating the checks performed by https://validator.schema.org/.
 *
 * Two kinds of violations are detected:
 *   1. **Unknown properties** – keys that do not exist in the schema.org vocabulary.
 *   2. **Wrong domain** – properties used on a `@type` for which they are not
 *      defined (respecting the full type-inheritance hierarchy).
 *
 * Unknown `@type` values are also reported as errors.
 *
 * Each call to {@see validate()} returns an immutable {@see ValidationResult}
 * object. A fresh {@see ValidationEngine} is created for every call, so
 * multiple documents can be validated independently without any shared mutable
 * state.
 *
 * Usage:
 * ```php
 * $validator = new \Nadar\Schema\JsonLdValidator();
 * $result = $validator->validate($myJsonLdArray);
 *
 * if (!$result->isValid()) {
 *     print_r($result->getErrors());
 *     echo $result->getErrorsAsString();
 * }
 * ```
 *
 * The schema.org vocabulary is read from a bundled JSON-LD file that ships with
 * this package. Use {@see Vocabulary} to select a specific bundled version if
 * needed.
 */
final class JsonLdValidator
{
    /**
     * The bundled schema.org vocabulary used for validation.
     *
     * Readable after construction so callers can inspect which vocabulary
     * version the validator was created with.
     */
    public readonly Vocabulary $vocabulary;

    /** Whether the vocabulary has already been parsed into memory. */
    private bool $loaded = false;

    /** @var array<string, true> All known schema.org type names (short form, no prefix). */
    private array $knownTypes = [];

    /**
     * Parent types for each known type (direct `rdfs:subClassOf` links).
     *
     * @var array<string, list<string>>
     */
    private array $typeParents = [];

    /** @var array<string, true> All known schema.org property names (short form). */
    private array $knownProperties = [];

    /**
     * Allowed domain types per property.
     *
     * @var array<string, array<string, true>>
     */
    private array $propertyDomains = [];

    /**
     * @param Vocabulary $vocabulary Bundled schema.org vocabulary version to use for
     *                               validation. Defaults to the latest bundled version.
     */
    public function __construct(
        Vocabulary $vocabulary = Vocabulary::V20260226,
    ) {
        $this->vocabulary = $vocabulary;
    }

    /**
     * Validates JSON-LD input against the schema.org vocabulary.
     *
     * Accepts either a decoded PHP array or a raw JSON string.
     * Returns an immutable {@see ValidationResult} that holds all errors (if
     * any). A fresh {@see ValidationEngine} is created for every call, so
     * no mutable state is shared between invocations.
     *
     * @param string|array<mixed> $jsonLd Decoded JSON-LD array or raw JSON string.
     * @param bool $strictRequireType When `true`, every JSON-LD node that is missing
     *                                a `@type` key is treated as a validation error.
     *                                **Default (`false`)** — nodes without `@type` are
     *                                silently accepted. **Strict (`true`)** — every node
     *                                must declare a `@type`, which is a requirement for
     *                                many SEO and rich-snippet use cases.
     */
    public function validate(string|array $jsonLd, bool $strictRequireType = false): ValidationResult
    {
        // Decode JSON string if needed — early return on syntax errors.
        if (is_string($jsonLd)) {
            if (function_exists('json_validate') && !json_validate($jsonLd)) {
                return new ValidationResult(null, ['Invalid JSON syntax.']);
            }

            try {
                $jsonLd = json_decode($jsonLd, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                return new ValidationResult(null, ['Invalid JSON: ' . $e->getMessage()]);
            }
        }

        if (!is_array($jsonLd)) {
            return new ValidationResult(null, ['Root must be a JSON object.']);
        }

        $this->ensureVocabularyLoaded();

        return (new ValidationEngine(
            knownTypes: $this->knownTypes,
            typeParents: $this->typeParents,
            knownProperties: $this->knownProperties,
            propertyDomains: $this->propertyDomains,
            strictRequireType: $strictRequireType,
        ))->run($jsonLd);
    }

    // -------------------------------------------------------------------------
    // Vocabulary loading (parsed once, then immutable)
    // -------------------------------------------------------------------------

    /**
     * Loads and parses the schema.org vocabulary unless already done.
     *
     * @throws RuntimeException When the vocabulary file cannot be read or parsed.
     */
    private function ensureVocabularyLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $raw = $this->readVocabRaw();
        $doc = json_decode($raw, true);

        if (!is_array($doc) || !isset($doc['@graph']) || !is_array($doc['@graph'])) {
            throw new RuntimeException(
                'schema.org vocabulary file is not valid JSON-LD (@graph missing).'
            );
        }

        foreach ($doc['@graph'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = $entry['@id'] ?? null;
            $type = $entry['@type'] ?? null;

            if (!is_string($id) || !is_string($type)) {
                continue;
            }

            if ($type === 'rdfs:Class') {
                $t = ValidationEngine::normalize($id);
                $this->knownTypes[$t] = true;
                $this->typeParents[$t] = $this->extractIdList($entry['rdfs:subClassOf'] ?? null);
                continue;
            }

            if ($type === 'rdf:Property') {
                $p = ValidationEngine::normalize($id);
                $this->knownProperties[$p] = true;

                foreach ($this->extractIdList($entry['schema:domainIncludes'] ?? null) as $domainId) {
                    $this->propertyDomains[$p][ValidationEngine::normalize($domainId)] = true;
                }
            }
        }

        $this->loaded = true;
    }

    /**
     * Returns the raw vocabulary JSON from the bundled definition file.
     *
     * @throws RuntimeException When the vocabulary file cannot be read.
     */
    private function readVocabRaw(): string
    {
        $path = $this->vocabulary->filePath();
        $raw = @file_get_contents($path);

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException(
                'Could not read bundled schema.org vocabulary file: ' . $path
            );
        }

        return $raw;
    }

    /**
     * Extracts `@id` strings from a schema.org `domainIncludes` / `subClassOf` value.
     *
     * Handles both a single `{"@id": "…"}` object and a list of such objects.
     *
     * @param mixed $value The raw value from the vocabulary JSON-LD entry.
     *
     * @return list<string>
     */
    private function extractIdList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        // Single object: {"@id": "schema:Thing"}
        if (isset($value['@id']) && is_string($value['@id'])) {
            return [$value['@id']];
        }

        // List of objects: [{"@id": "…"}, …]
        $out = [];
        foreach ($value as $v) {
            if (is_array($v) && isset($v['@id']) && is_string($v['@id'])) {
                $out[] = $v['@id'];
            }
        }
        return $out;
    }
}
