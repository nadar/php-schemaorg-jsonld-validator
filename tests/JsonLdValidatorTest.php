<?php

declare(strict_types=1);

namespace Nadar\Tests\Schema;

use Nadar\Schema\Vocabulary;
use Nadar\Schema\JsonLdValidator;
use Nadar\Schema\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see JsonLdValidator} and {@see ValidationResult}.
 *
 * All tests use the bundled schema.org vocabulary (no network required).
 * One integration test verifies the bundled vocabulary loads and validates
 * a real Course fixture end-to-end.
 */
final class JsonLdValidatorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a validator backed by the bundled vocabulary.
     */
    private function makeValidator(): JsonLdValidator
    {
        return new JsonLdValidator();
    }

    // -------------------------------------------------------------------------
    // ValidationResult basics
    // -------------------------------------------------------------------------

    public function testValidationResultIsValidWhenNoErrors(): void
    {
        $result = new ValidationResult();
        self::assertTrue($result->isValid());
        self::assertFalse($result->hasErrors());
        self::assertSame([], $result->getErrors());
        self::assertSame('', $result->getErrorsAsString());
        self::assertSame('Valid.', (string) $result);
        self::assertNull($result->getJsonLd());
    }

    public function testValidationResultWithErrors(): void
    {
        $result = new ValidationResult(null, ['Error one.', 'Error two.']);
        self::assertFalse($result->isValid());
        self::assertTrue($result->hasErrors());
        self::assertSame(['Error one.', 'Error two.'], $result->getErrors());
        self::assertSame("Error one.\nError two.", $result->getErrorsAsString());
        self::assertSame("Error one.\nError two.", (string) $result);
    }

    public function testValidationResultCustomSeparator(): void
    {
        $result = new ValidationResult(null, ['A', 'B', 'C']);
        self::assertSame('A|B|C', $result->getErrorsAsString('|'));
    }

    public function testValidationResultStoresJsonLd(): void
    {
        $data = ['@type' => 'Course', 'name' => 'Test'];
        $result = new ValidationResult($data);
        self::assertSame($data, $result->getJsonLd());
    }

    // -------------------------------------------------------------------------
    // Basic API tests
    // -------------------------------------------------------------------------

    public function testValidateReturnsValidationResult(): void
    {
        $validator = new JsonLdValidator();
        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
            'name'     => 'Test',
        ]);

        self::assertInstanceOf(ValidationResult::class, $result);
        self::assertTrue($result->isValid());
    }

    public function testValidJsonLdReturnsValidResult(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
            'name'     => 'Introduction to PHP',
            'url'      => 'https://example.com/php-course',
        ]);

        self::assertTrue($result->isValid());
        self::assertFalse($result->hasErrors());
        self::assertSame([], $result->getErrors());
    }

    public function testInvalidJsonStringReturnsInvalidResult(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate('{not valid json}');

        self::assertFalse($result->isValid());
        self::assertTrue($result->hasErrors());
    }

    public function testNonObjectRootReturnsError(): void
    {
        $validator = $this->makeValidator();

        // A JSON string (not an object) decoded will be a non-array.
        $result = $validator->validate('"just a string"');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Root must be a JSON object', $result->getErrorsAsString());
    }

    // -------------------------------------------------------------------------
    // Multiple independent validations (no shared mutable state)
    // -------------------------------------------------------------------------

    public function testMultipleValidationsAreIndependent(): void
    {
        $validator = $this->makeValidator();

        // First validation: invalid document
        $resultA = $validator->validate([
            '@context'  => 'https://schema.org',
            '@type'     => 'Course',
            'badProp1'  => 'x',
        ]);

        // Second validation: valid document
        $resultB = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
            'name'     => 'PHP 101',
        ]);

        // Third validation: different invalid document
        $resultC = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'DummyOrganization',
            'name'     => 'Test',
        ]);

        // Each result is independent.
        self::assertFalse($resultA->isValid());
        self::assertStringContainsString('badProp1', $resultA->getErrorsAsString());

        self::assertTrue($resultB->isValid());
        self::assertSame([], $resultB->getErrors());

        self::assertFalse($resultC->isValid());
        self::assertStringContainsString('DummyOrganization', $resultC->getErrorsAsString());
        // resultC must NOT contain errors from resultA
        self::assertStringNotContainsString('badProp1', $resultC->getErrorsAsString());
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

        self::assertFalse($result->isValid());
        $errors = $result->getErrors();
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

        self::assertFalse($result->isValid());
        self::assertStringContainsString('nonExistent', $result->getErrorsAsString());
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

        self::assertFalse($result->isValid());
        self::assertStringContainsString('courseCode', $result->getErrorsAsString());
    }

    // -------------------------------------------------------------------------
    // Inherited properties (subtype hierarchy)
    // -------------------------------------------------------------------------

    public function testPropertiesInheritedFromParentTypeAreAllowed(): void
    {
        $validator = $this->makeValidator();

        // 'name' and 'description' are defined on Thing; Course -> CreativeWork -> Thing.
        $result = $validator->validate([
            '@context'    => 'https://schema.org',
            '@type'       => 'Course',
            'name'        => 'A course',
            'description' => 'Some description',
        ]);

        self::assertTrue($result->isValid());
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
                '@type'      => 'DummyOrganization',  // unknown type -> error
                'name'       => 'Provider Name',
            ],
        ]);

        self::assertFalse($result->isValid());
        $errors = $result->getErrors();
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

        self::assertTrue($result->isValid());
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

        self::assertTrue($result->isValid());
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

        self::assertFalse($result->isValid());
        self::assertStringContainsString('nonExistent', $result->getErrorsAsString());
    }

    // -------------------------------------------------------------------------
    // strictRequireType
    // -------------------------------------------------------------------------

    public function testStrictModeReportsMissingType(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            'name'     => 'No type here',
        ], strictRequireType: true);

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Missing @type', $result->getErrorsAsString());
    }

    public function testNonStrictModeAllowsMissingType(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            'name'     => 'No type here',
        ], strictRequireType: false);

        // 'name' has no domain restriction violation since types === [].
        // The only check is unknown property, and 'name' is known.
        self::assertTrue($result->isValid());
    }

    // -------------------------------------------------------------------------
    // getErrorsAsString separator
    // -------------------------------------------------------------------------

    public function testGetErrorsAsStringSeparator(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context'  => 'https://schema.org',
            '@type'     => 'Course',
            'badProp1'  => 'x',
            'badProp2'  => 'y',
        ]);

        $pipe = $result->getErrorsAsString('|');
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

        self::assertTrue($result->isValid());
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

        self::assertFalse($result->isValid());
        self::assertTrue($result->hasErrors());

        $errors = $result->getErrors();
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $errorStr = $result->getErrorsAsString();
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
            '@context'                      => 'https://schema.org',
            '@type'                         => 'Course',
            'name'                          => 'Introduction to PHP',
            'description'                   => 'A beginner PHP course.',
            'url'                           => 'https://example.com/php',
            'image'                         => 'https://example.com/php.jpg',
            'courseCode'                    => 'PHP-101',
            'coursePrerequisites'           => 'None',
            'occupationalCredentialAwarded' => 'Certificate',
            'numberOfCredits'               => 3,
            'about'                         => ['PHP', 'Web Development'],
            'learningResourceType'          => 'Course',
            'educationalCredentialAwarded'  => 'Certificate of Completion',
            'educationalUse'                => ['Professional Development'],
            'provider'                      => [
                '@type'   => 'Organization',
                'name'    => 'PHP Academy',
                'address' => '1 PHP Street',
            ],
            'audience'                      => [
                '@type' => 'EducationalAudience',
                'educationalRole' => 'student',
            ],
            'hasCourseInstance'             => [
                [
                    '@type'                   => 'CourseInstance',
                    'name'                    => 'Online session',
                    'location'                => 'Online',
                    'courseMode'              => 'online',
                    'startDate'               => '2024-01-01',
                    'endDate'                 => '2024-06-01',
                    'maximumAttendeeCapacity' => 100,
                ],
            ],
            'educationalAlignment'          => [
                '@type'              => 'AlignmentObject',
                'educationalFramework' => 'CEFR',
                'targetName'         => 'B2',
                'targetDescription'  => 'Upper intermediate',
            ],
        ];

        $result = $validator->validate($jsonLd);

        self::assertTrue($result->isValid(), $result->getErrorsAsString());
    }

    // -------------------------------------------------------------------------
    // Vocabulary loading
    // -------------------------------------------------------------------------

    public function testVocabularyIsReusedAcrossCalls(): void
    {
        $validator = $this->makeValidator();

        // First call loads the vocabulary.
        $validator->validate(['@context' => 'https://schema.org', '@type' => 'Course', 'name' => 'A']);

        // Second call reuses the in-memory vocabulary without re-reading from disk.
        $result = $validator->validate(['@context' => 'https://schema.org', '@type' => 'Course', 'name' => 'B']);
        self::assertTrue($result->isValid());
    }

    public function testVocabularyFilePaths(): void
    {
        foreach (Vocabulary::cases() as $vocab) {
            self::assertFileExists(
                $vocab->filePath(),
                "Bundled vocabulary file for {$vocab->name} is missing."
            );
        }
    }

    // -------------------------------------------------------------------------
    // ValidationResult __toString
    // -------------------------------------------------------------------------

    public function testValidationResultToStringValid(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
            'name'     => 'PHP 101',
        ]);

        self::assertSame('Valid.', (string) $result);
    }

    public function testValidationResultToStringInvalid(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate([
            '@context'  => 'https://schema.org',
            '@type'     => 'Course',
            'badProp'   => 'x',
        ]);

        $str = (string) $result;
        self::assertStringContainsString('badProp', $str);
    }

    // -------------------------------------------------------------------------
    // getJsonLd()
    // -------------------------------------------------------------------------

    public function testGetJsonLdReturnsDecodedArrayFromJsonString(): void
    {
        $validator = $this->makeValidator();

        $json = '{"@context":"https://schema.org","@type":"Course","name":"PHP 101"}';
        $result = $validator->validate($json);

        self::assertTrue($result->isValid());
        $data = $result->getJsonLd();
        self::assertIsArray($data);
        self::assertSame('Course', $data['@type']);
        self::assertSame('PHP 101', $data['name']);
    }

    public function testGetJsonLdReturnsSameArrayWhenArrayPassed(): void
    {
        $validator = $this->makeValidator();

        $input = [
            '@context' => 'https://schema.org',
            '@type'    => 'Course',
            'name'     => 'A course',
        ];
        $result = $validator->validate($input);

        self::assertSame($input, $result->getJsonLd());
    }

    public function testGetJsonLdReturnsNullForInvalidJson(): void
    {
        $validator = $this->makeValidator();

        $result = $validator->validate('"just a string"');

        self::assertFalse($result->isValid());
        self::assertNull($result->getJsonLd());
    }
}
