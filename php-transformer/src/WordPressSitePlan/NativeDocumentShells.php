<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockMarkupIndex;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;

/** Lower capture document hosts only after shared chrome has consumed their boundaries. */
final class NativeDocumentShells
{
    /** @param list<array<string,mixed>> $documents @return list<array<string,mixed>> */
    public static function documents(array $documents): array
    {
        foreach ($documents as &$document) {
            foreach (array('canonical_block_markup', 'block_markup') as $key) {
                if (is_string($document[$key] ?? null)) $document[$key] = self::markup($document[$key]);
            }
            if (isset($document['canonical_block_markup'], $document['content_hash'])) {
                $document['content_hash'] = WordPressSitePlan::contentHash($document['canonical_block_markup']);
            }
        }
        unset($document);
        return $documents;
    }

    public static function markup(string $markup): string
    {
        $edits = array();
        foreach ((new BlockMarkupIndex($markup))->ranges() as $range) {
            $block = substr($markup, $range['offset'], $range['length']);
            if (!preg_match('/^<!-- wp:([a-z0-9-]+)\/layout-shell (\{.*?\}) -->/s', $block, $match)) continue;
            $attrs = json_decode($match[2], true);
            $wrappers = $attrs['wrappers'] ?? array();
            if (1 !== count($wrappers)) continue;
            $wrapper = $wrappers[0];
            $attributes = $wrapper['attributes'] ?? array();
            if (!array_key_exists('data-blocks-engine-capture-document-scope', $attributes) || 'div' !== ($wrapper['tagName'] ?? '')) continue;
            $class = (string) ($attributes['class'] ?? '');
            $classes = implode(' ', array_unique(preg_split('/\s+/', trim('blocks-engine-css-owned-layout ' . $class)) ?: array()));
            $native = array('className' => $classes, 'metadata' => array('blocksEngineRuntimeAttributes' => $attributes));
            // Save valid Core markup; restore the exact source host at render time.
            if (!preg_match('/^\s*<div\b[^>]*>/s', substr($block, strlen($match[0])), $host)) continue;
            $savedHost = preg_replace_callback('/\bclass="([^"]*)"/', static fn(array $m): string => 'class="wp-block-group ' . htmlspecialchars($classes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"', $host[0], 1);
            if (!str_contains($host[0], 'class="')) $savedHost = substr($host[0], 0, -1) . ' class="wp-block-group blocks-engine-css-owned-layout">';
            $opening = '<!-- wp:group ' . BlockAttributes::encode($native) . ' -->' . $savedHost;
            $edits[$range['offset']] = array(strlen($match[0]) + strlen($host[0]), $opening);
            $closing = '<!-- /wp:' . $match[1] . '/layout-shell -->';
            $edits[$range['offset'] + $range['length'] - strlen($closing)] = array(strlen($closing), '<!-- /wp:group -->');
        }
        krsort($edits);
        foreach ($edits as $offset => [$length, $replacement]) $markup = substr_replace($markup, $replacement, $offset, $length);
        return $markup;
    }
}
