<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Covers where a finding on an attributed declaration reports itself.
 *
 * A PHP 8 attribute group is a sub-node of the declaration it decorates, so `getStartLine()` on an attributed
 * method, class, property, constant or enum case returns the attribute's line. A finding reported there points
 * a reader, an editor jump, and any future autofix at `#[SomeAttribute]` rather than at the code the rule is
 * talking about. M07 routed those anchors through DeclarationLine; this suite is what keeps them there.
 */
final class DeclarationAnchorTest extends TestCase
{
    /**
     * Every finding on an attributed declaration must land on the declaration, never on its attribute.
     *
     * @return void
     */
    public function testNoFindingAnchorsOnAnAttributeGroup(): void
    {
        $lines    = self::attributedFixtureLines();
        $findings = $this->analyseSource(implode("\n", $lines) . "\n");

        self::assertNotSame([], $findings, 'the anchor fixture produced no findings, so it proved nothing');

        // The claim is about the source text at the reported line, not about which rules happened to fire.
        $anchoredOnAttribute = array_values(array_filter(
            $findings,
            static fn($finding): bool => $finding->line !== null && str_starts_with(trim($lines[$finding->line - 1] ?? ''), '#['),
        ));
        $reported = array_map(
            static fn($finding): string => sprintf('%s:%d', $finding->ruleId, (int) $finding->line),
            $anchoredOnAttribute,
        );

        self::assertSame([], $reported, 'findings anchored on an attribute group rather than on the declaration');
    }

    /**
     * The attributed method and class anchor on their own declaration lines.
     *
     * @return void
     */
    public function testAttributedDeclarationsAnchorOnTheirOwnLine(): void
    {
        $lines     = self::attributedFixtureLines();
        $findings  = $this->analyseSource(implode("\n", $lines) . "\n");
        $byRuleAndSymbol = [];

        foreach ($findings as $finding) {
            $byRuleAndSymbol[$finding->ruleId . '|' . (string) $finding->symbol] ??= $finding->line;
        }

        // Derived from the fixture text rather than written as bare numbers, so a reader can check the claim.
        $classDeclarationLine  = 1 + (int) array_search('final class Widget', $lines, true);
        $methodDeclarationLine = 1 + (int) array_search('    public function process(int $alpha): int', $lines, true);

        self::assertSame($classDeclarationLine, $byRuleAndSymbol['docs.missing-class-phpdoc|Widget'] ?? null);
        self::assertSame($methodDeclarationLine, $byRuleAndSymbol['docs.missing-return-tag|Widget::process()'] ?? null);
    }

    /**
     * One source file whose class, constant, property and method each carry an attribute group.
     *
     * @return list<string> - fixture source, one entry per line, so a test can name a line by its text.
     */
    private static function attributedFixtureLines(): array
    {
        return [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace Demo;',
            '',
            'use Attribute;',
            '',
            '#[Attribute]',
            'final class Marker',
            '{',
            '}',
            '',
            '#[Marker]',
            'final class Widget',
            '{',
            '    #[Marker]',
            '    public const THING = 1;',
            '',
            '    #[Marker]',
            '    public int $value = 0;',
            '',
            '    /**',
            '     * Do the thing.',
            '     */',
            '    #[Marker]',
            '    public function process(int $alpha): int',
            '    {',
            '        return $alpha + 1;',
            '    }',
            '}',
        ];
    }

    /**
     * Run the default rule registry over one source string written to a temporary file.
     *
     * @param string $source - Complete PHP source to analyse.
     *
     * @return list<\GruffPhp\Results\Finding\Finding> - every finding the default registry reported for that source.
     */
    private function analyseSource(string $source): array
    {
        $directory = sys_get_temp_dir() . '/gruff-anchor-' . bin2hex(random_bytes(6));

        mkdir($directory . '/src', 0o777, true);

        $path = $directory . '/src/Widget.php';

        file_put_contents($path, $source);

        try {
            $registry = RuleRegistry::defaults();
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'src/Widget.php'));

            return $registry->analyse([$unit], new RuleContext($directory, AnalysisConfig::fromRegistry($registry)));
        } finally {
            // The fixture is synthetic, but a stray temp tree in every run is its own kind of mess.
            unlink($path);
            rmdir($directory . '/src');
            rmdir($directory);
        }
    }

    /**
     * Guard the parse step so a malformed fixture fails as a fixture, not as a rule.
     *
     * @return void
     */
    public function testTheFixtureParses(): void
    {
        $unit = null;
        $lines = self::attributedFixtureLines();
        $directory = sys_get_temp_dir() . '/gruff-anchor-parse-' . bin2hex(random_bytes(6));

        mkdir($directory, 0o777, true);

        $path = $directory . '/Widget.php';

        file_put_contents($path, implode("\n", $lines) . "\n");

        try {
            $unit = (new PhpFileParser())->parse(new SourceFile($path, 'Widget.php'));
        } finally {
            unlink($path);
            rmdir($directory);
        }

        self::assertInstanceOf(AnalysisUnit::class, $unit);
        self::assertFalse($unit->hasParseErrors());
    }
}
