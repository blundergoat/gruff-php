<?php

declare(strict_types=1);

namespace GruffPhp\Results\Baseline;

use GruffPhp\Results\Finding\BaselineIdentity;
use GruffPhp\Results\Finding\Finding;

/**
 * Reconciles a fresh scan against the debt the user already reviewed, so only genuinely new problems stop the run.
 *
 * This is the engine behind `gruff-php analyse --baseline gruff-baseline.json`. Each live finding is classified in this order:
 * - not eligible: a sensitive finding, which no baseline entry may hide;
 * - collision: an identity covering two declarations, reported and never hidden;
 * - unchanged: within the count the user reviewed for that identity, hidden from the failing set;
 * - new: absent from the baseline or beyond its count, still fails the run.
 *
 * Section 13 exclusions are removed before findings reach this class, so an excluded finding never competes for a label here.
 *
 * @phpstan-type Collision array{identity: string, ruleId: string, path: string, subjects: list<string>}
 * @phpstan-type IdentityGroup array{findings: list<Finding>, positions: array<string, true>, subjects: list<string>, ruleId: string, path: string}
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
     * @param bool          $hasDiffScope - True when the scan covered only changed files, which turns off resolved-debt detection because unscanned files would look falsely resolved.
     *
     * @return array{findings: list<Finding>, new: list<Finding>, unchanged: list<Finding>, statuses: array<int, string>, collisions: list<Collision>, report: BaselineReport} - "findings" is the gated set
     *                         the user must still act on (new, collision, and not-eligible findings in scan order), "unchanged" the hidden ones, and "report" the movement summary.
     * @throws BaselineException When the baseline was written by another port, or a finding cannot be identified.
     */
    public function apply(BaselineData $baseline, array $findings, bool $hasDiffScope): array
    {
        // A baseline written by another port is refused before matching: applying it would report every row resolved and invite a destructive regenerate.
        if ($baseline->toolLanguage !== BaselineIdentity::TOOL_LANGUAGE) {
            throw new BaselineException(
                "Baseline {$baseline->path} was written by {$baseline->toolLanguage} and this run is " . BaselineIdentity::TOOL_LANGUAGE
                . '; baselines are not shared across languages.',
            );
        }

        $reviewedByIdentity = $baseline->byIdentity();
        $classification     = $this->classify($findings, $reviewedByIdentity);
        $partition          = $this->partition($findings, $classification['statuses']);
        // Only a full-project scan can prove reviewed debt was fixed; a diff-scoped run never opened the unchanged files, so it records the check as skipped.
        $resolved = $hasDiffScope ? ['entries' => [], 'occurrences' => 0] : $this->resolvedSurplus($reviewedByIdentity, $classification);

        return [
            'findings'   => $partition['gated'],
            'new'        => $partition['new'],
            'unchanged'  => $partition['unchanged'],
            // The status per finding travels out too, so a surface can report what the baseline made of each one.
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
     * Labels every finding: sensitive ones not eligible before any lookup, then each identity group spent against its reviewed count.
     *
     * @param list<Finding>                $findings - This run's live findings.
     * @param array<string, BaselineEntry> $reviewedByIdentity - The baseline's rows indexed by identity.
     *
     * @return Classification - Status per `spl_object_id`, the identity groups, every collision, and the collided identities.
     * @throws BaselineException When a finding cannot be identified.
     */
    private function classify(array $findings, array $reviewedByIdentity): array
    {
        $groups   = [];
        $statuses = [];

        // Sensitive findings are labelled before any lookup, so no reviewed row can reach a secret; everything else is bucketed by identity.
        foreach ($this->groupEligibleFindings($findings) as $identity => $group) {
            $groups[$identity] = $group;
        }

        foreach ($findings as $finding) {
            if (!BaselineIdentity::isEligible($finding)) {
                $statuses[spl_object_id($finding)] = 'notEligible';
            }
        }

        $collisions = [];
        $collided   = [];

        foreach ($groups as $identity => $group) {
            // One identity over two declarations cannot separate them, so neither is hidden and the run says so by name.
            if (count($group['positions']) > 1) {
                $collided[$identity] = true;
                $collisions[]        = ['identity' => $identity, 'ruleId' => $group['ruleId'], 'path' => $group['path'], 'subjects' => $group['subjects']];

                foreach ($group['findings'] as $finding) {
                    $statuses[spl_object_id($finding)] = 'collision';
                }

                continue;
            }

            $reviewedCount = isset($reviewedByIdentity[$identity]) ? $reviewedByIdentity[$identity]->count : 0;

            // The reviewed count is spent lowest line first, so two ports hide the same occurrences for identical input rather than merely the same number.
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
     *
     * @return array<string, IdentityGroup> - Identity to its occurrences; a sensitive finding appears in no group.
     * @throws BaselineException When a finding cannot be identified.
     */
    private function groupEligibleFindings(array $findings): array
    {
        $ordinals = BaselineIdentity::assignOrdinals($findings);
        $groups   = [];

        foreach ($findings as $finding) {
            if (!BaselineIdentity::isEligible($finding)) {
                continue;
            }

            $ordinal  = $ordinals[spl_object_id($finding)] ?? 0;
            $identity = BaselineIdentity::identityOf($finding, $ordinal);
            $group    = $groups[$identity] ?? ['findings' => [], 'positions' => [], 'subjects' => [], 'ruleId' => $finding->ruleId, 'path' => $finding->filePath];

            $group['findings'][] = $finding;
            $group['positions']['declaration:' . BaselineIdentity::declarationPosition($finding)] = true;
            $group['subjects'] = array_values(array_unique([...$group['subjects'], BaselineIdentity::subject($finding, $ordinal)]));

            $groups[$identity] = $group;
        }

        return $groups;
    }

    /**
     * Orders one identity's occurrences by line then column; an unlocated finding sorts last.
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
     * @param array<int, string> $statuses - Status per `spl_object_id`.
     *
     * @return Partition - The partition and its counts.
     */
    private function partition(array $findings, array $statuses): array
    {
        $partition = ['gated' => [], 'new' => [], 'unchanged' => [], 'collisionCount' => 0, 'notEligibleCount' => 0];

        foreach ($findings as $finding) {
            $status = $statuses[spl_object_id($finding)] ?? 'new';

            // Only an unchanged finding leaves the gated set; a collision or a sensitive finding stays visible and still fails the run.
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
     * Finds every reviewed identity with fewer live occurrences than reviewed, which is debt the user has since fixed.
     *
     * @param array<string, BaselineEntry> $reviewedByIdentity - The baseline's rows indexed by identity.
     * @param Classification               $classification - The identity groups and collided identities from `classify()`.
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

            $resolvedEntries[] = new BaselineEntry($identity, $surplus, $reviewedEntry->ruleId, $reviewedEntry->path, $reviewedEntry->subject);
            $occurrences      += $surplus;
        }

        return ['entries' => $resolvedEntries, 'occurrences' => $occurrences];
    }
}
