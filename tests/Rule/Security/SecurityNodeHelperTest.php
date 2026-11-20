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
 * Checks the security sink parameter table against PHP's reflected function signatures.
 * A name mapped to the wrong slot can select an unrelated expression and cause a false finding.
 * Named-argument probes check each available function's mapped slots; a minimum row count prevents empty coverage.
 */
final class SecurityNodeHelperTest extends TestCase
{
    /** Floor on reflectable slots, so a build missing every mapped function cannot pass these cases vacuously. */
    private const MINIMUM_REFLECTABLE_SLOTS = 30;

    /**
     * Provide mapped slots for available functions using reflected names or an absent-parameter marker.
     *
     * @return array<string, array{string, int, string}> - Rows of function, slot and declared name; 'absent' marks a missing parameter.
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
     * @param string $declaredName - Parameter name reported at that position; 'absent' makes the case fail when the configured slot is missing.
     *
     * @return void - Asserts the requested slot resolves to the supplied probe variable.
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
     * @return void - Asserts at least the configured minimum number of reflected slot cases.
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
     * @return FuncCall - First function-call node found; the assertion fails if no call is present.
     */
    private static function parseCall(string $source): FuncCall
    {
        $statements = (new ParserFactory())->createForVersion(PhpVersion::fromString('8.3'))->parse('<?php ' . $source);
        $call       = (new NodeFinder())->findFirstInstanceOf($statements ?? [], FuncCall::class);

        self::assertInstanceOf(FuncCall::class, $call);

        return $call;
    }
}
