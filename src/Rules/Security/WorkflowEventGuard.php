<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

/**
 * Proves only source-owned workflow guards unreachable for all currently detected PR events.
 *
 * @phpstan-type GuardNode array{key: string, content: string, indent: int, start: int, end: int, isItem: bool, children: list<int>}
 * @phpstan-type GuardParser array{position: int, isValid: bool, tokens: list<string>, event: string}
 * @phpstan-type GuardOperand array{kind: string, literal: string, truth: bool|null}
 */
final class WorkflowEventGuard
{
    /**
     * Returns inclusive lines whose own job or step rejects every detected PR event.
     *
     * @param string $source - Raw workflow source.
     * @return array<int, true> - Unreachable source lines.
     */
    public static function unreachableSecretLines(string $source): array
    {
        $events = self::coveredEvents($source);
        $nodes  = self::ownership($source);
        if ($events === [] || $nodes === null) {
            return [];
        }
        $jobs = self::child($nodes, 0, 'jobs');
        if ($jobs === null || $nodes[$jobs]['content'] !== '') {
            return [];
        }

        $blocked = [];
        foreach ($nodes[$jobs]['children'] as $job) {
            if ($nodes[$job]['isItem'] || $nodes[$job]['content'] !== '') {
                continue;
            }
            self::markIfRejected($nodes, $job, $events, $blocked);
            $steps = self::child($nodes, $job, 'steps');
            if ($steps === null || $nodes[$steps]['content'] !== '') {
                continue;
            }
            foreach ($nodes[$steps]['children'] as $step) {
                if ($nodes[$step]['isItem']) {
                    self::markIfRejected($nodes, $step, $events, $blocked);
                }
            }
        }
        return $blocked;
    }

    /**
     * Mirrors the existing detector's mapping/list event forms, including its broader plain PR policy.
     *
     * @param string $source - Workflow source used by the existing trigger detector.
     * @return list<string> - Each currently covered event present in the source.
     */
    private static function coveredEvents(string $source): array
    {
        $events = [];
        foreach (['pull_request', 'pull_request_target'] as $event) {
            // Preserve exactly the current mapping and list trigger forms.
            if (preg_match('/^\s*' . $event . '\s*:/m', $source) === 1
                || preg_match('/^\s*-\s*' . $event . '\s*(?:#.*)?$/m', $source) === 1) {
                $events[] = $event;
            }
        }
        return $events;
    }

    /**
     * Direct children prevent nested misleading keys from supplying a guard.
     *
     * @param list<GuardNode> $nodes  - Source ownership arena.
     * @param int             $parent - Owning node index.
     * @param string          $key    - Direct mapping key to locate.
     * @return int|null - Matching child index.
     */
    private static function child(array $nodes, int $parent, string $key): ?int
    {
        foreach ($nodes[$parent]['children'] as $index) {
            if (!$nodes[$index]['isItem'] && $nodes[$index]['key'] === $key) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Late conditions govern earlier secret references in the same inclusive scope.
     *
     * @param list<GuardNode>  $nodes   - Complete source ranges.
     * @param int              $owner   - Job or step index.
     * @param list<string>     $events  - All currently covered PR events.
     * @param array<int, true> $blocked - Lines already proven unreachable, updated in place.
     * @return void
     */
    private static function markIfRejected(array $nodes, int $owner, array $events, array &$blocked): void
    {
        $guard = self::child($nodes, $owner, 'if');
        if ($guard === null) {
            return;
        }
        foreach ($events as $event) {
            if (self::isEventReachable($nodes[$guard]['content'], $event) !== false) {
                return;
            }
        }
        for ($line = $nodes[$owner]['start']; $line <= $nodes[$owner]['end']; ++$line) {
            $blocked[$line] = true;
        }
    }

    /**
     * Preserves quoted hashes; unmatched quotes retain every warning.
     *
     * @param string $raw - One raw YAML line.
     * @return string|null - Comment-free trimmed text, or unsupported quoting.
     */
    private static function yamlText(string $raw): ?string
    {
        for ($index = 0; $index < strlen($raw); ++$index) {
            if ($raw[$index] === "'" || $raw[$index] === '"') {
                // Consume one complete YAML quoted scalar before looking for comments.
                if (preg_match('/^(?:\'(?:[^\']|\'\')*\'|"(?:[^"\\\\]|\\\\.)*")/', substr($raw, $index), $quoted) !== 1) {
                    return null;
                }
                $index += strlen($quoted[0]) - 1;
                continue;
            }
            if ($raw[$index] === '#' && ($index === 0 || ctype_space($raw[$index - 1]))) {
                return trim(substr($raw, 0, $index));
            }
        }
        return trim($raw);
    }

    /**
     * Completes ranges before evaluating; duplicates, aliases and unsupported structure veto proof.
     *
     * @param string $source - Full raw YAML source.
     * @return list<GuardNode>|null - Source ownership arena, or ambiguous ownership.
     */
    private static function ownership(string $source): ?array
    {
        $lines        = explode("\n", $source);
        $total        = count($lines);
        $nodes        = [self::node(-1, 1, $total)];
        $stack        = [0];
        $scalarIndent = -1;
        foreach ($lines as $index => $raw) {
            if (trim($raw) === '' || str_starts_with(ltrim($raw), '#')) {
                continue;
            }
            $indent = strlen($raw) - strlen(ltrim($raw, ' '));
            if ($scalarIndent >= 0 && $indent > $scalarIndent) {
                continue;
            }
            $scalarIndent = -1;
            if (str_starts_with(substr($raw, $indent), "\t")) {
                return null;
            }
            $text = self::yamlText($raw);
            if ($text === null) {
                return null;
            }
            $nodeIndex = self::attachLine($nodes, $stack, $text, self::node($indent, $index + 1, $total));
            if ($nodeIndex === null) {
                return null;
            }
            // A block-scalar header masks all more-indented text from guard discovery.
            if (preg_match('/^[|>](?:[+-]?\d*|\d*[+-]?)$/', $nodes[$nodeIndex]['content']) === 1) {
                $scalarIndent = $nodes[$nodeIndex]['indent'];
            }
        }
        return $nodes;
    }

    /**
     * Attaches one mapping or sequence line after closing its previous siblings.
     *
     * @param list<GuardNode> $nodes - Ownership arena updated in place.
     * @param list<int>       $stack - Open owners updated in place.
     * @param string          $text  - Comment-free line text.
     * @param GuardNode       $node  - Source location record.
     * @return int|null - Attached entry, or ambiguous structure.
     */
    private static function attachLine(array &$nodes, array &$stack, string $text, array $node): ?int
    {
        // A dash followed by space introduces a source-owned sequence item.
        preg_match('/^-(?: +|$)/', $text, $itemPrefix);
        $prefix = $itemPrefix[0] ?? '';
        $parent = self::parent($nodes, $stack, $node['indent'], $node['start'] - 1, $prefix !== '');
        if ($parent === null) {
            return null;
        }
        if ($prefix !== '') {
            $step           = $node;
            $step['isItem'] = true;
            $parent         = self::append($nodes, $parent, $step);
            $stack[]        = $parent;
        }
        $node['indent'] += strlen($prefix);
        $nodeIndex = self::mappingEntry($nodes, $parent, substr($text, strlen($prefix)), $node, $prefix !== '');
        if ($nodeIndex !== null && $nodeIndex !== $parent) {
            $stack[] = $nodeIndex;
        }
        return $nodeIndex;
    }

    /**
     * Creates a location record shared by mapping and sequence entries.
     *
     * @param int $indent   - Source column owning the entry.
     * @param int $start    - Inclusive first line.
     * @param int $lastLine - Inclusive last line.
     * @return GuardNode - Empty ownership entry.
     */
    private static function node(int $indent, int $start, int $lastLine): array
    {
        return ['key' => '', 'content' => '', 'indent' => $indent, 'start' => $start, 'end' => $lastLine, 'isItem' => false, 'children' => []];
    }

    /**
     * Closes siblings and rejects mapping/sequence mixtures under one owner.
     *
     * @param list<GuardNode> $nodes  - Arena updated with closed ranges.
     * @param list<int>       $stack  - Open owners, updated in place.
     * @param int             $indent - Current raw indentation.
     * @param int             $index  - Zero-based source line.
     * @param bool            $isItem - Whether this line begins a sequence item.
     * @return int|null - Current owning parent.
     */
    private static function parent(array &$nodes, array &$stack, int $indent, int $index, bool $isItem): ?int
    {
        while (count($stack) > 1 && $nodes[$stack[count($stack) - 1]]['indent'] >= $indent) {
            $closed            = array_pop($stack);
            $closedNode        = $nodes[$closed];
            $closedNode['end'] = $index;
            $nodes[$closed]    = $closedNode;
        }
        $parent = $stack[count($stack) - 1];
        if ($nodes[$parent]['content'] !== '') {
            return null;
        }
        foreach ($nodes[$parent]['children'] as $child) {
            if ($nodes[$child]['isItem'] !== $isItem) {
                return null;
            }
        }
        return $parent;
    }

    /**
     * Appends a direct child while preserving arena indices.
     *
     * @param list<GuardNode> $nodes  - Arena updated in place.
     * @param int             $parent - Owning parent index.
     * @param GuardNode       $node   - Child entry.
     * @return int - New child index.
     */
    private static function append(array &$nodes, int $parent, array $node): int
    {
        $index                    = count($nodes);
        $nodes[]                  = $node;
        $parentNode               = $nodes[$parent];
        $parentNode['children'][] = $index;
        $nodes[$parent]           = $parentNode;
        return $index;
    }

    /**
     * Accepts bounded keys and scalar list values without granting scalar values guards.
     *
     * @param list<GuardNode> $nodes  - Arena updated in place.
     * @param int             $parent - Direct owning parent.
     * @param string          $text   - Entry text after any list prefix.
     * @param GuardNode       $node   - Source location record.
     * @param bool            $isItem - Whether scalar sequence content is allowed.
     * @return int|null - Added node, scalar item owner, or unsupported entry.
     */
    private static function mappingEntry(array &$nodes, int $parent, string $text, array $node, bool $isItem): ?int
    {
        // Scalar list aliases are ambiguous even when no mapping key precedes them.
        if (preg_match('/(?:^|\s)[&*][A-Za-z0-9_-]+/', $text) === 1) {
            return null;
        }
        // Accept plain or simple quoted mapping keys with one scalar value.
        if (preg_match('/^(?:([A-Za-z0-9_.-]+)|\'([^\']+)\'|"([^"\\\\]+)"|(<<)):\s*(.*)$/', $text, $match) !== 1) {
            if (!$isItem) {
                return null;
            }
            $parentNode            = $nodes[$parent];
            $parentNode['content'] = $text;
            $nodes[$parent]        = $parentNode;
            return $parent;
        }
        $node['key']     = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : $match[4]));
        $node['content'] = $match[5];
        // Merge keys make ownership ambiguous.
        if ($node['key'] === '<<') {
            return null;
        }
        foreach ($nodes[$parent]['children'] as $child) {
            if ($nodes[$child]['key'] === $node['key']) {
                return null;
            }
        }
        return self::append($nodes, $parent, $node);
    }

    /**
     * Decodes whole YAML strings and optional expression wrappers before bounded tokenization.
     *
     * @param string $content - Guard scalar.
     * @return list<string>|null - Supported complete tokens.
     */
    private static function guardTokens(string $content): ?array
    {
        $expression = trim($content);
        if (str_starts_with($expression, '"')) {
            $decoded = json_decode($expression, true);
            if (!is_string($decoded)) {
                return null;
            }
            $expression = $decoded;
        } elseif (str_starts_with($expression, "'")) {
            // A YAML single-quoted guard must occupy the entire scalar.
            if (preg_match('/^\'(?:[^\']|\'\')*\'$/', $expression) !== 1) {
                return null;
            }
            $expression = str_replace("''", "'", substr($expression, 1, -1));
        }
        $expression = trim($expression);
        if (str_starts_with($expression, '$' . '{{')) {
            if (!str_ends_with($expression, '}}')) {
                return null;
            }
            $expression = trim(substr($expression, 3, -2));
        }
        $tokens = [];
        while ($expression !== '') {
            // Tokenize only the bounded comparison grammar and valid unknown identifiers.
            if (preg_match('/^(?:\s+|\'(?:[^\']|\'\')*\'|[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*|==|!=|&&|\|\||[!()])/', $expression, $match) !== 1) {
                return null;
            }
            if (trim($match[0]) !== '') {
                $tokens[] = $match[0];
            }
            $expression = substr($expression, strlen($match[0]));
            if (count($tokens) > 128) {
                return null;
            }
        }
        return $tokens;
    }

    /**
     * Requires complete valid input before trusting even a false partial result.
     *
     * @param string $content - Guard scalar.
     * @param string $event   - One currently covered event.
     * @return bool|null - Proven event result, or unknown.
     */
    private static function isEventReachable(string $content, string $event): ?bool
    {
        $tokens = self::guardTokens($content);
        if ($tokens === null || $tokens === []) {
            return null;
        }
        $parser = ['position' => 0, 'isValid' => true, 'tokens' => $tokens, 'event' => $event];
        $result = self::isDisjunctionTrue($parser);
        return $parser['isValid'] && $parser['position'] === count($tokens) ? $result : null;
    }

    /**
     * Returns an empty sentinel at the end of the stream.
     *
     * @param GuardParser $parser - Current bounded parser state.
     * @return string - Current token.
     */
    private static function peek(array $parser): string
    {
        return $parser['tokens'][$parser['position']] ?? '';
    }

    /**
     * Parses negation before equality; grouping contains conservative boolean proof.
     *
     * @param GuardParser $parser - Parser state updated in place.
     * @return GuardOperand - Event, literal, or unknown boolean operand.
     */
    private static function unary(array &$parser): array
    {
        $token = self::peek($parser);
        ++$parser['position'];
        if ($token === '!') {
            $operand = self::unary($parser);
            $truth   = $operand['kind'] === 'truth' ? $operand['truth'] : null;
            return ['kind' => 'truth', 'literal' => '', 'truth' => $truth === null ? null : !$truth];
        }
        if ($token === '(') {
            $result = self::isDisjunctionTrue($parser);
            if (self::peek($parser) !== ')') {
                $parser['isValid'] = false;
            }
            ++$parser['position'];
            return ['kind' => 'truth', 'literal' => '', 'truth' => $result];
        }
        if (str_starts_with($token, "'")) {
            return ['kind' => 'literal', 'literal' => str_replace("''", "'", substr($token, 1, -1)), 'truth' => null];
        }
        if ($token === 'github.event_name') {
            return ['kind' => 'event', 'literal' => '', 'truth' => null];
        }
        // Any unsupported primary makes the complete expression unknown.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $token) !== 1) {
            $parser['isValid'] = false;
        }
        return ['kind' => 'truth', 'literal' => '', 'truth' => null];
    }

    /**
     * Exact event/literal equality or inequality is the sole comparison proof.
     *
     * @param GuardParser $parser - Parser state updated in place.
     * @return bool|null - Comparison result or conservative unknown.
     */
    private static function hasEventMatch(array &$parser): ?bool
    {
        $left     = self::unary($parser);
        $operator = self::peek($parser);
        if ($operator !== '==' && $operator !== '!=') {
            return $left['truth'];
        }
        ++$parser['position'];
        $right = self::unary($parser);
        if ($left['kind'] === 'event' && $right['kind'] === 'literal') {
            $literal = $right['literal'];
        } elseif ($right['kind'] === 'event' && $left['kind'] === 'literal') {
            $literal = $left['literal'];
        } else {
            return null;
        }
        $equal = strtolower($parser['event']) === strtolower($literal);
        return $operator === '==' ? $equal : !$equal;
    }

    /**
     * False AND unknown is false, provided every branch parses.
     *
     * @param GuardParser $parser - Parser state updated in place.
     * @return bool|null - Three-valued conjunction result.
     */
    private static function isConjunctionTrue(array &$parser): ?bool
    {
        $result = self::hasEventMatch($parser);
        while (self::peek($parser) === '&&') {
            ++$parser['position'];
            $right  = self::hasEventMatch($parser);
            $result = $result === false || $right === false ? false : ($result === true && $right === true ? true : null);
        }
        return $result;
    }

    /**
     * Unknown OR retains a possibly reachable warning.
     *
     * @param GuardParser $parser - Parser state updated in place.
     * @return bool|null - Three-valued disjunction result.
     */
    private static function isDisjunctionTrue(array &$parser): ?bool
    {
        $result = self::isConjunctionTrue($parser);
        while (self::peek($parser) === '||') {
            ++$parser['position'];
            $right  = self::isConjunctionTrue($parser);
            $result = $result === true || $right === true ? true : ($result === false && $right === false ? false : null);
        }
        return $result;
    }
}
