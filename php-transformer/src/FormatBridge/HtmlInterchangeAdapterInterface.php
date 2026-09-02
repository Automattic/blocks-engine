<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\FormatBridge;

/**
 * A format adapter that can convert directly across the canonical HTML boundary.
 */
interface HtmlInterchangeAdapterInterface
{
    /** @param array<string, mixed> $options */
    public function toHtml(string $content, array $options = array()): string;

    /** @param array<string, mixed> $options */
    public function fromHtml(string $html, array $options = array()): string;
}
