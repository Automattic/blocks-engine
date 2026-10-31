<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** Captured comment records are independent of the repeatable presentation. */
final class WordPressComments
{
    public static function hasClass(DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', $element->getAttribute('class')) ?: array(), true);
    }

    public static function entities(string $html, string $sourcePath): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        $rows = array();
        // A responsive document copy renders the same comments again with
        // suffixed ids (`comment-249--data-blocks-engine-capture-mobile`).
        $identity = static fn(string $id): string => (string) preg_replace('/--data-blocks-engine-capture-[a-z0-9-]+$/', '', $id);
        foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," wp-block-comment-template ")]//li[starts-with(@id,"comment-")]') as $item) {
            $find = static fn(string $class) => $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")]', $item)->item(0);
            $author = $find('wp-block-comment-author-name');
            $link = $author instanceof DOMElement ? $author->getElementsByTagName('a')->item(0) : null;
            $date = $find('wp-block-comment-date');
            $time = $date instanceof DOMElement ? $date->getElementsByTagName('time')->item(0) : null;
            $avatar = $find('wp-block-avatar');
            $image = $avatar instanceof DOMElement ? $avatar->getElementsByTagName('img')->item(0) : null;
            $parent = null;
            for ($node = $item->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
                if ('ol' === $node->tagName && self::hasClass($node, 'children') && $node->parentNode instanceof DOMElement) {
                    $parent = $identity($node->parentNode->getAttribute('id'));
                    break;
                }
            }
            $content = $find('wp-block-comment-content');
            $id = $identity($item->getAttribute('id'));
            if (isset($rows[$id])) continue;
            $rows[$id] = array('source_path' => $sourcePath, 'source_id' => $id, 'parent_source_id' => $parent, 'author_name' => trim($author?->textContent ?? ''), 'author_url' => self::safeUrl($link?->getAttribute('href') ?? ''), 'date' => $time?->getAttribute('datetime') ?? '', 'content' => $content instanceof DOMElement ? self::safeContent($content) : '', 'avatar_url' => $image?->getAttribute('src') ?? '');
        }
        return array_values($rows);
    }

    private static function safeUrl(string $url): string
    {
        return preg_match('~^(?:https?://|mailto:|/|#)~i', trim($url)) ? $url : '';
    }

    private static function safeContent(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                if ($child instanceof \DOMText) $html .= htmlspecialchars($child->textContent, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, array('script', 'style', 'iframe', 'object', 'svg', 'form'), true)) continue;
            $inner = self::safeContent($child);
            if (!in_array($tag, array('p', 'br', 'a', 'strong', 'em', 'b', 'i', 's', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'span'), true)) { $html .= $inner; continue; }
            $attrs = '';
            if ('a' === $tag && '' !== ($url = self::safeUrl($child->getAttribute('href')))) $attrs = ' href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
            $html .= '<' . $tag . $attrs . '>' . $inner . ('br' === $tag ? '' : '</' . $tag . '>');
        }
        return $html;
    }
}
