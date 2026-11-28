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
 * Reads source files into analysis units containing raw text, AST statements, retained tokens, and diagnostics.
 *
 * PHP parsing resolves names and connects parents; non-PHP and over-budget files retain text without an AST or tokens.
 * Read failures and caught parser failures become diagnostics so callers can report unusable files.
 */
final readonly class PhpFileParser
{
    /**
     * Parser instance reused across files in a parsing pass.
     */
    private Parser $parser;

    /**
     * Builds the parser from a caller-supplied instance or the newest supported default.
     *
     * @param Parser|null $parser - Caller-supplied parser override, or null for the default parser.
     */
    public function __construct(?Parser $parser = null)
    {

        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Reads source files; successful PHP parsing resolves names, connects parents, and retains comment tokens.
     *
     * Non-PHP and over-budget files retain only source; a failed read returns empty contents with a fatal diagnostic.
     * Caught parser or visitor failures retain source without an AST; PhpParser errors also retain the full token stream.
     *
     * @param SourceFile $file - File descriptor to parse.
     *
     * @param array{enabled: bool, maxLines: int, maxBytes: int, override: string}|null $deepScanBudget - Null gives PHP files no structural limit.
     *
     * @return AnalysisUnit - Retained contents with fatal read/parse diagnostics or a nonfatal budget notice; no diagnostics otherwise.
     */
    public function parse(SourceFile $file, ?array $deepScanBudget = null): AnalysisUnit
    {
        $source = file_get_contents($file->absolutePath);

        // Unreadable input becomes a fatal diagnostic so the caller can report it without an AST.
        if ($source === false) {


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

            return new AnalysisUnit($file, $source, [], [], []);
        }

        $budgetDiagnostic = self::deepScanBudgetDiagnostic($file, $source, $deepScanBudget);
        // Keep the file available for text rules and report that the configured limit skipped structural analysis.
        if ($budgetDiagnostic instanceof ParseDiagnostic) {
            return new AnalysisUnit($file, $source, [], [], [$budgetDiagnostic]);
        }

        try {

            $statements = array_values($this->parser->parse($source) ?? []);

            $nodeTraverser = new NodeTraverser();
            $nodeTraverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]));
            $nodeTraverser->addVisitor(new ParentConnectingVisitor());
            /** @var list<\PhpParser\Node\Stmt> $traversed - Visitors preserve the parsed statement list shape. */
            $traversed = $nodeTraverser->traverse($statements);

            // Discard non-comment tokens after parsing to reduce the memory retained in each analysis unit.


            $commentTokens = [];

            // Retain only evidence the annotation and line-count rules can use after the AST is built.
            foreach ($this->parser->getTokens() as $token) {

                // Comment tokens preserve source intent without retaining identifiers and operators already described by the AST.
                if ($token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
                    $commentTokens[] = $token;
                }
            }


            return new AnalysisUnit($file, $source, $traversed, $commentTokens, []);
        } catch (Error $error) {
            // A file with an unfinished PHP expression still reaches the report as a fatal diagnostic at the parser's best known line.

            return new AnalysisUnit(
                $file,
                $source,
                [],
                array_values($this->parser->getTokens()),
                [new ParseDiagnostic($error->getRawMessage(), max(1, $error->getStartLine()))],
            );
        } catch (Throwable $throwable) {
            // If a caller-supplied parser throws before producing an AST, report a fatal error at line 1 and retain the source.

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
     * @param SourceFile $file   - Discovered file under consideration; a non-PHP source is never bounded.
     * @param string     $source - Raw contents, measured in lines and bytes against the budget.
     *
     * @param array{enabled: bool, maxLines: int, maxBytes: int, override: string}|null $deepScanBudget - Limits; null disables the guard.
     *
     * @return ParseDiagnostic|null - Nonfatal limit notice, or null for non-PHP input, no enabled budget, or counts within both limits.
     */
    public static function deepScanBudgetDiagnostic(
        SourceFile $file,
        string $source,
        ?array $deepScanBudget,
    ): ?ParseDiagnostic {
        // Text inputs have no structural budget. PHP can also run unbounded when the caller or config disables it.
        if (!$file->isPhp() || $deepScanBudget === null || !$deepScanBudget['enabled']) {
            return null;
        }

        $lineCount = substr_count($source, "\n") + (int)($source !== '' && !str_ends_with($source, "\n"));
        $byteCount = strlen($source);
        // A PHP file at the configured line and byte limits still gets a structural scan.
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
