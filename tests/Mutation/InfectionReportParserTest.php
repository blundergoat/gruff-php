<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Mutation;

use GruffPhp\Mutation\InfectionReportParser;
use GruffPhp\Mutation\MutationReportException;
use PHPUnit\Framework\TestCase;

final class InfectionReportParserTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../..';

    public function testParsesFullInfectionJsonReport(): void
    {
        $report = (new InfectionReportParser(self::PROJECT_ROOT))->parse(
            'tests/Fixtures/M14/Infection/infection-valid.json',
        );

        self::assertSame('tests/Fixtures/M14/Infection/infection-valid.json', $report->reportPath);
        self::assertSame(4, $report->totalMutants());
        self::assertSame(50.0, $report->msi());
        self::assertSame(50.0, $report->coveredMsi());
        self::assertSame(100.0, $report->coverageRate());
        self::assertCount(2, $report->survivedMutants());

        $fileSummaries = $report->fileSummaries();

        self::assertCount(1, $fileSummaries);
        self::assertSame('tests/Fixtures/M14/Source/OrderCalculator.php', $fileSummaries[0]->filePath);
        self::assertSame(4, $fileSummaries[0]->totalMutants);
        self::assertSame(2, $fileSummaries[0]->survivedMutants);
        self::assertSame(50.0, $fileSummaries[0]->msi);
    }

    public function testRejectsMalformedInfectionJsonReport(): void
    {
        $this->expectException(MutationReportException::class);
        $this->expectExceptionMessage('contains a non-numeric stats value');

        (new InfectionReportParser(self::PROJECT_ROOT))->parse('tests/Fixtures/M14/Infection/infection-malformed.json');
    }
}
