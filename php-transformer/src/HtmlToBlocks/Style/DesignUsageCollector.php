<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssColor;
use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use DOMElement;
use DOMText;

/**
 * Records which colours, font sizes, font families, spacing values and
 * content widths a source document actually renders, and how much of the
 * document each one covers.
 *
 * It reads the static author cascade the transformer already resolves for
 * each element ({@see StyleResolver::resolvedPresentationDeclarations()}) and
 * applies CSS inheritance and the user-agent defaults that matter for design
 * tokens (heading sizes, link colour, monospace code). It does not resolve a
 * second cascade and it does not change conversion: it runs after the blocks
 * are built and only reads the source DOM.
 *
 * There is no layout engine, so visual area is estimated. A run of text
 * weighs its character count times the square of its font size relative to
 * 16px: twice the size covers about four times the area. A background
 * weighs the text it contains plus one, so an empty coloured box still
 * counts once. Spacing weighs one per declared side.
 *
 * The result is per-document aggregate data. Colours are canonical hex,
 * sizes are pixels, family stacks keep their authored spelling, and weights
 * are integers so that adding documents up in any order gives the same
 * totals.
 */
final class DesignUsageCollector
{
    public const SCHEMA = 'blocks-engine/design-usage/v1';

    private const SKIPPED_TAGS = array('script', 'style', 'noscript', 'template', 'head', 'meta', 'link', 'title', 'svg', 'math', 'iframe', 'object', 'select', 'option', 'datalist', 'canvas', 'video', 'audio', 'picture', 'img');

    /** User-agent heading sizes, in em of the parent. */
    private const HEADING_EM = array('h1' => 2.0, 'h2' => 1.5, 'h3' => 1.17, 'h4' => 1.0, 'h5' => 0.83, 'h6' => 0.67);

    private const MONOSPACE_TAGS = array('code', 'pre', 'kbd', 'samp', 'tt');

    private const SPACING_PROPERTIES = array('padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'padding-inline', 'padding-block', 'padding-inline-start', 'padding-inline-end', 'padding-block-start', 'padding-block-end', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'margin-inline', 'margin-block', 'margin-inline-start', 'margin-inline-end', 'margin-block-start', 'margin-block-end', 'gap', 'row-gap', 'column-gap');

    /** Physical sides the logical padding properties set in horizontal left-to-right text. */
    private const PADDING_SIDES = array(
        'padding-inline' => array('left', 'right'), 'padding-block' => array('top', 'bottom'),
        'padding-inline-start' => array('left'), 'padding-inline-end' => array('right'),
        'padding-block-start' => array('top'), 'padding-block-end' => array('bottom'),
        'padding-top' => array('top'), 'padding-right' => array('right'), 'padding-bottom' => array('bottom'), 'padding-left' => array('left'),
    );

    /** Bindable properties of class-styled elements and the style family a variant of each declares. */
    private const CLASS_STYLE_FAMILIES = array(
        'color' => 'color', 'background-color' => 'background', 'font-size' => 'font-size', 'font-family' => 'font-family',
        'padding-top' => 'padding', 'padding-right' => 'padding', 'padding-bottom' => 'padding', 'padding-left' => 'padding',
    );

    /** @var array<string,array<string,mixed>> */
    private array $text = array();
    /** @var array<string,array<string,mixed>> */
    private array $backgrounds = array();
    /** @var array<string,array<string,mixed>> */
    private array $spacing = array();
    /** @var array<string,array<string,mixed>> */
    private array $widths = array();
    /** @var array<string,array<string,array<string,true>>> Class list => property => observations. */
    private array $classStyles = array();
    private float $rootPx = 16.0;
    private int $elements = 0;

    public function __construct(private readonly StyleResolver $resolver)
    {
    }

    /** @return array<string,mixed> */
    public function collect(DOMElement $body): array
    {
        $this->text = $this->backgrounds = $this->spacing = $this->widths = $this->classStyles = array();
        $this->elements = 0;
        $this->rootPx = $this->resolver->documentRootFontSize($body) ?? 16.0;
        $html = $body->parentNode instanceof DOMElement && 'html' === strtolower($body->parentNode->tagName) ? $body->parentNode : null;
        $htmlDeclarations = null !== $html ? $this->declarations($html) : array();
        $bodyDeclarations = $this->declarations($body);

        // The canvas paints the body background when it has one, else the
        // root element's, else the user agent's white.
        $rootBackground = $this->backgroundColor($bodyDeclarations) ?? $this->backgroundColor($htmlDeclarations);
        $canvas = null !== $rootBackground && CssColor::opaque($rootBackground) ? (string) CssColor::hex($rootBackground) : '#ffffff';
        $rootContext = array(
            'color' => '#000000',
            'size' => $this->rootPx,
            'family' => null,
            'background' => $canvas,
            'role' => 'body',
        );
        $htmlContext = null !== $html ? $this->inherit($rootContext, $html, $htmlDeclarations) : $rootContext;
        $bodyContext = $this->inherit($htmlContext, $body, $bodyDeclarations);
        $bodyContext['background'] = $canvas;
        $mass = $this->walkChildren($body, $bodyContext);
        $this->addBackground($canvas, 'root', max(1, $mass), $rootBackground ?? $canvas);
        $this->spacingFrom($body, $bodyDeclarations, $bodyContext['size']);

        $root = array(
            'background' => $canvas,
            'background_authored' => null !== $rootBackground,
            'color' => $bodyContext['color'],
            'font_family' => $bodyContext['family'],
            'font_size_px' => self::number($bodyContext['size']),
            'conditional' => array_values(array_filter(
                array('color', 'background', 'font-family', 'font-size'),
                fn(string $family): bool => $this->resolver->hasConditionalStyleFamily($body, $family) || (null !== $html && $this->resolver->hasConditionalStyleFamily($html, $family))
            )),
        );

        return array(
            'schema' => self::SCHEMA,
            'root_font_size_px' => self::number($this->rootPx),
            'root' => $root,
            'text' => self::rows($this->text),
            'backgrounds' => self::rows($this->backgrounds),
            'spacing' => self::rows($this->spacing),
            'widths' => self::rows($this->widths),
            'class_styles' => $this->classStyleRows(),
            'total_mass' => max(1, $mass),
            'element_count' => $this->elements,
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return int text mass under the element
     */
    private function walkChildren(DOMElement $element, array $context): int
    {
        $mass = 0;
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText) {
                $length = mb_strlen(trim((string) preg_replace('/\s+/u', ' ', $child->data)));
                if (0 === $length) continue;
                $runMass = max(1, (int) round($length * ($context['size'] / 16) ** 2));
                $mass += $runMass;
                $this->addText($context, $runMass);
                continue;
            }
            if ($child instanceof DOMElement) $mass += $this->walkElement($child, $context);
        }
        return $mass;
    }

    /** @param array<string,mixed> $parent */
    private function walkElement(DOMElement $element, array $parent): int
    {
        $tag = strtolower($element->tagName);
        if (in_array($tag, self::SKIPPED_TAGS, true) || $element->hasAttribute('hidden')) return 0;
        $declarations = $this->declarations($element);
        $display = strtolower(trim($declarations['display'] ?? ''));
        if ('none' === $display) return 0;
        ++$this->elements;
        $context = $this->inherit($parent, $element, $declarations);
        $background = $this->backgroundColor($declarations);
        $opaque = null !== $background && CssColor::opaque($background);
        $control = in_array($tag, array('button', 'a'), true) || 'button' === strtolower($element->getAttribute('role'));
        if ($opaque) {
            $context['background'] = (string) CssColor::hex($background);
            if ($control) $context['role'] = 'button';
        }
        $mass = $this->walkChildren($element, $context);
        if ('input' === $tag && in_array(strtolower($element->getAttribute('type')), array('submit', 'button'), true) && '' !== trim($element->getAttribute('value'))) {
            $inputMass = max(1, (int) round(mb_strlen(trim($element->getAttribute('value'))) * ($context['size'] / 16) ** 2));
            $mass += $inputMass;
            if ($opaque) $context['role'] = 'button';
            $this->addText($context, $inputMass);
        }
        if ($opaque) $this->addBackground((string) CssColor::hex($background), $control || 'input' === $tag ? 'cta' : 'surface', $mass + 1, $background);
        $this->spacingFrom($element, $declarations, $context['size']);
        $this->widthFrom($declarations, $context['size'], $mass);
        $this->recordClassStyles($element, $declarations, $parent['size'], $context['size']);
        return $mass;
    }

    /**
     * Apply one element's own declarations to the inherited context.
     *
     * @param array<string,mixed> $parent
     * @param array<string,string> $declarations
     * @return array<string,mixed>
     */
    private function inherit(array $parent, DOMElement $element, array $declarations): array
    {
        $tag = strtolower($element->tagName);
        $context = $parent;
        if (isset(self::HEADING_EM[$tag])) $context['role'] = 'heading';
        elseif ('a' === $tag && $element->hasAttribute('href') && 'button' !== $parent['role']) $context['role'] = 'link';
        elseif ('button' === $tag) $context['role'] = 'button';

        $size = $this->fontSize($declarations['font-size'] ?? null, $parent['size']);
        if (null === $size && isset(self::HEADING_EM[$tag])) $size = round(self::HEADING_EM[$tag] * $parent['size'], 3);
        if (null === $size && 'small' === $tag) $size = round(0.833 * $parent['size'], 3);
        if (null !== $size) $context['size'] = $size;

        $color = $this->keyword($declarations['color'] ?? null);
        if (null === $color || 'inherit' === $color || 'unset' === $color || 'currentcolor' === $color) {
            // Links and buttons do not inherit colour in the user-agent sheet.
            if (null === $color && 'a' === $tag && $element->hasAttribute('href')) $context['color'] = '#0000ee';
            elseif (null === $color && 'button' === $tag) $context['color'] = '#000000';
        } elseif ('initial' === $color) {
            $context['color'] = '#000000';
        } else {
            $hex = CssColor::hex($declarations['color']);
            if (null !== $hex) $context['color'] = $hex;
        }

        $family = $this->keyword($declarations['font-family'] ?? null);
        if (null !== $family && !in_array($family, array('inherit', 'unset'), true)) {
            $context['family'] = 'initial' === $family || str_contains($family, 'var(') ? null : self::clean($declarations['font-family']);
        } elseif (null === $family && in_array($tag, self::MONOSPACE_TAGS, true)) {
            $context['family'] = 'monospace';
        } elseif (null === $family && in_array($tag, array('button', 'input', 'textarea'), true)) {
            // Form controls use the user agent's control font unless the
            // author says otherwise. That font is not a design token.
            $context['family'] = null;
        }
        return $context;
    }

    private function fontSize(?string $value, float $parentPx): ?float
    {
        if (null === $value) return null;
        $value = strtolower(self::clean($value));
        if (in_array($value, array('inherit', 'unset'), true)) return $parentPx;
        if ('initial' === $value) return 16.0;
        if (isset(CssLength::FONT_SIZE_KEYWORDS[$value])) return CssLength::FONT_SIZE_KEYWORDS[$value];
        if ('smaller' === $value) return round($parentPx / 1.2, 3);
        if ('larger' === $value) return round($parentPx * 1.2, 3);
        if (1 === preg_match('/^(\d+\.?\d*|\.\d+)%$/', $value, $match)) return round((float) $match[1] / 100 * $parentPx, 3);
        $px = CssLength::toPx($value, $this->rootPx, $parentPx);
        return null !== $px && $px > 0 ? $px : null;
    }

    /** @param array<string,mixed> $context */
    private function addText(array $context, int $mass): void
    {
        $family = is_string($context['family']) ? $context['family'] : null;
        $key = implode("\n", array($context['role'], $context['color'], (string) $family, CssLength::format($context['size']), $context['background']));
        $row = $this->text[$key] ?? array(
            'role' => $context['role'],
            'color' => $context['color'],
            'font_family' => $family,
            'font_size_px' => self::number($context['size']),
            'background' => $context['background'],
            'count' => 0,
            'weight' => 0,
        );
        ++$row['count'];
        $row['weight'] += $mass;
        $this->text[$key] = $row;
    }

    private function addBackground(string $hex, string $role, int $weight, string $authored): void
    {
        $key = $role . "\n" . $hex;
        $row = $this->backgrounds[$key] ?? array('role' => $role, 'color' => $hex, 'authored' => self::clean($authored), 'count' => 0, 'weight' => 0);
        ++$row['count'];
        $row['weight'] += $weight;
        $this->backgrounds[$key] = $row;
    }

    /** @param array<string,string> $declarations */
    private function spacingFrom(DOMElement $element, array $declarations, float $fontPx): void
    {
        foreach (self::SPACING_PROPERTIES as $property) {
            if (!isset($declarations[$property])) continue;
            $value = self::clean($declarations[$property]);
            $values = match (true) {
                in_array($property, array('padding', 'margin'), true) => CssValueInspector::expandBoxShorthand($value),
                in_array($property, array('padding-inline', 'padding-block', 'margin-inline', 'margin-block'), true) => self::logicalPair($value),
                'gap' === $property => CssValueSplitter::splitTopLevelWhitespace($value),
                default => array($value),
            };
            foreach ($values as $side) {
                $px = CssLength::toPx($side, $this->rootPx, $fontPx);
                if (null === $px || $px <= 0) continue;
                $key = strtolower(trim($side));
                $row = $this->spacing[$key] ?? array('value' => trim($side), 'px' => self::number($px), 'count' => 0, 'weight' => 0);
                ++$row['count'];
                ++$row['weight'];
                $this->spacing[$key] = $row;
            }
        }
    }

    /**
     * What an element with this exact class list renders for each bindable
     * property, so the site plan can bind class-driven values to presets
     * (see PresetBinding). Only the element's own declarations count, not
     * inherited ones. An observation is the resting value in a comparable
     * form (opaque hex colour, pixel length, cleaned family stack), followed
     * by `;`-separated values the property takes under media conditions. It
     * is `!` when the value cannot be bound (not a plain opaque colour or
     * absolute length, `!important`, or changed by a `:hover`/`:focus` style)
     * and empty when the element does not declare the property.
     *
     * @param array<string,string> $declarations
     */
    private function recordClassStyles(DOMElement $element, array $declarations, float $parentPx, float $fontPx): void
    {
        $classes = array_values(array_unique(array_filter(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: array(), static fn(string $class): bool => '' !== $class)));
        if (array() === $classes) return;
        sort($classes, SORT_STRING);
        $key = implode(' ', $classes);
        $resting = array(
            'color' => isset($declarations['color']) ? self::colorObservation($declarations['color']) : '',
            'background-color' => self::backgroundObservation($declarations),
            'font-size' => isset($declarations['font-size']) ? $this->fontSizeObservation($declarations['font-size'], $parentPx) : '',
            'font-family' => isset($declarations['font-family']) ? self::familyObservation($declarations['font-family']) : '',
        ) + $this->paddingObservations($declarations, $fontPx);
        foreach ($resting as $property => $observation) {
            if ('' !== $observation && '!' !== $observation) $observation = $this->withVariants($element, $property, $observation, $parentPx);
            if ('' !== $observation && '!' !== $observation && $this->readsConditionalVariable($element, $property)) $observation = '!';
            $this->classStyles[$key][$property][$observation] = true;
        }
    }

    /**
     * Whether the element's own declaration of the property reads a custom
     * property that a media condition redefines (a dark theme that swaps
     * `:root` tokens), which a fixed preset could not follow.
     */
    private function readsConditionalVariable(DOMElement $element, string $property): bool
    {
        $properties = match (true) {
            'background-color' === $property => array('background', 'background-color'),
            str_starts_with($property, 'padding-') => array('padding', $property, 'padding-inline', 'padding-block', 'padding-inline-start', 'padding-inline-end', 'padding-block-start', 'padding-block-end'),
            'font-size' === $property, 'font-family' === $property => array('font', $property),
            default => array($property),
        };
        return $this->resolver->readsConditionalCustomProperty($element, $properties);
    }

    /** The resting observation followed by the values media-conditional rules give the property, or `!`. */
    private function withVariants(DOMElement $element, string $property, string $observation, float $parentPx): string
    {
        $family = self::CLASS_STYLE_FAMILIES[$property];
        if ($this->resolver->hasStateStyleFamily($element, $family)) return '!';
        $fontFamily = in_array($family, array('font-size', 'font-family'), true);
        if ($fontFamily && ($this->resolver->hasStateStyleFamily($element, 'font') || $this->resolver->hasConditionalStyleFamily($element, 'font'))) return '!';
        if (!$this->resolver->hasConditionalStyleFamily($element, $family)) return $observation;
        if (!in_array($property, array('color', 'background-color', 'font-size'), true)) return '!';
        $variants = array();
        $properties = 'background-color' === $property ? array('background', 'background-color') : array($property);
        foreach ($this->resolver->conditionalAuthorDeclaredPropertyValues($element, $properties) as $name => $values) {
            foreach ($values as $value) {
                $value = $this->resolver->resolveCssVariablesInValue((string) $value, $element);
                $variant = match ($property) {
                    'color' => self::colorObservation($value),
                    'background-color' => self::backgroundObservation(array($name => $value)),
                    default => $this->fontSizeObservation($value, $parentPx),
                };
                if ('!' === $variant || '' === $variant) return '!';
                $variants[$variant] = true;
            }
        }
        // A conditional rule of the family that sets none of the property's
        // own spellings (a `background-image`, say) leaves its value alone.
        ksort($variants, SORT_STRING);
        return implode(';', array_merge(array($observation), array_keys($variants)));
    }

    private static function colorObservation(string $value): string
    {
        if (1 === preg_match('/!\s*important\s*$/i', $value) || !CssColor::opaque($value)) return '!';
        return (string) CssColor::hex($value);
    }

    /** @param array<string,string> $declarations */
    private static function backgroundObservation(array $declarations): string
    {
        if (isset($declarations['background-color'])) return self::colorObservation($declarations['background-color']);
        if (!isset($declarations['background'])) return '';
        $tokens = CssValueSplitter::splitTopLevelWhitespace(trim($declarations['background']));
        return 1 === count($tokens) ? self::colorObservation($tokens[0]) : '!';
    }

    private function fontSizeObservation(string $value, float $parentPx): string
    {
        if (1 === preg_match('/!\s*important\s*$/i', $value)) return '!';
        $px = $this->fontSize($value, $parentPx);
        return null === $px ? '!' : CssLength::format($px);
    }

    private static function familyObservation(string $value): string
    {
        $stack = self::clean($value);
        if ($stack !== trim($value) || '' === $stack || str_contains($stack, 'var(') || in_array(strtolower($stack), array('inherit', 'initial', 'unset', 'revert', 'revert-layer'), true)) return '!';
        return $stack;
    }

    /**
     * Pixel observations for each physical padding side. The resolved
     * declarations are in cascade order, so a later shorthand or longhand
     * replaces the sides an earlier one set. An `!important` padding is not
     * bindable.
     *
     * @param array<string,string> $declarations
     * @return array<string,string>
     */
    private function paddingObservations(array $declarations, float $fontPx): array
    {
        $sides = array('top' => '', 'right' => '', 'bottom' => '', 'left' => '');
        foreach ($declarations as $property => $value) {
            $property = (string) $property;
            if ('padding' === $property) {
                $physical = array('top', 'right', 'bottom', 'left');
                $values = CssValueInspector::expandBoxShorthand(self::clean($value));
            } elseif (isset(self::PADDING_SIDES[$property])) {
                $physical = self::PADDING_SIDES[$property];
                $values = 2 === count($physical) ? self::logicalPair(self::clean($value)) : array(self::clean($value));
            } else {
                continue;
            }
            $important = 1 === preg_match('/!\s*important\s*$/i', $value);
            foreach ($physical as $index => $side) {
                $px = $important || !isset($values[$index]) ? null : CssLength::toPx((string) $values[$index], $this->rootPx, $fontPx);
                $sides[$side] = null === $px ? '!' : CssLength::format($px);
            }
        }
        $observations = array();
        foreach ($sides as $side => $observation) $observations['padding-' . $side] = $observation;
        return $observations;
    }

    /** @return list<string> The start and end values of a logical `*-inline` or `*-block` shorthand. */
    private static function logicalPair(string $value): array
    {
        $parts = CssValueSplitter::splitTopLevelWhitespace(trim($value));
        return match (count($parts)) {
            1 => array($parts[0], $parts[0]),
            2 => $parts,
            default => array(),
        };
    }

    /** @return list<array{classes:string,properties:array<string,list<string>>}> */
    private function classStyleRows(): array
    {
        ksort($this->classStyles, SORT_STRING);
        $rows = array();
        foreach ($this->classStyles as $classes => $properties) {
            $properties = array_map(static function (array $observations): array {
                $observations = array_keys($observations);
                sort($observations, SORT_STRING);
                return array_map('strval', $observations);
            }, array_filter($properties, static fn(array $observations): bool => array('' => true) !== $observations));
            if (array() === $properties) continue;
            ksort($properties, SORT_STRING);
            $rows[] = array('classes' => (string) $classes, 'properties' => $properties);
        }
        return $rows;
    }

    /** @param array<string,string> $declarations */
    private function widthFrom(array $declarations, float $fontPx, int $mass): void
    {
        $value = isset($declarations['max-width']) ? self::clean($declarations['max-width']) : '';
        if ('' === $value) return;
        $px = CssLength::toPx($value, $this->rootPx, $fontPx);
        if (null === $px || $px < 320) return;
        $margins = array_map(static fn(string $side): string => strtolower(trim($side)), isset($declarations['margin']) ? CssValueInspector::expandBoxShorthand(self::clean($declarations['margin'])) : array('', '', '', ''));
        $left = strtolower(self::clean($declarations['margin-left'] ?? $margins[3]));
        $right = strtolower(self::clean($declarations['margin-right'] ?? $margins[1]));
        $inline = strtolower(self::clean($declarations['margin-inline'] ?? ''));
        $centered = ('auto' === $left && 'auto' === $right) || 'auto' === $inline;
        if (!$centered) return;
        $key = strtolower($value);
        $row = $this->widths[$key] ?? array('value' => $value, 'px' => self::number($px), 'count' => 0, 'weight' => 0);
        ++$row['count'];
        $row['weight'] += $mass + 1;
        $this->widths[$key] = $row;
    }

    /** @param array<string,string> $declarations */
    private function backgroundColor(array $declarations): ?string
    {
        if (isset($declarations['background-color'])) {
            $value = self::clean($declarations['background-color']);
            return null !== CssColor::parse($value) ? $value : null;
        }
        if (!isset($declarations['background'])) return null;
        $value = self::clean($declarations['background']);
        if (str_contains(strtolower($value), 'gradient(')) return null;
        foreach (array_reverse(CssValueSplitter::splitTopLevelWhitespace($value)) as $token) {
            if (null !== CssColor::parse($token)) return $token;
        }
        return null;
    }

    /** @return array<string,string> */
    private function declarations(DOMElement $element): array
    {
        $declarations = array();
        foreach ($this->resolver->resolvedPresentationDeclarations($element) as $property => $value) {
            $declarations[strtolower((string) $property)] = (string) $value;
        }
        return $declarations;
    }

    private function keyword(?string $value): ?string
    {
        return null === $value ? null : strtolower(self::clean($value));
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s*!\s*important\s*$/i', '', $value));
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function rows(array $rows): array
    {
        ksort($rows, SORT_STRING);
        return array_values($rows);
    }

    /**
     * Integral lengths as integers so a report survives a JSON round trip
     * (staged receipts) unchanged.
     */
    private static function number(?float $px): int|float|null
    {
        if (null === $px) return null;
        $px = round($px, 3);
        return floor($px) === $px && abs($px) < PHP_INT_MAX ? (int) $px : $px;
    }
}
