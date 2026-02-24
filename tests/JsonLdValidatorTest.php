<?php

declare(strict_types=1);

namespace Nadar\Tests\Schema;

use Nadar\Schema\JsonLdValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for {@see JsonLdValidator}.
 *
 * Integration tests that require a real schema.org vocabulary download are
 * grouped in the "integration" test group and are skipped unless explicitly
 * enabled (via environment variable SCHEMAORG_INTEGRATION_TESTS=1).
 */
final class JsonLdValidatorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the path to a stub vocabulary cache file used in unit tests.
     * The stub contains a minimal schema.org-like JSON-LD document with only
     * the types and properties needed by the test fixtures.
     */
    private function stubCachePath(): string
    {
        return sys_get_temp_dir() . '/schemaorg-stub-test.jsonld.cache';
    }

    /**
     * Writes a minimal schema.org vocabulary stub so tests run without network.
     *
     * Covered types:   Thing, CreativeWork, Course, Organization, Audience,
     *                  CourseInstance, AlignmentObject, PropertyValue
     * Covered properties (selection): name, description, url, image, provider,
     *                  courseCode, coursePrerequisites, occupationalCredentialAwarded,
     *                  numberOfCredits, startDate, endDate, maximumEnrollment,
     *                  minimumEnrollment, audience, hasCourseInstance, about,
     *                  learningResourceType, educationalAlignment, educationalCredentialAwarded,
     *                  courseOutline, skills, additionalProperty, educationalUse,
     *                  address, educationalRole, location, courseMode,
     *                  maximumAttendeeCapacity, minimumAttendeeCapacity,
     *                  enrollmentStatus, waitingListSize,
     *                  educationalFramework, targetName, targetDescription,
     *                  value
     */
    private function writeStubVocab(): void
    {
        $types = [
            ['@id' => 'schema:Thing',            'rdfs:subClassOf' => []],
            ['@id' => 'schema:CreativeWork',     'rdfs:subClassOf' => [['@id' => 'schema:Thing']]],
            ['@id' => 'schema:Course',           'rdfs:subClassOf' => [['@id' => 'schema:CreativeWork']]],
            ['@id' => 'schema:Organization',     'rdfs:subClassOf' => [['@id' => 'schema:Thing']]],
            ['@id' => 'schema:Audience',         'rdfs:subClassOf' => [['@id' => 'schema:Thing']]],
            ['@id' => 'schema:CourseInstance',   'rdfs:subClassOf' => [['@id' => 'schema:CreativeWork']]],
            ['@id' => 'schema:AlignmentObject',  'rdfs:subClassOf' => [['@id' => 'schema:Thing']]],
            ['@id' => 'schema:PropertyValue',    'rdfs:subClassOf' => [['@id' => 'schema:Thing']]],
            ['@id' => 'schema:Event',            'rdfs:subClassOf' => [['@id' => 'schema:Thing']]],
        ];

        // Each entry: [property, [domainTypes...]]
        $properties = [
            // Thing
            ['name',                          ['Thing']],
            ['description',                   ['Thing']],
            ['url',                           ['Thing']],
            ['image',                         ['Thing']],
            ['additionalProperty',            ['Thing']],
            // CreativeWork
            ['about',                         ['CreativeWork']],
            ['provider',                      ['CreativeWork']],
            ['learningResourceType',          ['CreativeWork']],
            ['educationalAlignment',          ['CreativeWork']],
            ['educationalCredentialAwarded',  ['CreativeWork']],
            ['educationalUse',                ['CreativeWork']],
            // Course
            ['courseCode',                    ['Course']],
            ['coursePrerequisites',           ['Course']],
            ['occupationalCredentialAwarded', ['Course']],
            ['numberOfCredits',               ['Course']],
            ['hasCourseInstance',             ['Course']],
            ['courseOutline',                 ['Course']],
            ['skills',                        ['Course']],
            // CourseInstance
            ['courseMode',                    ['CourseInstance']],
            ['maximumAttendeeCapacity',       ['CourseInstance', 'Event']],
            ['minimumAttendeeCapacity',       ['CourseInstance', 'Event']],
            ['enrollmentStatus',              ['CourseInstance']],
            ['waitingListSize',               ['CourseInstance']],
            ['location',                      ['Event', 'CourseInstance']],
            // Course + CourseInstance share these via Event
            ['startDate',                     ['Event', 'CreativeWork']],
            ['endDate',                       ['Event', 'CreativeWork']],
            ['maximumEnrollment',             ['Course']],
            ['minimumEnrollment',             ['Course']],
            ['audience',                      ['CreativeWork']],
            // Organization
            ['address',                       ['Organization']],
            // Audience
            ['educationalRole',               ['Audience']],
            // AlignmentObject
            ['educationalFramework',          ['AlignmentObject']],
            ['targetName',                    ['AlignmentObject']],
            ['targetDescription',             ['AlignmentObject']],
            // PropertyValue
            ['value',                         ['PropertyValue']],
        ];

        $graph = [];

        foreach ($types as $t) {
            $entry = ['@id' => $t['@id'], '@type' => 'rdfs:Class'];
            if (!empty($t['rdfs:subClassOf'])) {
                $entry['rdfs:subClassOf'] = $t['rdfs:subClassOf'];
            }
            $graph[] = $entry;
        }

        foreach ($properties as [$prop, $domains]) {
            $domainList = array_map(
                static fn(string $d) => ['@id' => 'schema:' . $d],
                $domains
            );
            $graph[] = [
                '@id'                   => 'schema:' . $prop,
                '@type'                 => 'rdf:Property',
                'schema:domainIncludes' => count($domainList) === 1 ? $domainList[0] : $domainList,
            ];
        }

        $vocab = json_encode(['@graph' => $graph], JSON_THROW_ON_ERROR);
        file_put_contents($this->stubCachePath(), $vocab);
    }

    /**
     * Creates a validator pre-loaded with the stub vocabulary.
     * No network access is required.
     */
    private function makeValidator(bool $strictRequireType = false): JsonLdValidator
    {
        $this->writeStubVocab();
        return new JsonLdValidator(
            cacheFile: $this->stubCachePath(),
            strictRequireType: $strictRequireType,
        );
    }

    // -------------------------------------------------------------------------
    // Basic API tests
    // -------------------------------------------------------------------------

    public function testInitialStateHasNoErrors(): void
    {
        $validator = new JsonLdValidator(cacheFile: $this->stubCachePath());
        self::assertFalse($validator->hasErrors());
        self::assertSame([], $validator->getErrors());
        self::assertSame('', $validator->getErrorsAsString());
    }

    public function testValidJsonLdReturnsTrue(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
            'name'     => 'Introduction to PHP',
            'url'      => 'https://example.com/php-course',
        ]);

        self::assertTrue($result);
        self::assertFalse($validator->hasErrors());
        self::assertSame([], $validator->getErrors());
    }

    public function testInvalidJsonStringReturnsFalse(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate('{not valid json}');

        self::assertFalse($result);
        self::assertTrue($validator->hasErrors());
    }

    public function testNonObjectRootReturnsFalse(): void
    {
        $validator = $this->makeValidator();

        // A JSON string (not an object) decoded will be a non-array.
        $result = $validator->validate('"just a string"');

        self::assertFalse($result);
        self::assertStringContainsString('Root must be a JSON object', $validator->getErrorsAsString());
    }

    // -------------------------------------------------------------------------
    // Unknown @type
    // -------------------------------------------------------------------------

    public function testUnknownTypeIsReported(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'DummyOrganization',
            'name'     => 'Test',
        ]);

        self::assertFalse($result);
        $errors = $validator->getErrors();
        self::assertNotEmpty($errors);
        self::assertStringContainsString('DummyOrganization', implode(' ', $errors));
    }

    // -------------------------------------------------------------------------
    // Unknown properties
    // -------------------------------------------------------------------------

    public function testUnknownPropertyIsReported(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context'    => 'https://schema.org',
            '@type'       => 'Course',
            'nonExistent' => 'value',
        ]);

        self::assertFalse($result);
        self::assertStringContainsString('nonExistent', $validator->getErrorsAsString());
    }

    // -------------------------------------------------------------------------
    // Wrong domain
    // -------------------------------------------------------------------------

    public function testPropertyOnWrongTypeIsReported(): void
    {
        $validator = $this->makeValidator();

        // 'courseCode' belongs to Course, not to Organization.
        $result = $validator->validate([
            '@context'   => 'https://schema.org',
            '@type'      => 'Organization',
            'courseCode' => 'XYZ-101',
        ]);

        self::assertFalse($result);
        self::assertStringContainsString('courseCode', $validator->getErrorsAsString());
    }

    // -------------------------------------------------------------------------
    // Inherited properties (subtype hierarchy)
    // -------------------------------------------------------------------------

    public function testPropertiesInheritedFromParentTypeAreAllowed(): void
    {
        $validator = $this->makeValidator();

        // 'name' and 'description' are defined on Thing; Course → CreativeWork → Thing.
        $result = $validator->validate([
            '@context'    => 'https://schema.org',
            '@type'       => 'Course',
            'name'        => 'A course',
            'description' => 'Some description',
        ]);

        self::assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // Nested objects
    // -------------------------------------------------------------------------

    public function testNestedObjectsAreValidated(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
            'name'     => 'A course',
            'provider' => [
                '@type'      => 'DummyOrganization',  // unknown type → error
                'name'       => 'Provider Name',
            ],
        ]);

        self::assertFalse($result);
        $errors = $validator->getErrors();
        self::assertNotEmpty($errors);
        $errorString = implode(' ', $errors);
        self::assertStringContainsString('DummyOrganization', $errorString);
    }

    public function testNestedValidObjectPassesValidation(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
            'name'     => 'A course',
            'provider' => [
                '@type' => 'Organization',
                'name'  => 'Provider Name',
            ],
        ]);

        self::assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // @graph support
    // -------------------------------------------------------------------------

    public function testGraphArrayIsValidated(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type' => 'Course',
                    'name'  => 'Graph Course',
                ],
                [
                    '@type'    => 'Organization',
                    'name'     => 'Acme Inc.',
                    'address'  => '123 Main St',
                ],
            ],
        ]);

        self::assertTrue($result);
    }

    public function testGraphWithErrorsIsReported(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@graph'   => [
                [
                    '@type'      => 'Course',
                    'nonExistent' => 'bad',
                ],
            ],
        ]);

        self::assertFalse($result);
        self::assertStringContainsString('nonExistent', $validator->getErrorsAsString());
    }

    // -------------------------------------------------------------------------
    // strictRequireType
    // -------------------------------------------------------------------------

    public function testStrictModeReportsMissingType(): void
    {
        $validator = $this->makeValidator(strictRequireType: true);

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            'name'     => 'No type here',
        ]);

        self::assertFalse($result);
        self::assertStringContainsString('Missing @type', $validator->getErrorsAsString());
    }

    public function testNonStrictModeAllowsMissingType(): void
    {
        $validator = $this->makeValidator(strictRequireType: false);

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            'name'     => 'No type here',
        ]);

        // 'name' has no domain restriction violation since types === [].
        // The only check is unknown property, and 'name' is known.
        self::assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // getErrorsAsString separator
    // -------------------------------------------------------------------------

    public function testGetErrorsAsStringSeparator(): void
    {
        $validator = $this->makeValidator();

        $validator->validate([
            '@context'  => 'https://schema.org',
            '@type'     => 'Course',
            'badProp1'  => 'x',
            'badProp2'  => 'y',
        ]);

        $pipe = $validator->getErrorsAsString('|');
        self::assertStringContainsString('|', $pipe);
        self::assertStringContainsString('badProp1', $pipe);
        self::assertStringContainsString('badProp2', $pipe);
    }

    // -------------------------------------------------------------------------
    // Multiple @type values
    // -------------------------------------------------------------------------

    public function testMultipleTypesUnionDomainCheck(): void
    {
        $validator = $this->makeValidator();

        // 'courseCode' is valid for Course. If @type is [Course, Organization],
        // the property should be accepted.
        $result = $validator->validate([
            '@context'   => 'https://schema.org',
            '@type'      => ['Course', 'Organization'],
            'name'       => 'Dual type',
            'courseCode' => 'XYZ',
        ]);

        self::assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // Full erroneous fixture from the problem statement
    // -------------------------------------------------------------------------

    public function testFullCourseFixtureWithDummyTypesHasErrors(): void
    {
        $validator = $this->makeValidator();

        $jsonLd = <<<'JSON'
        {
          "@context": "https://schema.org",
          "@type": "Course",
          "name": "Dummy course name",
          "description": "Dummy description text.",
          "provider": {
            "@type": "DummyOrganization",
            "name": "Dummy provider name",
            "address": "Dummy address"
          },
          "url": "https://example.com/dummy-course",
          "image": "https://example.com/dummy-image.jpg",
          "courseCode": "00.00000",
          "coursePrerequisites": "Dummy prerequisites",
          "occupationalCredentialAwarded": "Dummy credential",
          "numberOfCredits": 0,
          "startDate": "2000-01-01T00:00:00Z",
          "endDate": "2000-01-02T00:00:00Z",
          "maximumEnrollment": 0,
          "minimumEnrollment": 0,
          "audience": {
            "@type": "DummyAudience",
            "educationalRole": "Dummy audience role"
          },
          "hasCourseInstance": [
            {
              "@type": "DummyCourseInstance",
              "location": "Dummy location",
              "courseMode": "Dummy mode",
              "maximumAttendeeCapacity": 0,
              "minimumAttendeeCapacity": 0,
              "enrollmentStatus": "https://example.com/dummy-status",
              "waitingListSize": 0
            }
          ],
          "about": ["Dummy topic 1", "Dummy topic 2"],
          "learningResourceType": "Dummy resource type",
          "educationalAlignment": {
            "@type": "DummyAlignmentObject",
            "educationalFramework": "Dummy framework",
            "targetName": "Dummy target name",
            "targetDescription": "Dummy target description."
          },
          "educationalCredentialAwarded": "Dummy additional credential info",
          "courseOutline": ["Dummy outline item 1"],
          "skills": ["Dummy skills text."],
          "additionalProperty": [
            {
              "@type": "DummyPropertyValue",
              "name": "Dummy property name 1",
              "value": "Dummy property value 1"
            }
          ],
          "educationalUse": ["Dummy use 1"]
        }
        JSON;

        $result = $validator->validate($jsonLd);

        self::assertFalse($result);
        self::assertTrue($validator->hasErrors());

        $errors = $validator->getErrors();
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $errorStr = $validator->getErrorsAsString();
        // All "Dummy*" types must be reported as unknown.
        self::assertStringContainsString('DummyOrganization', $errorStr);
        self::assertStringContainsString('DummyAudience', $errorStr);
        self::assertStringContainsString('DummyCourseInstance', $errorStr);
        self::assertStringContainsString('DummyAlignmentObject', $errorStr);
        self::assertStringContainsString('DummyPropertyValue', $errorStr);
    }

    // -------------------------------------------------------------------------
    // Clean fixture (all real schema.org types)
    // -------------------------------------------------------------------------

    public function testFullCourseFixtureWithRealTypesHasNoErrors(): void
    {
        $validator = $this->makeValidator();

        $jsonLd = [
            '@context'                     => 'https://schema.org',
            '@type'                        => 'Course',
            'name'                         => 'Introduction to PHP',
            'description'                  => 'A beginner PHP course.',
            'url'                          => 'https://example.com/php',
            'image'                        => 'https://example.com/php.jpg',
            'courseCode'                   => 'PHP-101',
            'coursePrerequisites'          => 'None',
            'occupationalCredentialAwarded' => 'Certificate',
            'numberOfCredits'              => 3,
            'startDate'                    => '2024-01-01',
            'endDate'                      => '2024-06-01',
            'maximumEnrollment'            => 30,
            'minimumEnrollment'            => 5,
            'about'                        => ['PHP', 'Web Development'],
            'learningResourceType'         => 'Course',
            'educationalCredentialAwarded' => 'Certificate of Completion',
            'educationalUse'               => ['Professional Development'],
            'provider'                     => [
                '@type'   => 'Organization',
                'name'    => 'PHP Academy',
                'address' => '1 PHP Street',
            ],
            'audience'                     => [
                '@type'           => 'Audience',
                'educationalRole' => 'student',
            ],
            'hasCourseInstance'            => [
                [
                    '@type'                   => 'CourseInstance',
                    'location'                => 'Online',
                    'courseMode'              => 'online',
                    'maximumAttendeeCapacity' => 100,
                    'minimumAttendeeCapacity' => 1,
                    'enrollmentStatus'        => 'https://schema.org/LimitedAvailability',
                    'waitingListSize'         => 10,
                ],
            ],
            'educationalAlignment'         => [
                '@type'              => 'AlignmentObject',
                'educationalFramework' => 'CEFR',
                'targetName'         => 'B2',
                'targetDescription'  => 'Upper intermediate',
            ],
            'courseOutline'                => ['Module 1', 'Module 2'],
            'skills'                       => ['PHP basics'],
            'additionalProperty'           => [
                [
                    '@type' => 'PropertyValue',
                    'name'  => 'difficulty',
                    'value' => 'beginner',
                ],
            ],
        ];

        $result = $validator->validate($jsonLd);

        self::assertTrue($result, implode("\n", $validator->getErrors()));
    }

    // -------------------------------------------------------------------------
    // Vocabulary cache
    // -------------------------------------------------------------------------

    public function testVocabularyIsReusedAcrossCalls(): void
    {
        $validator = $this->makeValidator();

        // First call loads the vocabulary.
        $validator->validate(['@context' => 'https://schema.org', '@type' => 'Course', 'name' => 'A']);

        // Second call must not throw even though writeStubVocab is not called again.
        $result = $validator->validate(['@context' => 'https://schema.org', '@type' => 'Course', 'name' => 'B']);
        self::assertTrue($result);
    }

    public function testMissingCacheFileTriggersException(): void
    {
        // Point at a non-existent cache and a file:// URL that does not exist so
        // the download helper fails immediately (no network timeout).
        $validator = new JsonLdValidator(
            cacheFile: '/tmp/nonexistent-cache-file-' . uniqid() . '.jsonld',
            vocabUrl: 'file:///tmp/nonexistent-vocab-' . uniqid() . '.jsonld',
        );

        $this->expectException(RuntimeException::class);
        $validator->validate(['@type' => 'Course']);
    }

    // -------------------------------------------------------------------------
    // Integration tests (skipped unless SCHEMAORG_INTEGRATION_TESTS=1)
    // -------------------------------------------------------------------------

    /**
     * @group integration
     */
    #[Group('integration')]
    public function testRealSchemaOrgVocabularyDownload(): void
    {
        if (getenv('SCHEMAORG_INTEGRATION_TESTS') !== '1') {
            self::markTestSkipped('Set SCHEMAORG_INTEGRATION_TESTS=1 to run integration tests.');
        }

        $cacheFile = sys_get_temp_dir() . '/schemaorg-integration-test.jsonld.cache';
        @unlink($cacheFile);

        $validator = new JsonLdValidator(cacheFile: $cacheFile);

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
            'name'     => 'Real vocabulary test',
        ]);

        self::assertTrue($result, implode("\n", $validator->getErrors()));
    }
}
