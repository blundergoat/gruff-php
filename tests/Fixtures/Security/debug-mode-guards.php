<?php

declare(strict_types=1);

namespace GruffPhp\Tests\Fixtures\Security;

const FIXTURE_DEBUG = true;

/**
 * Fixture for security.debug-mode-enabled guards: a CLI-only branch or a configuration constant keeps error display
 * away from web clients, while each look-alike still reports.
 */
final class DebugModeGuardsFixture
{
    /**
     * Turn display on in the CLI elseif of a SAPI chain (quiet).
     *
     * @return void
     */
    public function cliElseIf(): void
    {
        if (!\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            ini_set('display_errors', 0);
        } elseif (!ini_get('log_errors')) {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on in the else of a negated CLI test (quiet).
     *
     * @return void
     */
    public function cliElse(): void
    {
        if (!in_array(PHP_SAPI, ['cli'], true)) {
            ini_set('display_errors', 0);
        } else {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on in the web branch of the SAPI chain (reports).
     *
     * @return void
     */
    public function webBranch(): void
    {
        if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on after a SAPI list that names a web SAPI (reports).
     *
     * @return void
     */
    public function webSapiListed(): void
    {
        if (!in_array(PHP_SAPI, ['cli', 'fpm-fcgi'], true)) {
            ini_set('display_errors', 0);
        } else {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on in the else of a positive CLI test, which is the web path (reports).
     *
     * @return void
     */
    public function positiveCliTest(): void
    {
        if (in_array(PHP_SAPI, ['cli'], true)) {
            ini_set('display_errors', 0);
        } else {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on in a branch that runs before the chain's CLI test (reports).
     *
     * @return void
     */
    public function laterCliTest(): void
    {
        if (isset($_GET['quiet'])) {
            ini_set('display_errors', 0);
        } elseif (isset($_GET['loud'])) {
            ini_set('display_errors', 1);
        } elseif (!in_array(PHP_SAPI, ['cli'], true)) {
            ini_set('display_errors', 0);
        }
    }

    /**
     * Turn display on behind a deployment's configuration constant (quiet).
     *
     * @return void
     */
    public function configConstant(): void
    {
        if (APP_SHOW_ERRORS) {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Let an outer opt-in constant decide over an inner request check (quiet).
     *
     * @return void
     */
    public function optInWrapsRequestCheck(): void
    {
        if (APP_SHOW_ERRORS) {
            if (isset($_GET['debug'])) {
                ini_set('display_errors', 1);
            }
        }
    }

    /**
     * Turn display on when the constant is off (reports).
     *
     * @return void
     */
    public function negatedConstant(): void
    {
        if (!APP_SHOW_ERRORS) {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on in the else of a constant test (reports).
     *
     * @return void
     */
    public function elseOfConstant(): void
    {
        if (APP_SHOW_ERRORS) {
            ini_set('display_errors', 0);
        } else {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on behind a literal true (reports).
     *
     * @return void
     */
    public function literalTrue(): void
    {
        if (true) {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on behind a request check (reports).
     *
     * @return void
     */
    public function requestCheck(): void
    {
        if (isset($_GET['debug'])) {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on in a closure built inside a constant guard (reports).
     *
     * @return void
     */
    public function closureInsideGuard(): void
    {
        if (APP_SHOW_ERRORS) {
            $enable = static function (): void {
                ini_set('display_errors', 1);
            };
            $enable();
        }
    }

    /**
     * Turn display on behind a constant this file defines with define() (reports).
     *
     * @return void
     */
    public function locallyDefinedConstant(): void
    {
        define('LOCAL_DEBUG', true);
        if (LOCAL_DEBUG) {
            ini_set('display_errors', 1);
        }
    }

    /**
     * Turn display on behind a constant this file declares with const (reports).
     *
     * @return void
     */
    public function locallyDeclaredConstant(): void
    {
        if (FIXTURE_DEBUG) {
            ini_set('display_errors', 1);
        }
    }
}
