<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/**
 * Removes `@import` statements whose local target is not in the artifact.
 *
 * A browser requests such a target, gets nothing, and applies the rest of the
 * stylesheet, so the import is dropped with a warning instead of failing the
 * plan. Build-tool directives such as Tailwind's `@import 'tailwindcss';` are
 * the common case. Remote imports and imports of packaged stylesheets are kept.
 */
final class MissingStylesheetImportRecovery
{
    public const DIAGNOSTIC_CODE = 'wordpress_site_plan_missing_stylesheet_import';
    private const MAX_DIAGNOSTICS = 50;

    /** @var array<string,array<string,mixed>> */
    private array $diagnostics = array();
    private int $omitted = 0;

    public function __construct(private readonly bool $strict = false) {}

    /** @param callable(string):?string $resolve */
    public function stylesheet(string $css, string $origin, callable $resolve): string
    {
        if (false === stripos($css, '@import')) return $css;
        $output = '';
        $changed = false;
        foreach ((new CssStylesheetTransformer())->splitStyleRuleBody($css) as $part) {
            if (!isset($part['declarations'])) {
                $output .= $part['prelude'] . '{' . $part['body'] . '}';
                continue;
            }
            $statements = $this->statements($part['declarations'], $origin, $resolve);
            $changed = $changed || null !== $statements;
            $output .= $statements ?? $part['declarations'];
        }
        return $changed ? $output : $css;
    }

    /**
     * Statements outside rule blocks, without the missing imports, or null when
     * every statement is kept so the authored bytes stay untouched.
     *
     * @param callable(string):?string $resolve
     */
    private function statements(string $text, string $origin, callable $resolve): ?string
    {
        $kept = array();
        $changed = false;
        foreach (CssValueSplitter::splitTopLevel($text, array(';')) as $statement) {
            if (preg_match('/^@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^\s\)"\';]+))/i', $statement, $match)) {
                $url = (string) (($match[1] ?? '') ?: ($match[2] ?? '') ?: ($match[3] ?? ''));
                if ($this->missing($url, $resolve)) {
                    $this->record($url, $origin);
                    $changed = true;
                    continue;
                }
            }
            $kept[] = $statement;
        }
        if (!$changed) return null;
        return array() === $kept ? '' : implode(";\n", $kept) . ";\n";
    }

    /** @param callable(string):?string $resolve */
    private function missing(string $url, callable $resolve): bool
    {
        return '' !== trim($url)
            && !str_starts_with($url, WordPressSitePlan::TOKEN_PREFIX)
            && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $url)
            && !preg_match('~%2f|%5c|%2e~i', $url)
            && null === $resolve($url);
    }

    private function record(string $url, string $origin): void
    {
        $diagnostic = array(
            'code' => self::DIAGNOSTIC_CODE,
            'severity' => $this->strict ? 'error' : 'warning',
            'message' => mb_strcut(sprintf('Dropped @import %s because the artifact does not contain that stylesheet.', $url), 0, 256, 'UTF-8'),
            'source_path' => mb_strcut($origin, 0, 256, 'UTF-8'),
            'value' => mb_strcut($url, 0, 256, 'UTF-8'),
            'attribute' => '@import',
            'resolution' => 'dropped_stylesheet_import',
            'reason_code' => 'missing_stylesheet_import',
        );
        if ($this->strict) throw new ValidationException($diagnostic['message'], $diagnostic + array('reason' => 'missing_stylesheet_import'));
        $key = $origin . "\0" . $url;
        if (isset($this->diagnostics[$key])) return;
        if (count($this->diagnostics) >= self::MAX_DIAGNOSTICS) {
            ++$this->omitted;
            return;
        }
        $this->diagnostics[$key] = $diagnostic;
    }

    /** @return array<int,array<string,mixed>> */
    public function diagnostics(): array
    {
        $diagnostics = array_values($this->diagnostics);
        if ($this->omitted > 0) $diagnostics[] = array(
            'code' => self::DIAGNOSTIC_CODE,
            'severity' => 'warning',
            'message' => sprintf('%d more missing stylesheet imports were dropped; omitted from this diagnostic list.', $this->omitted),
            'reason' => 'truncated',
            'omitted_count' => $this->omitted,
        );
        return $diagnostics;
    }
}
