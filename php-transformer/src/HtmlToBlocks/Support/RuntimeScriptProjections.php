<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;

/**
 * Collects, for each runtime projection script, the author selectors it
 * queries and the superseded ids it looks up, so the artifact compiler can
 * rewrite those references.
 */
final class RuntimeScriptProjections
{
    /** @return list<array{path: string, selectors: array<string, list<string>>, superseded_ids: list<string>}> */
    public static function collect(HtmlTransformerSession $session): array
    {
        $selectorMarkers = $session->authorSelectorProjectionState()->runtimeAttributeSelectorMarkers();
        $supersededIds = array_values(array_filter(array_map(
            static fn (string $selector): string => str_starts_with($selector, '#') ? substr($selector, 1) : '',
            $session->runtimeSelectorState()->supersededSelectors()
        )));

        $projections = array();
        foreach ( $session->runtimeBehaviorState()->runtimeProjectionScriptAssets() as $asset ) {
            if ( ! is_array($asset) || ! is_string($asset['path'] ?? null) || ! is_string($asset['content'] ?? null) ) {
                continue;
            }
            $selectors = array();
            foreach ( $selectorMarkers as $selector => $markers ) {
                $selectorPattern = preg_quote($selector, '~');
                if ( preg_match('~(?:querySelector(?:All)?|closest|matches)\s*\(\s*(["\'])' . $selectorPattern . '\1~', $asset['content']) ) {
                    $selectors[$selector] = $markers;
                }
            }
            $assetSupersededIds = array_values(array_filter($supersededIds, static function (string $id) use ($asset): bool {
                return 1 === preg_match('~getElementById\s*\(\s*(["\'])' . preg_quote($id, '~') . '\1\s*\)~', $asset['content']);
            }));
            if ( array() !== $selectors || array() !== $assetSupersededIds ) {
                $projections[] = array('path' => $asset['path'], 'selectors' => $selectors, 'superseded_ids' => $assetSupersededIds);
            }
        }

        return $projections;
    }
}
