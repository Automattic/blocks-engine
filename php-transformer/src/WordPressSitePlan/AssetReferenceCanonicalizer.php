<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use InvalidArgumentException;

/** Maps browser-visible asset URLs to declared plan tokens. */
final class AssetReferenceCanonicalizer
{
    /** @var array<string,string> */
    private array $tokensBySource = array();

    /** @param array<int,array<string,string>> $tokens */
    public function __construct(array $tokens)
    {
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
        preg_match('/^([^?#]*)(.*)$/s', $reference, $parts);
        $path = $parts[1] ?? '';
        $suffix = $parts[2] ?? '';
        if ('' === $path || preg_match('~%2f|%5c|%2e~i', $path)) {
            return null;
        }
        if (str_starts_with(str_replace('\\', '/', $path), '/')) {
            $identity = self::identity(ltrim($path, '/'));
        } else {
            $identity = self::relativeIdentity($path, $origin);
            // Compiler block markup may already carry an artifact-relative identity.
            if (!isset($this->tokensBySource[$identity])) $identity = self::identity($path);
        }
        return '' !== $identity && isset($this->tokensBySource[$identity]) ? $this->tokensBySource[$identity] . $suffix : null;
    }

    public function content(string $content, string $origin): string
    {
        $replace = fn(string $reference): string => $this->reference($reference, $origin) ?? $reference;
        $content = preg_replace_callback('~(\b(?:src|href|poster)\s*=\s*\\\\")([^"\\\\]*)(\\\\")~is', static fn(array $match): string => $match[1] . $replace($match[2]) . $match[3], $content) ?? $content;
        // WordPress serializes quotes inside block attributes as \u0022, including
        // RichText image markup saved by generated blocks.
        $content = preg_replace_callback('~(\b(?:src|href|poster)\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~is', static fn(array $match): string => $match[1] . $replace($match[2]) . $match[3], $content) ?? $content;
        $content = preg_replace_callback('~(\bsrcset\s*=\s*\\\\u0022)(.*?)(\\\\u0022)~is', static fn(array $match): string => $match[1] . self::srcset($match[2], $replace) . $match[3], $content) ?? $content;
        $content = preg_replace_callback('~(\bsrcset\s*=\s*\\\\")([^"\\\\]*)(\\\\")~is', static fn(array $match): string => $match[1] . self::srcset($match[2], $replace) . $match[3], $content) ?? $content;
        $content = preg_replace_callback('~(\b(?:src|href|poster)\s*=\s*)(["\'])(.*?)\2~is', static fn(array $match): string => $match[1] . $match[2] . $replace($match[3]) . $match[2], $content) ?? $content;
        $content = preg_replace_callback('~(\b(?:src|href|poster)\s*=\s*)([^\s>]+)~i', static fn(array $match): string => $match[1] . $replace($match[2]), $content) ?? $content;
        $content = preg_replace_callback('~(\bsrcset\s*=\s*)(["\'])(.*?)\2~is', static fn(array $match): string => $match[1] . $match[2] . self::srcset($match[3], $replace) . $match[2], $content) ?? $content;
        $content = preg_replace_callback('~(\bsrcset\s*=\s*)([^\s>]+)~i', static fn(array $match): string => $match[1] . self::srcset($match[2], $replace), $content) ?? $content;
        $content = CssUrlRewriter::rewrite($content, $replace);
        $content = preg_replace_callback('~(@import\s+)(["\'])([^"\']+)\2~i', static fn(array $match): string => $match[1] . $match[2] . $replace($match[3]) . $match[2], $content) ?? $content;
        $content = preg_replace_callback('~(["\']srcset["\']\s*:\s*["\'])([^"\']*)(["\'])~i', static fn(array $match): string => $match[1] . self::srcset($match[2], $replace) . $match[3], $content) ?? $content;
        return preg_replace_callback('~(["\'](?:url|src|href|srcset|poster)["\']\s*:\s*["\'])([^"\']*)(["\'])~i', static fn(array $match): string => $match[1] . $replace($match[2]) . $match[3], $content) ?? $content;
    }

    /** @param callable(string):string $replace */
    private static function srcset(string $value, callable $replace): string
    {
        return implode(',', array_map(static function (string $candidate) use ($replace): string {
            if (!preg_match('/^(\s*)(\S+)(.*)$/s', $candidate, $parts)) return $candidate;
            return $parts[1] . $replace($parts[2]) . $parts[3];
        }, explode(',', $value)));
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

    /** @param array<int,string> $segments */
    private static function segments(array $segments): string
    {
        $normalized = array();
        foreach ($segments as $segment) {
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
