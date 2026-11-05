<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Waste;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Token;

/**
 * Reports a comment that holds disabled PHP code, which reviewers must read past and nobody runs.
 *
 * A user who left `// $result = $service->process($input);` above a rewrite gets one advisory on that comment.
 * Each comment line is scored for code signals (assignments, method calls, control keywords); two or more report it.
 *
 * Comments that explain rather than disable code stay quiet:
 * - a line that reads as prose, such as "It future replace str_getcsv with $dbi->fetchSingleRow(...);";
 * - a no-op `$v = $v;`, or an example ending in `...`;
 * - a restatement of the assignment below in another form, such as the branchy version of branchless arithmetic.
 */
final readonly class CommentedOutCodeRule implements RuleInterface
{
    /**
     * Stable rule identifier for commented-out code findings.
     */
    public const ID = 'waste.commented-out-code';

    /**
     * Bytes of source read after a comment to find the statement it may restate; long enough for a chained call over several lines.
     */
    private const FOLLOWING_CODE_WINDOW = 2000;

    /**
     * Compound assignment operators, such as `+=` and `??=`, which assign as surely as `=`.
     *
     * @var list<int>
     */
    private const COMPOUND_ASSIGNMENTS = [
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL,
        T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
    ];

    /**
     * Control keywords and non-assignment operators that set a restatement apart from the line below it.
     *
     * @var list<int|string>
     */
    private const OPERATOR_TOKENS = [
        '+', '-', '*', '/', '%', '|', '^', '~', '<', '>', '!', '.', '?', T_POW, T_SL, T_SR, T_IS_EQUAL, T_IS_IDENTICAL,
        T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP, T_BOOLEAN_AND,
        T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_COALESCE, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
        T_IF, T_ELSEIF, T_ELSE, T_FOR, T_FOREACH, T_WHILE, T_SWITCH, T_MATCH,
    ];

    /**
     * Describes the rule for `list-rules` and for each finding it reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence and advisory: a comment that quotes code can still be prose, so the user decides.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Commented-out code',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'Explanatory prose that names a variable and an assignment in the same sentence, which alone reaches the two-signal bar.',
                    'mitigation' => 'Signals are counted per line with no parse, so reword the comment to describe the behaviour without writing the assignment out.',
                ],
            ],
        );
    }

    /**
     * Reports each comment that reads as disabled code rather than an explanation.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One advisory per disabled-code comment; empty when every comment is prose, a docblock or a restatement.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];

        // The parser keeps only comment tokens, so every token here is a comment of some kind.
        foreach ($analysisUnit->tokens as $token) {
            // Docblocks document an API; only line and block comments can hold disabled code.
            if (!$this->isPlainComment($token)) {
                continue;
            }

            $text = $token->text;
            $line = $token->line;

            // A `/**` block outside a declaration is still documentation, never disabled code.
            if (str_starts_with(trim($text), '/**')) {
                continue;
            }

            $commentBody = $this->commentBody($text);

            // Code-shaped text reports, unless it explains the assignment right below it in another form.
            if ($this->looksLikeDisabledCode($commentBody) && !$this->restatesAssignmentBelow($commentBody, $analysisUnit->source, $token->pos + strlen($text))) {
                $findings[] = new Finding(
                    ruleId:      $definition->id,
                    message:     'Comment appears to contain commented-out code.',
                    filePath:    $analysisUnit->file->displayPath,
                    line:        $line,
                    severity:    $definition->defaultSeverity,
                    pillar:      $definition->pillar,
                    tier:        $definition->tier,
                    confidence:  $definition->confidence,
                    remediation: 'Remove commented-out code or restore it if still needed.',
                );
            }
        }

        return $findings;
    }

    /**
     * Reports whether a token is a plain line or block comment rather than a docblock.
     *
     * @param Token $token - Comment token from the parsed file.
     *
     * @return bool - True for a plain comment; false for a `/**` docblock.
     */
    private function isPlainComment(Token $token): bool
    {
        // Docblocks lex as T_DOC_COMMENT, so matching only T_COMMENT leaves them out.
        return $token->id === T_COMMENT;
    }

    /**
     * Returns a comment's text without its line-comment slashes or block-comment delimiters.
     *
     * @param string $text - Raw comment token text.
     *
     * @return string - Trimmed comment body; empty for a comment holding only markers.
     */
    private function commentBody(string $text): string
    {
        // Strip the surrounding `/*` and `*/` of a block comment.
        $text = preg_replace('/^\/\*+\s*|\s*\*+\/$/', '', $text) ?? $text;
        // Strip the leading `*` decoration from each block-comment line.
        $text = preg_replace('/^\s*\*\s?/m', '', $text) ?? $text;
        // Strip the leading `//` from each line comment.
        $text = preg_replace('/^\/\/\s?/m', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Reports whether a comment body carries enough code signals to read as disabled code.
     *
     * @param string $commentBody - Comment text without its markers.
     *
     * @return bool - True at two or more signals; false for short comments, blank comments and prose.
     */
    private function looksLikeDisabledCode(string $commentBody): bool
    {
        // A body under five characters, such as `// $x`, is too short to be a statement.
        if (strlen($commentBody) < 5) {
            return false;
        }

        $commentLines = array_filter(explode("\n", $commentBody), static fn (string $commentLine): bool => trim($commentLine) !== '');

        // A comment holding only blank lines has nothing to score.
        if ($commentLines === []) {
            return false;
        }

        $codeSignals = 0;

        // Each line is scored on its own, so prose lines in a block do not add to a disabled line's score.
        foreach ($commentLines as $commentLine) {
            $commentLine = trim($commentLine);
            $lineSignals = 0;

            // A semicolon with a variable reads like a statement, such as `$total = $a + $b;`.
            if (str_contains($commentLine, ';') && preg_match('/\$\w/', $commentLine) === 1) {
                $lineSignals++;
            }

            // A line opening with a control or output keyword and `(`, such as `if ($x)`, reads like code.
            if (preg_match('/^(if|for|foreach|while|return|throw|echo)\s*\(/', $commentLine) === 1) {
                $lineSignals++;
            }

            // A method call on a variable, such as `$service->process(`, reads like code.
            if (preg_match('/\$\w+\s*->\s*\w+\s*\(/', $commentLine) === 1) {
                $lineSignals++;
            }

            // An assignment to a variable, such as `$result =`, reads like code.
            if (preg_match('/\$\w+\s*=\s*/', $commentLine) === 1) {
                $lineSignals++;
            }

            // A line that quotes code inside a sentence, or a placeholder, scores nothing however code-shaped its words look.
            if ($lineSignals > 0 && !$this->readsAsProseOrPlaceholder($commentLine)) {
                $codeSignals += $lineSignals;
            }
        }

        // Two signals keep a lone `$`-mention or stray keyword in prose below the bar.
        return $codeSignals >= 2;
    }

    /**
     * Reports whether a comment line reads as prose or a placeholder rather than a statement.
     *
     * Nested `// notes` at the end of a line are ignored, so `$canAccess = true; // Admins can view all` still reads as code.
     *
     * @param string $commentLine - Trimmed comment line.
     *
     * @return bool - True for two bare words in a row, a no-op `$v = $v;`, or an example ending in `...`.
     */
    private function readsAsProseOrPlaceholder(string $commentLine): bool
    {
        $lineTokens = $this->meaningfulTokens($commentLine);
        $lastToken  = end($lineTokens);
        // A trailing ellipsis, as in `$i=0 : 1; $i=1 : 26; ...`, shows a pattern rather than code to restore.
        if ($lastToken instanceof Token && $lastToken->id === T_ELLIPSIS) {
            return true;
        }

        $tokenTexts = array_map(static fn(Token $token): string => $token->text, $lineTokens);
        // Assigning a variable to itself, as in `$userAgent = $userAgent;`, has nothing to restore.
        if (count($lineTokens) === 4 && $lineTokens[0]->id === T_VARIABLE && $tokenTexts[1] === '=' && $tokenTexts[2] === $tokenTexts[0] && $tokenTexts[3] === ';') {
            return true;
        }

        // Two bare words in a row, or a word before a literal as in "returns 09", is a sentence, never a PHP statement.
        foreach ($lineTokens as $index => $token) {
            $nextToken = $lineTokens[$index + 1] ?? null;
            // A keyword lexes as its own token, so only plain words pair up here.
            if ($token->id === T_STRING && $nextToken instanceof Token && $nextToken->is([T_STRING, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a comment restates, in a different form, the assignment directly below it.
     *
     * The comment must assign the same target, use at least two variables that all appear below, call nothing the line lacks,
     * and carry an operator or keyword the line lacks, such as `if` above branchless arithmetic; an older version of the line has none.
     *
     * @param string $commentBody - Comment text without its markers.
     * @param string $source - Full file text.
     * @param int    $commentEnd - Byte offset just past the comment.
     *
     * @return bool - True when the comment documents an equivalent form of the next assignment; false keeps the finding.
     */
    private function restatesAssignmentBelow(string $commentBody, string $source, int $commentEnd): bool
    {
        $assignmentTokens = $this->assignmentBelow($source, $commentEnd);
        $commentTokens    = $this->meaningfulTokens($commentBody);
        // With no assignment below, or nothing lexable in the comment, there is nothing to compare.
        if ($assignmentTokens === [] || $commentTokens === []) {
            return false;
        }

        $commentTargets = [];
        // Collect the variables the comment assigns to.
        foreach ($commentTokens as $index => $token) {
            $previousToken = $commentTokens[$index - 1] ?? null;
            $isMember      = $previousToken instanceof Token && $previousToken->is([T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);
            // A variable right before an assignment operator is a target, unless it names a member such as `Registry::$total`.
            if ($token->id === T_VARIABLE && !$isMember && $this->isAssignmentOperator($commentTokens[$index + 1] ?? null)) {
                $commentTargets[] = $token->text;
            }
        }

        $commentVariables = array_diff($this->variableNames($commentTokens), ['$this']);

        return in_array($assignmentTokens[0]->text, $commentTargets, true)
            && count($commentVariables) >= 2
            && array_diff($commentVariables, $this->variableNames($assignmentTokens)) === []
            && array_diff($this->calledFunctionNames($commentTokens), $this->calledFunctionNames($assignmentTokens)) === []
            && array_diff($this->operatorsAndKeywords($commentTokens), $this->operatorsAndKeywords($assignmentTokens)) !== [];
    }

    /**
     * Returns the assignment statement right below a comment, up to its semicolon.
     *
     * @param string $source - Full file text.
     * @param int    $commentEnd - Byte offset just past the comment.
     *
     * @return list<Token> - tokens of `$target = ...;`; empty when the next code is another comment, not an assignment to a variable,
     *               or opens or closes a block before its semicolon
     */
    private function assignmentBelow(string $source, int $commentEnd): array
    {
        $followingTokens = $this->meaningfulTokens(substr($source, $commentEnd, self::FOLLOWING_CODE_WINDOW), false);
        // Another comment next, or code that is not an assignment, cannot be what the comment restates.
        if ($followingTokens === [] || $followingTokens[0]->id !== T_VARIABLE || !$this->isAssignmentOperator($followingTokens[1] ?? null)) {
            return [];
        }

        $bracketDepth = 0;
        // Read to the first semicolon outside brackets, the end of a simple statement.
        foreach ($followingTokens as $index => $token) {
            $bracketDepth += match ($token->text) {
                '(', '[' => 1,
                ')', ']' => -1,
                default => 0,
            };
            // The statement ends here.
            if ($bracketDepth === 0 && $token->text === ';') {
                return array_slice($followingTokens, 0, $index + 1);
            }

            // A brace first, as in `$mode = match (true) { ... };`, means this is not a simple assignment to compare.
            if ($bracketDepth === 0 && ($token->text === '{' || $token->text === '}')) {
                return [];
            }
        }

        return [];
    }

    /**
     * Lexes a snippet and keeps the tokens that carry meaning.
     *
     * @param string $snippet - Code-shaped text without an opening tag.
     * @param bool   $dropComments - Whether nested comments are dropped as well as whitespace.
     *
     * @return list<Token> - tokens in order, without the opening tag and whitespace, and without comments when asked; empty for blank text
     */
    private function meaningfulTokens(string $snippet, bool $dropComments = true): array
    {
        $ignoredKinds = $dropComments ? [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT] : [T_OPEN_TAG, T_WHITESPACE];

        return array_values(array_filter(
            Token::tokenize('<?php ' . $snippet),
            static fn(Token $token): bool => !$token->is($ignoredKinds),
        ));
    }

    /**
     * Reports whether a token is an assignment operator.
     *
     * @param Token|null $token - Token after a variable; null when the variable ends the snippet.
     *
     * @return bool - True for `=` and every compound assignment operator.
     */
    private function isAssignmentOperator(?Token $token): bool
    {
        return $token instanceof Token && ($token->text === '=' || $token->is(self::COMPOUND_ASSIGNMENTS));
    }

    /**
     * Lists the distinct variables in a token list.
     *
     * @param list<Token> $tokens - Meaningful tokens of a comment or statement.
     *
     * @return list<string> - distinct variable names with their `$`; empty when none appear
     */
    private function variableNames(array $tokens): array
    {
        return array_values(array_unique(array_map(
            static fn(Token $token): string => $token->text,
            array_filter($tokens, static fn(Token $token): bool => $token->id === T_VARIABLE),
        )));
    }

    /**
     * Lists the distinct function and method names called in a token list.
     *
     * @param list<Token> $tokens - Meaningful tokens of a comment or statement.
     *
     * @return list<string> - lower-cased names directly followed by `(`; empty when nothing is called
     */
    private function calledFunctionNames(array $tokens): array
    {
        $calledNames = [];
        // A bare name followed by `(` is a call, such as `substr(` or `->add(`.
        foreach ($tokens as $index => $token) {
            // Keywords lex as their own tokens, so only real function and method names are collected.
            if ($token->id === T_STRING && ($tokens[$index + 1] ?? null)?->text === '(') {
                $calledNames[] = strtolower($token->text);
            }
        }

        return array_values(array_unique($calledNames));
    }

    /**
     * Lists the distinct control keywords and non-assignment operators in a token list.
     *
     * @param list<Token> $tokens - Meaningful tokens of a comment or statement.
     *
     * @return list<string> - lower-cased operator and keyword texts, both ampersand kinds read as `&`; empty when none appear
     */
    private function operatorsAndKeywords(array $tokens): array
    {
        $found = [];
        // Each token that is an operator or control keyword is recorded once.
        foreach ($tokens as $token) {
            // PHP lexes `&` as one of two tokens depending on what follows; both mean bitwise and here.
            if ($token->is([T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG])) {
                $found[] = '&';
            } elseif ($token->is(self::OPERATOR_TOKENS)) {
                // Keywords such as `if` are lower-cased so `IF` and `if` count once.
                $found[] = strtolower($token->text);
            }
        }

        return array_values(array_unique($found));
    }
}
