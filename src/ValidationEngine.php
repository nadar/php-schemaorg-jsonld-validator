<?php

declare(strict_types=1);

namespace Nadar\Schema;

/**
 * Internal validation engine — created fresh for every {@see JsonLdValidator::validate()} call.
 *
 * All per-validation mutable state lives here (error list, ancestor cache),
 * ensuring that the owning {@see JsonLdValidator} remains stateless between
 * calls. The vocabulary data is received as read-only arrays and is never
 * modified.
 *
 * @internal This class is an implementation detail and not part of the public API.
 */
final class ValidationEngine
{
    /** @var list<string> Collected validation error messages. */
    private array $errors = [];

    /**
     * Memoisation cache for type-ancestor closures (per-validation).
     *
     * @var array<string, list<string>>
     */
    private array $ancestorsCache = [];

    /**
     * @param array<string, true>               $knownTypes       All known schema.org type names (short form).
     * @param array<string, list<string>>        $typeParents      Direct parent types per type (`rdfs:subClassOf`).
     * @param array<string, true>               $knownProperties  All known schema.org property names (short form).
     * @param array<string, array<string, true>> $propertyDomains  Allowed domain types per property.
     * @param bool                               $strictRequireType When `true`, nodes without `@type` are errors.
     */
    public function __construct(
        private readonly array $knownTypes,
        private readonly array $typeParents,
        private readonly array $knownProperties,
        private readonly array $propertyDomains,
        private readonly bool $strictRequireType,
    ) {}

    /**
     * Validates the given decoded JSON-LD data and returns an immutable result.
     *
     * @param array<mixed> $data The decoded JSON-LD array.
     */
    public function run(array $data): ValidationResult
    {
        $this->validateNode($data, '$');

        return new ValidationResult($data, $this->errors);
    }

    // -------------------------------------------------------------------------
    // Validation logic
    // -------------------------------------------------------------------------

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
            $types[] = self::normalize($raw);
        } elseif (is_array($raw)) {
            foreach ($raw as $x) {
                if (is_string($x) && $x !== '') {
                    $types[] = self::normalize($x);
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
                $p = self::normalize($parentId);
                if (!isset($seen[$p])) {
                    $stack[] = $p;
                }
            }
        }

        return $this->ancestorsCache[$type] = array_keys($seen);
    }

    // -------------------------------------------------------------------------
    // Pure helpers
    // -------------------------------------------------------------------------

    /**
     * Strips well-known schema.org URI prefixes from a type or property identifier,
     * returning just the local name (e.g. `"schema:Person"` → `"Person"`).
     */
    public static function normalize(string $raw): string
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
