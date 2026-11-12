<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;

/**
 * Resolves the links one site plan run writes into WordPress markup.
 *
 * Built once the run's routes are canonical, it maps captured source paths
 * and route paths to the plan's WordPress routes, rewrites link-bearing
 * attributes in markup and block comments to them, and points links that
 * name no artifact page at the source site, or neutralizes them, reporting
 * each one. Resolutions are cached per origin and value for the run.
 */
final class RouteLinks
{
    /** @var array<string,string|false> */
    private array $routeReferenceCache = array();
    /** @var array<string,array<string,mixed>> */
    private array $unresolvedNavigationDiagnostics = array();
    private int $omittedUnresolvedNavigationDiagnostics = 0;
    /** @var array<string,array<string,true>> */
    private array $routeLinkedFragments = array();

    /**
     * @param array<string,string> $routeSources captured source path => WordPress route
     * @param array<string,string> $routeTargets normalized route path => WordPress route
     * @param array<int,array<string,mixed>> $documents
     * @param array<int,array<string,mixed>> $routes
     */
    public function __construct(
        private readonly string $sourceUrl,
        private readonly string $sourceOrigin,
        private readonly array $routeSources,
        private readonly array $routeTargets,
        private readonly ?PresetBinding $presetBinding,
        array $documents,
        array $routes,
    ) {
        $this->routeLinkedFragments = $this->linkedFragments($documents, $routes);
    }

    /**
     * @internal Exposed for ShellExtraction. Shell markup rendered beyond its
     * source route points same-page fragment links at that route, unless the
     * target is inside the shell itself.
     */
    public function qualifyShellFragments(string $content, string $origin): string
    {
        $route = $this->routeSources[$origin] ?? null;
        if (null === $route) return $content;
        $ids = array();
        if (preg_match_all('/\bid\s*=\s*\\\\?["\']([^"\'\\\\]+)|"anchor"\s*:\s*"([^"\\\\]+)"/i', $content, $matches)) $ids = array_flip(array_filter(array_merge($matches[1], $matches[2])));
        $qualify = static fn(array $match): string => isset($ids[$match[2]]) ? $match[0] : $match[1] . $route . '#' . $match[2] . $match[3];
        // HTML attributes, escaped attributes inside JSON block comments, and JSON url attributes.
        return preg_replace_callback('/(\b(?:href|data-[a-z0-9_-]*url)\s*=\s*\\\\?["\']|"url"\s*:\s*")#([A-Za-z][\w:.-]*)(\\\\?["\'])/i', $qualify, $content) ?? $content;
    }

    /** @internal Exposed for ShellExtraction, which canonicalizes shell-candidate links through the same route table as page content. @param array<int,array<string,mixed>> $routes */
    public function markup(string $content, string $origin, array $routes): string
    {
        $replace = fn(array $match): string => $match[1] . ($this->reference($match[2], $origin, $routes) ?? $match[2]) . $match[3];
        $content = preg_replace_callback('/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*["\'])([^"\']+)(["\'])/i', $replace, $content) ?? $content;
        // Companion block attributes carry editable HTML as a JSON string, so
        // route-bearing attributes use escaped quotes rather than HTML quotes.
        $content = preg_replace_callback('/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\")([^"\\\\]*)(\\\\")/i', $replace, $content) ?? $content;
        $content = preg_replace_callback('/(\b(?:href|action|data-[a-z0-9_-]*url)\s*=\s*\\\\u0022)(.*?)(\\\\u0022)/i', $replace, $content) ?? $content;
        $jsonPattern = '/(["\'](?:url|href|action)["\']\s*:\s*["\'])([^"\']+)(["\'])/i';
        $offset = 0;
        while (preg_match($jsonPattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            if (null !== $this->reference($match[2][0], $origin, $routes)) {
                $content = preg_replace_callback($jsonPattern, $replace, $content) ?? $content;
                break;
            }
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        // Route tokens and relative links retain their existing mapping. Only
        // absolute item destinations need URL serialization before shell/menu
        // identity is computed; emission uses these same attributes.
        $content = preg_replace_callback('/<!--\s*wp:navigation-(?:link|submenu)\s+(\{.*?\})\s*(\/)?-->/s', static function (array $match): string {
            $attrs = json_decode($match[1], true);
            if (!is_array($attrs) || !is_string($attrs['url'] ?? null)) return $match[0];
            $url = \Automattic\BlocksEngine\PhpTransformer\Support\NavigationUrl::serialize($attrs['url']);
            if ($url === $attrs['url']) return $match[0];
            $attrs['url'] = $url;
            return str_replace($match[1], BlockAttributes::encode($attrs, JSON_THROW_ON_ERROR), $match[0]);
        }, $content) ?? $content;
        $content = $this->unresolvedNavigationLinks($content, $origin, $routes);
        return null === $this->presetBinding ? $content : $this->presetBinding->bind($content);
    }

    /** Browser navigation attributes, excluding SVG resource references. */
    public static function isNavigationAttribute(string $element, string $attribute): bool
    {
        return ('href' === $attribute && in_array($element, array('a', 'area'), true))
            || ('xlink:href' === $attribute && 'a' === $element)
            || ('action' === $attribute && 'form' === $element);
    }

    /**
     * A document-relative a/area href or form action that names no artifact route cannot stay
     * in the plan: WordPress would resolve it against the imported page URL
     * (issue #636). It names a page on the source site, so point it there when
     * the artifact records its source URL, or neutralize it to a same-page
     * fragment otherwise, and report the outcome instead of rejecting the whole
     * plan. Asset references (src, srcset, CSS url(), link href) stay on the
     * declared-token path and remain rejected by LocalBrowserReferences::assertNone().
     *
     * @param array<int,array<string,mixed>> $routes
     */
    private function unresolvedNavigationLinks(string $content, string $origin, array $routes): string
    {
        $content = $this->unresolvedBlockAttributeLinks($content, $origin, $routes);
        if (!preg_match('~(?:<|\\\\u003c)(?:a|area|form)\b~i', $content)) return $content;
        return preg_replace_callback('~(?:<|\\\\u003c)(?:a|area|form)(?=[\s/>\\\\])(?:[^>\\\\]|\\\\(?!u003e))*~i', function (array $tag) use ($origin, $routes): string {
            $replace = function (array $match) use ($origin, $routes): string {
                $resolved = $this->unresolvedNavigationReference($match[2], $origin, $routes);
                return null === $resolved ? $match[0] : $match[1] . $resolved . $match[3];
            };
            $markup = $tag[0];
            // A form navigates through `action`; links navigate through `href`.
            preg_match('~^(?:<|\\\\u003c)(a|area|form)\b~i', $markup, $element);
            $attributes = array_filter(array('href', 'xlink:href', 'action'), static fn(string $name): bool => self::isNavigationAttribute(strtolower($element[1]), $name));
            $attribute = '(?:' . implode('|', $attributes) . ')';
            foreach (array('~((?<![\w:-])' . $attribute . '\s*=\s*")([^"]*)(")~i', "~((?<![\\w:-])" . $attribute . "\\s*=\\s*')([^']*)(')~i", '~((?<![\w:-])' . $attribute . '\s*=\s*\\\\")([^"\\\\]*)(\\\\")~i', '~((?<![\w:-])' . $attribute . '\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~i') as $pattern) $markup = preg_replace_callback($pattern, $replace, $markup) ?? $markup;
            return $markup;
        }, $content) ?? $content;
    }

    /**
     * Link destinations stored in block comment attributes (a navigation item's
     * `url`, a wrapper's `href`, a form's `action`) get the same treatment as
     * markup links. LocalBrowserReferences::assertNone() walks these attributes too,
     * so leaving them unresolved rejects the whole plan.
     *
     * @param array<int,array<string,mixed>> $routes
     */
    private function unresolvedBlockAttributeLinks(string $content, string $origin, array $routes): string
    {
        if (!str_contains($content, '<!-- wp:')) return $content;
        return preg_replace_callback('~<!--\s*wp:([^\s{]+)\s+(\{.*?\})\s*(/)?-->~s', function (array $match) use ($origin, $routes): string {
            $attrs = json_decode($match[2], true);
            if (!is_array($attrs)) return $match[0];
            $linkKeys = in_array(strtolower($match[1]), WordPressSitePlan::ROUTE_URL_BLOCKS, true) ? array('url', 'href', 'action') : array('href', 'action');
            $changed = false;
            $walk = function (array $value) use (&$walk, &$changed, $linkKeys, $origin, $routes): array {
                foreach ($value as $key => $item) {
                    if (is_array($item)) { $value[$key] = $walk($item); continue; }
                    if (!is_string($key) || !is_string($item) || !in_array(strtolower($key), $linkKeys, true)) continue;
                    $resolved = $this->unresolvedNavigationReference($item, $origin, $routes);
                    if (null === $resolved) continue;
                    $value[$key] = html_entity_decode($resolved, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $changed = true;
                }
                return $value;
            };
            $attrs = $walk($attrs);
            return $changed ? str_replace($match[2], BlockAttributes::encode($attrs, JSON_THROW_ON_ERROR), $match[0]) : $match[0];
        }, $content) ?? $content;
    }

    /** @param array<int,array<string,mixed>> $routes */
    private function unresolvedNavigationReference(string $value, string $origin, array $routes): ?string
    {
        $url = str_replace('\\/', '/', trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ('' === $url || str_starts_with($url, WordPressSitePlan::TOKEN_PREFIX) || preg_match('~^(?:[a-z][a-z0-9+.-]*:|/|#|\?)~i', $url)) return null;
        $path = $url; $suffix = '';
        if (preg_match('/^([^?#]*)(.*)$/s', $url, $parts)) { $path = $parts[1]; $suffix = $parts[2]; }
        if (null !== $this->reference($url, $origin, $routes)) return null;
        $absolute = $this->sourceDocumentReference($path, $origin, $routes);
        $key = $origin . "\0" . $url;
        if (!isset($this->unresolvedNavigationDiagnostics[$key])) {
            if (count($this->unresolvedNavigationDiagnostics) >= WordPressSitePlan::MAX_UNRESOLVED_NAVIGATION_DIAGNOSTICS) $this->omittedUnresolvedNavigationDiagnostics++;
            else $this->unresolvedNavigationDiagnostics[$key] = array_filter(array(
                'code' => 'wordpress_site_plan_unresolved_navigation_link',
                'severity' => 'warning',
                'message' => mb_strcut(null === $absolute ? "Neutralized a link to {$url}, which names no artifact page and has no recorded source URL." : "Linked {$url} to the source site because it names no artifact page.", 0, 256, 'UTF-8'),
                'source_path' => mb_strcut($origin, 0, 256, 'UTF-8'),
                'value' => mb_strcut($url, 0, 256, 'UTF-8'),
                'resolution' => null === $absolute ? 'neutralized' : 'source_url',
                'resolved_url' => null === $absolute ? null : mb_strcut($absolute . $suffix, 0, 512, 'UTF-8'),
                'reason_code' => 'unresolved_local_browser_reference',
            ), static fn(mixed $field): bool => null !== $field);
        }
        // Keep the authored query/fragment bytes so their context encoding survives.
        return null === $absolute ? '#' : htmlspecialchars($absolute, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . (preg_match('/[?#].*$/s', $value, $rawSuffix) ? $rawSuffix[0] : '');
    }

    /**
     * Compare current-document URLs as route state, without changing emission.
     *
     * @param array<string,mixed> $page
     * @param array<int,array<string,mixed>> $routes
     */
    public function withoutShellSelfReferences(string $markup, array $page, array $routes): string
    {
        $source = (string) ($page['source_path'] ?? '');
        $name = basename($source);
        $url = $this->sourceDocumentReference(str_starts_with(strtolower($name), 'index.') ? '' : $name, $source, $routes);
        if (null === $url || '' === $url) return $markup;
        if (str_starts_with(strtolower($name), 'index.') && !str_ends_with($url, '/')) $url .= '/';
        // Exact values only: neither other routes nor URLs merely beginning
        // with the current route are self references. Work on the comparison
        // copy; there is no shared-part current-document URL runtime contract.
        $urls = array_unique(array($url, rtrim($url, '/')));
        return \Automattic\BlocksEngine\PhpTransformer\Support\RouteSelfReference::markup($markup, array_values($urls));
    }

    /**
     * Resolve against the recorded entrypoint URL and the document directory.
     *
     * @param array<int,array<string,mixed>> $routes
     */
    private function sourceDocumentReference(string $path, string $origin, array $routes): ?string
    {
        $source = '' !== $this->sourceUrl ? parse_url($this->sourceUrl) : false;
        if (!is_array($source) || str_contains($path, '\\')) return null;
        $entry = ''; foreach ($routes as $route) if (is_array($route) && !empty($route['entrypoint']) && is_string($route['source_path'] ?? null)) { $entry = $route['source_path']; break; }
        $entryRoot = WordPressSitePlan::entryRootFromDocuments($routes);
        if ('' !== $entryRoot && !str_starts_with($origin, $entryRoot . '/')) return null;
        $basePath = (string) ($source['path'] ?? '/');
        // The source URL names the entrypoint; an index entry is served as its directory.
        if (!str_ends_with($basePath, '/')) $basePath = str_starts_with(strtolower(basename($entry)), 'index.') && strtolower(basename($basePath)) !== strtolower(basename($entry)) ? $basePath . '/' : dirname($basePath) . '/';
        $documentDirectory = dirname('' === $entryRoot ? $origin : substr($origin, strlen($entryRoot) + 1));
        $segments = array();
        foreach (explode('/', $basePath . ('.' === $documentDirectory ? '' : $documentDirectory . '/') . $path) as $index => $segment) {
            if ('..' === $segment) { array_pop($segments); continue; }
            if ('.' !== $segment && ('' !== $segment || 0 === $index)) $segments[] = $segment;
        }
        $resolvedPath = '/' . ltrim(implode('/', $segments), '/') . (preg_match('~(?:^|/)\.{0,2}$~', $path) && '' !== $path ? '/' : '');
        if ('//' === $resolvedPath) $resolvedPath = '/';
        return strtolower((string) $source['scheme']) . '://' . $source['host'] . (isset($source['port']) ? ':' . $source['port'] : '') . $resolvedPath;
    }

    /** @return array<int,array<string,mixed>> */
    public function diagnostics(): array
    {
        $diagnostics = array_values($this->unresolvedNavigationDiagnostics);
        if ($this->omittedUnresolvedNavigationDiagnostics > 0) $diagnostics[] = array('code' => 'wordpress_site_plan_unresolved_navigation_link', 'severity' => 'warning', 'message' => sprintf('%d more unresolved navigation links were resolved or neutralized; omitted from this diagnostic list.', $this->omittedUnresolvedNavigationDiagnostics), 'reason' => 'truncated', 'omitted_count' => $this->omittedUnresolvedNavigationDiagnostics);
        return $diagnostics;
    }

    /** Preserve fixed section destinations also referenced by other routes. */
    public function shellFragmentReference(string $value, string $origin): string
    {
        $path = $this->routeSources[$origin] ?? null;
        if (!is_string($path) || !str_starts_with($value, $path . '#')) return $value;
        $fragment = substr($value, strlen($path) + 1);
        return isset($this->routeLinkedFragments[$origin][$fragment]) ? $value : '#' . $fragment;
    }

    public function reference(string $value, string $origin, array $routes): ?string
    {
        $cacheKey = $origin . "\0" . $value;
        if (array_key_exists($cacheKey, $this->routeReferenceCache)) {
            return false === $this->routeReferenceCache[$cacheKey] ? null : $this->routeReferenceCache[$cacheKey];
        }
        $resolved = $this->resolveReference($value, $origin, $routes);
        $this->routeReferenceCache[$cacheKey] = $resolved ?? false;
        return $resolved;
    }

    /**
     * A section other routes reach as `index.html#donate` is the same target
     * its own route reaches as `#donate`. Spell that same-document link the
     * way the other routes do, so chrome repeated on every route carries one
     * link. A fragment no other route names stays bare: `#top` on every route
     * means each route's own top. A route that also links to its own
     * `#contact` treats that section as per-page, so its link to another
     * route's `#contact` is a deliberate cross reference and does not fix the
     * destination.
     *
     * @param array<int,array<string,mixed>> $documents
     * @param array<int,array<string,mixed>> $routes
     * @return array<string,array<string,true>>
     */
    private function linkedFragments(array $documents, array $routes): array
    {
        $targets = array_flip($this->routeSources);
        $cross = array();
        $own = array();
        foreach ($documents as $document) {
            $origin = (string) ($document['source_path'] ?? '');
            $markup = (string) ($document['block_markup'] ?? '');
            if (preg_match_all('/(?:\bhref\s*=\s*(?:\\\\)?["\']|"url"\s*:\s*")#([^"\'\\\\]+)/i', $markup, $bare)) {
                foreach ($bare[1] as $fragment) $own[$origin][$fragment] = true;
            }
            if (!preg_match_all('/(?:\bhref\s*=\s*(?:\\\\)?["\']|"url"\s*:\s*")([^"\'#\\\\]+)#([^"\'\\\\]+)/i', $markup, $hrefs, PREG_SET_ORDER)) continue;
            foreach ($hrefs as $href) {
                $target = $targets[(string) $this->reference($href[1], $origin, $routes)] ?? null;
                if (!is_string($target)) continue;
                if ($target === $origin) $own[$origin][$href[2]] = true;
                else $cross[$origin][] = array($target, $href[2]);
            }
        }
        $linked = array();
        foreach ($cross as $origin => $links) {
            foreach ($links as [$target, $fragment]) {
                if (!isset($own[$origin][$fragment])) $linked[$target][$fragment] = true;
            }
        }
        return $linked;
    }

    /** @param array<int,array<string,mixed>> $routes */
    private function resolveReference(string $value, string $origin, array $routes): ?string
    {
        if (preg_match('~^#(.+)$~s', $value, $fragment) && isset($this->routeLinkedFragments[$origin][$fragment[1]], $this->routeSources[$origin])) return $this->routeSources[$origin] . $value;
        if ('' === $value || preg_match('~^(?://|#|\?)~', $value)) return null;
        $suffix = ''; if (preg_match('/^([^?#]*)(.*)$/', $value, $match)) { $value = $match[1]; $suffix = $match[2]; }
        if (('.' === $value || './' === $value) && '' !== $suffix) return $this->routeSources[$origin] ?? null ? $this->routeSources[$origin] . $suffix : null;
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) {
            if (!$this->isSameOrigin($value)) return null;
            $absolute = parse_url($value);
            $value = is_array($absolute) && is_string($absolute['path'] ?? null) && '' !== $absolute['path'] ? $absolute['path'] : '/';
        }
        if (str_contains($value, '\\')) return null;
        if (str_starts_with($value, '/')) {
            $routePath = '/' . trim($value, '/');
            if ('/' === $value) $routePath = '/';
            if (isset($this->routeTargets[$routePath])) return $this->routeTargets[$routePath] . $suffix;
        }
        // A root-relative link (e.g. /contact.html) targets the site web root,
        // which is the entrypoint's packaging directory. Resolve it against that
        // root so it matches the document source path (website/contact.html)
        // rather than a bare top-level path the artifact never contains.
        $entryRoot = WordPressSitePlan::entryRootFromDocuments($routes);
        $path = str_starts_with($value, '/') ? ('' === $entryRoot ? ltrim($value, '/') : $entryRoot . '/' . ltrim($value, '/')) : self::resolveSource($origin, $value);
        if (null === $path) return null;
        $resolved = $this->sourceDocument($path);
        // Capture layers write encoded punctuation (`%E2%80%99`) literally into
        // exported file names, so a percent-carrying reference usually matches an
        // artifact path byte for byte. When it does not, retry the spelling a
        // standards-compliant author encoded: decode each segment once, on the same
        // safety rule as `PageRoute::decodedSegment()` — a decode must never give
        // the path structure the raw reference did not already have, so encoded separators,
        // dot segments, and NUL leave the reference unresolved instead of decoded.
        if (null === $resolved && str_contains($path, '%')) { $decodedPath = self::decodedReferencePath($path); if (null !== $decodedPath && $decodedPath !== $path) $resolved = $this->sourceDocument($decodedPath); }
        return null === $resolved ? null : $resolved . $suffix;
    }

    private function sourceDocument(string $path): ?string
    {
        if (isset($this->routeSources[$path])) return $this->routeSources[$path];
        // A directory reference (interactive/, ../) names its index document.
        foreach (array('index.html', 'index.htm') as $index) { $indexPath = ('' === $path ? '' : rtrim($path, '/') . '/') . $index; if (isset($this->routeSources[$indexPath])) return $this->routeSources[$indexPath]; }
        return null;
    }

    private static function decodedReferencePath(string $path): ?string { $segments = array(); foreach (explode('/', $path) as $segment) { if (!str_contains($segment, '%')) { $segments[] = $segment; continue; } $decoded = rawurldecode($segment); if ('.' === $decoded || '..' === $decoded || preg_match('~[/\\\\\x00]~', $decoded)) return null; $segments[] = $decoded; } return implode('/', $segments); }

    public function isSameOrigin(string $url): bool
    {
        return '' !== $this->sourceOrigin && $this->sourceOrigin === self::origin($url);
    }

    public static function origin(string $url): string
    {
        $parts = '' !== $url ? parse_url($url) : false;
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array( 'http', 'https' ), true) || '' === (string) ($parts['host'] ?? '') || isset($parts['user'], $parts['pass'])) return '';
        $scheme = strtolower((string) $parts['scheme']);
        return $scheme . '://' . strtolower((string) $parts['host']) . ':' . (string) ($parts['port'] ?? ('https' === $scheme ? 443 : 80));
    }

    public static function resolveSource(string $origin, string $value): ?string { $segments = array_filter(explode('/', dirname($origin)), static fn(string $segment): bool => '' !== $segment && '.' !== $segment); foreach (explode('/', $value) as $segment) { if ('' === $segment || '.' === $segment) continue; if ('..' === $segment) { if (array() === $segments) return null; array_pop($segments); continue; } $segments[] = $segment; } return implode('/', $segments); }
}
