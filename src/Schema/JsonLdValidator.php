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
 * Usage:
 * ```php
 * $validator = new \Nadar\Schema\JsonLdValidator();
 * $valid = $validator->validate($myJsonLdArray);
 *
 * if (!$valid) {
 *     print_r($validator->getErrors());
 *     echo $validator->getErrorsAsString();
 * }
 * ```
 *
 * The schema.org vocabulary is read from a bundled JSON-LD file that ships with
 * this package. Use {@see DefinitionVersion} to select a specific bundled
 * version if needed.
 */
final class JsonLdValidator
{
    /** Bundled vocabulary version to validate against. */
    private readonly DefinitionVersion $version;

    /**
     * When `true`, nodes without a `@type` key generate a validation error.
     */
    private readonly bool $strictRequireType;

    /** @var list<string> Collected validation error messages. */
    private array $errors = [];

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
     * Memoisation cache for type-ancestor closures.
     *
     * @var array<string, list<string>>
     */
    private array $ancestorsCache = [];

    /**
     * @param DefinitionVersion $version          Bundled vocabulary version to use for validation.
     *                                            Defaults to the latest bundled version.
     * @param bool              $strictRequireType When `true`, nodes missing `@type` are flagged.
     */
    public function __construct(
        DefinitionVersion $version = DefinitionVersion::V20260226,
        bool $strictRequireType = false,
    ) {
        $this->version = $version;
        $this->strictRequireType = $strictRequireType;
    }

    /**
     * Validates JSON-LD input against the schema.org vocabulary.
     *
     * Accepts either a decoded PHP array or a raw JSON string.
     * After calling this method, use {@see getErrors()} or {@see getErrorsAsString()}
     * to inspect any violations found.
     *
     * @param string|array<mixed> $jsonLd Decoded JSON-LD array or raw JSON string.
     *
     * @return bool `true` when no violations were found, `false` otherwise.
     */
    public function validate(string|array $jsonLd): bool
    {
        $this->errors = [];

        $data = is_string($jsonLd) ? $this->decodeJson($jsonLd) : $jsonLd;
        if (!is_array($data)) {
            $this->errors[] = 'Root must be a JSON object.';
            return false;
        }

        $this->ensureVocabularyLoaded();
        $this->validateNode($data, '$');

        return $this->errors === [];
    }

    /**
     * Returns `true` when the most recent {@see validate()} call produced errors.
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Returns all validation errors collected during the last {@see validate()} call.
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

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Decodes a raw JSON string, populating `$this->errors` on failure.
     */
    private function decodeJson(string $json): mixed
    {
        if (function_exists('json_validate') && !json_validate($json)) {
            $this->errors[] = 'Invalid JSON syntax.';
            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->errors[] = 'Invalid JSON: ' . $e->getMessage();
            return null;
        }
    }

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
                $t = $this->normalize($id);
                $this->knownTypes[$t] = true;
                $this->typeParents[$t] = $this->extractIdList($entry['rdfs:subClassOf'] ?? null);
                continue;
            }

            if ($type === 'rdf:Property') {
                $p = $this->normalize($id);
                $this->knownProperties[$p] = true;

                foreach ($this->extractIdList($entry['schema:domainIncludes'] ?? null) as $domainId) {
                    $this->propertyDomains[$p][$this->normalize($domainId)] = true;
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
        $path = $this->version->filePath();
        $raw = @file_get_contents($path);

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException(
                'Could not read bundled schema.org vocabulary file: ' . $path
            );
        }

        return $raw;
    }

    /**
     * Recursively validates a node, dispatching to {@see validateObject()} for
     * associative arrays and iterating list arrays element by element.
     */
    private function validateNode(mixed $node, string $path): void
    {
        if (!is_array($node)) {
            return;
        }

        if ($this->isAssocArray($node)) {
            $this->validateObject($node, $path);
            return;
        }

        foreach ($node as $i => $child) {
            $this->validateNode($child, $path . '[' . $i . ']');
        }
    }

    /**
     * Validates a single JSON-LD object node.
     *
     * Checks:
     *  - Recursively validates any embedded `@graph`.
     *  - Reports unknown `@type` values.
     *  - Reports unknown properties.
     *  - Reports properties whose domain does not include any of the node's types.
     *
     * @param array<mixed> $obj  The decoded JSON-LD object.
     * @param string       $path Human-readable path expression for error messages.
     */
    private function validateObject(array $obj, string $path): void
    {
        if (isset($obj['@graph']) && is_array($obj['@graph'])) {
            $this->validateNode($obj['@graph'], $path . '.@graph');
        }

        $types = $this->extractTypes($obj, $path);

        if ($this->strictRequireType && $types === []) {
            $this->errors[] = $path . ': Missing @type.';
        }

        // Compute ancestor closure once per type so domain lookups are O(1).
        $typeClosures = [];
        foreach ($types as $t) {
            $typeClosures[$t] = $this->typeWithAncestors($t);
        }

        foreach ($obj as $key => $value) {
            $key = (string) $key;

            // JSON-LD keywords (@context, @type, @id, …) are not validated.
            if (str_starts_with($key, '@')) {
                continue;
            }

            if (!isset($this->knownProperties[$key])) {
                $this->errors[] = sprintf(
                    "%s: Unknown schema.org property '%s'.",
                    $path,
                    $key,
                );
            } elseif ($types !== [] && !$this->propertyAllowedForAnyType($key, $typeClosures)) {
                $this->errors[] = sprintf(
                    "%s: Property '%s' is not allowed for @type %s.",
                    $path,
                    $key,
                    implode(', ', $types),
                );
            }

            $this->validateNode($value, $path . '.' . $key);
        }
    }

    /**
     * Extracts the normalised type names from a JSON-LD node's `@type` field.
     *
     * Unknown type names are added to `$this->errors` as a side-effect.
     *
     * @param array<mixed> $obj  The JSON-LD node.
     * @param string       $path Path for error messages.
     *
     * @return list<string> Unique, normalised type names.
     */
    private function extractTypes(array $obj, string $path): array
    {
        $raw = $obj['@type'] ?? null;
        $types = [];

        if (is_string($raw) && $raw !== '') {
            $types[] = $this->normalize($raw);
        } elseif (is_array($raw)) {
            foreach ($raw as $x) {
                if (is_string($x) && $x !== '') {
                    $types[] = $this->normalize($x);
                }
            }
        }

        $types = array_values(array_unique($types));

        foreach ($types as $typeName) {
            if (!isset($this->knownTypes[$typeName])) {
                $this->errors[] = sprintf(
                    "%s: Unknown schema.org @type '%s'.",
                    $path,
                    $typeName,
                );
            }
        }

        return $types;
    }

    /**
     * Returns `true` when `$property` is valid for at least one of the given types,
     * taking the full type-inheritance hierarchy into account.
     *
     * @param array<string, list<string>> $typeClosures Map of type => ancestors (incl. itself).
     */
    private function propertyAllowedForAnyType(string $property, array $typeClosures): bool
    {
        $domains = $this->propertyDomains[$property] ?? null;
        if (!$domains) {
            return false;
        }

        foreach ($typeClosures as $ancestors) {
            foreach ($ancestors as $ancestor) {
                if (isset($domains[$ancestor])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns `$type` together with all of its ancestor types (breadth-first).
     *
     * Results are memoised in {@see $ancestorsCache}.
     *
     * @return list<string>
     */
    private function typeWithAncestors(string $type): array
    {
        if (isset($this->ancestorsCache[$type])) {
            return $this->ancestorsCache[$type];
        }

        $seen = [];
        $stack = [$type];

        while ($stack !== []) {
            $t = array_pop($stack);
            if (isset($seen[$t])) {
                continue;
            }
            $seen[$t] = true;

            foreach ($this->typeParents[$t] ?? [] as $parentId) {
                $p = $this->normalize($parentId);
                if (!isset($seen[$p])) {
                    $stack[] = $p;
                }
            }
        }

        return $this->ancestorsCache[$type] = array_keys($seen);
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

    /**
     * Strips well-known schema.org URI prefixes from a type or property identifier,
     * returning just the local name (e.g. `"schema:Person"` → `"Person"`).
     */
    private function normalize(string $raw): string
    {
        $raw = preg_replace('~^schema:~', '', $raw) ?? $raw;
        $raw = preg_replace('~^https?://schema\.org/~', '', $raw) ?? $raw;
        return ltrim($raw, '#');
    }

    /**
     * Returns `true` when `$arr` is a non-empty associative (map-like) array.
     *
     * @param array<mixed> $arr
     */
    private function isAssocArray(array $arr): bool
    {
        return $arr !== [] && array_keys($arr) !== range(0, count($arr) - 1);
    }

}
