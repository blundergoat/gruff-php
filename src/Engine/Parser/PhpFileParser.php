<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Parser;

use GruffPhp\Engine\Source\SourceFile;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Turns a source file on disk into the parsed form rules analyse - statements, comment tokens, and any
 * parse diagnostics.
 *
 * Every PHP file gruff checks passes through here first. It reads the file, parses it with
 * nikic/php-parser, resolves names and links parent nodes so structural rules can navigate the tree,
 * and keeps only the comment tokens rules actually read (dropping the rest is the big memory saving on
 * large projects). Crucially, a file that cannot be read or will not parse becomes a diagnostic on an
 * empty unit rather than an exception, so one broken file never aborts the whole run.
 */
final readonly class PhpFileParser
{
    /**
     * Parser instance reused across files in a parsing pass.
     */
    private Parser $parser;

    /**
     * Builds the parser, taking a test-supplied instance or falling back to the newest supported version.
     *
     * @param Parser|null $parser - Parser override used by tests, or null for the default parser.
     */
    public function __construct(?Parser $parser = null)
    {
        // Fall back to a parser for the newest supported PHP version unless a test supplied its own.
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parses one file into an analysis unit for rules, degrading an unreadable or unparseable file into
     * a diagnostic rather than letting it crash the run.
     *
     * @param SourceFile                                                                $file           - File descriptor to parse.
     * @param array{enabled: bool, maxLines: int, maxBytes: int, override: string}|null $deepScanBudget - Effective structural-analysis budget; null leaves parsing unbounded for direct callers.
     *
     * @return AnalysisUnit - The parsed representation rules consume; an empty unit carrying a ParseDiagnostic when the file could not be read or parsed.
     */
    public function parse(SourceFile $file, ?array $deepScanBudget = null): AnalysisUnit
    {
        $source = file_get_contents($file->absolutePath);

        // The file could not be read, so hand back an empty unit carrying the error.
        if ($source === false) {
            // Unreadable file is surfaced as a diagnostic, not an exception, so one bad file
            // does not abort the whole run; downstream rules see an empty unit carrying the error.
            return new AnalysisUnit(
                $file,
                '',
                [],
                [],
                [new ParseDiagnostic('Unable to read file.', 1)],
            );
        }

        // A non-PHP file keeps its text for text-scanning rules but skips the AST and token work.
        if (!$file->isPhp()) {
            // Non-PHP sources keep their text for raw-content rules but skip AST/token work.
            return new AnalysisUnit($file, $source, [], [], []);
        }

        $budgetDiagnostic = self::deepScanBudgetDiagnostic($file, $source, $deepScanBudget);
        if ($budgetDiagnostic instanceof ParseDiagnostic) {
            return new AnalysisUnit($file, $source, [], [], [$budgetDiagnostic]);
        }

        try {
            // Parse the file; a null result (an empty file) becomes an empty statement list.
            $statements = array_values($this->parser->parse($source) ?? []);

            $nodeTraverser = new NodeTraverser();
            $nodeTraverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
            $nodeTraverser->addVisitor(new ParentConnectingVisitor());
            /** @var list<\PhpParser\Node\Stmt> $traversed Visitors preserve the parsed statement list shape. */
            $traversed = $nodeTraverser->traverse($statements);

            // Only comment tokens are consumed by rules (TODO density, commented-out code, secret
            // scanner comment ranges). Keeping the full token stream is the dominant per-file
            // memory cost at scale (~4-5GB peak on large PHP projects).
            $commentTokens = [];
            // Keep only the comment tokens - the rest of the stream is the memory cost we do not need.
            foreach ($this->parser->getTokens() as $token) {
                // Comment and doc-comment tokens are the ones rules read (TODOs, commented-out code, secret ranges).
                if ($token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
                    $commentTokens[] = $token;
                }
            }

            // Fully parsed unit: name-resolved, parent-linked statements plus only the comment tokens rules read.
            return new AnalysisUnit($file, $source, $traversed, $commentTokens, []);
        } catch (Error $error) {
            // A syntax error is expected input here (we analyse broken code too): record it as a
            // diagnostic at its source line and keep the tokens so comment-based rules still run.
            return new AnalysisUnit(
                $file,
                $source,
                [],
                array_values($this->parser->getTokens()),
                [new ParseDiagnostic($error->getRawMessage(), max(1, $error->getStartLine()))],
            );
        } catch (Throwable $throwable) {
            // Last-resort guard against parser internals failing unexpectedly: degrade to an empty
            // unit pinned to line 1 rather than letting one file crash the whole analysis pass.
            return new AnalysisUnit(
                $file,
                $source,
                [],
                [],
                [new ParseDiagnostic($throwable->getMessage(), 1)],
            );
        }
    }

    /**
     * Describes a PHP file whose raw source crosses either structural-analysis bound.
     *
     * @param SourceFile $file - Discovered file under consideration; a non-PHP source is never bounded.
     * @param string $source - Raw contents, measured in lines and bytes against the budget.
     * @param array{enabled: bool, maxLines: int, maxBytes: int, override: string}|null $deepScanBudget - Bounds in force, or null when the guard is off.
     *
     * @return ParseDiagnostic|null - Nonfatal note naming both counts and both limits; null while the file stays inside the budget.
     */
    public static function deepScanBudgetDiagnostic(
        SourceFile $file,
        string $source,
        ?array $deepScanBudget,
    ): ?ParseDiagnostic {
        if (!$file->isPhp() || $deepScanBudget === null || !$deepScanBudget['enabled']) {
            return null;
        }

        $lineCount = $source === '' ? 0 : substr_count($source, "\n") + 1;
        $byteCount = strlen($source);
        if ($lineCount <= $deepScanBudget['maxLines'] && $byteCount <= $deepScanBudget['maxBytes']) {
            return null;
        }

        return new ParseDiagnostic(
            sprintf(
                'path=%s; lines=%d; bytes=%d; maxLines=%d; maxBytes=%d; override=%s. Text-level rules (size, sensitive-data, config) still ran; masking, block parsing, AST walking, and other deep script analysis were skipped.',
                $file->displayPath,
                $lineCount,
                $byteCount,
                $deepScanBudget['maxLines'],
                $deepScanBudget['maxBytes'],
                $deepScanBudget['override'],
            ),
            1,
            'bounded-deep-scan',
            false,
        );
    }
}
