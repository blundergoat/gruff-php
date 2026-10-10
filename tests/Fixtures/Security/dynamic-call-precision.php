<?php
// Callability proofs for security.dangerous-function-call. Every shape here was reported on a real repository in
// the 2026-08-18 false-positive hunt and must stay quiet. Each line ending in "reported" must keep firing: a real
// dynamic sink, a proof that belongs to a sibling scope, or a guard shape the rule deliberately does not read.

use Illuminate\Support\Arr;

// guzzle CurlMultiHandlerTest: an object built with `new` is invoked through __invoke.
function invokeConstructedHandler(array $request): void
{
    $handler = new CurlMultiHandler(['options' => []]);
    $handler($request, []);
}

// Laravel SessionGuard: a documented `array|callable` parameter unwrapped before each call.
final class SessionGuardShape
{
    /**
     * @param  array|callable|null  $callbacks
     */
    public function attemptWhen(array $credentials = [], $callbacks = null): bool
    {
        foreach (Arr::wrap($callbacks) as $callback) {
            if (! $callback($credentials, $this)) {
                return false;
            }
        }

        return true;
    }
}

// Laravel Gate: an `instanceof Closure` test encloses the call.
function inspectCondition($condition, object $user): bool
{
    if ($condition instanceof Closure) {
        return $condition($user) ? true : false;
    }

    return (bool) $condition;
}

// Laravel Filesystem: an immediately invoked closure is its own proof.
function getRequire(string $path, array $data): mixed
{
    return (static function () use ($path, $data) {
        return [$path, $data];
    })();
}

// Laravel Lock: `is_callable()` guards an untyped, undocumented parameter.
function getWithLock(bool $result, $callback = null): mixed
{
    if ($result && is_callable($callback)) {
        return $callback();
    }

    return $result;
}

// Laravel AuthenticateMiddlewareTest: `$creator(...)` creates a Closure and executes nothing.
function registerDriver(object $auth, $driver): void
{
    $creator = new CustomAuthDriver($driver);
    $auth->extend('custom', $creator(...));
}

// Slim ServerRequestCreator: an inline `@var callable` docblock proves the variable Closure::fromCallable wraps.
function createFromGlobals(object $creator, string $method): object
{
    /** @var callable $callable */
    $callable = [$creator, $method];

    return (Closure::fromCallable($callable))();
}

// A closure or arrow function trusts its own callable parameters.
function closureParameters(): void
{
    $apply = function (callable $operation) {
        return $operation();
    };
    $applyShort = fn (callable $operation) => $operation();
}

// Scoped trust must not break the two ways a nested function sees its parent's callable.
function nestedCaptures(callable $listener): void
{
    $viaUse = function () use ($listener) {
        return $listener();
    };
    $viaArrow = fn () => $listener();
}

// A `callable $command` parameter in one function proves nothing about another function's `$command`.
function trustedElsewhere(callable $command): void
{
    $command();
}

function untrustedHere(): void
{
    $command = $_GET['command'];
    $command(); // reported
}

// Real dynamic sinks keep firing.
function requestSelectedFunction(): void
{
    $name = $_GET['f'];
    $name(); // reported
    (Closure::fromCallable($_GET['f']))(); // reported
    $reference = $_GET['f'](...);
    $reference(); // reported
}

// Known residual: an early-return guard is not read as a proof, so the call still reports.
function earlyReturnGuard($handler): void
{
    if (!is_callable($handler)) {
        return;
    }

    $handler(); // reported
}

// Request input outranks every proof: each shape below was silenced by a proof it should not have passed.
function requestInputLaundering(object $factory): void
{
    $fn = $_REQUEST['fn'];
    if (is_callable($fn)) {
        $fn($_REQUEST['arg']); // reported
    }

    /** @var callable $documented */
    $documented = $_GET['f'];
    $documented(); // reported

    $object = new $_GET['class']();
    $object(); // reported
    (new $_GET['class']())(); // reported

    $dispatch = call_user_func(...);
    $dispatch($_GET['fn'], $_GET['arg']); // reported
    $map = array_map(...);
    $map($_GET['fn'], [1]); // reported
    $length = strlen(...);
    $length($_GET['arg']);
}

function syntaxOnlyCallabilityCheck($handler): void
{
    if (is_callable($handler, true)) {
        $handler(); // reported
    }
}

/** @param callable-string $function */
function documentedFunctionName($function): void
{
    $function(); // reported
}

// An arrow function's own parameter hides the parent's proof of the same name.
function shadowedByArrowParameter(callable $callback, array $names): array
{
    return array_map(fn ($callback) => $callback(), $names); // reported
}

final class ReceiverMatters
{
    private \Closure $handler;

    public function run(): void
    {
        $request = json_decode($_POST['payload']);
        ($request->handler)(); // reported
        ($this->handler)();
    }
}

// A closure literal is a closure whatever its body reads, so request state inside it taints nothing.
function resetsRequestState(): void
{
    $reset = static function (): void {
        unset($_SERVER['FOO']);
    };
    $reset();
}

// A guard proves nothing once the guarded name is reassigned inside it, and a spread hides a callable array's shape.
function reassignedAfterGuard($handler, array $parts): void
{
    if ($handler instanceof \Closure) {
        $handler = $_GET['handler'];
        $handler(); // reported
    }

    [...$parts, 'run'](); // reported
}
