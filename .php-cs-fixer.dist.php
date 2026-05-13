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
     * Return the custom fixer rule name.
     *
     * @return string Fixture value.
     */
    public function getName(): string
    {
        return 'GruffPhp/align_named_arguments';
    }

    /**
     * Return the custom fixer definition.
     *
     * @return FixerDefinitionInterface Fixture value.
     */
    public function getDefinition(): FixerDefinitionInterface
    {
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
     * Return the custom fixer priority.
     *
     * @return int Fixture value.
     */
    public function getPriority(): int
    {
        return -100;
    }

    /**
     * Report whether the token stream may contain named arguments.
     *
     * @param Tokens $tokens Token stream to fix.
     * @return bool True when the assertion condition is met.
     */
    public function isCandidate(Tokens $tokens): bool
    {
        return str_contains($tokens->generateCode(), ':');
    }

    /**
     * Apply named-argument alignment to a token stream.
     *
     * @param SplFileInfo $file File being fixed.
     * @param Tokens $tokens Token stream to fix.
     * @return void No return value.
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
     * @return string Fixture value.
     */
    private function alignNamedArgumentGroups(string $code): string
    {
        $parts = preg_split('/(\R)/', $code, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!is_array($parts)) {
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

        return implode('', $lines);
    }

    /**
     * Report whether a source line contains a named argument.
     *
     * @param string $line Finding line number.
     * @return bool True when the assertion condition is met.
     */
    private function isNamedArgumentLine(string $line): bool
    {
        return preg_match('/^[ \t]+[A-Za-z_][A-Za-z0-9_]*\s*:(?!:)\s*\S.*(?:,)?\R?$/', $line) === 1;
    }

    /**
     * @param list<string> $lines
     * @param list<int>    $group
     */
    private function alignGroup(array &$lines, array $group): void
    {
        if (count($group) < 2) {
            return;
        }

        $rows          = [];
        $maxNameLength = 0;

        foreach ($group as $index) {
            if (preg_match('/^(?<indent>[ \t]+)(?<name>[A-Za-z_][A-Za-z0-9_]*)\s*:(?!:)\s*(?<value>\S.*?)(?<eol>\R?)$/', $lines[$index], $matches) !== 1) {
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
