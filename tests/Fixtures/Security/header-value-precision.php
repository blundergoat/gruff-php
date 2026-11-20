<?php

declare(strict_types=1);

/**
 * Quiet: a status line built from the protocol token and literal status text.
 *
 * @return void
 */
function protocolStatusLine(): void
{
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    header("{$_SERVER['SERVER_PROTOCOL']} 403 Forbidden");
}

/**
 * Reported: other entries, a second dynamic part, no status code, the token alone, or the token later in the value.
 *
 * @return void
 */
function protocolLookAlikes(): void
{
    header($_SERVER['QUERY_STRING'] . ' 404 Not Found');
    header($_SERVER['SERVER_PROTOCOL'] . ' 404 ' . $_GET['reason']);
    header($_SERVER['SERVER_PROTOCOL'] . ' ok');
    header($_SERVER['SERVER_PROTOCOL']);
    header('X-Proto: ' . $_SERVER['SERVER_PROTOCOL']);
}

/**
 * Quiet: integer values, cast inline, cast into a local, or picked by a ternary with integer arms.
 *
 * @return void
 */
function integerValues(): void
{
    header('X-Inline: ' . (int) $_GET['id']);
    $id = (int) $_POST['id'];
    $response = ['id' => $id];
    header('X-Id: ' . $id);
    $wait = $_GET['slow'] ? 30 : 5;
    header('Retry-After: ' . $wait);
}

/**
 * Reported: a local whose writes are not all plain integer assignments.
 *
 * @param bool $flag - Branch selector.
 *
 * @return void
 */
function integerLookAlikes(bool $flag): void
{
    $appended = (int) $_GET['id'];
    $appended .= $_GET['x'];
    header('X-Appended: ' . $appended);
    $branched = (int) $_GET['id'];
    if ($flag) {
        $branched = $_GET['raw'];
    }
    header('X-Branched: ' . $branched);
    $short = $_GET['n'] ?: 5;
    header('X-Short: ' . $short);
    $mixed = $flag ? (int) $_GET['n'] : $_GET['m'];
    header('X-Mixed: ' . $mixed);
}

/**
 * Reported: a later write in the loop reaches the header on the next pass.
 *
 * @param bool $more - Loop condition.
 *
 * @return void
 */
function loopBackEdge(bool $more): void
{
    $id = (int) $_GET['a'];
    while ($more) {
        header('X-Loop: ' . $id);
        $id = $_GET['b'];
    }
}

/**
 * Reported: a write the integer check cannot follow.
 *
 * @return void
 */
function unseenWrites(): void
{
    $aliased = (int) $_GET['a'];
    $alias = &$aliased;
    header('X-Aliased: ' . $aliased);
    $looped = (int) $_GET['a'];
    foreach ($_GET['ids'] as $looped) {
    }
    header('X-Looped: ' . $looped);
    $listed = (int) $_GET['a'];
    [$listed] = $_GET['pair'];
    header('X-Listed: ' . $listed);
    $captured = (int) $_GET['a'];
    $fill = static function () use (&$captured): void {
    };
    $fill();
    header('X-Captured: ' . $captured);
}

/**
 * Reported: a variable-variable could write any local.
 *
 * @param string $name - Variable-variable name.
 *
 * @return void
 */
function variableVariable(string $name): void
{
    $dynamic = (int) $_GET['a'];
    $$name = $_GET['b'];
    header('X-Dynamic: ' . $dynamic);
}

/**
 * Reported: a value bound from outside the function body.
 *
 * @param int $param - Caller's value.
 *
 * @return void
 */
function outsideBindings(int $param): void
{
    global $shared;
    static $kept;
    try {
        $caught = 1;
    } catch (\Exception $caught) {
    }
    $shared = (int) $_GET['a'];
    header('X-Shared: ' . $shared);
    $kept = (int) $_GET['a'];
    header('X-Kept: ' . $kept);
    $caught = (int) $_GET['a'];
    header('X-Caught: ' . $caught);
    $param = (int) $_GET['a'];
    header('X-Param: ' . $param);
}

/**
 * Reported: a call that writes locals by name, a by-reference array item, or a superglobal's name.
 *
 * @param array<string, mixed> $input - Values to extract.
 * @param string               $code - Code to evaluate.
 * @param string               $file - File to include.
 *
 * @return void
 */
function byNameWriters(array $input, string $code, string $file): void
{
    $extracted = (int) $_GET['a'];
    extract($input);
    header('X-Extracted: ' . $extracted);
}

/**
 * Reported: eval() can write any local.
 *
 * @param string $code - Code to evaluate.
 *
 * @return void
 */
function evaluated(string $code): void
{
    $evaluated = (int) $_GET['a'];
    eval($code);
    header('X-Evaluated: ' . $evaluated);
}

/**
 * Reported: an included file can write any local.
 *
 * @param string $file - File to include.
 *
 * @return void
 */
function included(string $file): void
{
    $included = (int) $_GET['a'];
    include $file;
    header('X-Included: ' . $included);
}

/**
 * Reported: a by-reference array item aliases the local, and a superglobal name is not a local.
 *
 * @return void
 */
function aliasesAndSuperglobals(): void
{
    $referenced = (int) $_GET['a'];
    $refs = [&$referenced];
    header('X-Referenced: ' . $referenced);
    $_SESSION = (int) $_GET['a'];
    header('X-Session: ' . $_SESSION);
}
