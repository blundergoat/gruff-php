<?php

declare(strict_types=1);

// Synthetic redaction-corpus fixture with no credential-shaped literal, so an exclusion scoped to it
// legitimately matches nothing and reports a zero count.
$greeting = 'hello';
