<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Finder;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Align multiline named-argument values in the same style as PhpStorm's code reformat.
 */
final class AlignNamedArgumentsFixer extends PhpCsFixer\AbstractFixer
{
    /**
     * Identify the in-repo named-argument alignment fixer.
     *
     * @return string
     */
    public function getName(): string
    {
        // This literal string is PHP-CS-Fixer's registration key; setRules() must reference the same value.
        return 'GruffPhp/align_named_arguments';
    }

    /**
     * Describe the named-argument alignment fixer for PHP-CS-Fixer.
     *
     * @return FixerDefinitionInterface
     */
    public function getDefinition(): FixerDefinitionInterface
    {
        // Summary plus a worked sample is the metadata PHP-CS-Fixer shows for a custom fixer.
        return new FixerDefinition(
            'Aligns values in consecutive multiline named-argument groups.',
            [
                new CodeSample(
                    "<?php\nnew Example(\n    id: self::ID,\n    defaultSeverity: Severity::Warning,\n);\n",
                ),
            ],
        );
    }

    /**
     * Run after built-in whitespace fixers so alignment sees final argument layout.
     *
     * @return int
     */
    public function getPriority(): int
    {
        // Negative priority sequences this fixer after the whitespace fixers, so it aligns final layout.
        return -100;
    }

    /**
     * Report whether the token stream may contain named arguments.
     *
     * @param Tokens $tokens Token stream to fix.
     * @return bool
     */
    public function isCandidate(Tokens $tokens): bool
    {
        $code = $tokens->generateCode();

        // A colon is the cheapest necessary signal of a named argument; skip the full pass without one.
        return str_contains($code, ':');
    }

    /**
     * Apply named-argument alignment to a token stream.
     *
     * @param SplFileInfo $file   File being fixed.
     * @param Tokens      $tokens Token stream to fix.
     * @return void
     */
    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $code  = $tokens->generateCode();
        $fixed = $this->alignNamedArgumentGroups($code);

        if ($fixed !== $code) {
            $tokens->setCode($fixed);
        }
    }

    /**
     * Align consecutive multiline named-argument groups in source code.
     *
     * @param string $code Source code to inspect.
     * @return string
     */
    private function alignNamedArgumentGroups(string $code): string
    {
        $parts = preg_split('/(\R)/', $code, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!is_array($parts)) {
            // Split failed, so hand back the source unchanged rather than corrupt it.
            return $code;
        }

        $lines = [];
        for ($index = 0; $index < count($parts); $index += 2) {
            $line       = $parts[$index];
            $lineEnding = $parts[$index + 1] ?? '';

            if ($line === '' && $lineEnding === '') {
                continue;
            }

            $lines[] = $line . $lineEnding;
        }

        $group = [];
        foreach ($lines as $index => $line) {
            if ($this->isNamedArgumentLine($line)) {
                $group[] = $index;
                continue;
            }

            $this->alignGroup($lines, $group);
            $group = [];
        }

        $this->alignGroup($lines, $group);

        // Each entry already carries its own line ending, so join with no separator; '\R' would double them.
        return implode('', $lines);
    }

    /**
     * Report whether a source line contains a named argument.
     *
     * @param string $line Source line to inspect.
     * @return bool
     */
    private function isNamedArgumentLine(string $line): bool
    {
        $namedArgumentPattern = '/^[ \t]+[A-Za-z_][A-Za-z0-9_]*\s*:(?!:)\s*\S.*(?:,)?\R?$/';

        // Match an indented named argument line without confusing `::` for an argument separator.
        return preg_match($namedArgumentPattern, $line) === 1;
    }

    /**
     * Align one consecutive block of multiline named arguments.
     *
     * @param list<string> $lines
     * @param list<int>    $group
     * @return void
     */
    private function alignGroup(array &$lines, array $group): void
    {
        if (count($group) < 2) {
            // A lone named argument has nothing to align against, so leave it as written.
            return;
        }

        $rows          = [];
        $maxNameLength = 0;

        foreach ($group as $index) {
            // Capture indentation, argument name, value, and line ending before aligning the group.
            if (preg_match('/^(?<indent>[ \t]+)(?<name>[A-Za-z_][A-Za-z0-9_]*)\s*:(?!:)\s*(?<value>\S.*?)(?<eol>\R?)$/', $lines[$index], $matches) !== 1) {
                // One unparsable row means we cannot align the block safely, so abandon the whole group.
                return;
            }

            $rows[$index]  = $matches;
            $maxNameLength = max($maxNameLength, strlen($matches['name']));
        }

        foreach ($rows as $index => $row) {
            $spaces        = str_repeat(' ', $maxNameLength - strlen($row['name']) + 1);
            $lines[$index] = $row['indent'] . $row['name'] . ':' . $spaces . rtrim($row['value']) . $row['eol'];
        }
    }
}

$finder = Finder::create()
    ->in([
        __DIR__ . '/bin',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->exclude([
        'Fixtures',
    ]);

$config = new Config();
$config->registerCustomFixers([new AlignNamedArgumentsFixer()]);

// PHP-CS-Fixer loads this file expecting the fully configured Config; hand back the rule set and finder.
return $config
    ->setRiskyAllowed(false)
    ->setRules([
        'GruffPhp/align_named_arguments' => true,
        'binary_operator_spaces' => [
            'default' => 'single_space',
            'operators' => [
                '=' => 'align_single_space_minimal',
            ],
        ],
        'encoding' => true,
        'indentation_type' => true,
        'line_ending' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'phpdoc_align' => [
            'align' => 'vertical',
            'tags' => ['param', 'phpstan-param', 'psalm-param'],
        ],
        'single_blank_line_at_eof' => true,
    ])
    ->setFinder($finder);
