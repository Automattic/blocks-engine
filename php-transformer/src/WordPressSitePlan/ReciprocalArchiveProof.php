<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

/** Source-local links and exact heading names shared by native archive proofs. */
final class ReciprocalArchiveProof
{
    /** Resolve only aliases declared by the exact source document and canonical route tables. */
    public static function routeLinkAliases(array $routes): array
    {
        $sourceRoot = null;
        foreach ($routes as $route) {
            if ('/' !== ($route['target_path'] ?? null) || !is_string($route['source_path'] ?? null)) continue;
            $source = str_replace('\\', '/', $route['source_path']);
            if ('index.html' === $source || str_ends_with($source, '/index.html')) $sourceRoot = dirname($source);
            break;
        }
        $targetsByLink = array();
        foreach ($routes as $route) {
            $source = $route['source_path'] ?? null;
            $target = $route['target_path'] ?? null;
            if (!is_string($source) || !is_string($target) || '' === $target) continue;
            $target = rtrim($target, '/') ?: '/';
            $targetsByLink[self::pathKey($target)][$target] = true;
            if (null === $sourceRoot) continue;
            $source = str_replace('\\', '/', $source);
            if ('.' === $sourceRoot) {
                $relativeSource = $source;
            } else {
                $prefix = rtrim($sourceRoot, '/') . '/';
                if (!str_starts_with($source, $prefix)) continue;
                $relativeSource = substr($source, strlen($prefix));
            }
            if ('' === $relativeSource) continue;
            $targetsByLink[self::pathKey('/' . ltrim($relativeSource, '/'))][$target] = true;
            // The captured directory spells the URL that served it.
            if (str_ends_with($relativeSource, '/index.html')) $targetsByLink[self::pathKey('/' . dirname(ltrim($relativeSource, '/')))][$target] = true;
        }
        $aliases = array();
        foreach ($targetsByLink as $link => $targets) if (1 === count($targets)) $aliases[$link] = (string) array_key_first($targets);
        return $aliases;
    }

    /** @return array<int,array{href:string,label:string}> */
    public static function links(string $html, string $sourceOrigin): array
    {
        if ('' === $html || !class_exists(\DOMDocument::class)) return array();
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return array();
            $links = array();
            foreach ($document->getElementsByTagName('a') as $anchor) {
                $href = trim($anchor->getAttribute('href'));
                // A query names a filtered or paged view, not the archive itself.
                if (null !== parse_url($href, PHP_URL_QUERY)) continue;
                $path = self::localLinkPath($href, $sourceOrigin);
                $label = trim(preg_replace('/\s+/u', ' ', $anchor->textContent) ?? '');
                if (null !== $path && '' !== $label) $links[] = array('href' => self::pathKey(rtrim($path, '/') ?: '/'), 'label' => $label);
            }
            return $links;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Only source-local root-relative or same-origin HTTP(S) links prove structure. */
    private static function localLinkPath(string $href, string $sourceOrigin): ?string
    {
        if ('' === $href) return null;
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $href) === 1) return '' !== $sourceOrigin && preg_match('~^https?://~i', $href) === 1 && self::origin($href) === $sourceOrigin ? (string) (parse_url($href, PHP_URL_PATH) ?? '') : null;
        if (str_starts_with($href, '/')) return (string) (parse_url($href, PHP_URL_PATH) ?? '');
        return null;
    }

    /**
     * Compare paths by the resource they name: `/tag/a%20b` and `/tag/a+b`
     * (the form encoding builders use in their own tag links) are one URL.
     */
    private static function pathKey(string $path): string
    {
        return rawurldecode(str_replace('+', '%20', $path));
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true) || '' === (string) ($parts['host'] ?? '')) return '';
        $scheme = strtolower((string) $parts['scheme']);
        return $scheme . '://' . strtolower((string) $parts['host']) . ':' . (string) ($parts['port'] ?? ('https' === $scheme ? 443 : 80));
    }

    public static function heading(string $html): string
    {
        if ('' === $html || !class_exists(\DOMDocument::class)) return '';
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return '';
            // The page banner (a header outside sectioning content) carries site
            // identity, so the archive is named by the first heading outside it.
            // A heading that is wholly a link titles the listed document it
            // links to, as each entry of a listing does, not this page.
            $heading = null;
            foreach ($document->getElementsByTagName('h1') as $candidate) {
                if (!self::inPageBanner($candidate) && !self::isLinkTitle($candidate)) {
                    $heading = $candidate;
                    break;
                }
            }
            if (!$heading instanceof \DOMElement) return '';
            $text = static fn(\DOMNode $node): string => trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            // A single named element beside prefix text owns the archive name.
            $named = array_values(array_filter(iterator_to_array($heading->childNodes), static fn(\DOMNode $node): bool => $node instanceof \DOMElement && '' !== $text($node)));
            $hasOwnText = '' !== trim(implode('', array_map(static fn(\DOMNode $node): string => $node instanceof \DOMText ? $node->textContent : '', iterator_to_array($heading->childNodes))));
            return 1 === count($named) && $hasOwnText ? $text($named[0]) : $text($heading);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function isLinkTitle(\DOMElement $heading): bool
    {
        $links = $heading->getElementsByTagName('a');
        if (1 !== $links->length || '' === trim($links->item(0)->getAttribute('href'))) return false;
        $normalize = static fn(string $text): string => trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return '' !== $normalize($heading->textContent) && $normalize($links->item(0)->textContent) === $normalize($heading->textContent);
    }

    /** A header is the page banner unless it sits inside article, aside, main, nav or section content. */
    private static function inPageBanner(\DOMNode $node): bool
    {
        $banner = false;
        for ($ancestor = $node->parentNode; $ancestor instanceof \DOMElement; $ancestor = $ancestor->parentNode) {
            $tag = strtolower($ancestor->tagName);
            if (in_array($tag, array('article', 'aside', 'main', 'nav', 'section'), true)) return false;
            if ('header' === $tag) $banner = true;
        }
        return $banner;
    }
}
