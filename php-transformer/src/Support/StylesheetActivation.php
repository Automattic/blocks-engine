<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Support;

/** Document stylesheet sets are occurrence semantics, not properties of a CSS file. */
final class StylesheetActivation
{
    /** @return array<int,array{rel:string,title:string,disabled:bool,active:bool}> */
    public static function links(string $html): array
    {
        $preferred = '';
        $links = array();
        foreach (StyleTagScanner::scanLinks($html) as $link) {
            $attributes = HtmlTagScanner::attributes($link['tag']);
            $rel = $attributes['rel'] ?? '';
            if (!StyleTagScanner::isStylesheetRel($rel) || !StyleTagScanner::isCssType($attributes['type'] ?? '')) continue;
            $title = $attributes['title'] ?? '';
            $alternate = 1 === preg_match('/(?:^|\s)alternate(?:\s|$)/i', $rel);
            if ('' === $preferred && !$alternate && '' !== $title) $preferred = $title;
            $links[$link['offset']] = array('rel' => $rel, 'title' => $title, 'disabled' => array_key_exists('disabled', $attributes), 'active' => !$alternate);
        }
        foreach ($links as &$link) {
            $link['active'] = !$link['disabled'] && ('' === $link['title'] ? $link['active'] : $link['title'] === $preferred);
        }
        unset($link);
        return $links;
    }

    /** @param array<string,mixed> $asset */
    public static function active(array $asset): bool
    {
        return $asset['stylesheet_activation']['active'] ?? true;
    }
}
