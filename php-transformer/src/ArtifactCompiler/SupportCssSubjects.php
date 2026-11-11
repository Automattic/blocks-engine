<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Support\HtmlSerialization;

/** Conservative presence proof, not dead-code elimination of authored CSS. */
final class SupportCssSubjects
{
    private array $classes = array();
    private array $ids = array();
    private array $attributes = array();

    public function __construct(string $markup)
    {
        // Dynamic blocks serialize their author identity in attributes, not HTML.
        preg_match_all('/<!-- wp:[^\s]+ (\{.*?\})\s*\/?-->/s', $markup, $blocks);
        foreach ($blocks[1] as $json) {
            $attributes = json_decode($json, true);
            foreach (preg_split('/\s+/', (string) ($attributes['className'] ?? '')) ?: array() as $class) $this->classes[$class] = true;
            if (isset($attributes['anchor'])) $this->ids[$attributes['anchor']] = true;
        }
        $document = HtmlSerialization::parseDocument('<html><body>' . $markup . '</body></html>');
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (SourceDom::tokens($element->getAttribute('class')) as $class) $this->classes[$class] = true;
            if ($element->hasAttribute('id')) $this->ids[$element->getAttribute('id')] = true;
            foreach ($element->attributes as $attribute) $this->attributes[strtolower($attribute->name)] = true;
        }
    }

    public function stylesheet(string $css): string
    {
        $result = (new CssStylesheetTransformer())->transform($css, function (string $prelude, string $body): string|array {
            $selectors = CssStylesheetTransformer::splitSelectorList($prelude);
            if (null === $selectors) return $prelude;
            $retained = array_filter($selectors, function (string $selector): bool {
                $host = CssSelectorMatcher::pseudoElementHost($selector);
                $parsed = CssSelectorMatcher::parse($host['selector'] ?? $selector);
                return !$parsed['supported'] || $this->possible($parsed['compounds']);
            });
            return array() === $retained ? array() : array(array('prelude' => implode(', ', $retained), 'body' => $body));
        });
        $hasRules = false;
        (new CssStylesheetTransformer())->transform($result, static function (string $prelude) use (&$hasRules): string {
            $hasRules = true;
            return $prelude;
        });
        return $hasRules ? $result : '';
    }

    /** Only positive requirements prove absence; negations and runtime states cannot. */
    private function possible(array $compounds): bool
    {
        foreach ($compounds as $compound) {
            foreach ($compound['classes'] as $class) if (!str_starts_with($class, 'wp-block-') && !str_starts_with($class, 'has-') && !isset($this->classes[$class])) return false;
            foreach ($compound['ids'] as $id) if (!isset($this->ids[$id])) return false;
            foreach ($compound['attributes'] as $attribute) if (!str_starts_with(strtolower($attribute['name']), 'aria-') && !isset($this->attributes[strtolower($attribute['name'])])) return false;
            foreach ($compound['any'] ?? array() as $group) {
                $possible = false;
                foreach ($group['alternatives'] as $alternative) $possible = $possible || $this->possible($alternative['compounds']);
                if (!$possible) return false;
            }
        }
        return true;
    }
}
