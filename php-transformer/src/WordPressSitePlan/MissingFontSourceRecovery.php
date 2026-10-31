<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\CssUrlRewriter;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSyntaxScanner;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/** Removes unpackaged font alternatives before generic CSS media recovery. */
final class MissingFontSourceRecovery
{
    public const DIAGNOSTIC_CODE = 'wordpress_site_plan_missing_font_source';
    private const MAX_DIAGNOSTICS = 50;

    /** @var array<string,array<string,mixed>> */
    private array $diagnostics = array();
    private int $omitted = 0;

    public function __construct(private readonly bool $strict = false) {}

    /** @param callable(string):?string $resolve */
    public function stylesheet(string $css, string $origin, callable $resolve): string
    {
        $walker = new CssStylesheetTransformer();
        $output = '';
        foreach ($walker->splitStyleRuleBody($css) as $part) {
            if (isset($part['declarations'])) {
                $output .= $part['declarations'];
                continue;
            }
            $body = $part['body'];
            if ('font-face' === $part['at_rule']) {
                $body = $this->face($body, $origin, $resolve);
                if (null === $body) continue;
            } elseif ($walker->nestsStyleRules($part['prelude'])) {
                $body = $this->stylesheet($body, $origin, $resolve);
            }
            $output .= $part['prelude'] . '{' . $body . '}';
        }
        return $output;
    }

    /** @param callable(string):?string $resolve */
    private function face(string $body, string $origin, callable $resolve): ?string
    {
        $family = '';
        foreach (CssRuleAnalyzer::declarations($body, array('font-family')) as $declaration) {
            $family = trim($declaration['value'], " \t\r\n\"'");
        }
        $changed = false;
        $hasSource = false;
        $declarations = CssValueSplitter::splitTopLevel($body, array(';'));
        foreach ($declarations as &$declaration) {
            if (!preg_match('/^(src\s*:\s*)(.*)$/is', CssSyntaxScanner::withoutComments($declaration), $match)) continue;
            $kept = array();
            foreach (CssStylesheetTransformer::splitSelectorList($match[2]) ?? array($match[2]) as $entry) {
                $missing = false;
                CssUrlRewriter::rewrite($entry, function (string $url) use ($resolve, $origin, $family, &$missing): string {
                    // Remote/data sources and already canonical tokens retain the
                    // existing browser-reference policy; only local misses drop.
                    if (null !== $resolve($url) || str_starts_with($url, WordPressSitePlan::TOKEN_PREFIX) || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|#|\?)~i', $url) || preg_match('~%2f|%5c|%2e~i', $url)) return $url;
                    $this->record($family, $url, $origin);
                    $missing = true;
                    return $url;
                });
                if (!$missing) $kept[] = trim($entry);
                else $changed = true;
            }
            $hasSource = $hasSource || array() !== $kept;
            $declaration = array() === $kept ? '' : $match[1] . implode(', ', $kept);
        }
        unset($declaration);
        if (!$changed) return $body;
        return $hasSource ? implode(';', array_filter($declarations, static fn(string $value): bool => '' !== $value)) . ';' : null;
    }

    private function record(string $family, string $url, string $origin): void
    {
        $diagnostic = array(
            'code' => self::DIAGNOSTIC_CODE,
            'severity' => $this->strict ? 'error' : 'warning',
            'message' => mb_strcut(sprintf('Dropped font source %s for %s because the artifact does not contain that file.', $url, $family), 0, 256, 'UTF-8'),
            'source_path' => mb_strcut($origin, 0, 256, 'UTF-8'),
            'family' => mb_strcut($family, 0, 256, 'UTF-8'),
            'value' => mb_strcut($url, 0, 256, 'UTF-8'),
            'attribute' => 'src',
            'resolution' => 'dropped_font_source',
            'reason_code' => 'missing_font_source',
        );
        if ($this->strict) throw new ValidationException($diagnostic['message'], $diagnostic + array('reason' => 'missing_font_source'));
        $key = $origin . "\0" . $family . "\0" . $url;
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
            'message' => sprintf('%d more missing font sources were dropped; omitted from this diagnostic list.', $this->omitted),
            'reason' => 'truncated',
            'omitted_count' => $this->omitted,
        );
        return $diagnostics;
    }
}
