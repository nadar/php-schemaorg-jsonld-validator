# php-schemaorg-jsonld-validator

A PHP 8.4+ library that validates JSON-LD structured data against the official [schema.org](https://schema.org) vocabulary — mirroring what [validator.schema.org](https://validator.schema.org/) checks for.

## What it does

The validator inspects a JSON-LD document (as a PHP array or raw JSON string) and reports:

1. **Unknown properties** — keys that are not part of the schema.org vocabulary.
2. **Wrong-domain properties** — properties used on a `@type` they do not belong to (the full `rdfs:subClassOf` type-inheritance hierarchy is respected, so subtype properties are always valid on parent types too).
3. **Unknown `@type` values** — type names that do not exist in the schema.org vocabulary.

The schema.org vocabulary is downloaded once from the official JSON-LD release file and cached locally, so subsequent validations are instant and require no network access.

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

### `new JsonLdValidator(?string $cacheFile = null, ?string $vocabUrl = null, bool $strictRequireType = false)`

| Parameter           | Default                                              | Description |
|---------------------|------------------------------------------------------|-------------|
| `$cacheFile`        | `sys_get_temp_dir() . '/schemaorg-current-https.jsonld.cache'` | Path where the downloaded vocabulary is stored. |
| `$vocabUrl`         | Latest official schema.org JSON-LD release URL       | Override to use a specific schema.org version or a local file. |
| `$strictRequireType`| `false`                                              | When `true`, nodes without a `@type` are reported as errors. |

### `validate(string|array $jsonLd): bool`

Validates the JSON-LD input. Returns `true` when no violations are found. All violations from the previous call are cleared at the start of each new call.

### `hasErrors(): bool`

Returns `true` when the last `validate()` call found errors.

### `getErrors(): list<string>`

Returns all validation error messages as an array of strings.

### `getErrorsAsString(string $separator = "\n"): string`

Returns all validation error messages joined into a single string.

### `refreshVocabularyCache(): void`

Forces a fresh download of the schema.org vocabulary and replaces the local cache. Useful for scheduled updates or deployments.

## Vocabulary caching

On the first call to `validate()`, the library downloads the schema.org JSON-LD vocabulary from `https://schema.org/version/latest/schemaorg-current-https.jsonld` and writes it to a local cache file using an atomic rename. Subsequent calls read from the cache and parse the vocabulary into memory, keeping it there for the lifetime of the `JsonLdValidator` instance.

To keep the vocabulary up-to-date (schema.org releases updates periodically), call `refreshVocabularyCache()` in a scheduled task or as part of your deployment pipeline.

## Example — detecting errors

The following JSON-LD uses several fictional `Dummy*` types that do not exist in schema.org:

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

To run the integration test that performs a real vocabulary download, set the environment variable:

```bash
SCHEMAORG_INTEGRATION_TESTS=1 vendor/bin/phpunit --testdox
```

## License

MIT
