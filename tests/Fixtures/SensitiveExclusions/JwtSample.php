<?php

declare(strict_types=1);

// Synthetic redaction-corpus fixture carrying a second sensitive-data rule in its own file, so a test
// can prove two entries stay independent. The token below is the canonical JWT specification example.
$sessionToken = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.sflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
