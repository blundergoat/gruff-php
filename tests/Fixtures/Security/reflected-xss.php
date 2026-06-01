<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Security;

/**
 * Fixture exercising the reflected-XSS rule: request data reaching output.
 */
final class ReflectedXssFixture
{
    /**
     * Echo a request value directly.
     *
     * @return void
     */
    public function directEcho(): void
    {
        echo $_GET['name'];
    }

    /**
     * Concatenate a request value into echoed output.
     *
     * @return void
     */
    public function concatEcho(): void
    {
        echo 'Hello ' . $_POST['user'];
    }

    /**
     * Print a request value.
     *
     * @return void
     */
    public function printSink(): void
    {
        print $_REQUEST['q'];
    }

    /**
     * Render a request value through printf.
     *
     * @return void
     */
    public function printfSink(): void
    {
        printf('%s', $_GET['fmt']);
    }

    /**
     * Echo a local alias of a request value.
     *
     * @return void
     */
    public function aliasedEcho(): void
    {
        $value = $_GET['alias'];
        echo $value;
    }

    /**
     * Echo a request-influenced server variable.
     *
     * @return void
     */
    public function serverEcho(): void
    {
        echo $_SERVER['PHP_SELF'];
    }

    /**
     * Echo an escaped request value (safe).
     *
     * @return void
     */
    public function escapedEcho(): void
    {
        echo htmlspecialchars($_GET['safe']);
    }

    /**
     * Echo a numeric-cast request value (safe).
     *
     * @return void
     */
    public function castEcho(): void
    {
        echo (int) $_GET['id'];
    }

    /**
     * Echo a local that was escaped at assignment (safe).
     *
     * @return void
     */
    public function escapedAlias(): void
    {
        $safe = htmlspecialchars($_GET['z']);
        echo $safe;
    }

    /**
     * Echo a static literal (safe).
     *
     * @return void
     */
    public function literalEcho(): void
    {
        echo 'Static literal';
    }

    /**
     * Echo a Blade-style escaped request value (safe).
     *
     * @return void
     */
    public function bladeEscapeHelper(): void
    {
        echo e($_GET['blade']);
    }

    /**
     * Render a constant through printf (safe).
     *
     * @return void
     */
    public function safePrintf(): void
    {
        printf('%d', 42);
    }

    /**
     * Echo a non-tainted local (safe).
     *
     * @return void
     */
    public function nonTaintedLocal(): void
    {
        $label = 'constant';
        echo $label;
    }
}
