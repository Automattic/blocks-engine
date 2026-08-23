<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

/** Per-transform memoized presentation results. */
final class PresentationResolutionCache
{
    /** @var array<string, array<string, mixed>> */
    public array $attributes = array();

    /** @var array<string, array<string, string>> */
    public array $declarations = array();

    /** @var array<string, string> */
    public array $mergedStyles = array();

    /** @var array<string, string> */
    public array $mediaTextStyles = array();
}
