<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule;

use GruffPhp\Rule\StmtChildBlock;
use GruffPhp\Rule\StmtChildVisitor;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the shared statement-child visitor used by complexity and waste rules.
 */
final class StmtChildVisitorTest extends TestCase
{
    /**
     * Provide PHP source fragments classified as control-flow statements.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function controlFlowSampleProvider(): iterable
    {
        yield 'if'      => ['if ($x) { }'];
        yield 'for'     => ['for ($i = 0; $i < 1; $i++) { }'];
        yield 'foreach' => ['foreach ([] as $v) { }'];
        yield 'while'   => ['while ($x) { }'];
        yield 'do'      => ['do { } while ($x);'];
        yield 'switch'  => ['switch ($x) { case 1: break; }'];
        yield 'try'     => ['try { } catch (\Throwable $e) { }'];
    }

    /**
     * Provide PHP source fragments NOT classified as control-flow statements.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function nonControlFlowSampleProvider(): iterable
    {
        yield 'assignment' => ['$x = 1;'];
        yield 'return'     => ['return $x;'];
        yield 'echo'       => ['echo $x;'];
        yield 'throw'      => ['throw new \RuntimeException();'];
    }

    /**
     * Verify the visitor identifies a control-flow statement type.
     *
     * @param string $source PHP source fragment without the open tag.
     * @return void No return value.
     */
    #[DataProvider('controlFlowSampleProvider')]
    public function testControlFlowStatementIsRecognised(string $source): void
    {
        $stmt = $this->firstStatement($source);

        self::assertTrue(StmtChildVisitor::isControlFlowStmt($stmt));
    }

    /**
     * Verify the visitor rejects a non-control-flow statement type.
     *
     * @param string $source PHP source fragment without the open tag.
     * @return void No return value.
     */
    #[DataProvider('nonControlFlowSampleProvider')]
    public function testNonControlFlowStatementIsRejected(string $source): void
    {
        $stmt = $this->firstStatement($source);

        self::assertFalse(StmtChildVisitor::isControlFlowStmt($stmt));
    }

    /**
     * Verify an if/elseif/else chain yields one block per branch with the right kinds.
     *
     * @return void No return value.
     */
    public function testIfChainYieldsBranchBlocks(): void
    {
        $stmt = $this->firstStatement('if ($a) { $b = 1; } elseif ($c) { $d = 2; } else { $e = 3; }');

        $blocks = $this->blocksOf($stmt);

        self::assertCount(3, $blocks);
        self::assertSame(StmtChildBlock::KIND_IF_BODY, $blocks[0]->kind);
        self::assertSame(StmtChildBlock::KIND_ELSEIF_BODY, $blocks[1]->kind);
        self::assertSame(StmtChildBlock::KIND_ELSE_BODY, $blocks[2]->kind);
        self::assertCount(1, $blocks[0]->statements);
        self::assertCount(1, $blocks[1]->statements);
        self::assertCount(1, $blocks[2]->statements);
    }

    /**
     * Provide loop-statement source fragments.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function loopSampleProvider(): iterable
    {
        yield 'for'     => ['for ($i = 0; $i < 1; $i++) { $a = 1; }'];
        yield 'foreach' => ['foreach ([] as $v) { $a = 1; }'];
        yield 'while'   => ['while ($x) { $a = 1; }'];
        yield 'do'      => ['do { $a = 1; } while ($x);'];
    }

    /**
     * Verify each loop type yields exactly one loop-body block.
     *
     * @param string $source PHP source fragment without the open tag.
     * @return void No return value.
     */
    #[DataProvider('loopSampleProvider')]
    public function testLoopTypeYieldsSingleBody(string $source): void
    {
        $stmt   = $this->firstStatement($source);
        $blocks = $this->blocksOf($stmt);

        self::assertCount(1, $blocks);
        self::assertSame(StmtChildBlock::KIND_LOOP_BODY, $blocks[0]->kind);
        self::assertCount(1, $blocks[0]->statements);
    }

    /**
     * Verify switch statements yield one block per case, all tagged with the SWITCH_CASE kind.
     *
     * @return void No return value.
     */
    public function testSwitchYieldsCaseBlocks(): void
    {
        $stmt = $this->firstStatement('switch ($x) { case 1: $a = 1; break; case 2: $a = 2; break; default: $a = 3; }');

        $blocks = $this->blocksOf($stmt);

        self::assertCount(3, $blocks);
        $kinds  = array_map(static fn (StmtChildBlock $block): string => $block->kind, $blocks);
        $owners = array_map(static fn (StmtChildBlock $block): string => $block->owner::class, $blocks);

        self::assertSame([
            StmtChildBlock::KIND_SWITCH_CASE,
            StmtChildBlock::KIND_SWITCH_CASE,
            StmtChildBlock::KIND_SWITCH_CASE,
        ], $kinds);
        self::assertSame([
            Stmt\Case_::class,
            Stmt\Case_::class,
            Stmt\Case_::class,
        ], $owners);
    }

    /**
     * Verify try/catch/finally yields one block for try, each catch, and finally.
     *
     * @return void No return value.
     */
    public function testTryCatchFinallyYieldsAllArms(): void
    {
        $stmt = $this->firstStatement('try { $a = 1; } catch (\RuntimeException $e) { $a = 2; } catch (\LogicException $e) { $a = 3; } finally { $a = 4; }');

        $blocks = $this->blocksOf($stmt);

        self::assertCount(4, $blocks);
        self::assertSame(StmtChildBlock::KIND_TRY_BODY, $blocks[0]->kind);
        self::assertSame(StmtChildBlock::KIND_CATCH_BODY, $blocks[1]->kind);
        self::assertSame(StmtChildBlock::KIND_CATCH_BODY, $blocks[2]->kind);
        self::assertSame(StmtChildBlock::KIND_FINALLY_BODY, $blocks[3]->kind);
    }

    /**
     * Verify try without finally yields try + catches but no finally block.
     *
     * @return void No return value.
     */
    public function testTryCatchWithoutFinallySkipsFinallyBlock(): void
    {
        $stmt = $this->firstStatement('try { $a = 1; } catch (\Throwable $e) { $a = 2; }');

        $blocks = $this->blocksOf($stmt);

        self::assertCount(2, $blocks);
        self::assertSame(StmtChildBlock::KIND_TRY_BODY, $blocks[0]->kind);
        self::assertSame(StmtChildBlock::KIND_CATCH_BODY, $blocks[1]->kind);
    }

    /**
     * Verify non-control-flow nodes yield no blocks.
     *
     * @return void No return value.
     */
    public function testNonControlFlowYieldsNothing(): void
    {
        $stmt = $this->firstStatement('$x = 1;');

        $blocks = $this->blocksOf($stmt);

        self::assertSame([], $blocks);
    }

    /**
     * Parse a single statement from source.
     *
     * @param string $source PHP source fragment without the open tag.
     * @return Node Parsed first statement.
     */
    private function firstStatement(string $source): Node
    {
        $parser     = (new ParserFactory())->createForVersion(PhpVersion::fromString('8.3'));
        $statements = $parser->parse('<?php ' . $source);

        self::assertNotNull($statements);
        self::assertNotEmpty($statements);

        return $statements[0];
    }

    /**
     * Materialise the visitor's iterable as a numeric list of blocks.
     *
     * @param Node $node Statement to inspect.
     * @return list<StmtChildBlock> Child blocks in source order.
     */
    private function blocksOf(Node $node): array
    {
        return iterator_to_array(StmtChildVisitor::childBlocks($node), false);
    }
}
