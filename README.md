# php-schemaorg-jsonld-validator

A PHP 8.4+ library that validates JSON-LD structured data against the official [schema.org](https://schema.org) vocabulary — mirroring what [validator.schema.org](https://validator.schema.org/) checks for.

## What it does

The validator inspects a JSON-LD document (as a PHP array or raw JSON string) and reports:

1. **Unknown properties** — keys that are not part of the schema.org vocabulary.
2. **Wrong-domain properties** — properties used on a `@type` they do not belong to (the full `rdfs:subClassOf` type-inheritance hierarchy is respected, so subtype properties are always valid on parent types too).
3. **Unknown `@type` values** — type names that do not exist in the schema.org vocabulary.

The schema.org vocabulary ships **bundled** with this package as versioned JSON-LD definition files — no network access or caching required.

## Requirements

- PHP **8.4** or later
- The `json` extension (included in PHP by default)

## Installation

```bash
composer require nadar/php-schemaorg-jsonld-validator
```

## Quick start

```php
use Nadar\Schema\JsonLdValidator;

$validator = new JsonLdValidator();

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'Course',
    'name'     => 'Introduction to PHP',
    'url'      => 'https://example.com/php-course',
];

if ($validator->validate($jsonLd)) {
    echo 'Valid!';
} else {
    // Get errors as an array
    print_r($validator->getErrors());

    // …or as a single string (default separator: newline)
    echo $validator->getErrorsAsString();

    // Custom separator
    echo $validator->getErrorsAsString(' | ');
}
```

You can also pass a raw JSON string instead of a decoded array:

```php
$validator->validate('{"@context":"https://schema.org","@type":"Course","name":"PHP 101"}');
```

## API

### `new JsonLdValidator(DefinitionVersion $version = DefinitionVersion::V20260226, bool $strictRequireType = false)`

| Parameter            | Default                        | Description |
|----------------------|--------------------------------|-------------|
| `$version`           | `DefinitionVersion::V20260226` | Bundled schema.org vocabulary version to use. |
| `$strictRequireType` | `false`                        | When `true`, nodes without a `@type` are reported as errors. |

### `validate(string|array $jsonLd): bool`

Validates the JSON-LD input. Returns `true` when no violations are found. All violations from the previous call are cleared at the start of each new call.

### `hasErrors(): bool`

Returns `true` when the last `validate()` call found errors.

### `getErrors(): list<string>`

Returns all validation error messages as an array of strings.

### `getErrorsAsString(string $separator = "\n"): string`

Returns all validation error messages joined into a single string.

## Bundled vocabulary versions

The vocabulary is bundled inside `src/definitions/` as dated JSON-LD files and exposed via the `DefinitionVersion` enum. Each case maps to one bundled file:

| Enum case                    | File                          | schema.org version |
|------------------------------|-------------------------------|---------------------|
| `DefinitionVersion::V20260226` | `src/definitions/2026-02-26.jsonld` | v29.4 |

To pin to a specific version explicitly:

```php
use Nadar\Schema\DefinitionVersion;
use Nadar\Schema\JsonLdValidator;

$validator = new JsonLdValidator(version: DefinitionVersion::V20260226);
```

When a new schema.org release is bundled in a future version of this package, a new `DefinitionVersion` case will be added with an updated date. You can then choose when to adopt it by updating the `$version` argument.

## Example — detecting errors

The following JSON-LD uses a fictional `DummyOrganization` type that does not exist in schema.org:

```json
{
  "@context": "https://schema.org",
  "@type": "Course",
  "name": "Dummy course",
  "provider": {
    "@type": "DummyOrganization",
    "name": "Dummy provider"
  }
}
```

```php
$validator = new \Nadar\Schema\JsonLdValidator();
$validator->validate($jsonString);

print_r($validator->getErrors());
// Array
// (
//     [0] => $.provider: Unknown schema.org @type 'DummyOrganization'.
// )
```

## Running the tests

```bash
composer install
vendor/bin/phpunit --testdox
```

## License

MIT
