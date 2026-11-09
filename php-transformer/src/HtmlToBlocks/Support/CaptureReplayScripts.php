<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlTagScanner;

/**
 * Capture interpreters of evidence the engine lowers or replays itself (native
 * control state, viewport entrances, responsive fragment targets). They are capture plumbing, never author
 * behavior, so no compiled site ships them or analyses their queries.
 */
final class CaptureReplayScripts
{
    public static function isReplayScript(string $tag): bool
    {
        $attributes = HtmlTagScanner::attributes($tag);
        return array_key_exists(CaptureEvidence::NATIVE_CONTROL_RUNTIME, $attributes) || array_key_exists(CaptureEvidence::VIEWPORT_ENTRANCE_ACTIVATOR, $attributes)
            || array_key_exists(CaptureEvidence::RESPONSIVE_FRAGMENT_RESOLVER, $attributes);
    }

    /** Discard capture replay interpreters, never grant a script trust by its marker. */
    public static function withoutReplayScripts(string $html): string
    {
        foreach (array_reverse(HtmlTagScanner::scan($html, 'script')) as $script) if (self::isReplayScript($script['tag'])) {
            $html = substr_replace($html, '', $script['offset'], $script['end_offset'] - $script['offset']);
        }
        return $html;
    }
}
