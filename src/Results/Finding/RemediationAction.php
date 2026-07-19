<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * Defines the machine-readable action attached to a finding's metadata.
 * Rules use these values to distinguish a direct source fix, deterministic configuration,
 * and a compatibility-sensitive decision before reporters or hooks transport the finding.
 * Users reach the action through JSON, hook, and SARIF consumers in this release.
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
     * @param string|null $configurationKey - Full configuration path for an available hatch; null means no hatch is offered.
     *
     * @return array{remediationAction: string, configurationKey?: string} - Action metadata, including the config path when one exists.
     */
    public function metadata(?string $configurationKey = null): array
    {
        $metadata = [self::METADATA_KEY => $this->value];

        // A finding exposes the full configuration path only when its rule offers a deliberate hatch.
        if ($configurationKey !== null) {
            $metadata[self::CONFIGURATION_KEY] = $configurationKey;
        }

        return $metadata;
    }
}
