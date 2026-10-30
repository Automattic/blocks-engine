<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;
use InvalidArgumentException;

/** Maps browser-visible asset URLs to declared plan tokens. */
final class AssetReferenceCanonicalizer
{
    /** @var array<string,string> */
    private array $tokensBySource = array();

    private string $siteRoot;

    /** @param array<int,array<string,string>> $tokens */
    public function __construct(array $tokens, string $siteRoot = '', private readonly ?MissingMediaRecovery $missingMedia = null)
    {
        $this->siteRoot = self::identity($siteRoot);
        foreach ($tokens as $token) {
            $source = self::identity($token['source_path'] ?? '');
            if ('' === $source || !is_string($token['token'] ?? null) || isset($this->tokensBySource[$source])) {
                throw new InvalidArgumentException('WordPress site plan has colliding declared asset source identities.');
            }
            $this->tokensBySource[$source] = WordPressSitePlan::TOKEN_PREFIX . $token['token'] . '}}';
        }
    }

    public function reference(string $reference, string $origin): ?string
    {
        if ('' === trim($reference) || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $reference)) {
            return null;
        }
        $decodedReference = html_entity_decode(str_ireplace('\\u0026', '&', $reference), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match('/^([^?#]*)(.*)$/s', $decodedReference, $parts);
        $path = $parts[1] ?? '';
        $suffix = $parts[2] ?? '';
        if ('' === $path || preg_match('~%2f|%5c|%2e~i', $path)) {
            return null;
        }
        $normalizedPath = str_replace('\\', '/', $path);
        $externalPath = ltrim($normalizedPath, '/');
        $external = str_starts_with($externalPath, '_external/');
        if (str_starts_with($normalizedPath, '/')) {
            // The entry document's directory is the website root when an
            // artifact is packaged beneath a wrapper such as `website/`.
            $identity = self::rootedIdentity($path, $this->siteRoot);
            // Keep bare artifact-root references working for standalone assets
            // and transport-only `_external/` paths.
            if ('' !== $identity && !isset($this->tokensBySource[$identity])) $identity = self::identity(ltrim($path, '/'));
        } elseif ($external) {
            // Downloaded remote assets retain this artifact-root staging namespace.
            $identity = self::identity($path);
            if (!isset($this->tokensBySource[$identity])) {
                $relativeIdentity = self::relativeIdentity($path, $origin);
                if (isset($this->tokensBySource[$relativeIdentity])) {
                    $identity = $relativeIdentity;
                }
            }
        } else {
            $identity = self::relativeIdentity($path, $origin);
            // Compiler block markup may already carry an artifact-relative identity.
            if (!isset($this->tokensBySource[$identity])) $identity = self::identity($path);
        }
        if ('' !== $identity && isset($this->tokensBySource[$identity])) {
            return $this->tokensBySource[$identity] . $suffix;
        }
        // `_external/` is a transport-only prefix used by downloaded remote
        // assets; match its declared artifact-relative identity when present.
        if ($external) {
            $relativeIdentity = self::relativeIdentity($externalPath, $origin);
            if (isset($this->tokensBySource[$relativeIdentity])) {
                return $this->tokensBySource[$relativeIdentity] . $suffix;
            }
            // Generated stylesheets can retain a transport-root reference after
            // moving away from the source document's website/ wrapper.
            $rootedIdentity = self::rootedIdentity($externalPath, $this->siteRoot);
            if (isset($this->tokensBySource[$rootedIdentity])) {
                return $this->tokensBySource[$rootedIdentity] . $suffix;
            }
            $stagedIdentity = substr($externalPath, strlen('_external/'));
            if (isset($this->tokensBySource[$stagedIdentity])) {
                return $this->tokensBySource[$stagedIdentity] . $suffix;
            }
        }
        return null;
    }

    public function content(string $content, string $origin): string
    {
        try {
            return $this->canonicalizeContent($content, $origin);
        } catch (ValidationException $error) {
            throw new ValidationException($error->getMessage(), array_replace($error->diagnostic(), array('source_path' => $origin)));
        }
    }

    private function canonicalizeContent(string $content, string $origin): string
    {
        // Element and attribute context travels with each reference so missing
        // media can be recovered without touching navigation or script targets.
        $replace = fn(string $reference, string $element = '', string $attribute = ''): string => $this->reference($reference, $origin)
            ?? $this->missingMedia?->recover($reference, $origin, $element, $attribute)
            ?? $reference;
        if (str_ends_with(strtolower($origin), '.css')) return self::css($content, $replace);
        $content = self::tags($content, $replace);
        // RichText image attributes inside serialized block JSON use escaped
        // quotes, so they are not parsed as ordinary HTML tags above.
        if (str_contains($content, '\\"')) {
            $content = self::replaceRegex('~(\b(?:src|href|poster)\s*=\s*\\\\")((?:\\\\/|[^"\\\\])*)(\\\\")~is', static fn(array $match): string => $match[1] . self::escapedJsonValue($match[2], static fn(string $value): string => $replace($value, '', self::attributeName($match[1]))) . $match[3], $content);
            $content = self::replaceRegex('~(\bsrcset\s*=\s*\\\\")((?:\\\\/|[^"\\\\])*)(\\\\")~is', static fn(array $match): string => $match[1] . self::escapedJsonValue($match[2], static fn(string $value): string => self::srcset($value, static fn(string $reference): string => $replace($reference, '', 'srcset'))) . $match[3], $content);
            $content = self::replaceRegex('~(\bstyle\s*=\s*\\\\")((?:\\\\/|[^"\\\\])*)(\\\\")~is', static fn(array $match): string => $match[1] . self::escapedJsonValue($match[2], static fn(string $value): string => self::css($value, $replace)) . $match[3], $content);
        }
        if (str_contains($content, '\\u0022')) {
            $content = self::replaceWhenChanged('~(\b(?:src|href|poster)\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~is', $content, static fn(array $match): string => $match[1] . self::escapedJsonValue($match[2], static fn(string $value): string => $replace($value, '', self::attributeName($match[1]))) . $match[3]);
            $content = self::replaceWhenChanged('~(\bsrcset\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~is', $content, static fn(array $match): string => $match[1] . self::escapedJsonValue($match[2], static fn(string $value): string => self::srcset($value, static fn(string $reference): string => $replace($reference, '', 'srcset'))) . $match[3]);
            $content = self::replaceWhenChanged('~(\bstyle\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~is', $content, static fn(array $match): string => $match[1] . self::escapedJsonValue($match[2], static fn(string $value): string => self::css($value, $replace)) . $match[3]);
        }
        if (str_contains($content, '\\u003c')) {
            $content = self::replaceWhenChanged('~(\bstyle\s*=\s*([\'\"]))(.*?)\2~is', $content, static fn(array $match): string => $match[1] . self::css($match[3], $replace) . $match[2]);
        }
        if (false !== stripos($content, '<style')) {
            $content = self::replaceRegex('~<style\b[^>]*>(.*?)</style\s*>~is', static function (array $match) use ($replace): string {
                return str_replace($match[1], self::css($match[1], $replace), $match[0]);
            }, $content);
        }
        // Block comments are the supported serialized JSON transport. Restricting
        // rewrites to them avoids treating arbitrary text or SVG data as markup.
        return str_contains($content, '<!--') ? self::replaceWhenChanged('~<!--\s*wp:.*?-->~is', $content, static fn(array $match): string => self::json($match[0], $replace)) : $content;
    }

    /** @param array<int,string> $origins */
    public function cssFromOrigins(string $content, array $origins): string
    {
        $origins = array_values(array_unique(array_filter($origins, static fn(mixed $origin): bool => is_string($origin) && '' !== $origin)));
        try {
            return self::css($content, function (string $reference) use ($origins): string {
                $matches = array_values(array_unique(array_filter(array_map(fn(string $origin): ?string => $this->reference($reference, $origin), $origins))));
                return 1 === count($matches) ? $matches[0] : $reference;
            });
        } catch (ValidationException $error) {
            throw new ValidationException($error->getMessage(), array_replace($error->diagnostic(), array('source_path' => $origins[0] ?? '')));
        }
    }

    private static function regexFailure(): ValidationException
    {
        return new ValidationException('Asset reference canonicalization failed: ' . preg_last_error_msg(), array(
            'reason' => 'asset_reference_canonicalization_failed',
            'fields' => array('pcre_error' => preg_last_error()),
        ));
    }

    /** @param callable(array<int|string,string>):string $replace */
    private static function replaceRegex(string $pattern, callable $replace, string $content): string
    {
        $result = preg_replace_callback($pattern, $replace, $content);
        if (null === $result) throw self::regexFailure();
        return $result;
    }

    /**
     * Source-preserving start-tag scan following HtmlMarkupParser's attribute
     * states. Quotes only open values after =; a matching quote always closes
     * that value, even in malformed markup. Every byte is visited at most once.
     *
     * @param callable(string,string,string):string $replace
     */
    private static function tags(string $content, callable $replace): string
    {
        $length = strlen($content);
        $offset = 0;
        $copied = 0;
        $chunks = array();
        while ($offset < $length && false !== ($start = strpos($content, '<', $offset))) {
            if ('<!--' === substr($content, $start, 4)) {
                $end = strpos($content, '-->', $start + 4);
                $offset = false === $end ? $length : $end + 3;
                continue;
            }
            $cursor = $start + 1;
            if ($cursor >= $length || !ctype_alpha($content[$cursor])) {
                $offset = $cursor;
                continue;
            }
            // HTML tag names end at whitespace, / or >, not at odd quotes.
            while ($cursor < $length && !ctype_space($content[$cursor]) && !str_contains('/>', $content[$cursor])) ++$cursor;
            foreach (self::attributes($content, $cursor) as $attribute) {
                // Consume the shared scan to locate the tag's closing boundary.
            }
            if ($cursor < $length && '>' === $content[$cursor]) {
                ++$cursor;
                $chunks[] = substr($content, $copied, $start - $copied);
                $chunks[] = self::tag(substr($content, $start, $cursor - $start), $replace);
                $copied = $cursor;
            }
            $offset = $cursor;
        }
        $chunks[] = substr($content, $copied);
        return implode('', $chunks);
    }

    /**
     * Yield attribute names and value byte spans, leaving the cursor at > or EOF.
     * Quotes in names are parse errors, but remain name bytes. Only quotes after
     * = open a quoted value; unquoted values end at whitespace or >.
     *
     * @return \Generator<int,array{string,int,int}>
     */
    private static function attributes(string $content, int &$cursor): \Generator
    {
        $length = strlen($content);
        while ($cursor < $length) {
            while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
            if ($cursor >= $length || '>' === $content[$cursor]) break;
            if ('/' === $content[$cursor]) { ++$cursor; continue; }
            $start = $cursor;
            while ($cursor < $length && !ctype_space($content[$cursor]) && !str_contains('=/>', $content[$cursor])) ++$cursor;
            $name = strtolower(substr($content, $start, $cursor - $start));
            while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
            if ($cursor >= $length || '=' !== $content[$cursor]) continue;
            ++$cursor;
            while ($cursor < $length && ctype_space($content[$cursor])) ++$cursor;
            if ($cursor >= $length) break;
            if ('"' === $content[$cursor] || "'" === $content[$cursor]) {
                $quote = $content[$cursor++];
                $start = $cursor;
                while ($cursor < $length && $quote !== $content[$cursor]) ++$cursor;
                $size = $cursor - $start;
                if ($cursor < $length) ++$cursor;
            } else {
                $start = $cursor;
                while ($cursor < $length && !ctype_space($content[$cursor]) && '>' !== $content[$cursor]) ++$cursor;
                $size = $cursor - $start;
            }
            yield array($name, $start, $size);
        }
    }

    /** @param callable(array<int|string,string>):string $replace */
    private static function replaceWhenChanged(string $pattern, string $content, callable $replace): string
    {
        $offset = 0;
        while (true) {
            $result = preg_match($pattern, $content, $captures, PREG_OFFSET_CAPTURE, $offset);
            if (false === $result) throw self::regexFailure();
            if (0 === $result) break;
            $match = array();
            foreach ($captures as $key => $capture) $match[$key] = $capture[0];
            if ($replace($match) !== $match[0]) return self::replaceRegex($pattern, $replace, $content);
            $offset = $captures[0][1] + strlen($captures[0][0]);
        }
        return $content;
    }

    /** @param callable(string,string,string):string $replace */
    private static function tag(string $tag, callable $replace): string
    {
        $cursor = 1;
        $length = strlen($tag);
        while ($cursor < $length && !ctype_space($tag[$cursor]) && !str_contains('/>', $tag[$cursor])) ++$cursor;
        $element = strtolower(substr($tag, 1, $cursor - 1));
        $copied = 0;
        $chunks = array();
        foreach (self::attributes($tag, $cursor) as [$name, $start, $size]) {
            if (!in_array($name, array('xlink:href', 'srcset', 'src', 'href', 'poster', 'action', 'style', CaptureEvidence::GALLERY_SEQUENCE), true)) continue;
            $original = substr($tag, $start, $size);
            if (CaptureEvidence::GALLERY_SEQUENCE === $name) {
                $value = self::gallerySequence($original, static fn(string $reference): string => $replace($reference, $element, $name));
            } else {
                $value = 'style' === $name ? self::css($original, $replace, $element) : ('srcset' === $name ? self::srcset($original, static fn(string $reference): string => $replace($reference, $element, $name)) : $replace($original, $element, $name));
            }
            $chunks[] = substr($tag, $copied, $start - $copied);
            $chunks[] = $value;
            $copied = $start + $size;
        }
        $chunks[] = substr($tag, $copied);
        return implode('', $chunks);
    }

    /** Rewrite every URL input consumed by the pinned gallery sequence runtime.
     * @param callable(string):string $replace
     */
    public static function gallerySequence(string $value, callable $replace): string
    {
        $sequence = json_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!is_array($sequence) || !array_is_list($sequence) || array_filter($sequence, static fn(mixed $url): bool => !is_string($url))) {
            throw new ValidationException('Gallery sequence must be a JSON list of URL strings.', array('reason' => 'invalid_gallery_sequence'));
        }
        $rewritten = array_map($replace, $sequence);
        return $rewritten === $sequence ? $value : htmlspecialchars(json_encode($rewritten, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param callable(string,string,string):string $replace */
    private static function css(string $css, callable $replace, string $element = ''): string
    {
        $css = CssUrlRewriter::rewrite($css, static fn(string $reference): string => $replace($reference, $element, 'css:url'));
        return self::replaceRegex('~(@import\s+)(["\'])([^"\']+)\2~i', static fn(array $match): string => $match[1] . $match[2] . $replace($match[3], $element, 'css:@import') . $match[2], $css);
    }

    /** @param callable(string,string,string):string $replace */
    private static function json(string $comment, callable $replace): string
    {
        // Block attributes are a JSON data model, not markup. Rewrite CSS URL
        // values recursively so nested authored-button contentParts styles stay
        // aligned with the HTML produced by the block's save() implementation.
        if ( preg_match('~^<!--\s*wp:([^\s]+?)(?:\s+(\{.*\}))?\s*(/)?-->$~s', $comment, $opener) ) {
            $attributes = isset($opener[2]) && '' !== $opener[2] ? json_decode($opener[2], true) : array();
            if ( is_array($attributes) ) {
                $route = in_array(strtolower($opener[1]), WordPressSitePlan::ROUTE_URL_BLOCKS, true);
                $rewriteModel = static function (array $values) use (&$rewriteModel, $replace, $route): array {
                    foreach ( $values as $key => $value ) {
                        $field = strtolower((string) $key);
                        if ( is_array($value) ) {
                            $values[$key] = $rewriteModel($value);
                        } elseif ( is_string($value) && CaptureEvidence::GALLERY_SEQUENCE === $field ) {
                            $values[$key] = html_entity_decode(self::gallerySequence($value, static fn(string $reference): string => $replace($reference, '', $field)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        } elseif ( is_string($value) && 'style' === $field ) {
                            $values[$key] = self::css($value, $replace);
                        } elseif ( is_string($value) && 'srcset' === $field ) {
                            $values[$key] = self::srcset($value, static fn(string $reference): string => $replace($reference, '', 'srcset'));
                        } elseif ( is_string($value) && in_array($field, array('url', 'src', 'href', 'poster', 'action'), true) ) {
                            $attribute = ($route && 'url' === $field) || in_array($field, array('href', 'action'), true) ? 'route_' . $field : $field;
                            $values[$key] = $replace($value, '', 'json:' . $attribute);
                        }
                    }
                    return $values;
                };
                $rewritten = $rewriteModel($attributes);
                if ( $rewritten !== $attributes ) {
                    $encoded = BlockAttributes::encode($rewritten, JSON_INVALID_UTF8_SUBSTITUTE);
                    // The block grammar only recognizes a void block whose void
                    // marker is the exact `/-->` closer; `} / -->` stops parsing.
                    if ( '' !== $encoded ) return '<!-- wp:' . $opener[1] . ' ' . $encoded . ( ! empty($opener[3]) ? ' /-->' : ' -->' );
                }
            }
        }
        // Route-bearing blocks keep their own resolve-or-neutralize recovery, so
        // their url/href/action fields are never treated as media references.
        $route = preg_match('~^<!--\s*wp:([^\s{]+)~i', $comment, $block) && in_array(strtolower($block[1]), WordPressSitePlan::ROUTE_URL_BLOCKS, true);
        return self::replaceRegex('~((?:"|\\\\u0022)(url|src|href|poster|action|srcset)(?:"|\\\\u0022)\s*:\s*(?:"|\\\\u0022))(.*?)(?:"|\\\\u0022)~is', static function (array $match) use ($replace, $route): string {
            $field = strtolower($match[2]);
            $attribute = 'json:' . (($route && 'url' === $field) || in_array($field, array('href', 'action'), true) ? 'route_' . $field : $field);
            $jsonReplace = static fn(string $reference): string => self::escapedJsonValue($reference, static fn(string $value): string => $replace($value, '', $attribute));
            $value = 'srcset' === strtolower($match[2]) ? self::srcset($match[3], $jsonReplace) : $jsonReplace($match[3]);
            return $match[1] . $value . (str_contains($match[0], '\\u0022') ? '\\u0022' : '"');
        }, $comment);
    }

    /** Lowercased attribute name from a captured `name="` prefix. */
    private static function attributeName(string $prefix): string
    {
        return preg_match('~([A-Za-z][A-Za-z0-9:_-]*)~', $prefix, $match) ? strtolower($match[1]) : '';
    }

    /** @param callable(string):string $rewrite */
    private static function escapedJsonValue(string $value, callable $rewrite): string
    {
        $normalized = str_replace('\\/', '/', $value);
        $rewritten = $rewrite($normalized);
        return $normalized === $rewritten ? $value : $rewritten;
    }

    /** @param callable(string):string $replace */
    private static function srcset(string $value, callable $replace): string
    {
        return SrcsetParser::rewrite($value, $replace);
    }

    private static function relativeIdentity(string $reference, string $origin): string
    {
        $base = '' === $origin || !str_contains($origin, '/') ? array() : explode('/', dirname($origin));
        return self::segments(array_merge($base, explode('/', str_replace('\\', '/', $reference))));
    }

    private static function identity(string $path): string
    {
        return self::segments(explode('/', str_replace('\\', '/', $path)));
    }

    private static function rootedIdentity(string $reference, string $siteRoot): string
    {
        $root = '' === $siteRoot ? array() : explode('/', $siteRoot);
        $segments = $root;
        foreach (explode('/', ltrim(str_replace('\\', '/', $reference), '/')) as $segment) {
            $segment = rawurldecode($segment);
            if (str_contains($segment, '/') || str_contains($segment, '\\')) return '';
            if ('' === $segment || '.' === $segment) continue;
            if ('..' === $segment) {
                if (count($segments) <= count($root)) return '';
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    /** @param array<int,string> $segments */
    private static function segments(array $segments): string
    {
        $normalized = array();
        foreach ($segments as $segment) {
			$segment = rawurldecode($segment);
			if (str_contains($segment, '/') || str_contains($segment, '\\')) return '';
            if ('' === $segment || '.' === $segment) continue;
            if ('..' === $segment) {
                if (array() === $normalized) return '';
                array_pop($normalized);
                continue;
            }
            $normalized[] = $segment;
        }
        return implode('/', $normalized);
    }
}
