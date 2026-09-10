<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/** Emits bounded authored presentation facts for provider-materialized form controls. */
final class FormPresentationGraphBuilder
{
    private const MAX_CONTROLS = 128;
    private const MAX_RULES_PER_ROLE = 32;
    private const MAX_CSS_BYTES = 4194304;
    private const MAX_RULES = 8192;
    private const MAX_SELECTORS = 16384;
    private const MAX_CONDITION_DEPTH = 8;
    private const MAX_VARIANTS = 256;
    private const MAX_VISUAL_PARTS = 32;
    private const MAX_VISUAL_BYTES = 12288;
    private const MAX_VISUAL_DIMENSION = 4096;
    private const MAX_PROVENANCE = 16;
    private const MAX_DIAGNOSTICS = 32;
    private const PROPERTIES = array(
        'appearance', 'background', 'background-color', 'border', 'border-color', 'border-style', 'border-width',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
        'box-sizing', 'color', 'display', 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight',
        'height', 'letter-spacing', 'line-height', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'max-width', 'min-height', 'min-width', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end',
        'text-align', 'text-decoration', 'text-indent', 'text-transform', 'vertical-align', 'width',
        'align-self', 'bottom', 'flex', 'flex-basis', 'flex-grow', 'flex-shrink', 'inset', 'justify-self',
        'left', 'margin-block', 'margin-inline', 'order', 'position', 'right', 'top', 'transform', 'z-index'
    );

    private array $diagnostics = array();
    private bool $truncated = false;

    /** @param (Closure(DOMElement, string): string)|null $resolveValue @param (Closure(DOMElement): string)|null $sanitizeInlineSvgMarkup */
    public function __construct(private readonly ?Closure $resolveValue = null, private readonly ?Closure $sanitizeInlineSvgMarkup = null)
    {
    }

    /** @param list<array<string, mixed>> $stylesheets @return array<string, mixed> */
    public function build(DOMElement $form, array $stylesheets, string $inlineCss = ''): array
    {
        $this->diagnostics = array();
        $this->truncated = false;
        $analysis = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, self::PROPERTIES, self::MAX_CSS_BYTES, self::MAX_RULES, self::MAX_SELECTORS, self::MAX_CONDITION_DEPTH);
        $controlsForCustomProperties = $this->controls($form);
        $customPropertyAnalysis = (new CssRuleAnalyzer())->analyze(
            $stylesheets,
            $inlineCss,
            array('--*'),
            self::MAX_CSS_BYTES,
            self::MAX_RULES,
            self::MAX_SELECTORS,
            self::MAX_CONDITION_DEPTH,
            function (array $selector) use ($controlsForCustomProperties): bool {
                foreach ( $controlsForCustomProperties as $control ) {
                    for ( $ancestor = $control; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode instanceof DOMElement ? $ancestor->parentNode : null ) {
                        if ( CssSelectorMatcher::matches($ancestor, $selector)['matches'] ) return true;
                    }
                }
                return false;
            }
        );
        $this->diagnostics = $analysis['diagnostics'];
        $this->truncated = $analysis['truncated'];
        $controls = array();
        $variants = array();
        $visualParts = array();

        foreach ( $this->controls($form) as $index => $control ) {
            if ( $index >= self::MAX_CONTROLS ) {
                $this->truncated = true;
                $this->diagnostics[] = 'control_limit';
                break;
            }
            $row = array( 'index' => $index );
            foreach ( array( 'control' => $control, 'label' => $this->label($control) ) as $role => $element ) {
                if ( ! $element instanceof DOMElement ) {
                    continue;
                }
                $matched = $this->matched($element, $analysis['rules']);
                $styles = $this->styles($matched['base'], $element, null, $customPropertyAnalysis['rules']);
                if ( array() !== $styles ) {
                    $row[$role] = array( 'styles' => $styles, 'provenance' => $this->provenance($matched['base'], null) );
                }
                foreach ( $this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $facts ) {
                    if ( count($variants) >= self::MAX_VARIANTS ) {
                        $this->truncated = true;
                        $this->diagnostics[] = 'variant_limit';
                        break 2;
                    }
                    $condition = json_decode($encoded, true);
                    $patch = $this->styles($facts, $element, $condition, $customPropertyAnalysis['rules']);
                    if ( array() !== $patch ) {
                        $variants[] = array(
                            'index' => $index,
                            'role' => $role,
                            'condition' => $condition,
                            'style_patch' => $patch,
                            'precedence' => $this->precedence($facts),
                            'provenance' => $this->provenance($facts, $condition),
                        );
                    }
                }
            }
            foreach ( $this->visualParts($control, $index, $analysis['rules'], $customPropertyAnalysis['rules']) as $part ) {
                if ( count($visualParts) >= self::MAX_VISUAL_PARTS ) {
                    $this->truncated = true;
                    $this->diagnostics[] = 'visual_part_limit';
                    break;
                }
                $visualParts[] = $part['part'];
                foreach ( $part['variants'] as $variant ) {
                    if ( count($variants) >= self::MAX_VARIANTS ) {
                        $this->truncated = true;
                        $this->diagnostics[] = 'variant_limit';
                        break 2;
                    }
                    $variants[] = $variant;
                }
            }
            if ( count($row) > 1 ) {
                $controls[] = $row;
            }
        }

        $graph = array(
            'schema' => array() === $visualParts ? 'generic/computed-form-presentation/v1' : 'generic/computed-form-presentation/v2',
            'basis' => 'source_css_cascade',
            'truncated' => $this->truncated,
            'limits' => array( 'controls' => self::MAX_CONTROLS, 'rules_per_role' => self::MAX_RULES_PER_ROLE ),
            'controls' => $controls,
            'variants' => $variants,
            'diagnostics' => array_slice(array_values(array_unique($this->diagnostics)), 0, self::MAX_DIAGNOSTICS),
        );
        if ( array() !== $visualParts ) {
            $graph['visual_parts'] = $visualParts;
        }
        self::assertValid($graph);
        return $graph;
    }

    /** @param array<string, mixed> $graph */
    public static function assertValid(array $graph): void
    {
        $version = $graph['schema'] ?? null;
        $v1 = 'generic/computed-form-presentation/v1' === $version;
        $v2 = 'generic/computed-form-presentation/v2' === $version;
        $expectedKeys = $v2 ? array( 'schema', 'basis', 'truncated', 'limits', 'controls', 'visual_parts', 'variants', 'diagnostics' ) : array( 'schema', 'basis', 'truncated', 'limits', 'controls', 'variants', 'diagnostics' );
        if ( (! $v1 && ! $v2) || 'source_css_cascade' !== ($graph['basis'] ?? null) || ! is_bool($graph['truncated'] ?? null) || ! is_array($graph['limits'] ?? null) || array_diff(array_keys($graph['limits']), array( 'controls', 'rules_per_role' )) || self::MAX_CONTROLS !== ($graph['limits']['controls'] ?? null) || self::MAX_RULES_PER_ROLE !== ($graph['limits']['rules_per_role'] ?? null) || ! is_array($graph['controls'] ?? null) || ! array_is_list($graph['controls']) || count($graph['controls']) > self::MAX_CONTROLS || ($v2 && (! is_array($graph['visual_parts'] ?? null) || ! array_is_list($graph['visual_parts']) || count($graph['visual_parts']) > self::MAX_VISUAL_PARTS)) || ! is_array($graph['variants'] ?? null) || ! array_is_list($graph['variants']) || count($graph['variants']) > self::MAX_VARIANTS || ! is_array($graph['diagnostics'] ?? null) || ! array_is_list($graph['diagnostics']) || count($graph['diagnostics']) > self::MAX_DIAGNOSTICS || array_filter($graph['diagnostics'], static fn (mixed $diagnostic): bool => ! is_string($diagnostic) || '' === trim($diagnostic) || strlen($diagnostic) > 1100) || array_diff(array_keys($graph), $expectedKeys) ) {
            throw new InvalidArgumentException('Form presentation graph envelope is invalid.');
        }
        $seen = array();
        foreach ( $graph['controls'] as $row ) {
            if ( ! is_array($row) || array_diff(array_keys($row), array( 'index', 'control', 'label' )) || ! is_int($row['index'] ?? null) || $row['index'] < 0 || $row['index'] >= self::MAX_CONTROLS || isset($seen[$row['index']]) || (! isset($row['control']) && ! isset($row['label'])) ) {
                throw new InvalidArgumentException('Form presentation control is invalid.');
            }
            $seen[$row['index']] = true;
            foreach ( array( 'control', 'label' ) as $role ) {
                if ( isset($row[$role]) ) self::assertRole($row[$role], null);
            }
        }
        $partIds = array();
        foreach ( $v2 ? $graph['visual_parts'] : array() as $part ) {
            self::assertVisualPart($part);
            if ( isset($partIds[$part['id']]) ) throw new InvalidArgumentException('Form presentation visual part identity is duplicated.');
            $partIds[$part['id']] = $part['index'];
        }
        foreach ( $graph['variants'] as $variant ) {
            if ( ! is_array($variant) || array_diff(array_keys($variant), array( 'index', 'role', 'part_id', 'condition', 'style_patch', 'precedence', 'provenance' )) || ! is_int($variant['index'] ?? null) || $variant['index'] < 0 || $variant['index'] >= self::MAX_CONTROLS || ! in_array($variant['role'] ?? null, $v2 ? array( 'control', 'label', 'visual_part' ) : array( 'control', 'label' ), true) || ('visual_part' === ($variant['role'] ?? null) ? (! is_string($variant['part_id'] ?? null) || ! isset($partIds[$variant['part_id']]) || $variant['index'] !== $partIds[$variant['part_id']]) : isset($variant['part_id'])) || ! is_array($variant['condition'] ?? null) || ! self::validCondition($variant['condition']) || ! is_array($variant['style_patch'] ?? null) || array() === $variant['style_patch'] || ! is_array($variant['precedence'] ?? null) || ! is_array($variant['provenance'] ?? null) ) {
                throw new InvalidArgumentException('Form presentation variant is invalid.');
            }
            self::assertStyles($variant['style_patch']);
            foreach ( $variant['precedence'] as $property => $precedence ) {
                if ( ! in_array($property, self::PROPERTIES, true) || ! isset($variant['style_patch'][self::key($property)]) || ! is_array($precedence) || ! is_int($precedence['source_order'] ?? null) || ! is_int($precedence['specificity'] ?? null) || ! is_bool($precedence['important'] ?? null) ) throw new InvalidArgumentException('Form presentation precedence is invalid.');
            }
            self::assertProvenance($variant['provenance'], $variant['style_patch'], $variant['condition']);
        }
    }

    private static function assertRole(mixed $role, ?array $condition): void
    {
        if ( ! is_array($role) || count($role) !== 2 || array_diff(array_keys($role), array( 'styles', 'provenance' )) || ! is_array($role['styles'] ?? null) || array() === $role['styles'] || ! is_array($role['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation role is invalid.');
        self::assertStyles($role['styles']);
        self::assertProvenance($role['provenance'], $role['styles'], $condition);
    }

    /** A visual part describes source identity and facts, never an inferred semantic role. */
    private static function assertVisualPart(mixed $part): void
    {
        if ( ! is_array($part) || array_diff(array_keys($part), array( 'id', 'index', 'kind', 'source_selector', 'markup', 'intrinsic_size', 'source_css' )) || ! is_string($part['id'] ?? null) || ! preg_match('/^control-[0-9]+-svg-[0-9]+$/', $part['id']) || ! is_int($part['index'] ?? null) || $part['index'] < 0 || $part['index'] >= self::MAX_CONTROLS || ! is_string($part['source_selector'] ?? null) || '' === trim($part['source_selector']) || strlen($part['source_selector']) > 2048 || 'inline_svg' !== ($part['kind'] ?? null) || ! is_string($part['markup'] ?? null) || strlen($part['markup']) > self::MAX_VISUAL_BYTES || ! SourceDom::isSafeInlineSvgMarkup($part['markup']) || ! is_array($part['source_css'] ?? null) || ! in_array($part['source_css']['state'] ?? null, array( 'known', 'unknown' ), true) ) throw new InvalidArgumentException('Form presentation visual part is invalid.');
        if ( isset($part['intrinsic_size']) && (! is_array($part['intrinsic_size']) || array_diff(array_keys($part['intrinsic_size']), array( 'width', 'height' )) || ! self::validDimension($part['intrinsic_size']['width'] ?? null) || ! self::validDimension($part['intrinsic_size']['height'] ?? null)) ) throw new InvalidArgumentException('Form presentation visual part dimensions are invalid.');
        $sourceCss = $part['source_css'];
        if ( 'known' === $sourceCss['state'] ) {
            if ( array_diff(array_keys($sourceCss), array( 'state', 'styles', 'provenance' )) || ! is_array($sourceCss['styles'] ?? null) || array() === $sourceCss['styles'] || ! is_array($sourceCss['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation visual part source CSS is invalid.');
            self::assertStyles($sourceCss['styles']); self::assertProvenance($sourceCss['provenance'], $sourceCss['styles'], null);
        } elseif ( array_diff(array_keys($sourceCss), array( 'state' )) ) throw new InvalidArgumentException('Form presentation visual part unknown CSS state is invalid.');
    }

    private static function assertStyles(array $styles): void
    {
        foreach ( $styles as $key => $value ) if ( ! is_string($key) || ! in_array($key, array_map(self::key(...), self::PROPERTIES), true) || ! is_string($value) || '' === trim($value) || strlen($value) > 160 ) throw new InvalidArgumentException('Form presentation style is invalid.');
    }

    private static function assertProvenance(array $provenance, array $styles, ?array $condition): void
    {
        if ( count($provenance) > self::MAX_PROVENANCE ) throw new InvalidArgumentException('Form presentation provenance exceeds its limit.');
        foreach ( $provenance as $fact ) {
            if ( ! is_array($fact) || ! is_string($fact['source_path'] ?? null) || ! preg_match('~^(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$~', $fact['source_path']) || ! preg_match('/^[a-f0-9]{64}$/', $fact['source_sha256'] ?? '') || ! is_string($fact['selector'] ?? null) || '' === trim($fact['selector']) || strlen($fact['selector']) > 1024 || ! is_array($fact['properties'] ?? null) || array() === $fact['properties'] || array_filter($fact['properties'], static fn (mixed $property): bool => ! is_string($property) || ! in_array($property, self::PROPERTIES, true) || ! isset($styles[self::key($property)])) || ($condition !== null && ($fact['condition'] ?? null) !== $condition) || ($condition === null && ($fact['condition'] ?? null) !== null) ) throw new InvalidArgumentException('Form presentation provenance is invalid.');
        }
    }

    /** @return list<DOMElement> */
    private function controls(DOMElement $form): array
    {
        $result = array();
        foreach ( $form->getElementsByTagName('*') as $element ) if ( in_array(strtolower($element->tagName), array( 'input', 'select', 'textarea', 'button' ), true) ) $result[] = $element;
        return $result;
    }

    private function label(DOMElement $control): ?DOMElement
    {
        $id = $control->getAttribute('id');
        if ( '' !== $id && $control->ownerDocument instanceof DOMDocument ) foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) if ( $label instanceof DOMElement && $label->getAttribute('for') === $id ) return $label;
        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) if ( 'label' === strtolower($parent->tagName) ) return $parent;
        return null;
    }

    /** @return list<array{part: array<string,mixed>, variants: list<array<string,mixed>>}> */
    private function visualParts(DOMElement $control, int $index, array $rules, array $customPropertyRules): array
    {
        if ( null === $this->sanitizeInlineSvgMarkup ) return array();
        $parts = array();
        $ordinal = 0;
        foreach ( $control->getElementsByTagName('svg') as $svg ) {
            if ( ! $svg instanceof DOMElement || ! SourceDom::svgHasDrawableContent($svg) ) continue;
            if ( count($parts) >= self::MAX_VISUAL_PARTS ) { $this->truncated = true; $this->diagnostics[] = 'visual_part_limit'; break; }
            $selector = SourceDom::elementSelector($svg);
            if ( strlen($selector) > 2048 ) { $this->diagnostics[] = 'visual_selector_limit'; continue; }
            $markup = trim(($this->sanitizeInlineSvgMarkup)($svg));
            if ( strlen($markup) > self::MAX_VISUAL_BYTES || ! SourceDom::isSafeInlineSvgMarkup($markup) ) { $this->diagnostics[] = 'unsafe_visual_part'; continue; }
            $matched = $this->matched($svg, $rules);
            $styles = $this->styles($matched['base'], $svg, null, $customPropertyRules);
            $part = array( 'id' => 'control-' . $index . '-svg-' . $ordinal++, 'index' => $index, 'kind' => 'inline_svg', 'source_selector' => $selector, 'markup' => $markup, 'source_css' => array( 'state' => 'unknown' ) );
            $size = $this->intrinsicSize($svg);
            if ( array() !== $size ) $part['intrinsic_size'] = $size;
            if ( array() !== $styles ) $part['source_css'] = array( 'state' => 'known', 'styles' => $styles, 'provenance' => $this->provenance($matched['base'], null) );
            $variants = array();
            foreach ( $this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $facts ) {
                $condition = json_decode($encoded, true);
                $patch = $this->styles($facts, $svg, $condition, $customPropertyRules);
                if ( array() !== $patch ) $variants[] = array( 'index' => $index, 'role' => 'visual_part', 'part_id' => $part['id'], 'condition' => $condition, 'style_patch' => $patch, 'precedence' => $this->precedence($facts), 'provenance' => $this->provenance($facts, $condition) );
            }
            $parts[] = array( 'part' => $part, 'variants' => $variants );
        }
        return $parts;
    }

    /** @param list<array<string, mixed>> $rules */
    private function matched(DOMElement $element, array $rules): array
    {
        if ( $element->hasAttribute('style') ) {
            $inline = $element->getAttribute('style');
            $rules[] = array( 'inline' => true, 'selector' => '[style]', 'declarations' => CssRuleAnalyzer::declarations($inline, self::PROPERTIES), 'condition' => null, 'path' => 'inline-style', 'hash' => hash('sha256', $inline), 'order' => PHP_INT_MAX, 'specificity' => 10000 );
        }
        $base = array(); $conditional = array(); $matched = 0;
        foreach ( $rules as $rule ) {
            $match = ! empty($rule['inline']) ? array( 'supported' => true, 'matches' => true ) : CssSelectorMatcher::matches($element, $rule['parsed_selector']);
            if ( ! $match['supported'] ) { $this->diagnostics[] = 'unsupported_selector:' . $rule['selector']; continue; }
            if ( ! $match['matches'] ) continue;
            if ( $matched++ >= self::MAX_RULES_PER_ROLE ) { $this->truncated = true; $this->diagnostics[] = 'rules_per_role_limit'; break; }
            foreach ( $rule['declarations'] as $declaration ) {
                if ( ! in_array($declaration['name'], self::PROPERTIES, true) ) continue;
                $important = 1 === preg_match('/\s*!important\s*$/i', $declaration['value']);
                $value = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
                $fact = array( 'value' => $value, 'path' => $rule['path'], 'hash' => $rule['hash'], 'selector' => $rule['selector'], 'order' => $rule['order'], 'specificity' => $rule['specificity'], 'important' => $important );
                $encoded = null === $rule['condition'] ? null : json_encode($rule['condition']);
                if ( null === $encoded ) $target =& $base; else { $conditional[$encoded] ??= array(); $target =& $conditional[$encoded]; }
                CssCascade::apply($target, $declaration['name'], $fact); unset($target);
            }
        }
        return array( 'base' => $base, 'conditional' => $conditional );
    }

    private function effectiveConditional(array $conditional, array $base): array
    {
        foreach ( $conditional as $condition => &$facts ) foreach ( $facts as $property => $fact ) if ( isset($base[$property]) && ! CssCascade::wins($fact, $base[$property]) ) unset($facts[$property]);
        unset($facts);
        return array_filter($conditional);
    }

    /** @param list<array<string, mixed>> $rules */
    private function styles(array $facts, DOMElement $element, ?array $condition, array $rules): array
    {
        $customProperties = $this->cascadedCustomProperties($element, $condition, $rules);
        $result = array();
        foreach ( $facts as $property => $fact ) {
            $value = $this->expandCustomProperties($fact['value'], $customProperties);
            if ( null !== $this->resolveValue && str_contains($value, 'var(') ) $value = ($this->resolveValue)($element, $value);
            $result[self::key($property)] = $value;
        }
        ksort($result);
        return $result;
    }

    /**
     * Resolve source custom properties at the control's cascade scope. Conditional
     * presentation variants only admit declarations from their own condition, so a
     * mobile token cannot replace the desktop value in a separate emitted rule.
     *
     * @param list<array<string, mixed>> $rules
     * @return array<string, string>
     */
    private function cascadedCustomProperties(DOMElement $element, ?array $condition, array $rules): array
    {
        $properties = array();
        $ancestors = array();
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) $ancestors[] = $current;
        foreach ( array_reverse($ancestors) as $ancestor ) {
            foreach ( $rules as $rule ) {
                if ( ( null !== ($rule['condition'] ?? null) && ($rule['condition'] ?? null) !== $condition ) || ! CssSelectorMatcher::matches($ancestor, $rule['parsed_selector'])['matches'] ) continue;
                foreach ( $rule['declarations'] as $declaration ) {
                    if ( ! str_starts_with($declaration['name'], '--') ) continue;
                    $value = preg_replace('/\s*!important\s*$/i', '', $declaration['value']) ?? $declaration['value'];
                    $fact = array( 'value' => $value, 'order' => $rule['order'], 'specificity' => $rule['specificity'], 'important' => 1 === preg_match('/\s*!important\s*$/i', $declaration['value']) );
                    CssCascade::apply($properties, $declaration['name'], $fact);
                }
            }
        }
        return array_map(static fn (array $fact): string => $fact['value'], $properties);
    }

    /** @param array<string, string> $customProperties */
    private function expandCustomProperties(string $value, array $customProperties): string
    {
        for ( $pass = 0; $pass < 5 && str_contains($value, 'var('); ++$pass ) {
            $expanded = preg_replace_callback('/var\(\s*(--[A-Za-z0-9_-]+)\s*(?:,\s*([^()]*))?\)/', static function (array $matches) use ($customProperties): string {
                return $customProperties[$matches[1]] ?? ( isset($matches[2]) ? trim($matches[2]) : $matches[0] );
            }, $value);
            if ( ! is_string($expanded) || $expanded === $value ) break;
            $value = $expanded;
        }
        return trim($value);
    }
    private static function key(string $property): string { return str_replace('-', '_', $property); }
    /** @return array<string, int> */
    private function intrinsicSize(DOMElement $svg): array { $width = $this->dimension(SourceDom::attr($svg, 'width')); $height = $this->dimension(SourceDom::attr($svg, 'height')); return null !== $width && null !== $height ? array( 'width' => $width, 'height' => $height ) : array(); }
    private function dimension(string $value): ?int { return 1 === preg_match('/^[1-9][0-9]{0,3}$/D', trim($value)) && (int) $value <= self::MAX_VISUAL_DIMENSION ? (int) $value : null; }
    private static function validDimension(mixed $value): bool { return is_int($value) && $value > 0 && $value <= self::MAX_VISUAL_DIMENSION; }
    private function precedence(array $facts): array { $result = array(); foreach ( $facts as $property => $fact ) $result[$property] = array( 'source_order' => $fact['order'], 'specificity' => $fact['specificity'], 'important' => $fact['important'] ); ksort($result); return $result; }
    private function provenance(array $facts, ?array $condition): array { $grouped = array(); foreach ( $facts as $property => $fact ) { $key = $fact['path'] . "\n" . $fact['selector']; $grouped[$key] ??= array( 'source_path' => $fact['path'], 'source_sha256' => $fact['hash'], 'selector' => $fact['selector'], 'condition' => $condition, 'properties' => array() ); $grouped[$key]['properties'][] = $property; } foreach ( $grouped as &$item ) sort($item['properties'], SORT_STRING); unset($item); if ( count($grouped) > self::MAX_PROVENANCE ) { $this->truncated = true; $this->diagnostics[] = 'provenance_limit'; } return array_slice(array_values($grouped), 0, self::MAX_PROVENANCE); }

    private static function validCondition(array $condition, int $depth = 0): bool
    {
        if ( $depth > self::MAX_CONDITION_DEPTH ) return false;
        if ( 'all' === ($condition['kind'] ?? null) ) return is_array($condition['conditions'] ?? null) && array() !== $condition['conditions'] && count($condition['conditions']) <= self::MAX_CONDITION_DEPTH && array_reduce($condition['conditions'], static fn (bool $ok, mixed $item): bool => $ok && is_array($item) && self::validCondition($item, $depth + 1), true);
        return in_array($condition['kind'] ?? null, array( 'media', 'container', 'supports' ), true) && is_string($condition['query'] ?? null) && '' !== trim($condition['query']) && strlen($condition['query']) <= 1024;
    }
}
