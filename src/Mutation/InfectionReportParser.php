<?php

declare(strict_types=1);

namespace GruffPhp\Mutation;

use JsonException;

final readonly class InfectionReportParser
{
    public function __construct(private string $projectRoot)
    {
    }

    public function parse(string $path): InfectionReport
    {
        $resolvedPath = $this->resolvePath($path);
        $contents = file_get_contents($resolvedPath);

        if ($contents === false) {
            throw new MutationReportException(sprintf('Unable to read Infection report: %s', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new MutationReportException(sprintf('Invalid Infection JSON report "%s": %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new MutationReportException(sprintf('Infection report "%s" must be a JSON object.', $path));
        }

        $stats = $this->parseStats($decoded, $path);
        $mutants = [];

        foreach ($this->statusSections() as $section => $status) {
            $rows = $decoded[$section] ?? [];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new MutationReportException(sprintf('Infection report "%s" section "%s" must be a JSON array.', $path, $section));
            }

            foreach ($rows as $index => $row) {
                $mutants[] = $this->parseMutant($row, $status, sprintf('%s[%d]', $section, $index), $path);
            }
        }

        return new InfectionReport($this->displayPath($resolvedPath), $stats, $mutants);
    }

    private function resolvePath(string $path): string
    {
        $candidate = $path[0] === '/' ? $path : $this->projectRoot . '/' . $path;

        if (!is_file($candidate)) {
            throw new MutationReportException(sprintf('Infection report not found: %s', $path));
        }

        $realPath = realpath($candidate);

        return $realPath === false ? $candidate : $realPath;
    }

    /**
     * @param array<mixed> $decoded
     * @return array<string, int|float>
     */
    private function parseStats(array $decoded, string $path): array
    {
        $rawStats = $decoded['stats'] ?? null;
        if (!is_array($rawStats) || array_is_list($rawStats)) {
            throw new MutationReportException(sprintf('Infection report "%s" must contain a "stats" object.', $path));
        }

        $stats = [];
        foreach ($rawStats as $key => $value) {
            if (!is_string($key) || (!is_int($value) && !is_float($value))) {
                throw new MutationReportException(sprintf('Infection report "%s" contains a non-numeric stats value.', $path));
            }

            $stats[$key] = $value;
        }

        foreach (['totalMutantsCount', 'msi', 'coveredCodeMsi', 'mutationCodeCoverage'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $stats)) {
                throw new MutationReportException(sprintf('Infection report "%s" is missing stats.%s.', $path, $requiredKey));
            }
        }

        return $stats;
    }

    /**
     * @param mixed $row
     */
    private function parseMutant(mixed $row, string $status, string $location, string $path): InfectionMutant
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s must be a JSON object.', $path, $location));
        }

        $mutator = $row['mutator'] ?? null;
        if (!is_array($mutator) || array_is_list($mutator)) {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s must contain a mutator object.', $path, $location));
        }

        $mutatorName = $mutator['mutatorName'] ?? null;
        $filePath = $mutator['originalFilePath'] ?? null;
        $line = $mutator['originalStartLine'] ?? null;

        if (!is_string($mutatorName) || $mutatorName === '') {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s is missing mutator.mutatorName.', $path, $location));
        }

        if (!is_string($filePath) || $filePath === '') {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s is missing mutator.originalFilePath.', $path, $location));
        }

        if ($line !== null && !is_int($line)) {
            throw new MutationReportException(sprintf('Infection report "%s" mutant %s has a non-integer mutator.originalStartLine.', $path, $location));
        }

        $diff = $row['diff'] ?? null;
        $processOutput = $row['processOutput'] ?? null;

        return new InfectionMutant(
            status: $status,
            filePath: $this->displayPath($filePath),
            line: $line,
            mutator: $mutatorName,
            diff: is_string($diff) && $diff !== '' ? $diff : null,
            processOutput: is_string($processOutput) && $processOutput !== '' ? $processOutput : null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function statusSections(): array
    {
        return [
            'escaped' => 'escaped',
            'timeouted' => 'timed out',
            'killed' => 'killed',
            'killedByStaticAnalysis' => 'killed by SA',
            'errored' => 'error',
            'syntaxErrors' => 'syntax error',
            'uncovered' => 'not covered',
            'ignored' => 'ignored',
        ];
    }

    private function displayPath(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $realProjectRoot = realpath($this->projectRoot);
        $normalizedRoot = rtrim(str_replace('\\', '/', is_string($realProjectRoot) ? $realProjectRoot : $this->projectRoot), '/');

        if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return ltrim($normalizedPath, './');
    }
}
