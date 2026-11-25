<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\BaselineIdentity;
use GruffPhp\Results\Finding\DeclarationInventory;
use GruffPhp\Results\Finding\Finding;

/**
 * Reconciles a scan with reviewed baseline counts, keeping unsuppressed findings available for scoring.
 * Ineligible findings and collisions stay visible; other findings within the reviewed count are suppressed and excess findings remain new.
 * Full scans also report absent reviewed occurrences; diff-scoped scans skip that comparison.
 *
 * @phpstan-type Collision array{identity: string, ruleId: string, path: string, subjects: list<string>}
 * @phpstan-type IdentityGroup array{identity: string, findings: list<Finding>, positions: array<string, true>, subjects: list<string>, ruleId: string, path: string}
 * @phpstan-type Classification array{statuses: array<int, string>, groups: array<string, IdentityGroup>, collisions: list<Collision>, collided: array<string, true>}
 * @phpstan-type Partition array{gated: list<Finding>, new: list<Finding>, unchanged: list<Finding>, collisionCount: int, notEligibleCount: int}
 */
final readonly class BaselineFilter
{
    /**
     * Sorts one scan's findings into unchanged, new, collision, and not-eligible sets, and reports the reviewed debt that has since gone.
     *
     * @param BaselineData  $baseline - The debt the user previously reviewed, one row per identity.
     * @param list<Finding> $findings - This run's live findings; empty means nothing to match, so every reviewed row resolves on a full scan.
     * @param bool $hasDiffScope - True to skip absent-debt detection because unscanned files cannot prove resolution.
     * @param DeclarationInventory|null $inventory - Scanned variable positions for stable ordinals; null ranks variables from findings only.
     *
     * @return array{findings: list<Finding>, new: list<Finding>, unchanged: list<Finding>, statuses: array<int, string>, collisions: list<Collision>, report: BaselineReport}
     *     - findings: new/collision/ineligible in scan order; unchanged: hidden findings; statuses, collisions; report: movement.
     * @throws BaselineException - When the baseline was written by another port.
     */
    public function apply(BaselineData $baseline, array $findings, bool $hasDiffScope, ?DeclarationInventory $inventory = null): array
    {
        // Reject another port's baseline before its unmatched identities can be misreported as resolved debt.
        if ($baseline->toolLanguage !== BaselineIdentity::TOOL_LANGUAGE) {
            throw new BaselineException(
                "Baseline {$baseline->path} was written by {$baseline->toolLanguage} and this run is " . BaselineIdentity::TOOL_LANGUAGE
                . '; baselines are not shared across languages.',
            );
        }

        $reviewedByIdentity = $baseline->byIdentity();
        $classification     = $this->classify($findings, $reviewedByIdentity, $inventory);
        $partition          = $this->partition($findings, $classification['statuses']);
        // Skip absent-debt detection for diff-scoped runs because unscanned files would look resolved.
        $resolved = $hasDiffScope ? ['entries' => [], 'occurrences' => 0] : $this->resolvedSurplus($reviewedByIdentity, $classification);

        return [
            'findings'   => $partition['gated'],
            'new'        => $partition['new'],
            'unchanged'  => $partition['unchanged'],

            'statuses'   => $classification['statuses'],
            'collisions' => $classification['collisions'],
            'report'     => new BaselineReport(
                path:               $baseline->path,
                generated:          false,
                totalEntries:       count($baseline->entries),
                suppressedFindings: count($partition['unchanged']),
                staleEvaluation:    $hasDiffScope ? 'not-evaluated-diff-scope' : 'full-project',
                staleEntries:       $resolved['entries'],
                newCount:           count($partition['new']),
                unchangedCount:     count($partition['unchanged']),
                absentCount:        $resolved['occurrences'],
                collisionCount:     $partition['collisionCount'],
                notEligibleCount:   $partition['notEligibleCount'],
            ),
        ];
    }

    /**
     * Labels findings without an identity as ineligible, detects identity collisions, and spends each remaining group against its reviewed count.
     *
     * @param list<Finding>                $findings - This run's live findings.
     * @param array<string, BaselineEntry> $reviewedByIdentity - The baseline's rows indexed by identity.
     * @param DeclarationInventory|null $inventory - Scanned variable positions for stable ordinals; null ranks only the supplied findings.
     *
     * @return Classification - Status per spl_object_id, the identity groups, every collision, and the collided identities.
     * @throws BaselineException - When a finding cannot be identified.
     */
    private function classify(array $findings, array $reviewedByIdentity, ?DeclarationInventory $inventory): array
    {
        $groups   = [];
        $statuses = [];


        foreach ($this->groupEligibleFindings($findings, $inventory) as $identity => $group) {
            $groups[$identity] = $group;
        }

        foreach ($findings as $finding) {
            // A sensitive finding, or one whose symbol carries the ordinal separator, has no identity to match.
            if (!BaselineIdentity::hasIdentity($finding)) {
                $statuses[spl_object_id($finding)] = 'notEligible';
            }
        }

        $collisions = [];
        $collided   = [];

        foreach ($groups as $identity => $group) {
            // One identity over two declarations cannot separate them, so neither is hidden and the run says so by name.
            if (count($group['positions']) > 1) {
                $collided[$identity] = true;
                $collisions[]        = ['identity' => $group['identity'], 'ruleId' => $group['ruleId'], 'path' => $group['path'], 'subjects' => $group['subjects']];

                foreach ($group['findings'] as $finding) {
                    $statuses[spl_object_id($finding)] = 'collision';
                }

                continue;
            }

            $reviewedCount = isset($reviewedByIdentity[$identity]) ? $reviewedByIdentity[$identity]->count : 0;

            // Spend reviewed counts in line/column order so suppression is stable for occurrences at distinct locations.
            foreach ($this->spendOrder($group['findings']) as $position => $finding) {
                $statuses[spl_object_id($finding)] = $position < $reviewedCount ? 'unchanged' : 'new';
            }
        }

        return ['statuses' => $statuses, 'groups' => $groups, 'collisions' => $collisions, 'collided' => $collided];
    }

    /**
     * Buckets every eligible finding by its identity, remembering the declarations and subjects each identity covers.
     *
     * @param list<Finding> $findings - This run's live findings.
     * @param DeclarationInventory|null $inventory - Scanned variable positions for stable ordinals; null ranks only the supplied findings.
     *
     * @return array<string, IdentityGroup> - Identity to its occurrences; a sensitive finding appears in no group.
     * @throws BaselineException - When a finding cannot be identified.
     */
    private function groupEligibleFindings(array $findings, ?DeclarationInventory $inventory): array
    {
        $ordinals = BaselineIdentity::assignOrdinals($findings, $inventory);
        $groups   = [];

        foreach ($findings as $finding) {
            if (!BaselineIdentity::hasIdentity($finding)) {
                continue;
            }

            $ordinal  = $ordinals[spl_object_id($finding)] ?? 0;
            $identity = BaselineIdentity::identityOf($finding, $ordinal);
            $group    = $groups[$identity] ?? ['identity' => $identity, 'findings' => [], 'positions' => [], 'subjects' => [], 'ruleId' => $finding->ruleId, 'path' => $finding->filePath];

            $group['findings'][] = $finding;
            $group['positions']['declaration:' . BaselineIdentity::declarationPosition($finding)] = true;
            $group['subjects'] = array_values(array_unique([...$group['subjects'], BaselineIdentity::subject($finding, $ordinal)]));

            $groups[$identity] = $group;
        }

        return $groups;
    }

    /**
     * Orders occurrences by line, then column; missing lines use PHP_INT_MAX and missing columns use zero.
     *
     * @param list<Finding> $groupFindings - Occurrences sharing one identity.
     *
     * @return list<Finding> - The same occurrences, lowest line first.
     */
    private function spendOrder(array $groupFindings): array
    {
        usort(
            $groupFindings,
            static fn(Finding $left, Finding $right): int => [$left->line ?? PHP_INT_MAX, $left->column ?? 0] <=> [$right->line ?? PHP_INT_MAX, $right->column ?? 0],
        );

        return $groupFindings;
    }

    /**
     * Splits the findings into the hidden set and the gated set, in scan order so the user's report lists them deterministically.
     *
     * @param list<Finding>      $findings - This run's live findings.
     * @param array<int, string> $statuses - Status per spl_object_id().
     *
     * @return Partition - Scan-ordered gated, new, and unchanged lists, plus collision and ineligible counts.
     */
    private function partition(array $findings, array $statuses): array
    {
        $partition = ['gated' => [], 'new' => [], 'unchanged' => [], 'collisionCount' => 0, 'notEligibleCount' => 0];

        foreach ($findings as $finding) {
            $status = $statuses[spl_object_id($finding)] ?? 'new';

            // Only unchanged findings are suppressed; collisions and ineligible findings remain available for scoring.
            if ($status === 'unchanged') {
                $partition['unchanged'][] = $finding;
                continue;
            }

            $partition['gated'][] = $finding;

            if ($status === 'new') {
                $partition['new'][] = $finding;
            } elseif ($status === 'collision') {
                $partition['collisionCount']++;
            } else {
                $partition['notEligibleCount']++;
            }
        }

        return $partition;
    }

    /**
     * Reports reviewed occurrence counts missing from non-colliding identities in the current scan.
     *
     * @param array<string, BaselineEntry> $reviewedByIdentity - The baseline's rows indexed by identity.
     * @param Classification               $classification - The identity groups and collided identities from classify().
     *
     * @return array{entries: list<BaselineEntry>, occurrences: int} - One resolved row per identity with its surplus, and the surplus total.
     */
    private function resolvedSurplus(array $reviewedByIdentity, array $classification): array
    {
        $resolvedEntries = [];
        $occurrences     = 0;

        foreach ($reviewedByIdentity as $identity => $reviewedEntry) {
            // A collided identity is accounted for by the collision; counting it resolved as well would double-report it.
            if (isset($classification['collided'][$identity])) {
                continue;
            }

            $surplus = $reviewedEntry->count - count($classification['groups'][$identity]['findings'] ?? []);

            if ($surplus <= 0) {
                continue;
            }

            // Use the entry's string identity rather than the array key, because PHP coerces all-digit hex keys to int.
            $resolvedEntries[] = new BaselineEntry($reviewedEntry->identity, $surplus, $reviewedEntry->ruleId, $reviewedEntry->path, $reviewedEntry->subject);
            $occurrences      += $surplus;
        }

        return ['entries' => $resolvedEntries, 'occurrences' => $occurrences];
    }
}
