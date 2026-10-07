<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Support\EngineMarker;

/** Keep authored identity selectors live across source document ID disambiguation. */
final class ScopedAnchorSelectorProjection
{
    /** @return array<string,true> */
    public static function identities(AuthorStyleAnalysis $source): array
    {
        $ids = array();
        foreach ($source->sourceElementIds() as $id) {
            $id = (string) $id;
            if ('' === SourceDom::safeAnchor($id)) continue;
            $elements = $source->sourceElementsById($id);
            if (count($elements) < 2) continue;
            foreach ($elements as $element) {
                $root = SourceDom::documentVariantRoot($element);
                if ($root && $root->hasAttribute('data-dla-document-scope') && '' !== SourceDom::documentVariantIdSuffix($element)) { $ids[$id] = true; break; }
            }
        }
        return $ids;
    }

    /** Alternatives retain authored specificity and the original, unrenamed hook. */
    public static function selector(string $selector, array $ids): string
    {
        $selector = preg_replace_callback('/(^|[\s>+~,(])#([A-Za-z][A-Za-z0-9_-]*)/', static function (array $match) use ($ids): string {
            return isset($ids[$match[2]]) ? $match[1] . ':is(#' . $match[2] . ',.' . EngineMarker::editorAnchorClass($match[2]) . ')' : $match[0];
        }, $selector) ?? $selector;
        return preg_replace_callback('/\[\s*id\s*=\s*(?:"([A-Za-z][A-Za-z0-9_-]*)"|\'([A-Za-z][A-Za-z0-9_-]*)\'|([A-Za-z][A-Za-z0-9_-]*))\s*\]/i', static function (array $match) use ($ids): string {
            $id = ($match[1] ?? '') ?: (($match[2] ?? '') ?: ($match[3] ?? ''));
            return isset($ids[$id]) ? ':is(' . $match[0] . ',.' . EngineMarker::editorAnchorClass($id) . ')' : $match[0];
        }, $selector) ?? $selector;
    }
}
