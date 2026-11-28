<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Naming;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Engine\Parser\PhpFileParser;
use GruffPhp\Rules\Naming\FunctionLikeScope;
use GruffPhp\Rules\Naming\FunctionLikeScopeWalker;
use GruffPhp\Engine\Source\SourceFile;
use PHPUnit\Framework\TestCase;

/**
 * Covers parameter and local-variable isolation across nested closures.
 * Checks separate scopes for arrow functions declared inside foreach bodies.
 * Checks that methods precede body closures in the returned scope list.
 */
final class FunctionLikeScopeWalkerTest extends TestCase
{
    /**
     * Verify nested closures keep parameter and local-variable names isolated by scope.
     *
     * @return void - Asserts scope kinds and isolated parameter and local-variable names.
     */
    public function testScopesIsolateNestedClosuresAndShadowedParameters(): void
    {
        $scopes = $this->scopesFor(<<<'PHP'
            <?php

            function outer($item): void
            {
                $outerValue = 1;
                $callback = function ($item) use ($outerValue): int {
                    $insideValue = $item;
                    $nested = function ($item): int {
                        $deepValue = $item;

                        return $deepValue;
                    };

                    return $insideValue;
                };
            }
            PHP
        );

        self::assertSame(['function', 'closure', 'closure'], array_map(
            static fn(FunctionLikeScope $scope): string => $scope->kind,
            $scopes,
        ));

        self::assertSame(['item'], array_keys($scopes[0]->parameterNames));
        self::assertSame(['outerValue', 'callback'], array_keys($scopes[0]->localVariables));

        self::assertSame(['item'], array_keys($scopes[1]->parameterNames));
        self::assertSame(['insideValue', 'nested'], array_keys($scopes[1]->localVariables));
        self::assertArrayNotHasKey('outerValue', $scopes[1]->localVariables);

        self::assertSame(['item'], array_keys($scopes[2]->parameterNames));
        self::assertSame(['deepValue'], array_keys($scopes[2]->localVariables));
    }

    /**
     * Verify arrow functions inside foreach bodies become separate scopes.
     *
     * @return void - Asserts separate enclosing and arrow scopes with their expected names.
     */
    public function testArrowFunctionsInsideForeachAreSeparateScopes(): void
    {
        $scopes = $this->scopesFor(<<<'PHP'
            <?php

            function buildCallbacks(array $values): array
            {
                $callbacks = [];

                foreach ($values as $index => $value) {
                    $callbacks[] = fn ($entry): string => $entry . ':' . $value;
                }

                return $callbacks;
            }
            PHP
        );

        self::assertSame(['function', 'arrow'], array_map(
            static fn(FunctionLikeScope $scope): string => $scope->kind,
            $scopes,
        ));

        self::assertSame(['values'], array_keys($scopes[0]->parameterNames));
        self::assertSame(['callbacks', 'index', 'value'], array_keys($scopes[0]->localVariables));
        self::assertArrayNotHasKey('entry', $scopes[0]->localVariables);

        self::assertSame(['entry'], array_keys($scopes[1]->parameterNames));
        self::assertSame(['value'], array_keys($scopes[1]->localVariables));
    }

    /**
     * Verify class methods are yielded before closures declared in their bodies.
     *
     * @return void - Asserts method-before-closure order and the names assigned to each scope.
     */
    public function testMethodsAreYieldedBeforeBodyClosures(): void
    {
        $scopes = $this->scopesFor(<<<'PHP'
            <?php

            final class Example
            {
                public function handle($request): void
                {
                    $runner = static function ($request): void {
                        $result = $request;
                    };
                }
            }
            PHP
        );

        self::assertSame(['method', 'closure'], array_map(
            static fn(FunctionLikeScope $scope): string => $scope->kind,
            $scopes,
        ));

        self::assertSame(['request'], array_keys($scopes[0]->parameterNames));
        self::assertSame(['runner'], array_keys($scopes[0]->localVariables));

        self::assertSame(['request'], array_keys($scopes[1]->parameterNames));
        self::assertSame(['result'], array_keys($scopes[1]->localVariables));
    }

    /**
     * Build function-like scopes for a test fixture.
     *
     * @param string $source - Inline PHP source defining the functions and closures to walk.
     *
     * @return list<FunctionLikeScope> - Function-like scopes discovered in the fixture.
     */
    private function scopesFor(string $source): array
    {
        $unit = $this->parseSource($source);

        // FunctionLikeScopeWalker yields in depth-first declaration order; callers index $scopes
        // by fixed position, so that order is the contract this helper hands back unchanged.
        return (new FunctionLikeScopeWalker())->scopes($unit->statements);
    }

    /**
     * Parse inline PHP source through the production parser.
     *
     * @param string $source - Inline PHP written to a throwaway temp file before parsing.
     *
     * @return AnalysisUnit - parsed statements plus diagnostics, asserted diagnostic-free so callers walk valid input
     */
    private function parseSource(string $source): AnalysisUnit
    {
        $path = tempnam(sys_get_temp_dir(), 'gruff-scope-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $source));

        try {
            $unit = (new PhpFileParser())->parse(new SourceFile($path, 'inline.php'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::assertSame([], $unit->diagnostics);

        return $unit;
    }
}
