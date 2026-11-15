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
 * Flags plain comments whose retained lines contribute at least two code signals in total.
 *
 * Signals include assignments, method calls, and selected keywords; recognized prose and placeholder lines contribute none.
 * Docblocks and comments matching the token-based heuristic for restating the next assignment are skipped.
 */
final readonly class CommentedOutCodeRule implements RuleInterface
{
    /**
     * Stable rule identifier for commented-out code findings.
     */
    public const ID = 'waste.commented-out-code';

    /**
     * Maximum source bytes inspected after a comment when looking for an assignment it may restate.
     */
    private const FOLLOWING_CODE_WINDOW = 2000;

    /**
     * Compound assignment operators, such as `+=` and `??=`, which assign as surely as `=`.
     *
     * @var list<int> - Token IDs accepted when identifying assignment targets.
     */
    private const COMPOUND_ASSIGNMENTS = [
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL,
        T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
    ];

    /**
     * Control keywords and non-assignment operators that set a restatement apart from the line below it.
     *
     * @var list<int|string> - Token IDs and literal operators used to compare a comment with the following assignment.
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
     * Reports plain comments that meet the code-signal threshold and are not recognized as restatements.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file the user asked to analyse.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One advisory per comment passing both heuristic checks; empty when none qualify.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $findings   = [];


        foreach ($analysisUnit->tokens as $token) {
            // Treat docblocks as documentation even when they contain code examples.
            if (!$this->isPlainComment($token)) {
                continue;
            }

            $text = $token->text;
            $line = $token->line;

            // A documentation-style opener remains exempt even when lexed as a plain comment.
            if (str_starts_with(trim($text), '/**')) {
                continue;
            }

            $commentBody = $this->commentBody($text);

            // Code-shaped text reports unless the token heuristic matches it to the following assignment.
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
     * Reports whether a parser token has the plain-comment token kind.
     *
     * @param Token $token - Token to classify from the parser's retained token list.
     *
     * @return bool - True only for T_COMMENT; false for docblocks and all other token kinds.
     */
    private function isPlainComment(Token $token): bool
    {
        // Docblocks lex as T_DOC_COMMENT, so matching only T_COMMENT leaves them out.
        return $token->id === T_COMMENT;
    }

    /**
     * Removes slash-based comment delimiters and leading block-comment decoration.
     *
     * @param string $text - Raw comment token text.
     *
     * @return string - Trimmed body; empty when only slash-based markers, block decoration, and whitespace remain.
     */
    private function commentBody(string $text): string
    {
        // Remove delimiters only at token boundaries so slash content inside the block comment remains available for scoring.
        $text = preg_replace('/^\/\*+\s*|\s*\*+\/$/', '', $text) ?? $text;
        // Normalize block-comment decoration so each line is scored from its content rather than its leading star.
        $text = preg_replace('/^\s*\*\s?/m', '', $text) ?? $text;
        // Normalize line-comment markers so both comment styles feed the same scoring heuristic.
        $text = preg_replace('/^\/\/\s?/m', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Reports whether a comment body carries enough code signals to read as disabled code.
     *
     * @param string $commentBody - Comment text after commentBody has stripped the supported markers.
     *
     * @return bool - True for bodies of at least five bytes with at least two signals across non-exempt lines; false otherwise.
     */
    private function looksLikeDisabledCode(string $commentBody): bool
    {

        if (strlen($commentBody) < 5) {
            return false;
        }

        $commentLines = array_filter(explode("\n", $commentBody), static fn (string $commentLine): bool => trim($commentLine) !== '');


        if ($commentLines === []) {
            return false;
        }

        $codeSignals = 0;

        // Recognized prose and placeholder lines do not contribute to the comment's combined score.
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
     * Checks a comment line for the token patterns treated as prose or placeholders.
     *
     * Nested `// notes` at the end of a line are ignored, so `$canAccess = true; // Admins can view all` still reads as code.
     *
     * @param string $commentLine - Trimmed comment line.
     *
     * @return bool - True for trailing ellipsis, a lone `$v = $v;`, or T_STRING followed by T_STRING or a numeric/string literal; false otherwise.
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

        // Adjacent names or a name before a literal, such as "returns 09", are treated as prose.
        foreach ($lineTokens as $index => $token) {
            $nextToken = $lineTokens[$index + 1] ?? null;
            // A keyword alone is not evidence of prose because PHP statements use keywords.
            if ($token->id === T_STRING && $nextToken instanceof Token && $nextToken->is([T_STRING, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a comment meets the token heuristic for restating the following assignment.
     *
     * A match uses the target and two shared non-$this variables, adds an operator or keyword, and adds no call name missing from the assignment.
     *
     * @param string $commentBody - Comment text after commentBody has stripped the supported markers.
     * @param string $source - Full file text.
     * @param int    $commentEnd - Byte offset just past the comment.
     *
     * @return bool - True when the token checks recognize a restatement; false leaves the comment eligible for a finding.
     */
    private function restatesAssignmentBelow(string $commentBody, string $source, int $commentEnd): bool
    {
        $assignmentTokens = $this->assignmentBelow($source, $commentEnd);
        $commentTokens    = $this->meaningfulTokens($commentBody);

        if ($assignmentTokens === [] || $commentTokens === []) {
            return false;
        }

        $commentTargets = [];

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
     * Returns the immediately following simple variable assignment, including its semicolon, within the lookahead window.
     *
     * @param string $source - Full file text.
     * @param int    $commentEnd - Byte offset just past the comment.
     *
     * @return list<Token> - Tokens through the semicolon; empty for a non-assignment, intervening comment, top-level brace, or exhausted lookahead.
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
     * Lists distinct function and method call-name candidates in a token list.
     *
     * @param list<Token> $tokens - Meaningful tokens of a comment or statement.
     *
     * @return list<string> - Lowercase T_STRING texts followed by an opening parenthesis; empty when none qualify.
     */
    private function calledFunctionNames(array $tokens): array
    {
        $calledNames = [];
        foreach ($tokens as $index => $token) {
            // Keywords use other token kinds, so they are excluded from these candidates.
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

        foreach ($tokens as $token) {
            // Normalize PHP's two ampersand token kinds so both compare as `&`.
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
