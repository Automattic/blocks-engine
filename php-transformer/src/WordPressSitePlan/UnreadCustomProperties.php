<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\UnusedCustomPropertyPruner;

/**
 * An author sheet declares custom properties for readers it expects: a
 * framework's component sets `--x-modal-header-padding` for a modal header,
 * its root sets `--x-body-color` for the body rule. The compiler keeps
 * component-scoped declarations as the source's styling API because, while
 * pages are still being projected, a reader may yet ship. Readers the site
 * never had, and rules the plan translated into theme.json and block
 * attributes, leave those declarations with nothing to compute.
 *
 * Once the plan's markup and stylesheets are final, every reader the site
 * ships is known: the emitted sheets (front end and editor), the markup,
 * runtime records, theme.json and every inline script. A custom property
 * none of them names is then dropped from the author sheets, in any rule.
 * WordPress presets and engine properties (read by styles the plugin prints)
 * are kept, and a sheet or script whose bytes are not inline leaves every
 * declaration in place.
 */
final class UnreadCustomProperties
{
    /** Asset sources that are the site's own authored sheets. */
    private const AUTHORED = array('files', 'inline-style');

    /**
     * @param list<array<string,mixed>> $assets
     * @param list<string> $texts Every emitted markup body, runtime record and theme.json.
     * @return list<array<string,mixed>>
     */
    public static function prune(array $assets, array $texts): array
    {
        $stylesheets = array();
        foreach ($assets as $asset) {
            if (!empty($asset['binary'])) continue;
            $content = $asset['content'] ?? null;
            $css = 'css' === ($asset['kind'] ?? null);
            // A sheet or script whose bytes are not here could read anything.
            if (!is_string($content) || isset($asset['payload_reference'])) {
                if ($css || self::isScriptLike($asset)) return $assets;
                continue;
            }
            if ($css) $stylesheets[] = $content;
            else $texts[] = $content;
        }
        $pruner = new UnusedCustomPropertyPruner($stylesheets, $texts, true);
        foreach ($assets as &$asset) {
            if ('css' !== ($asset['kind'] ?? null) || !in_array($asset['source'] ?? null, self::AUTHORED, true) || !is_string($asset['content'] ?? null) || !str_contains($asset['content'], '--')) continue;
            $content = $pruner->prune($asset['content']);
            if ($content === $asset['content']) continue;
            $asset = CompiledAssets::withRewrittenTextContent($asset, $content);
        }
        unset($asset);
        return $assets;
    }

    /** @param array<string,mixed> $asset */
    private static function isScriptLike(array $asset): bool
    {
        if (in_array($asset['kind'] ?? null, array('js', 'script', 'html', 'json'), true)) return true;
        return 1 === preg_match('/\.(?:m?js|html?|json)$/i', (string) ($asset['target_path'] ?? $asset['source_path'] ?? ''));
    }
}
