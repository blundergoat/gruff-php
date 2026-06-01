<?php

declare(strict_types=1);

namespace GruffPhp\Parser;

use GruffPhp\Source\SourceFile;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Converts source files into parser statements, tokens, and diagnostics.
 */
final readonly class PhpFileParser
{
    /**
     * Parser instance reused across files in a parsing pass.
     */
    private Parser $parser;

    /**
     * Create a parser using the supplied nikic/php-parser instance or default.
     *
     * @param Parser|null $parser Parser override used by tests, or null for the default parser.
     */
    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parse a source file into statements, tokens, and diagnostics for rules.
     *
     * @param SourceFile $file File descriptor to parse.
     * @return AnalysisUnit Parsed source representation consumed by rules.
     */
    public function parse(SourceFile $file): AnalysisUnit
    {
        $source = file_get_contents($file->absolutePath);

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

        if (!$file->isPhp()) {
            // Non-PHP sources keep their text for raw-content rules but skip AST/token work.
            return new AnalysisUnit($file, $source, [], [], []);
        }

        try {
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
            foreach ($this->parser->getTokens() as $token) {
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
}
