<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

/**
 * The width band each box-tree capture governs on a responsive capture.
 *
 * A responsive capture folds a route's desktop and phone frames into sibling
 * device islands and ships the rules that choose one per viewport
 * (`@media (max-width:1023px){.data-blocks-engine-capture-desktop-only{display:none}}`).
 * Below that switch the visitor sees the phone frame, so the phone capture's
 * geometry is the evidence for every width up to the switch, not only for
 * widths up to the phone's own capture width. Without this the phone island
 * shows above its capture width inside ancestors still frozen at the desktop
 * capture's pixels, which pushes it out of the viewport.
 *
 * A capture whose band is known gains `max_width`; others keep their own width.
 */
final class BoxTreeDeviceBands
{
    /**
     * @param array<string, array<string, mixed>> $viewports Captured viewports, by key.
     * @return array<string, array<string, mixed>>
     */
    public static function withDeviceSwitch(array $viewports, string $css): array
    {
        $switch = self::deviceSwitch($css);
        if (null === $switch || count($viewports) < 2) return $viewports;
        $widths = array();
        foreach ($viewports as $key => $viewport) {
            if (!is_numeric($viewport['width'] ?? null)) return $viewports;
            $widths[$key] = (float) $viewport['width'];
        }
        arsort($widths);
        $wider = null;
        foreach ($widths as $key => $width) {
            // The widest capture inside the phone band, under a capture outside it.
            if (null !== $wider && $wider > $switch && $width <= $switch) {
                $viewports[$key]['max_width'] = $switch;
                break;
            }
            $wider = $width;
        }
        return $viewports;
    }

    /**
     * The widest width that shows the phone frame, read from the capture's
     * device-island visibility rules, or null when it ships none.
     */
    public static function deviceSwitch(string $css): ?float
    {
        if (!str_contains($css, CaptureEvidence::DESKTOP_ONLY_CLASS) && !str_contains($css, CaptureEvidence::MOBILE_ONLY_CLASS)) return null;
        $switches = array();
        (new CssStylesheetTransformer())->visitStyleRules($css, static function (string $prelude, string $body, array $ancestors) use (&$switches): void {
            if (1 !== count($ancestors) || 1 !== preg_match('/(?<![\w-])display\s*:\s*none(?![\w-])/i', $body)) return;
            $selector = trim($prelude);
            if ('.' . CaptureEvidence::DESKTOP_ONLY_CLASS === $selector && 1 === preg_match('/^@media\s*(?:(?:only\s+)?screen\s+and\s*)?\(\s*max-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/i', trim($ancestors[0]), $match)) {
                $switches[] = (float) $match[1];
            } elseif ('.' . CaptureEvidence::MOBILE_ONLY_CLASS === $selector && 1 === preg_match('/^@media\s*(?:(?:only\s+)?screen\s+and\s*)?\(\s*min-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/i', trim($ancestors[0]), $match)) {
                $switches[] = (float) $match[1] - 1;
            }
        });
        return array() === $switches ? null : min($switches);
    }
}
