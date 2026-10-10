<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Size;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Config\RuleSettings;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleInterface;
use GruffPhp\Rules\Contracts\SourceTextRuleInterface;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Size\LimitBand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the two bands the banded size and complexity rules report in (FAMILY-CONTRACT.md section 12, "Size and complexity
 * findings in two bands"): just over its limit a unit gets an advisory do-not-add notice, and at twice its limit it keeps
 * the rule's severity and the advice to split or simplify. Each fixture is generated so its measure is known exactly.
 */
final class LimitBandTest extends TestCase
{
    /** Banded rule ids; the averages, halstead volume and maintainability index are routed by measurement instead. */
    private const BANDED = [
        'size.file-length', 'size.class-length', 'size.method-length', 'size.parameter-count', 'size.property-count',
        'size.public-method-count', 'complexity.cognitive', 'complexity.cyclomatic', 'complexity.nesting-depth',
    ];

    /** @var list<string> Temporary fixture paths removed after each test. */
    private array $paths = [];

    /**
     * Remove the generated fixtures.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            // A fixture the test never wrote has nothing to remove.
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Supplies, per rule, a fixture just over its default limit and one at twice it, with the advice each band gives.
     *
     * @return array<string, array{string, string, string, string, string}> - rule id, lower-band source, upper-band source, lower advice, upper advice
     */
    public static function bandCases(): array
    {
        return [
            'file length' => ['size.file-length', self::statements(1100), self::statements(2000), LimitBand::LOWER_FILE, LimitBand::SPLIT_FILE],
            'class length' => ['size.class-length', self::classOfLines(1100), self::classOfLines(2000), LimitBand::LOWER_CLASS, LimitBand::SPLIT_CLASS],
            'method length' => ['size.method-length', self::methodOfStatements(110), self::methodOfStatements(200), LimitBand::LOWER_METHOD, LimitBand::SPLIT_METHOD],
            'parameter count' => ['size.parameter-count', self::functionWithParameters(11), self::functionWithParameters(20), LimitBand::LOWER_PARAMETER, LimitBand::GROUP_PARAMETERS],
            'property count' => ['size.property-count', self::classWithProperties(16), self::classWithProperties(30), LimitBand::LOWER_PROPERTY, LimitBand::SPLIT_CLASS],
            'public method count' => ['size.public-method-count', self::classWithPublicMethods(26), self::classWithPublicMethods(50), LimitBand::LOWER_PUBLIC_METHOD, LimitBand::SPLIT_CLASS],
            'cognitive' => ['complexity.cognitive', self::methodWithBranches(21), self::methodWithBranches(40), LimitBand::LOWER_METHOD, LimitBand::SIMPLIFY_PATH],
            'cyclomatic' => ['complexity.cyclomatic', self::methodWithBranches(20), self::methodWithBranches(39), LimitBand::LOWER_METHOD, LimitBand::SIMPLIFY_PATH],
            'nesting depth' => ['complexity.nesting-depth', self::methodNestedTo(5), self::methodNestedTo(8), LimitBand::LOWER_METHOD, LimitBand::SIMPLIFY_PATH],
        ];
    }

    /**
     * Verify each banded rule reports the advisory notice just over its limit and its own severity at twice it.
     *
     * @param string $ruleId - Rule under test.
     * @param string $lowerSource - Source whose measure is just over the rule's default limit.
     * @param string $upperSource - Source whose measure is at twice the rule's default limit.
     * @param string $lowerAdvice - Advice the lower band gives.
     * @param string $upperAdvice - Advice the upper band gives.
     *
     * @return void
     */
    #[DataProvider('bandCases')]
    public function testEachRuleReportsInTwoBands(string $ruleId, string $lowerSource, string $upperSource, string $lowerAdvice, string $upperAdvice): void
    {
        $defaultSeverity = RuleRegistry::defaults()->get($ruleId)->definition()->severityThreshold?->severity;

        $lower = $this->onlyFinding($ruleId, $lowerSource);
        self::assertSame(LimitBand::LOWER, $lower->metadata[LimitBand::KEY]);
        self::assertSame(Severity::Advisory, $lower->severity);
        self::assertSame($lowerAdvice, $lower->remediation);

        $upper = $this->onlyFinding($ruleId, $upperSource);
        self::assertSame(LimitBand::UPPER, $upper->metadata[LimitBand::KEY]);
        self::assertSame($defaultSeverity, $upper->severity);
        self::assertSame($upperAdvice, $upper->remediation);
        self::assertDoesNotMatchRegularExpression('/raise|threshold|ignore|exclude|extract/i', $lower->remediation . ' ' . $upper->remediation);
    }

    /**
     * Verify the lower band is advisory even when configuration sets an error tier the value crosses.
     *
     * @return void
     */
    public function testLowerBandIsAdvisoryWhateverTheConfiguredTier(): void
    {
        $finding = $this->onlyFinding('size.method-length', self::methodOfStatements(35), ['warning' => 30, 'error' => 32]);

        self::assertSame(LimitBand::LOWER, $finding->metadata[LimitBand::KEY]);
        self::assertSame(Severity::Advisory, $finding->severity);
        self::assertStringContainsString('above the error threshold of 32', $finding->message);
    }

    /**
     * Supplies a promoted value object's parameter counts with the band each falls in against the ceiling of 25.
     *
     * @return array<string, array{int, string}> - parameter count, expected band
     */
    public static function valueObjectCases(): array
    {
        return ['just over the ceiling' => [27, LimitBand::LOWER], 'well over the ceiling' => [40, LimitBand::UPPER]];
    }

    /**
     * Verify a promoted value-object constructor is banded against its own ceiling and stays advisory in both bands.
     *
     * @param int $count - Promoted parameters.
     * @param string $band - Band the count falls in.
     *
     * @return void
     */
    #[DataProvider('valueObjectCases')]
    public function testValueObjectBandsAgainstItsOwnCeiling(int $count, string $band): void
    {
        $finding = $this->onlyFinding('size.parameter-count', self::valueObject($count));

        self::assertSame($band, $finding->metadata[LimitBand::KEY]);
        self::assertSame(Severity::Advisory, $finding->severity);
    }

    /**
     * Supplies a service constructor's parameter counts with the band and severity each gets against a cap of 5.
     *
     * @return array<string, array{int, string, Severity}> - parameter count, expected band, expected severity
     */
    public static function constructorCapCases(): array
    {
        return [
            'just over the cap' => [6, LimitBand::LOWER, Severity::Advisory],
            'well over the cap' => [8, LimitBand::UPPER, Severity::Error],
        ];
    }

    /**
     * Verify an opted-in constructor cap is the limit its band is measured against.
     *
     * @param int $count - Constructor parameters.
     * @param string $band - Band the count falls in.
     * @param Severity $severity - Severity the finding carries.
     *
     * @return void
     */
    #[DataProvider('constructorCapCases')]
    public function testConstructorCapIsTheLimitInForce(int $count, string $band, Severity $severity): void
    {
        $finding = $this->onlyFinding('size.parameter-count', self::serviceConstructor($count), null, ['constructorMaxParameters' => 5]);

        self::assertSame('constructor-threshold', $finding->metadata['findingKind']);
        self::assertSame($band, $finding->metadata[LimitBand::KEY]);
        self::assertSame($severity, $finding->severity);
    }

    /**
     * Verify a read-only data carrier stays advisory in both bands while its band key still follows the count.
     *
     * @return void
     */
    public function testReadonlyDataCarrierStaysAdvisoryInBothBands(): void
    {
        foreach ([16 => LimitBand::LOWER, 30 => LimitBand::UPPER] as $count => $band) {
            $finding = $this->onlyFinding('size.property-count', self::readonlyCarrier($count));
            self::assertSame('readonly-data-carrier', $finding->metadata['findingKind'], "{$count} properties");
            self::assertSame($band, $finding->metadata[LimitBand::KEY], "{$count} properties");
            self::assertSame(Severity::Advisory, $finding->severity, "{$count} properties");
        }
    }

    /**
     * Verify no banded rule can see a data file: only source-text rules run on non-PHP files.
     *
     * @return void
     */
    public function testBandedRulesNeverMeasureDataFiles(): void
    {
        $registry = RuleRegistry::defaults();

        foreach (self::BANDED as $ruleId) {
            self::assertNotInstanceOf(SourceTextRuleInterface::class, $registry->get($ruleId), $ruleId);
        }
    }

    /**
     * Run one rule over generated source and return its single finding.
     *
     * @param string $ruleId - Rule to run.
     * @param string $source - PHP source to analyse.
     * @param array<string, int|float>|null $thresholds - Warning and error tiers; null keeps the rule's defaults.
     * @param array<string, int|float|bool|string> $options - Option overrides layered onto the rule's defaults.
     *
     * @return Finding - The rule's only finding on the source.
     */
    private function onlyFinding(string $ruleId, string $source, ?array $thresholds = null, array $options = []): Finding
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-band-');
        self::assertIsString($path);
        rename($path, $path . '.php');
        $path .= '.php';
        $this->paths[] = $path;
        file_put_contents($path, $source);

        $registry = RuleRegistry::defaults();
        $rule     = $registry->get($ruleId);
        self::assertInstanceOf(RuleInterface::class, $rule);
        $config   = AnalysisConfig::fromRegistry($registry);
        $current  = $config->ruleSettings($ruleId);
        $config   = $config->withRuleSettings($ruleId, new RuleSettings(
            true,
            $thresholds ?? $current->thresholds,
            array_merge($current->options, $options),
            $thresholds === null ? $current->severityThreshold : null,
        ));
        $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'band.php'));
        $findings = $rule->analyse($unit, new RuleContext(__DIR__ . '/../../..', $config));

        self::assertCount(1, $findings, $ruleId);

        return $findings[0];
    }

    /**
     * Build a file of top-level assignments.
     *
     * @param int $count - Number of assignment lines.
     *
     * @return string - PHP source.
     */
    private static function statements(int $count): string
    {
        $lines = [];
        for ($index = 0; $index < $count; $index++) {
            $lines[] = sprintf('$value%d = %d;', $index, $index);
        }

        return "<?php\n" . implode("\n", $lines) . "\n";
    }

    /**
     * Build a class whose body spans the given number of lines in one method.
     *
     * @param int $lines - Approximate class length in code lines.
     *
     * @return string - PHP source.
     */
    private static function classOfLines(int $lines): string
    {
        $body = [];
        for ($index = 0; $index < $lines - 4; $index++) {
            $body[] = sprintf('        $value%d = %d;', $index, $index);
        }

        return "<?php\nfinal class BandClass\n{\n    public function run(): void\n    {\n" . implode("\n", $body) . "\n    }\n}\n";
    }

    /**
     * Build a function of the given number of statements.
     *
     * @param int $count - Number of statements.
     *
     * @return string - PHP source.
     */
    private static function methodOfStatements(int $count): string
    {
        $body = [];
        for ($index = 0; $index < $count; $index++) {
            $body[] = sprintf('        $value%d = %d;', $index, $index);
        }

        return "<?php\nfinal class BandMethod\n{\n    public function run(): void\n    {\n" . implode("\n", $body) . "\n    }\n}\n";
    }

    /**
     * Build a function taking the given number of parameters.
     *
     * @param int $count - Number of parameters.
     *
     * @return string - PHP source.
     */
    private static function functionWithParameters(int $count): string
    {
        $params = [];
        for ($index = 0; $index < $count; $index++) {
            $params[] = sprintf('int $p%d', $index);
        }

        return "<?php\nfunction band(" . implode(', ', $params) . "): void\n{\n}\n";
    }

    /**
     * Build a class declaring the given number of properties.
     *
     * @param int $count - Number of properties.
     *
     * @return string - PHP source.
     */
    private static function classWithProperties(int $count): string
    {
        $props = [];
        for ($index = 0; $index < $count; $index++) {
            $props[] = sprintf('    public int $p%d = %d;', $index, $index);
        }

        return "<?php\nclass BandProperties\n{\n" . implode("\n", $props) . "\n}\n";
    }

    /**
     * Build a class with the given number of public methods.
     *
     * @param int $count - Number of public methods.
     *
     * @return string - PHP source.
     */
    private static function classWithPublicMethods(int $count): string
    {
        $methods = [];
        for ($index = 0; $index < $count; $index++) {
            $methods[] = sprintf('    public function m%d(): int { return %d; }', $index, $index);
        }

        return "<?php\nclass BandMethods\n{\n" . implode("\n", $methods) . "\n}\n";
    }

    /**
     * Build a method of flat branches that do work, so neither flat-guard softening nor nesting applies.
     *
     * @param int $count - Number of if statements.
     *
     * @return string - PHP source.
     */
    private static function methodWithBranches(int $count): string
    {
        $body = [];
        for ($index = 0; $index < $count; $index++) {
            $body[] = sprintf('        if ($flag === %d) { $total += %d; }', $index, $index);
        }

        return "<?php\nfinal class BandBranches\n{\n    public function run(int \$flag): int\n    {\n        \$total = 0;\n"
            . implode("\n", $body) . "\n        return \$total;\n    }\n}\n";
    }

    /**
     * Build a method whose if statements nest the given number of levels.
     *
     * @param int $depth - Nesting depth.
     *
     * @return string - PHP source.
     */
    private static function methodNestedTo(int $depth): string
    {
        return "<?php\nfinal class BandNesting\n{\n    public function run(bool \$flag): void\n    {\n"
            . str_repeat("if (\$flag) {\n", $depth) . "echo 1;\n" . str_repeat("}\n", $depth) . "    }\n}\n";
    }

    /**
     * Build a final readonly value object whose constructor promotes the given number of parameters.
     *
     * @param int $count - Number of promoted parameters.
     *
     * @return string - PHP source.
     */
    private static function valueObject(int $count): string
    {
        $params = [];
        for ($index = 0; $index < $count; $index++) {
            $params[] = sprintf('public int $p%d', $index);
        }

        return "<?php\nfinal readonly class BandValue\n{\n    public function __construct(" . implode(', ', $params) . ")\n    {\n    }\n}\n";
    }

    /**
     * Build a service class whose constructor takes the given number of dependencies.
     *
     * @param int $count - Number of constructor parameters.
     *
     * @return string - PHP source.
     */
    private static function serviceConstructor(int $count): string
    {
        $params = [];
        for ($index = 0; $index < $count; $index++) {
            $params[] = sprintf('object $dependency%d', $index);
        }

        return "<?php\nfinal class BandService\n{\n    public function __construct(" . implode(', ', $params) . ")\n    {\n    }\n\n    public function run(): void\n    {\n    }\n}\n";
    }

    /**
     * Build a final readonly data carrier with the given number of properties and no behaviour.
     *
     * @param int $count - Number of properties.
     *
     * @return string - PHP source.
     */
    private static function readonlyCarrier(int $count): string
    {
        $props = [];
        for ($index = 0; $index < $count; $index++) {
            $props[] = sprintf('    public int $p%d;', $index);
        }

        return "<?php\nfinal readonly class BandCarrier\n{\n" . implode("\n", $props) . "\n}\n";
    }
}
