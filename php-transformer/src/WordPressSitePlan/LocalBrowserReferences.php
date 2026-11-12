<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlMarkupParser;

/**
 * The integrity backstop for browser references a site plan publishes.
 *
 * Every URL in published markup, stylesheets, JSON block attributes and
 * generated writes must be a declared asset token, an artifact route or an
 * explicit external URL. A local path left anywhere else would break once the
 * theme is installed, so it fails the plan. A captured script or JSON data file
 * is published verbatim, so its local strings are reported instead.
 */
final class LocalBrowserReferences
{
    /** Document navigation relationships are routes; subresource links are assets. */
    private static function isRouteLink(array $attributes): bool
    {
        $relations = preg_split('/\s+/', strtolower(trim($attributes['rel'] ?? ''))) ?: array();
        return array() !== $relations && array() === array_diff($relations, array('canonical', 'alternate', 'next', 'prev', 'author', 'license', 'help')) && !isset($attributes['as']) && !in_array(strtolower($attributes['type'] ?? ''), array('text/css', 'text/javascript'), true);
    }

    /**
     * Validates a text write in the grammar its target declares. A JSON data
     * file is not markup: quotes inside its strings are JSON-escaped, so reading
     * the raw file as HTML turns `href=\"https://…\"` into a bogus local
     * reference. Decode it and validate each markup-bearing string instead.
     * A generated PHP write (functions.php) carries markup only inside
     * var_export() string literals, which escape `\` and `'`. Read raw, a
     * head style's `url('…')` is `url(\'…\')` and its token reads as a
     * quoted value. Undo those two escapes so the markup is read as written.
     * (Tokenizing would be exact but costs ~10x the file in memory.)
     */
    public static function assertNoneInWrite(string $targetPath, string $payload, string $sourcePath, ?callable $report = null): void
    {
        $target = strtolower($targetPath);
        if (str_ends_with($target, '.php')) {
            self::assertNone(strtr($payload, array('\\\\' => '\\', "\\'" => "'")), $sourcePath, 'write:php', $report);
            return;
        }
        $decoded = str_ends_with($target, '.json') ? json_decode($payload, true) : null;
        if (!is_array($decoded)) {
            self::assertNone(str_ends_with($target, '.css') ? '<style>' . $payload . '</style>' : $payload, $sourcePath, 'write', $report);
            return;
        }
        array_walk_recursive($decoded, static function (mixed $value) use ($sourcePath, $report): void {
            if (is_string($value) && str_contains($value, '<')) self::assertNone($value, $sourcePath, 'write:json', $report);
        });
    }

    /**
     * A captured script or JSON data file is published byte for byte apart from
     * declared asset tokens. Markup it builds at runtime is not page markup the
     * plan can rewrite, so its local strings are findings, not plan failures.
     */
    public static function isVerbatimWrite(string $kind, string $targetPath): bool
    {
        return 'theme_asset' === $kind && 1 === preg_match('/\.(?:m?js|cjs|json)$/i', $targetPath);
    }

    public static function assertNone(string $content, string $sourcePath = '', string $context = 'markup', ?callable $report = null): void
    {
        $assertReference = static function (string $candidate, string $attribute, string $element = '', bool $documentLink = false) use ($sourcePath, $context, $report): void { $url = trim(preg_split('/\s+/', trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8')))[0] ?? ''); $route = str_starts_with($url, '/') && (str_starts_with($attribute, 'json:route_') || RouteLinks::isNavigationAttribute($element, $attribute) || ('href' === $attribute && $documentLink)); if ('' !== $url && !str_starts_with($url, WordPressSitePlan::TOKEN_PREFIX) && !$route && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $url)) { if (null !== $report) { $report($url, $attribute); return; } throw new ValidationException(sprintf('WordPress site plan contains unresolved local browser reference %s.', $url), array('source_path' => $sourcePath, 'document_kind' => $context, 'declaration_kind' => 'browser_reference', 'declaration_index' => 0, 'reason' => 'unresolved_local_browser_reference', 'fields' => array('context' => $context, 'attribute' => $attribute, 'value' => $url))); } };
        $assertCss = static function (string $css, string $cssContext) use ($assertReference): void { \Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter::rewrite(html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8'), static function (string $url) use ($assertReference, $cssContext): string { $assertReference($url, $cssContext . ':url'); return $url; }); if (preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^\s\)"\';]+))/i', \Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner::withoutComments(html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $matches, PREG_SET_ORDER)) foreach ($matches as $match) $assertReference((string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? '')), $cssContext . ':@import'); };
        $assertJsonAttributes = null;
        $assertJsonAttributes = static function (array $attributes, bool $route) use (&$assertJsonAttributes, $assertReference, $sourcePath, $context, $report): void {
            foreach ($attributes as $name => $value) {
                if (is_array($value)) { $assertJsonAttributes($value, $route); continue; }
                // Dynamic companion blocks carry editable markup in a string
                // attribute. Validate its browser references just as strictly as
                // native saved markup instead of treating it as opaque JSON.
                if ('content' === strtolower((string) $name) && is_string($value) && str_contains($value, '<')) {
                    self::assertNone($value, $sourcePath, $context . ':companion_content', $report);
                    continue;
                }
                if (CaptureEvidence::GALLERY_SEQUENCE === strtolower((string) $name) && is_string($value)) {
                    AssetReferenceCanonicalizer::gallerySequence($value, static function (string $reference) use ($assertReference): string {
                        $assertReference($reference, CaptureEvidence::GALLERY_SEQUENCE);
                        return $reference;
                    });
                    continue;
                }
                if (!is_string($name) || !is_string($value) || !in_array(strtolower($name), array('url', 'src', 'href', 'poster', 'action', 'srcset'), true)) continue;
                $routeField = $route && 'url' === strtolower($name) ? 'route_url' : (in_array(strtolower($name), array('href', 'action'), true) ? 'route_' . strtolower($name) : strtolower($name));
                foreach ('srcset' === strtolower($name) ? SrcsetParser::urls($value) : array($value) as $candidate) $assertReference($candidate, 'json:' . $routeField);
            }
        };
        foreach (HtmlMarkupParser::htmlMarkupNodes($content) as $node) {
            if ('tag' === $node['kind']) foreach ($node['attributes'] as $name => $value) {
                if (CaptureEvidence::GALLERY_SEQUENCE === $name) {
                    AssetReferenceCanonicalizer::gallerySequence($value, static function (string $reference) use ($assertReference, $name, $node): string {
                        $assertReference($reference, $name, $node['name']);
                        return $reference;
                    });
                    continue;
                }
                if (!in_array($name, array('xlink:href', 'srcset', 'src', 'href', 'poster', 'action', 'style'), true)) continue;
                if ('action' === $name && 'form' !== $node['name']) continue;
                if ('style' === $name) { $assertCss($value, 'style_attribute'); continue; }
                foreach ('srcset' === $name ? SrcsetParser::urls($value) : array($value) as $candidate) $assertReference($candidate, $name, $node['name'], 'link' === $node['name'] && self::isRouteLink($node['attributes']));
            }
            if ('style' === $node['kind']) $assertCss($node['css'], 'style_block');
            if ('comment' === $node['kind'] && preg_match('~^\s*wp:~i', $node['content'])) {
                $attributes = BlockAttributes::decodeComment($node['content']);
                if (is_array($attributes)) {
                    $assertJsonAttributes($attributes, self::jsonUrlIsRoute($node['content']));
                } elseif (preg_match_all('~(?:"|\\\\u0022)(url|src|href|poster|action|srcset)(?:"|\\\\u0022)\s*:\s*(?:"|\\\\u0022)(.*?)(?:"|\\\\u0022)~is', $node['content'], $fields, PREG_SET_ORDER)) {
                    $route = self::jsonUrlIsRoute($node['content']);
                    foreach ($fields as $field) {
                        $name = strtolower($field[1]);
                        $routeField = $route && 'url' === $name ? 'route_url' : (in_array($name, array('href', 'action'), true) ? 'route_' . $name : $name);
                        foreach ('srcset' === $name ? SrcsetParser::urls($field[2]) : array($field[2]) as $candidate) $assertReference(str_replace('\\/', '/', (string) $candidate), 'json:' . $routeField);
                    }
                }
            }
        }
    }

    private static function jsonUrlIsRoute(string $comment): bool { if (!preg_match('~^\s*wp:([^\s{]+)~i', $comment, $block)) return false; return in_array(strtolower($block[1]), WordPressSitePlan::ROUTE_URL_BLOCKS, true); }
}
