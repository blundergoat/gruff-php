<?php

declare(strict_types=1);

namespace Fixtures\Docs;

final class VarAnnotationDescriptionFixture
{
    /**
     * Exercise inline var assertion descriptions.
     *
     * @param mixed $rawToken The token returned by the SDK.
     * @param mixed $rawValue The value returned by the legacy API.
     * @return string The combined value.
     */
    public function run(mixed $rawToken, mixed $rawValue): string
    {
        /** @var string $token Twilio SDK 5.x phpdoc says ClientToken, but JWT::encode() returns a token string. */
        $token = $rawToken;

        /** @var string $missing */
        $missing = $rawValue;

        /**
         * Legacy API returns mixed but the normalizer guarantees a string here.
         *
         * @var string $documentedOnSeparateLine
         */
        $documentedOnSeparateLine = $rawValue;

        $local = new class {
            /** @var string */
            public string $declaredProperty = 'property';
        };

        return $token . $missing . $documentedOnSeparateLine . $local->declaredProperty;
    }
}
