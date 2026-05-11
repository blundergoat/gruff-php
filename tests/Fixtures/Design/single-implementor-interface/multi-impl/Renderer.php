<?php

declare(strict_types=1);

namespace Fixtures\Design\SingleImplementor\MultiImpl;

/**
 * Interface with two concrete implementations - rule must not flag.
 */
interface RendererInterface
{
    public function render(string $template): string;
}

/**
 * One of two implementors.
 */
final class HtmlRenderer implements RendererInterface
{
    public function render(string $template): string
    {
        return '<p>' . $template . '</p>';
    }
}

/**
 * Two of two implementors.
 */
final class JsonRenderer implements RendererInterface
{
    public function render(string $template): string
    {
        return '"' . $template . '"';
    }
}
