<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\SensitiveData;

/**
 * Fixture proving PHI/PII rules require an identifier-shaped *value*, not just a
 * health/identity context keyword: schema field names and reserved-example
 * contacts must not fire (scope-hardening guard). Committed identifier-shaped
 * values (e.g. a real-looking SSN literal) are intentionally still flagged
 * elsewhere — see profile-data.json.
 */
final class PhiSchemaPlaceholdersFixture
{
    /**
     * Field-name schema with type strings only — PHI context keywords, no values.
     *
     * @var array<string, string>
     */
    public const SCHEMA = [
        'ssn' => 'string',
        'patient_mrn' => 'nullable|string',
        'medicare_number' => 'integer',
        'tax_file_number' => 'string',
    ];

    /**
     * Reserved-example contacts that must not be treated as committed PII.
     *
     * @return array<string, string>
     */
    public function reservedExamples(): array
    {
        return [
            'patient_email' => 'jane@example.com',
            'support_email' => 'help@example.org',
        ];
    }
}
