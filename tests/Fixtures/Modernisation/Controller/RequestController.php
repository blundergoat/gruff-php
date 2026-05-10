<?php

declare(strict_types=1);

final class RequestController
{
    public function show(): ?string
    {
        return $_GET['id'] ?? null;
    }
}
