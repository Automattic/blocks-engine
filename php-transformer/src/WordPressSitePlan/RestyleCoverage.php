<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan;

use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;

/**
 * Estimates `restyle_coverage`: of the text and background colours rendered
 * on block-level content, the share that come from a preset reference, so a
 * Global Styles palette edit restyles them.
 *
 * The estimate reads the emitted block markup and stylesheets, without a
 * browser. Each text element (a block with text of its own, and each
 * authored inline element in it that sets a colour) and each block that
 * paints a background is one count. A colour's source is, in cascade order:
 *
 * 1. the block's preset attribute (`textColor`, `backgroundColor`, a
 *    `var:preset|…` style value): a preset;
 * 2. a literal style value on the block: a literal;
 * 3. stylesheet rules for one of the element's own classes: a preset when
 *    every such value reads a `--wp--preset--color--…` variable, otherwise a
 *    literal;
 * 4. for text, the nearest ancestor block's text colour source, and at the
 *    root, Global Styles: a preset when the root text colour is a token.
 *
 * Classes WordPress adds for every block of a type (`wp-block-*`, `has-*`)
 * and the engine's role classes are not the element's own: they do not
 * identify an author rule. The browser test in
 * tests/integration/design-system-restyle.sh measures the same share on the
 * rendered site and is the ground truth.
 */
final class RestyleCoverage
{
    /** Blocks whose own content is text a reader sees. */
    private const TEXT_BLOCKS = array('paragraph', 'heading', 'list-item', 'button', 'navigation-link', 'navigation-submenu', 'site-title', 'site-tagline', 'preformatted', 'code', 'verse', 'pullquote', 'post-title', 'post-excerpt', 'query-title', 'table', 'details');

    /** Classes that do not identify an author rule (see the class comment). */
    private const SHARED_CLASS = '/^(?:wp-|has-|is-|align(?:wide|full|left|right|center)$|blocks-engine-(?:role-|subject$|subject-tag-|synthetic-|specificity-|list-navigation|brand-navigation))/';

    private const INLINE_ELEMENT = '/<(a|span|strong|em|b|i|mark|small|code)\b([^>]*)>/i';

    /** @var array<string,array<string,bool>> Class => colour property => every value is a preset reference. */
    private array $classColors = array();

    /** @param array<int,array<string,mixed>> $assets */
    private function __construct(array $assets)
    {
        $visitor = new CssStylesheetTransformer();
        foreach ($assets as $asset) {
            if ('css' !== ($asset['kind'] ?? null) || !is_string($asset['content'] ?? null)) continue;
            $visitor->visitStyleRules($asset['content'], function (string $prelude, string $body): void {
                $this->indexRule($prelude, $body);
            });
        }
    }

    /**
     * @param list<string> $markups Every emitted block document.
     * @param array<int,array<string,mixed>> $assets
     * @return array{share:float,text_elements:int,text_preset:int,backgrounds:int,backgrounds_preset:int}
     */
    public static function measure(array $markups, array $assets, bool $rootTextIsPreset): array
    {
        $coverage = new self($assets);
        $counts = array('text' => 0, 'text_preset' => 0, 'background' => 0, 'background_preset' => 0);
        foreach ($markups as $markup) $coverage->walk($markup, $rootTextIsPreset, $counts);
        $total = $counts['text'] + $counts['background'];
        return array(
            'share' => 0 === $total ? 1.0 : round(($counts['text_preset'] + $counts['background_preset']) / $total, 4),
            'text_elements' => $counts['text'],
            'text_preset' => $counts['text_preset'],
            'backgrounds' => $counts['background'],
            'backgrounds_preset' => $counts['background_preset'],
        );
    }

    /** @param array<string,int> $counts */
    private function walk(string $markup, bool $rootTextIsPreset, array &$counts): void
    {
        if (!preg_match_all('/<!--\s+(\/)?wp:([a-z0-9\/-]+)(?:\s+(\{.*?\}))?\s+(\/)?-->/s', $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return;
        // Each open block: its name, whether its text colour is a preset, and
        // where its own content starts.
        $stack = array(array('name' => '', 'preset' => $rootTextIsPreset, 'start' => 0, 'own' => ''));
        foreach ($matches as $match) {
            $offset = $match[0][1];
            $end = $offset + strlen($match[0][0]);
            $parent = &$stack[count($stack) - 1];
            $parent['own'] .= substr($markup, $parent['start'], $offset - $parent['start']);
            unset($parent);
            if ('' !== ($match[1][0] ?? '')) {
                $closed = array_pop($stack);
                if (null !== $closed && count($stack) > 0) $this->count($closed, $counts);
                if (count($stack) === 0) $stack[] = array('name' => '', 'preset' => $rootTextIsPreset, 'start' => $end, 'own' => '');
                $stack[count($stack) - 1]['start'] = $end;
                continue;
            }
            $name = preg_replace('/^core\//', '', $match[2][0]);
            $attributes = json_decode($match[3][0] ?? '', true);
            $attributes = is_array($attributes) ? $attributes : array();
            $block = array('name' => (string) $name, 'attributes' => $attributes, 'inherited' => $stack[count($stack) - 1]['preset'], 'start' => $end, 'own' => '');
            $block['preset'] = $this->textSource($block) ?? $block['inherited'];
            if ('/' === ($match[4][0] ?? '')) {
                $this->count($block, $counts);
                $stack[count($stack) - 1]['start'] = $end;
                continue;
            }
            $stack[] = $block;
        }
    }

    /**
     * Whether the block's own text colour is a preset, or null when it
     * inherits.
     *
     * @param array<string,mixed> $block
     */
    private function textSource(array $block): ?bool
    {
        $attributes = $block['attributes'];
        if (is_string($attributes['textColor'] ?? null)) return true;
        $literal = $attributes['style']['color']['text'] ?? null;
        if (is_string($literal)) return str_starts_with($literal, 'var:preset|') || str_contains($literal, '--wp--preset--color--');
        return $this->classSource($this->classes($attributes), 'color');
    }

    /** @param array<string,mixed> $block @param array<string,int> $counts */
    private function count(array $block, array &$counts): void
    {
        $attributes = $block['attributes'] ?? array();
        $literal = $attributes['style']['color']['background'] ?? null;
        if (is_string($attributes['backgroundColor'] ?? null)) $background = true;
        elseif (is_string($literal) && str_starts_with($literal, 'var:preset|')) $background = true;
        elseif (is_string($literal)) $background = CssColor::opaque($literal) ? false : null;
        else $background = $this->classSource($this->classes($attributes), 'background');
        if (null !== $background) {
            $counts['background']++;
            if ($background) $counts['background_preset']++;
        }
        if (!in_array($block['name'], self::TEXT_BLOCKS, true)) return;
        $own = (string) ($block['own'] ?? '');
        // Authored inline elements that set their own colour are text
        // elements of their own; the block counts for the text outside them.
        if (preg_match_all(self::INLINE_ELEMENT, $own, $elements, PREG_SET_ORDER)) {
            foreach ($elements as $element) {
                $source = $this->inlineSource($element[2]);
                if (null === $source) continue;
                $counts['text']++;
                if ($source) $counts['text_preset']++;
                $own = (string) preg_replace('/' . preg_quote($element[0], '/') . '.*?<\/' . $element[1] . '>/is', '', $own, 1);
            }
        }
        $hasText = '' !== trim(html_entity_decode(strip_tags($own), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{a0}") || '' !== trim((string) ($attributes['label'] ?? ''));
        if (!$hasText) return;
        $counts['text']++;
        if ($block['preset']) $counts['text_preset']++;
    }

    /** Whether an inline element's own colour is a preset, or null when it inherits. */
    private function inlineSource(string $tagAttributes): ?bool
    {
        $style = preg_match('/\sstyle="([^"]*)"/i', $tagAttributes, $match) ? html_entity_decode($match[1], ENT_QUOTES) : '';
        $classes = preg_match('/\sclass="([^"]*)"/i', $tagAttributes, $match) ? (preg_split('/\s+/', trim($match[1])) ?: array()) : array();
        if (array() !== preg_grep('/^has-(?!text-color$|inline-color$|border-color$).+(?<!-background|-border)-color$/', $classes)) return true;
        if (1 === preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $color)) return str_contains($color[1], '--wp--preset--color--');
        return $this->classSource($classes, 'color');
    }

    /**
     * @param array<string,mixed> $attributes
     * @return list<string>
     */
    private function classes(array $attributes): array
    {
        $classes = is_string($attributes['className'] ?? null) ? $attributes['className'] : '';
        $anchor = $attributes['metadata']['blocksEngineNavigationAnchor']['className'] ?? null;
        if (is_string($anchor)) $classes .= ' ' . $anchor;
        return preg_split('/\s+/', trim($classes)) ?: array();
    }

    /**
     * Whether stylesheet rules on the element's own classes set the colour
     * from a preset, or null when none sets it.
     *
     * @param list<string> $classes
     */
    private function classSource(array $classes, string $property): ?bool
    {
        $source = null;
        foreach ($classes as $class) {
            if ('' === $class || 1 === preg_match(self::SHARED_CLASS, $class) || !isset($this->classColors[$class][$property])) continue;
            $source = ($source ?? true) && $this->classColors[$class][$property];
        }
        return $source;
    }

    private function indexRule(string $prelude, string $body): void
    {
        $values = array();
        foreach (CssValueSplitter::splitTopLevel($body, array(';')) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (2 !== count($parts)) continue;
            $property = match (strtolower(trim($parts[0]))) {
                'color' => 'color',
                'background-color', 'background' => 'background',
                default => null,
            };
            $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $parts[1]));
            if (null === $property || 1 === preg_match('/^(?:inherit|initial|unset|revert|revert-layer|currentcolor|transparent|none)$/i', $value)) continue;
            if ('background' === $property && !str_contains($value, '--wp--preset--color--') && null === CssColor::parse($value)) continue;
            $values[$property] = ($values[$property] ?? true) && str_contains($value, '--wp--preset--color--');
        }
        if (array() === $values) return;
        foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array($prelude) as $selector) {
            $selector = (string) preg_replace('/:not\((?:[^()]|\([^()]*\))*\)/', '', $selector);
            if (!preg_match_all('/\.((?:\\\\.|[\w-])+)/', $selector, $classes)) continue;
            foreach (array_unique($classes[1]) as $class) {
                $class = stripslashes($class);
                foreach ($values as $property => $preset) $this->classColors[$class][$property] = ($this->classColors[$class][$property] ?? true) && $preset;
            }
        }
    }
}
