<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

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
        'text-align', 'text-decoration', 'text-indent', 'text-transform', 'vertical-align', 'width'
    );

    private array $diagnostics = array();
    private bool $truncated = false;

    /** @param list<array<string, mixed>> $stylesheets @return array<string, mixed> */
    public function build(DOMElement $form, array $stylesheets, string $inlineCss = ''): array
    {
        $this->diagnostics = array();
        $this->truncated = false;
        $analysis = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, self::PROPERTIES, self::MAX_CSS_BYTES, self::MAX_RULES, self::MAX_SELECTORS, self::MAX_CONDITION_DEPTH);
        $this->diagnostics = $analysis['diagnostics'];
        $this->truncated = $analysis['truncated'];
        $controls = array();
        $variants = array();

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
                $styles = $this->styles($matched['base']);
                if ( array() !== $styles ) {
                    $row[$role] = array( 'styles' => $styles, 'provenance' => $this->provenance($matched['base'], null) );
                }
                foreach ( $this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $facts ) {
                    if ( count($variants) >= self::MAX_VARIANTS ) {
                        $this->truncated = true;
                        $this->diagnostics[] = 'variant_limit';
                        break 2;
                    }
                    $patch = $this->styles($facts);
                    if ( array() !== $patch ) {
                        $condition = json_decode($encoded, true);
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
            if ( count($row) > 1 ) {
                $controls[] = $row;
            }
        }

        $graph = array(
            'schema' => 'generic/computed-form-presentation/v1',
            'basis' => 'source_css_cascade',
            'truncated' => $this->truncated,
            'limits' => array( 'controls' => self::MAX_CONTROLS, 'rules_per_role' => self::MAX_RULES_PER_ROLE ),
            'controls' => $controls,
            'variants' => $variants,
            'diagnostics' => array_slice(array_values(array_unique($this->diagnostics)), 0, self::MAX_DIAGNOSTICS),
        );
        self::assertValid($graph);
        return $graph;
    }

    /** @param array<string, mixed> $graph */
    public static function assertValid(array $graph): void
    {
        if ( 'generic/computed-form-presentation/v1' !== ($graph['schema'] ?? null) || 'source_css_cascade' !== ($graph['basis'] ?? null) || ! is_bool($graph['truncated'] ?? null) || ! is_array($graph['limits'] ?? null) || array_diff(array_keys($graph['limits']), array( 'controls', 'rules_per_role' )) || self::MAX_CONTROLS !== ($graph['limits']['controls'] ?? null) || self::MAX_RULES_PER_ROLE !== ($graph['limits']['rules_per_role'] ?? null) || ! is_array($graph['controls'] ?? null) || ! array_is_list($graph['controls']) || count($graph['controls']) > self::MAX_CONTROLS || ! is_array($graph['variants'] ?? null) || ! array_is_list($graph['variants']) || count($graph['variants']) > self::MAX_VARIANTS || ! is_array($graph['diagnostics'] ?? null) || ! array_is_list($graph['diagnostics']) || count($graph['diagnostics']) > self::MAX_DIAGNOSTICS || array_filter($graph['diagnostics'], static fn (mixed $diagnostic): bool => ! is_string($diagnostic) || '' === trim($diagnostic) || strlen($diagnostic) > 1100) || array_diff(array_keys($graph), array( 'schema', 'basis', 'truncated', 'limits', 'controls', 'variants', 'diagnostics' )) ) {
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
        foreach ( $graph['variants'] as $variant ) {
            if ( ! is_array($variant) || array_diff(array_keys($variant), array( 'index', 'role', 'condition', 'style_patch', 'precedence', 'provenance' )) || ! is_int($variant['index'] ?? null) || $variant['index'] < 0 || $variant['index'] >= self::MAX_CONTROLS || ! in_array($variant['role'] ?? null, array( 'control', 'label' ), true) || ! is_array($variant['condition'] ?? null) || ! self::validCondition($variant['condition']) || ! is_array($variant['style_patch'] ?? null) || array() === $variant['style_patch'] || ! is_array($variant['precedence'] ?? null) || ! is_array($variant['provenance'] ?? null) ) {
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

    private function styles(array $facts): array { $result = array(); foreach ( $facts as $property => $fact ) $result[self::key($property)] = $fact['value']; ksort($result); return $result; }
    private static function key(string $property): string { return str_replace('-', '_', $property); }
    private function precedence(array $facts): array { $result = array(); foreach ( $facts as $property => $fact ) $result[$property] = array( 'source_order' => $fact['order'], 'specificity' => $fact['specificity'], 'important' => $fact['important'] ); ksort($result); return $result; }
    private function provenance(array $facts, ?array $condition): array { $grouped = array(); foreach ( $facts as $property => $fact ) { $key = $fact['path'] . "\n" . $fact['selector']; $grouped[$key] ??= array( 'source_path' => $fact['path'], 'source_sha256' => $fact['hash'], 'selector' => $fact['selector'], 'condition' => $condition, 'properties' => array() ); $grouped[$key]['properties'][] = $property; } foreach ( $grouped as &$item ) sort($item['properties'], SORT_STRING); unset($item); if ( count($grouped) > self::MAX_PROVENANCE ) { $this->truncated = true; $this->diagnostics[] = 'provenance_limit'; } return array_slice(array_values($grouped), 0, self::MAX_PROVENANCE); }

    private static function validCondition(array $condition, int $depth = 0): bool
    {
        if ( $depth > self::MAX_CONDITION_DEPTH ) return false;
        if ( 'all' === ($condition['kind'] ?? null) ) return is_array($condition['conditions'] ?? null) && array() !== $condition['conditions'] && count($condition['conditions']) <= self::MAX_CONDITION_DEPTH && array_reduce($condition['conditions'], static fn (bool $ok, mixed $item): bool => $ok && is_array($item) && self::validCondition($item, $depth + 1), true);
        return in_array($condition['kind'] ?? null, array( 'media', 'container', 'supports' ), true) && is_string($condition['query'] ?? null) && '' !== trim($condition['query']) && strlen($condition['query']) <= 1024;
    }
}
