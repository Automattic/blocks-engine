<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssAnalysisLimits;
use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\Path\ArtifactPath;
use Closure;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/** Emits bounded authored presentation facts for provider-materialized form controls. */
final class FormPresentationGraphBuilder
{
    private const MAX_CONTROLS = 128;
    private const MAX_RULES_PER_ROLE = 32;
    private const MAX_RULES = 8192;
    private const MAX_SELECTORS = 16384;
    private const MAX_CONDITION_DEPTH = 8;
    private const MAX_VARIANTS = 256;
    private const MAX_VISUAL_PARTS = 32;
    private const MAX_VISUAL_BYTES = 12288;
    private const MAX_VISUAL_DIMENSION = 4096;
    private const MAX_PROVENANCE = 16;
    private const MAX_DIAGNOSTICS = 32;
    private const CONTROL_CONTAINER_PROPERTIES = array(
        'background', 'background-color', 'border', 'border-color', 'border-style', 'border-width',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
    );
    private const PROPERTIES = array(
        'appearance', 'background', 'background-color', 'border', 'border-color', 'border-style', 'border-width',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
        'box-sizing', 'color', 'display', 'font-family', 'font-size', 'font-style', 'font-variant', 'font-weight',
        'height', 'letter-spacing', 'line-height', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        // Margin carries the same logical longhands padding already reports, so a
        // source that spaces an element with `margin-inline-start` keeps that box.
        'margin-block-start', 'margin-block-end', 'margin-inline-start', 'margin-inline-end',
        'max-width', 'min-height', 'min-width', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'padding-block-start', 'padding-block-end', 'padding-inline-start', 'padding-inline-end',
        'text-align', 'text-decoration', 'text-indent', 'text-transform', 'vertical-align', 'width',
        'align-self', 'bottom', 'flex', 'flex-basis', 'flex-grow', 'flex-shrink', 'inset', 'justify-self',
        'left', 'margin-block', 'margin-inline', 'order', 'position', 'right', 'top', 'transform', 'z-index',
        'flex-direction', 'align-items', 'justify-content', 'gap'
    );

    private array $diagnostics = array();
    private bool $truncated = false;

    /** @param (Closure(DOMElement, string): string)|null $resolveValue @param (Closure(DOMElement): string)|null $sanitizeInlineSvgMarkup @param (Closure(DOMElement): ?DOMElement)|null $requiredMarker */
    public function __construct(private readonly ?Closure $resolveValue = null, private readonly ?Closure $sanitizeInlineSvgMarkup = null, private readonly ?Closure $requiredMarker = null)
    {
    }

    /** @param list<array<string, mixed>> $stylesheets @return array<string, mixed> */
    public function build(DOMElement $form, array $stylesheets, string $inlineCss = ''): array
    {
        $this->diagnostics = array();
        $this->truncated = false;
        $analysis = (new CssRuleAnalyzer())->analyze($stylesheets, $inlineCss, self::PROPERTIES, CssAnalysisLimits::MAX_STYLESHEET_BYTES, self::MAX_RULES, self::MAX_SELECTORS, self::MAX_CONDITION_DEPTH);
        $controlsForCustomProperties = $this->presentationElements($form);
        $customPropertyAnalysis = (new CssRuleAnalyzer())->analyze(
            $stylesheets,
            $inlineCss,
            array('--*'),
            CssAnalysisLimits::MAX_STYLESHEET_BYTES,
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
        $visualGroups = array();
        $hasRequiredMarker = false;
        $controlContainers = array();
        $exclusiveAncestors = (new FormControlTopologyBuilder())->exclusiveWrapperAncestors($form);

        foreach ( $this->controls($form) as $index => $control ) {
            if ( $index >= self::MAX_CONTROLS ) {
                $this->truncated = true;
                $this->diagnostics[] = 'control_limit';
                break;
            }
            $row = array( 'index' => $index );
            $roles = array( 'control' => $control, 'label' => $this->label($control) );
            if ( null !== $this->requiredMarker && ($marker = ($this->requiredMarker)($control)) instanceof DOMElement ) {
                $roles['required_marker'] = $marker;
                $hasRequiredMarker = true;
            }
            foreach ( $roles as $role => $element ) {
                if ( ! $element instanceof DOMElement ) {
                    continue;
                }
                $matched = $this->matched($element, $analysis['rules']);
                $styles = $this->styles($matched['base'], $element, null, $customPropertyAnalysis['rules']);
                if ( array() !== $styles || 'required_marker' === $role ) {
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
            $container = $this->controlContainer($index, $exclusiveAncestors[$index] ?? array(), $analysis['rules'], $customPropertyAnalysis['rules']);
            if ( null !== $container ) {
                $controlContainers[] = $container['container'];
                foreach ( $container['variants'] as $variant ) {
                    if ( count($variants) >= self::MAX_VARIANTS ) { $this->truncated = true; $this->diagnostics[] = 'variant_limit'; break 2; }
                    $variants[] = $variant;
                }
            }
            $capturedParts = $this->visualParts($control, $index, $analysis['rules'], $customPropertyAnalysis['rules']);
            foreach ( $capturedParts as $part ) {
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
            $visualGroup = $capturedParts === array() ? null : $capturedParts[array_key_last($capturedParts)];
            if ( isset($visualGroup['group']) && array() === array_diff($visualGroup['group']['part_ids'], array_column($visualParts, 'id')) ) {
                $visualGroups[] = $visualGroup['group'];
                foreach ( $visualGroup['group_variants'] as $variant ) {
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
            'schema' => array() === $visualParts && ! $hasRequiredMarker && array() === $controlContainers ? 'generic/computed-form-presentation/v1' : 'generic/computed-form-presentation/v2',
            'basis' => 'source_css_cascade',
            'truncated' => $this->truncated,
            'limits' => array( 'controls' => self::MAX_CONTROLS, 'rules_per_role' => self::MAX_RULES_PER_ROLE ),
            'controls' => $controls,
            'variants' => $variants,
            'diagnostics' => array_slice(array_values(array_unique($this->diagnostics)), 0, self::MAX_DIAGNOSTICS),
        );
        if ( 'generic/computed-form-presentation/v2' === $graph['schema'] ) {
            $graph['visual_parts'] = $visualParts;
            $graph['visual_groups'] = $visualGroups;
            $graph['control_containers'] = $controlContainers;
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
        $expectedKeys = $v2 ? array( 'schema', 'basis', 'truncated', 'limits', 'controls', 'visual_parts', 'visual_groups', 'control_containers', 'variants', 'diagnostics' ) : array( 'schema', 'basis', 'truncated', 'limits', 'controls', 'variants', 'diagnostics' );
        if ( (! $v1 && ! $v2) || 'source_css_cascade' !== ($graph['basis'] ?? null) || ! is_bool($graph['truncated'] ?? null) || ! is_array($graph['limits'] ?? null) || array_diff(array_keys($graph['limits']), array( 'controls', 'rules_per_role' )) || self::MAX_CONTROLS !== ($graph['limits']['controls'] ?? null) || self::MAX_RULES_PER_ROLE !== ($graph['limits']['rules_per_role'] ?? null) || ! is_array($graph['controls'] ?? null) || ! array_is_list($graph['controls']) || count($graph['controls']) > self::MAX_CONTROLS || ($v2 && (! is_array($graph['visual_parts'] ?? null) || ! array_is_list($graph['visual_parts']) || count($graph['visual_parts']) > self::MAX_VISUAL_PARTS || ! is_array($graph['visual_groups'] ?? null) || ! array_is_list($graph['visual_groups']) || count($graph['visual_groups']) > self::MAX_VISUAL_PARTS || ! is_array($graph['control_containers'] ?? null) || ! array_is_list($graph['control_containers']) || count($graph['control_containers']) > self::MAX_CONTROLS)) || ! is_array($graph['variants'] ?? null) || ! array_is_list($graph['variants']) || count($graph['variants']) > self::MAX_VARIANTS || ! is_array($graph['diagnostics'] ?? null) || ! array_is_list($graph['diagnostics']) || count($graph['diagnostics']) > self::MAX_DIAGNOSTICS || array_filter($graph['diagnostics'], static fn (mixed $diagnostic): bool => ! is_string($diagnostic) || '' === trim($diagnostic) || strlen($diagnostic) > 1100) || array_diff(array_keys($graph), $expectedKeys) ) {
            throw new InvalidArgumentException('Form presentation graph envelope is invalid.');
        }
        $containerIndexes = array();
        foreach ( $v2 ? $graph['control_containers'] : array() as $container ) {
            if ( ! is_array($container) || array_diff(array_keys($container), array( 'index', 'source_selector', 'styles', 'provenance' )) || ! is_int($container['index'] ?? null) || $container['index'] < 0 || $container['index'] >= self::MAX_CONTROLS || isset($containerIndexes[$container['index']]) || ! is_string($container['source_selector'] ?? null) || '' === trim($container['source_selector']) || strlen($container['source_selector']) > 2048 || ! is_array($container['styles'] ?? null) || array_diff(array_keys($container['styles']), array_map(self::key(...), self::CONTROL_CONTAINER_PROPERTIES)) || ! is_array($container['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation control container is invalid.');
            self::assertStyles($container['styles']); self::assertProvenance($container['provenance'], $container['styles'], null);
            $containerIndexes[$container['index']] = array() !== $container['styles'];
        }
        $seen = array();
        foreach ( $graph['controls'] as $row ) {
            if ( ! is_array($row) || array_diff(array_keys($row), $v2 ? array( 'index', 'control', 'label', 'required_marker' ) : array( 'index', 'control', 'label' )) || ! is_int($row['index'] ?? null) || $row['index'] < 0 || $row['index'] >= self::MAX_CONTROLS || isset($seen[$row['index']]) || (! isset($row['control']) && ! isset($row['label']) && ! isset($row['required_marker'])) ) {
                throw new InvalidArgumentException('Form presentation control is invalid.');
            }
            $seen[$row['index']] = true;
            foreach ( $v2 ? array( 'control', 'label', 'required_marker' ) : array( 'control', 'label' ) as $role ) {
                if ( isset($row[$role]) ) self::assertRole($row[$role], null, 'required_marker' === $role);
            }
        }
        $partIds = array();
        foreach ( $v2 ? $graph['visual_parts'] : array() as $part ) {
            self::assertVisualPart($part);
            if ( isset($partIds[$part['id']]) ) throw new InvalidArgumentException('Form presentation visual part identity is duplicated.');
            $partIds[$part['id']] = $part['index'];
        }
        $groupIds = array();
        foreach ( $v2 ? $graph['visual_groups'] : array() as $group ) {
            self::assertVisualGroup($group, $partIds);
            if ( isset($groupIds[$group['id']]) ) throw new InvalidArgumentException('Form presentation visual group identity is duplicated.');
            $groupIds[$group['id']] = true;
        }
        $containerVariants = array();
        foreach ( $graph['variants'] as $variant ) {
            $isVisualPart = 'visual_part' === ($variant['role'] ?? null); $isVisualGroup = 'visual_group' === ($variant['role'] ?? null);
            $keys = $isVisualPart ? array( 'index', 'role', 'part_id', 'condition', 'style_patch', 'precedence', 'provenance' ) : ($isVisualGroup ? array( 'role', 'group_id', 'condition', 'style_patch', 'precedence', 'provenance' ) : array( 'index', 'role', 'condition', 'style_patch', 'precedence', 'provenance' ));
            if ( ! is_array($variant) || array_diff(array_keys($variant), $keys) || (! $isVisualGroup && (! is_int($variant['index'] ?? null) || $variant['index'] < 0 || $variant['index'] >= self::MAX_CONTROLS)) || ! in_array($variant['role'] ?? null, $v2 ? array( 'control', 'label', 'required_marker', 'control_container', 'visual_part', 'visual_group' ) : array( 'control', 'label' ), true) || ($isVisualPart ? (! is_string($variant['part_id'] ?? null) || ! isset($partIds[$variant['part_id']]) || $variant['index'] !== $partIds[$variant['part_id']]) : ($isVisualGroup ? (! is_string($variant['group_id'] ?? null) || ! isset($groupIds[$variant['group_id']])) : (isset($variant['part_id']) || isset($variant['group_id'])))) || ! is_array($variant['condition'] ?? null) || ! self::validCondition($variant['condition']) || ! is_array($variant['style_patch'] ?? null) || array() === $variant['style_patch'] || ! is_array($variant['precedence'] ?? null) || ! is_array($variant['provenance'] ?? null) ) {
                throw new InvalidArgumentException('Form presentation variant is invalid.');
            }
            self::assertStyles($variant['style_patch']);
            if ( 'control_container' === $variant['role'] ) $containerVariants[$variant['index']] = true;
            foreach ( $variant['precedence'] as $property => $precedence ) {
                if ( ! in_array($property, self::PROPERTIES, true) || ! isset($variant['style_patch'][self::key($property)]) || ! is_array($precedence) || ! is_int($precedence['source_order'] ?? null) || ! is_int($precedence['specificity'] ?? null) || ! is_bool($precedence['important'] ?? null) ) throw new InvalidArgumentException('Form presentation precedence is invalid.');
            }
            self::assertProvenance($variant['provenance'], $variant['style_patch'], $variant['condition']);
        }
        foreach ( $containerIndexes as $index => $hasBaseStyles ) if (! $hasBaseStyles && ! isset($containerVariants[$index])) throw new InvalidArgumentException('Form presentation conditional control container has no variants.');
    }

    private static function assertRole(mixed $role, ?array $condition, bool $allowEmpty = false): void
    {
        if ( ! is_array($role) || count($role) !== 2 || array_diff(array_keys($role), array( 'styles', 'provenance' )) || ! is_array($role['styles'] ?? null) || (! $allowEmpty && array() === $role['styles']) || ! is_array($role['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation role is invalid.');
        self::assertStyles($role['styles']);
        self::assertProvenance($role['provenance'], $role['styles'], $condition);
    }

    /** @param list<DOMElement> $ancestors @return array{container:array<string,mixed>,variants:list<array<string,mixed>}|null */
    private function controlContainer(int $index, array $ancestors, array $rules, array $customPropertyRules): ?array
    {
        $painted = array();
        $properties = array_flip(self::CONTROL_CONTAINER_PROPERTIES);
        foreach ( $ancestors as $ancestor ) {
            $matched = $this->matched($ancestor, $rules);
            $facts = array_intersect_key($matched['base'], $properties);
            $styles = $this->styles($facts, $ancestor, null, $customPropertyRules);
            $variants = array();
            foreach ( $this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $conditionalFacts ) {
                $condition = json_decode($encoded, true); $conditionalFacts = array_intersect_key($conditionalFacts, $properties);
                $patch = $this->styles($conditionalFacts, $ancestor, $condition, $customPropertyRules);
                if ($this->hasContainerPaint($patch)) $variants[] = array( 'index' => $index, 'role' => 'control_container', 'condition' => $condition, 'style_patch' => $patch, 'precedence' => $this->precedence($conditionalFacts), 'provenance' => $this->provenance($conditionalFacts, $condition) );
            }
            if ($this->hasContainerPaint($styles) || array() !== $variants) $painted[] = array( 'element' => $ancestor, 'facts' => $this->hasContainerPaint($styles) ? $facts : array(), 'matched' => $matched, 'styles' => $this->hasContainerPaint($styles) ? $styles : array(), 'variants' => $variants );
        }
        if ( count($painted) > 1 ) { $this->diagnostics[] = 'control_container_multiple_painted_wrappers'; return null; }
        if ( array() === $painted ) return null;
        $paint = $painted[0];
        $container = array( 'index' => $index, 'source_selector' => SourceDom::elementSelector($paint['element']), 'styles' => $paint['styles'], 'provenance' => $this->provenance($paint['facts'], null) );
        return array( 'container' => $container, 'variants' => $paint['variants'] );
    }

    /** A container paint requires a visible fill or border, not radius or neutral resets alone. */
    private function hasContainerPaint(array $styles): bool
    {
        $classifier = new SourceElementClassifier();
        foreach ( array('background', 'background_color') as $property ) if (isset($styles[$property]) && $classifier->isVisibleEmptyVisualPaint($styles[$property])) return true;
        if (isset($styles['border']) && $classifier->isVisibleEmptyVisualBorder($styles['border'])) return true;
        foreach ( array('border_width', 'border_top_width', 'border_right_width', 'border_bottom_width', 'border_left_width') as $property ) if (isset($styles[$property]) && ! $classifier->isPositiveCssLength($styles[$property])) return false;
        foreach ( array('border_color', 'border_top_color', 'border_right_color', 'border_bottom_color', 'border_left_color') as $property ) if (isset($styles[$property]) && $classifier->isVisibleEmptyVisualPaint($styles[$property])) return true;
        return false;
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

    /** A visual group retains the authored common container without assigning semantic meaning. */
    private static function assertVisualGroup(mixed $group, array $partIds): void
    {
        if ( ! is_array($group) || array_diff(array_keys($group), array( 'id', 'source_selector', 'part_ids', 'source_css' )) || ! is_string($group['id'] ?? null) || ! preg_match('/^visual-group-[a-f0-9]{16}$/', $group['id']) || ! is_string($group['source_selector'] ?? null) || '' === trim($group['source_selector']) || strlen($group['source_selector']) > 2048 || ! is_array($group['part_ids'] ?? null) || ! array_is_list($group['part_ids']) || count($group['part_ids']) < 2 || count($group['part_ids']) > self::MAX_VISUAL_PARTS || count(array_unique($group['part_ids'])) !== count($group['part_ids']) || array_filter($group['part_ids'], static fn (mixed $id): bool => ! is_string($id) || ! isset($partIds[$id])) || ! is_array($group['source_css'] ?? null) || ! in_array($group['source_css']['state'] ?? null, array( 'known', 'unknown' ), true) ) throw new InvalidArgumentException('Form presentation visual group is invalid.');
        if ( 'known' === $group['source_css']['state'] ) {
            if ( array_diff(array_keys($group['source_css']), array( 'state', 'styles', 'provenance' )) || ! is_array($group['source_css']['styles'] ?? null) || array() === $group['source_css']['styles'] || ! is_array($group['source_css']['provenance'] ?? null) ) throw new InvalidArgumentException('Form presentation visual group source CSS is invalid.');
            self::assertStyles($group['source_css']['styles']); self::assertProvenance($group['source_css']['provenance'], $group['source_css']['styles'], null);
        } elseif ( array_diff(array_keys($group['source_css']), array( 'state' )) ) throw new InvalidArgumentException('Form presentation visual group unknown CSS state is invalid.');
    }

    private static function assertStyles(array $styles): void
    {
        foreach ( $styles as $key => $value ) if ( ! is_string($key) || ! in_array($key, array_map(self::key(...), self::PROPERTIES), true) || ! is_string($value) || '' === trim($value) || strlen($value) > 160 ) throw new InvalidArgumentException('Form presentation style is invalid.');
    }

    private static function assertProvenance(array $provenance, array $styles, ?array $condition): void
    {
        if ( count($provenance) > self::MAX_PROVENANCE ) throw new InvalidArgumentException('Form presentation provenance exceeds its limit.');
        foreach ( $provenance as $fact ) {
            if ( ! is_array($fact) || ! is_string($fact['source_path'] ?? null) || '' === ArtifactPath::safeRelativePath($fact['source_path']) || ArtifactPath::safeRelativePath($fact['source_path']) !== $fact['source_path'] || ! preg_match('/^[a-f0-9]{64}$/', $fact['source_sha256'] ?? '') || ! is_string($fact['selector'] ?? null) || '' === trim($fact['selector']) || strlen($fact['selector']) > 1024 || ! is_array($fact['properties'] ?? null) || array() === $fact['properties'] || array_filter($fact['properties'], static fn (mixed $property): bool => ! is_string($property) || ! in_array($property, self::PROPERTIES, true) || ! isset($styles[self::key($property)])) || ($condition !== null && ($fact['condition'] ?? null) !== $condition) || ($condition === null && ($fact['condition'] ?? null) !== null) ) throw new InvalidArgumentException('Form presentation provenance is invalid.');
        }
    }

    /** @return list<DOMElement> */
    private function controls(DOMElement $form): array
    {
        $result = array();
        foreach ( $form->getElementsByTagName('*') as $element ) if ( in_array(strtolower($element->tagName), array( 'input', 'select', 'textarea', 'button' ), true) ) $result[] = $element;
        return $result;
    }

    /** @return list<DOMElement> */
    private function presentationElements(DOMElement $form): array
    {
        $elements = $this->controls($form);
        foreach ( $this->controls($form) as $control ) {
            $label = $this->label($control);
            if ( $label instanceof DOMElement ) $elements[] = $label;
            if ( null !== $this->requiredMarker && ($marker = ($this->requiredMarker)($control)) instanceof DOMElement ) $elements[] = $marker;
        }
        return $elements;
    }

    private function label(DOMElement $control): ?DOMElement
    {
        $id = $control->getAttribute('id');
        if ( '' !== $id && $control->ownerDocument instanceof DOMDocument ) foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) if ( $label instanceof DOMElement && $label->getAttribute('for') === $id ) return $label;
        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) if ( 'label' === strtolower($parent->tagName) ) return $parent;
        return null;
    }

    /** @return list<array{part: array<string,mixed>, variants: list<array<string,mixed>>, group?: array<string,mixed>, group_variants?: list<array<string,mixed>>}> */
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
            $parts[] = array( 'part' => $part, 'variants' => $variants, 'element' => $svg );
        }
        $svgs = array_column($parts, 'element');
        if ( count($svgs) >= 2 && ($groupElement = $this->lowestCommonAncestor($svgs)) instanceof DOMElement ) {
            $selector = SourceDom::elementSelector($groupElement);
            if ( strlen($selector) <= 2048 ) {
                $matched = $this->matched($groupElement, $rules);
                $styles = $this->styles($matched['base'], $groupElement, null, $customPropertyRules);
                $group = array( 'id' => 'visual-group-' . substr(hash('sha256', $selector), 0, 16), 'source_selector' => $selector, 'part_ids' => array_column(array_column($parts, 'part'), 'id'), 'source_css' => array( 'state' => 'unknown' ) );
                if ( array() !== $styles ) $group['source_css'] = array( 'state' => 'known', 'styles' => $styles, 'provenance' => $this->provenance($matched['base'], null) );
                $groupVariants = array();
                foreach ( $this->effectiveConditional($matched['conditional'], $matched['base']) as $encoded => $facts ) { $condition = json_decode($encoded, true); $patch = $this->styles($facts, $groupElement, $condition, $customPropertyRules); if ( array() !== $patch ) $groupVariants[] = array( 'role' => 'visual_group', 'group_id' => $group['id'], 'condition' => $condition, 'style_patch' => $patch, 'precedence' => $this->precedence($facts), 'provenance' => $this->provenance($facts, $condition) ); }
                $parts[array_key_last($parts)]['group'] = $group;
                $parts[array_key_last($parts)]['group_variants'] = $groupVariants;
            }
        }
        foreach ( $parts as &$part ) unset($part['element']); unset($part);
        return $parts;
    }

    /** @param list<DOMElement> $elements */
    private function lowestCommonAncestor(array $elements): ?DOMElement
    {
        for ( $candidate = $elements[0]->parentNode; $candidate instanceof DOMElement; $candidate = $candidate->parentNode ) {
            foreach ( $elements as $element ) { for ( $current = $element; $current instanceof DOMElement && ! $current->isSameNode($candidate); $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {} if (! $current instanceof DOMElement) continue 2; }
            return $candidate;
        }
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
        $result = array();
        foreach ( $facts as $property => $fact ) {
            $value = FormCustomPropertyResolver::resolve($fact['value'], $element, $condition, $rules);
            if ( null !== $this->resolveValue && str_contains($value, 'var(') ) $value = ($this->resolveValue)($element, $value);
            $result[self::key($property)] = $value;
        }
        ksort($result);
        return $result;
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
