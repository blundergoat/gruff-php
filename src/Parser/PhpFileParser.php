<?php

declare(strict_types=1);

namespace GruffPhp\Parser;

use GruffPhp\Source\SourceFile;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

final readonly class PhpFileParser
{
    private Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function parse(SourceFile $file): AnalysisUnit
    {
        $source = file_get_contents($file->absolutePath);

        if ($source === false) {
            return new AnalysisUnit(
                $file,
                '',
                [],
                [],
                [new ParseDiagnostic('Unable to read file.', 1)],
            );
        }

        if (!$file->isPhp()) {
            return new AnalysisUnit($file, $source, [], [], []);
        }

        try {
            $statements = array_values($this->parser->parse($source) ?? []);

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ParentConnectingVisitor());
            /** @var list<\PhpParser\Node\Stmt> $traversed */
            $traversed = $traverser->traverse($statements);

            return new AnalysisUnit($file, $source, $traversed, array_values($this->parser->getTokens()), []);
        } catch (Error $error) {
            return new AnalysisUnit(
                $file,
                $source,
                [],
                array_values($this->parser->getTokens()),
                [new ParseDiagnostic($error->getRawMessage(), max(1, $error->getStartLine()))],
            );
        } catch (Throwable $throwable) {
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
