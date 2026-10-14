<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Engine\Config\AnalysisConfig;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\RuleRegistry;
use GruffPhp\Rules\Security\ReflectedXssRule;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers the reflected-XSS rule: request data reaching output sinks, with
 * escaping/casting recognised as safe and local aliases followed in-scope.
 */
final class ReflectedXssRuleTest extends TestCase
{
    /** Project root used to resolve fixtures. */
    private const PROJECT_ROOT = __DIR__ . '/../../..';

    /** Fixture exercising unsafe and safe output sinks. */
    private const FIXTURE = 'tests/Fixtures/Security/reflected-xss.php';

    /**
     * Verify only unescaped request-derived output is flagged.
     *
     * @return void
     */
    public function testUnescapedRequestOutputDetected(): void
    {
        $findings = $this->findings(self::FIXTURE);

        $lines = array_map(static fn(Finding $finding): ?int => $finding->line, $findings);
        sort($lines);

        // directEcho, concatEcho, printSink, printfSink, aliasedEcho, serverEcho.
        self::assertSame([19, 29, 39, 49, 60, 70], $lines);
    }

    /**
     * Verify each finding carries safe, value-free metadata.
     *
     * @return void
     */
    public function testFindingsCarryStableMetadataAndNoRawValues(): void
    {
        $findings = $this->findings(self::FIXTURE);

        self::assertCount(6, $findings);
        self::assertSame(array_fill(0, 6, Severity::Warning), array_map(static fn(Finding $finding): Severity => $finding->severity, $findings));
        self::assertSame(array_fill(0, 6, Pillar::Security), array_map(static fn(Finding $finding): Pillar => $finding->pillar, $findings));
        self::assertSame(array_fill(0, 6, Confidence::Medium), array_map(static fn(Finding $finding): Confidence => $finding->confidence, $findings));

        $sinks        = array_map(static fn(Finding $finding): mixed => $finding->metadata['sink'] ?? null, $findings);
        $allowedSinks = ['echo', 'print', 'printf', 'vprintf'];
        $unknownSinks = array_values(array_filter(
                                         $sinks,
                                         static fn(mixed $sink): bool => !is_string($sink) || !in_array($sink, $allowedSinks, true),
                                     ));
        self::assertSame([], $unknownSinks);
        self::assertContains('echo', $sinks);
        self::assertContains('print', $sinks);
        self::assertContains('printf', $sinks);
    }

    /**
     * Verify escaped, cast, literal, and non-tainted output never fires.
     *
     * @return void
     */
    public function testEscapedAndSafeOutputIgnored(): void
    {
        $findings = $this->findings(self::FIXTURE);

        // Every safe method (escapedEcho, castEcho, escapedAlias, literalEcho,
        // bladeEscapeHelper, safePrintf, nonTaintedLocal) is defined after the
        // last unsafe sink (serverEcho on line 70), so no finding may land below it.
        foreach ($findings as $finding) {
            self::assertNotNull($finding->line, 'Reflected-XSS findings must remain line-addressable.');
            self::assertLessThanOrEqual(70, $finding->line, 'Safe output below line 70 must not fire.');
        }
    }

    /**
     * Verify JSON encoding does not count as escaping for an HTML output sink.
     *
     * @return void
     */
    public function testJsonEncodeDoesNotCountAsHtmlEscaping(): void
    {
        $findings = $this->findingsForSource(<<<'PHP'
<?php
function render(): void
{
    echo json_encode($_GET['name']);
}
PHP);

        self::assertCount(1, $findings);
        self::assertSame(4, $findings[0]->line);
    }

    /**
     * Verify WordPress's HTML escapers count as escaping while esc_url_raw(), which does not encode for HTML, still fires.
     *
     * @return void
     */
    public function testWordPressEscapersCountAsHtmlEscaping(): void
    {
        $findings = $this->findingsForSource(<<<'PHP'
<?php
function renderWordPress(): void
{
    echo esc_html($_GET['name']);
    echo '<a href="' . esc_url($_GET['next']) . '">';
    echo '<input value="' . esc_attr($_GET['page']) . '">';
    echo esc_url_raw($_GET['next']);
}
PHP);

        self::assertCount(1, $findings);
        self::assertSame(7, $findings[0]->line);
    }

    /**
     * Verify isset()/empty() probes and a number formatter's number argument carry no markup, while a separator argument,
     * a named first argument and an isset-guarded raw value still report.
     *
     * @return void
     */
    public function testProbesAndNumberFormattersCarryNoMarkup(): void
    {
        $findings = $this->findingsForSource(<<<'PHP'
<?php
function numbers(): void
{
    echo absint($_GET['page']);
    echo number_format_i18n($_GET['count']) . '+';
    echo number_format($_GET['n'], 2, '.', $_GET['sep']);
    echo number_format(decimal_separator: $_GET['sep'], num: 5);
    echo isset($_GET['debug']) ? 'on' : 'off';
    echo empty($_GET['q']) ? 'none' : 'some';
    echo isset($_GET['a']) ? $_GET['a'] : '';
    $page = isset($_REQUEST['paged']) ? absint($_REQUEST['paged']) : 1;
    echo $page;
}
PHP);

        self::assertSame([6, 7, 10], array_map(static fn(Finding $finding): ?int => $finding->line, $findings));
    }

    /**
     * Verify json_encode output is markup-free only after a JSON Content-Type header beside the sink that nothing replaced.
     *
     * @return void
     */
    public function testJsonEncodeCountsOnlyInAJsonResponse(): void
    {
        $findings = $this->findingsForSource(<<<'PHP'
<?php
function api(): void
{
    header('Content-Type: application/json');
    echo json_encode($_GET['q']);
}
function apiThenHtml(): void
{
    header('Content-Type: application/json');
    header('Content-Type: text/html');
    echo json_encode($_GET['q']);
}
function apiThenComputed(string $type): void
{
    header('Content-Type: application/json');
    header('Content-Type: ' . $type);
    echo json_encode($_GET['q']);
}
function problemJsonWithCacheHeader(): void
{
    header('Content-Type: application/problem+json');
    header('Cache-Control: no-cache');
    echo json_encode($_GET['q']);
}
function jsonp(): void
{
    header('Content-Type: application/json');
    echo $_GET['callback'] . '(' . json_encode($_GET['q']) . ')';
}
function conditionalJson(bool $ok): void
{
    if ($ok) {
        header('Content-Type: application/json');
    }
    echo json_encode($_GET['q']);
}
function jsonThenConditionalHtml(bool $ok): void
{
    header('Content-Type: application/json');
    if ($ok) {
        header('Content-Type: text/html');
    }
    echo json_encode($_GET['q']);
}
function headerInUncalledClosure(): void
{
    header('Content-Type: application/json');
    $fallback = static function (): void {
        header('Content-Type: text/html');
    };
    echo json_encode($_GET['q']);
}
function headerAfterOutput(): void
{
    echo json_encode($_GET['q']);
    header('Content-Type: application/json');
}
function aliasedJson(): void
{
    $body = '';
    $body .= json_encode($_GET['q']);
    header('Content-Type: application/json');
    echo $body;
}
PHP);

        self::assertSame([11, 17, 28, 35, 43, 55], array_map(static fn(Finding $finding): ?int => $finding->line, $findings));
    }

    /**
     * Verify output after a top-level guard that stops every SAPI but the CLI is quiet, in both operand orders and with
     * throw, exit or return.
     *
     * @return void
     */
    public function testOutputAfterACliOnlyGuardIsQuiet(): void
    {
        $throwGuard = $this->findingsForSource(<<<'PHP'
<?php
if ('cli' !== \PHP_SAPI) {
    throw new Exception('CLI only');
}
echo $_SERVER['argv'][1];
PHP);
        $exitGuard = $this->findingsForSource(<<<'PHP'
<?php
echo $_GET['early'];
if (php_sapi_name() != 'cli') {
    exit(1);
}
echo $_GET['late'];
PHP);
        $returnGuard = $this->findingsForSource(<<<'PHP'
<?php
if (PHP_SAPI !== 'cli') {
    return;
}
echo $_GET['x'];
PHP);

        self::assertSame([], $throwGuard);
        self::assertSame([2], array_map(static fn(Finding $finding): ?int => $finding->line, $exitGuard));
        self::assertSame([], $returnGuard);
    }

    /**
     * Verify a SAPI check that is not a top-level, unconditional CLI-only stop leaves later output reported.
     *
     * @return void
     */
    public function testOtherSapiChecksDoNotQuietOutput(): void
    {
        $sources = [
            'guard inside a function' => "<?php\nfunction run(): void\n{\n    if ('cli' !== PHP_SAPI) {\n        return;\n    }\n    echo \$_GET['x'];\n}\n",
            'guard with an else'      => "<?php\nif ('cli' !== PHP_SAPI) {\n    throw new Exception('x');\n} else {\n    echo 'cli';\n}\necho \$_GET['x'];\n",
            'guard that only logs'    => "<?php\nif ('cli' !== PHP_SAPI) {\n    error_log('not cli');\n}\necho \$_GET['x'];\n",
            'positive cli test'       => "<?php\nif ('cli' === PHP_SAPI) {\n    throw new Exception('x');\n}\necho \$_GET['x'];\n",
            'another sapi literal'    => "<?php\nif ('fpm-fcgi' !== PHP_SAPI) {\n    throw new Exception('x');\n}\necho \$_GET['x'];\n",
            'another constant'        => "<?php\nif ('cli' !== PHP_OS) {\n    throw new Exception('x');\n}\necho \$_GET['x'];\n",
        ];

        foreach ($sources as $case => $source) {
            self::assertCount(1, $this->findingsForSource($source), $case);
        }
    }

    /**
     * Analyse a fixture and return only reflected-XSS findings.
     *
     * @param string $displayPath - Fixture display path.
     *
     * @return list<Finding> - reflected-XSS findings for the fixture in registry order; empty when the rule fires none
     */
    private function findings(string $displayPath): array
    {
        $unit     = (new PhpFileParser())->parse(new SourceFile(
                                                     self::PROJECT_ROOT . '/' . $displayPath,
                                                     $displayPath,
                                                     SourceFile::TYPE_PHP,
                                                 ));
        $registry = RuleRegistry::defaults();
        $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

        return array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === ReflectedXssRule::ID));
    }

    /**
     * Analyse inline PHP source and return reflected-XSS findings.
     *
     * @param string $source - PHP source to parse.
     *
     * @return list<Finding> - reflected-XSS findings for the source
     */
    private function findingsForSource(string $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-xss-');
        self::assertIsString($path);
        file_put_contents($path, $source);

        try {
            $unit     = (new PhpFileParser())->parse(new SourceFile($path, 'inline.php', SourceFile::TYPE_PHP));
            $registry = RuleRegistry::defaults();
            $findings = $registry->analyse([$unit], new RuleContext(self::PROJECT_ROOT, AnalysisConfig::fromRegistry($registry)));

            return array_values(array_filter($findings, static fn(Finding $finding): bool => $finding->ruleId === ReflectedXssRule::ID));
        } finally {
            unlink($path);
        }
    }
}
