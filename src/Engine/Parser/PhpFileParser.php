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
     * Non-PHP and over-budget files retain only source; a failed read returns empty contents with a fatal diagnostic.
     * Caught parser or visitor failures retain source without an AST; PhpParser errors also retain the full token stream.
     *
     * @param SourceFile $file - File descriptor to parse.
     *
     * @param array{enabled: bool, maxLines: int, maxBytes: int, override: string}|null $deepScanBudget - Limits; null leaves parsing unbounded.
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

            foreach ($this->parser->getTokens() as $token) {

                if ($token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
                    $commentTokens[] = $token;
                }
            }


            return new AnalysisUnit($file, $source, $traversed, $commentTokens, []);
        } catch (Error $error) {
            // Caught parser errors retain source and all tokens; their fatal diagnostic uses the reported line, or line 1 if unknown.

            return new AnalysisUnit(
                $file,
                $source,
                [],
                array_values($this->parser->getTokens()),
                [new ParseDiagnostic($error->getRawMessage(), max(1, $error->getStartLine()))],
            );
        } catch (Throwable $throwable) {
            // Unexpected parser or visitor failures retain the source and become a fatal diagnostic at line 1.

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
