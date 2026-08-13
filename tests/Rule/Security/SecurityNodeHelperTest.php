<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Rule\Security;

use GruffPhp\Rules\Security\SecurityNodeHelper;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionFunction;
use ReflectionParameter;

/**
 * Covers the sink parameter table the security rules resolve named arguments through.
 *
 * A wrong name here does more than miss a finding. Argument lookup hands back whichever argument carries the
 * name it is given, so a name belonging to another slot returns the wrong expression and can invent a finding
 * on safe code. Each case feeds a call the parameter name PHP itself reports and asserts the table routes it
 * to the slot the rules ask for, which keeps the table honest against the runtime rather than against memory.
 */
final class SecurityNodeHelperTest extends TestCase
{
    /** Floor on reflectable slots, so a build missing every mapped function cannot pass these cases vacuously. */
    private const MINIMUM_REFLECTABLE_SLOTS = 30;

    /**
     * Provide each mapped sink slot together with the parameter name PHP declares at that position.
     *
     * @return array<string, array{string, int, string}> - rows of function name, slot index, and PHP's declared
     *                       parameter name; functions this build does not ship are skipped, having no signature to report
     */
    public static function reflectableSinkSlots(): array
    {
        /** @var array<string, list<list<string>>> $table - reflection reports mixed, while the constant it reads declares this shape. */
        $table = (new ReflectionClassConstant(SecurityNodeHelper::class, 'SINK_PARAMETERS'))->getValue();
        $slots = [];

        foreach ($table as $function => $acceptedNamesPerSlot) {
            // Extension and framework helpers are absent from a bare CLI build, so PHP reports no signature to check.
            if (!function_exists($function)) {
                continue;
            }

            $declared = array_map(
                static fn(ReflectionParameter $parameter): string => $parameter->getName(),
                (new ReflectionFunction($function))->getParameters(),
            );

            foreach (array_keys($acceptedNamesPerSlot) as $index) {
                $slots[$function . '[' . $index . ']'] = [$function, $index, $declared[$index] ?? 'absent'];
            }
        }

        return $slots;
    }

    /**
     * Verify a call written with PHP's own parameter name resolves to the slot the table maps it to.
     *
     * @param string $function - Mapped global sink function.
     * @param int    $index - Slot index the security rules read from that function.
     * @param string $declaredName - Parameter name PHP reports at that position.
     *
     * @return void
     */
    #[DataProvider('reflectableSinkSlots')]
    public function testDeclaredParameterNameResolvesToItsMappedSlot(string $function, int $index, string $declaredName): void
    {
        $call = self::parseCall(sprintf('%s(%s: $probe);', $function, $declaredName));

        $resolved = SecurityNodeHelper::sinkArgumentValue($call, $index);

        self::assertInstanceOf(Variable::class, $resolved, sprintf('%s slot %d never resolved $%s', $function, $index, $declaredName));
        self::assertSame('probe', $resolved->name);
    }

    /**
     * Verify enough slots reflect that the per-slot cases cannot all be skipped without notice.
     *
     * @return void
     */
    public function testReflectableSlotCoverageIsNotVacuous(): void
    {
        self::assertGreaterThanOrEqual(self::MINIMUM_REFLECTABLE_SLOTS, count(self::reflectableSinkSlots()));
    }

    /**
     * Parses one call statement and hands back the function-call node the helper reads arguments from.
     *
     * @param string $source - Single call statement written without the opening tag.
     *
     * @return FuncCall - Parsed call node carrying the probe argument.
     */
    private static function parseCall(string $source): FuncCall
    {
        $statements = (new ParserFactory())->createForVersion(PhpVersion::fromString('8.3'))->parse('<?php ' . $source);
        $call       = (new NodeFinder())->findFirstInstanceOf($statements ?? [], FuncCall::class);

        self::assertInstanceOf(FuncCall::class, $call);

        return $call;
    }
}
