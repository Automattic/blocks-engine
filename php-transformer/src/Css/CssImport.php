<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Css;

/** Shared token boundary for stylesheet import recognizers. */
final class CssImport
{
    // A string may touch the at-keyword; url() needs a separating whitespace token.
    public const PREFIX = '@import(?:\s+|(?=["\']))';
}
