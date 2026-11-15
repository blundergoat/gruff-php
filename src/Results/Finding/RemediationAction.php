<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * Defines the machine-readable action attached to a finding's metadata.
 * Rules distinguish direct source changes, configuration changes, and decisions that need review.
 * The action travels with the finding's metadata through reporters and hooks.
 */
enum RemediationAction: string
{
    /** Canonical finding metadata key carrying the action value. */
    public const METADATA_KEY = 'remediationAction';

    /** Canonical finding metadata key carrying an optional full configuration path. */
    public const CONFIGURATION_KEY = 'configurationKey';

    /** A safe source change that can be applied directly. */
    case Apply = 'APPLY';

    /** A deterministic configuration-only resolution. */
    case Configure = 'CONFIGURE';

    /** A judgement call or compatibility-sensitive change that needs review. */
    case Consider = 'CONSIDER';

    /**
     * Builds the canonical metadata fragment transported with a classified finding.
     *
     * @param string|null $configurationKey - Configuration path to include verbatim; null omits the configurationKey entry.
     *
     * @return array{remediationAction: string, configurationKey?: string} - Action value and any supplied configuration path.
     */
    public function metadata(?string $configurationKey = null): array
    {
        $metadata = [self::METADATA_KEY => $this->value];


        if ($configurationKey !== null) {
            $metadata[self::CONFIGURATION_KEY] = $configurationKey;
        }

        return $metadata;
    }
}
