<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\WordPress;

final class Runtime
{
    /**
     * @var array<int, string>
     */
    private const FALLBACK_CORE_BLOCK_NAMES = array(
        'core/accordion',
        'core/audio',
        'core/breadcrumbs',
        'core/button',
        'core/buttons',
        'core/categories',
        'core/code',
        'core/column',
        'core/columns',
        'core/details',
        'core/embed',
        'core/file',
        'core/footnotes',
        'core/gallery',
        'core/group',
        'core/heading',
        'core/icon',
        'core/image',
        'core/list',
        'core/list-item',
        'core/math',
        'core/media-text',
        'core/navigation',
        'core/navigation-link',
        'core/navigation-submenu',
        'core/paragraph',
        'core/post-terms',
        'core/preformatted',
        'core/pullquote',
        'core/query-total',
        'core/quote',
        'core/search',
        'core/separator',
        'core/shortcode',
        'core/spacer',
        'core/table',
        'core/tag-cloud',
        'core/term-description',
        'core/video',
    );

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $diagnostics = array();

    /** @var array<string, array<string, mixed>>|null */
    private ?array $fallbackCoreBlockMetadata = null;

    public function hasWordPress(): bool
    {
        return $this->canParseBlocks()
            || $this->canSerializeBlocks()
            || $this->canRenderBlock()
            || $this->canStripAllTags()
            || $this->canParseShortcodeAttributes()
            || $this->canEncodeJson()
            || $this->canEscapeHtml()
            || $this->canEscapeAttribute();
    }

    public function canParseBlocks(): bool
    {
        return function_exists('parse_blocks');
    }

    public function canSerializeBlocks(): bool
    {
        return function_exists('serialize_blocks');
    }

    public function canRenderBlock(): bool
    {
        return function_exists('render_block');
    }

    public function canStripAllTags(): bool
    {
        return function_exists('wp_strip_all_tags');
    }

    public function canParseShortcodeAttributes(): bool
    {
        return function_exists('shortcode_parse_atts');
    }

    public function canEncodeJson(): bool
    {
        return function_exists('wp_json_encode');
    }

    public function canEscapeHtml(): bool
    {
        return function_exists('esc_html');
    }

    public function canEscapeAttribute(): bool
    {
        return function_exists('esc_attr');
    }

    /**
     * Native core block names available as potential WordPress targets.
     *
     * @return array<int, string>
     */
    public function availableCoreBlockNames(): array
    {
        $registered = $this->registeredCoreBlockNames();
        if ( array() !== $registered ) {
            return $registered;
        }

        return self::FALLBACK_CORE_BLOCK_NAMES;
    }

    /**
     * Whether the registered block type declares support for one authored border
     * component. WordPress still exposes border support under the historical
     * `__experimentalBorder` key in block.json; accept the stabilized `border`
     * key as well. Unknown block metadata fails closed so callers can retain the
     * declaration through a CSS carrier instead of emitting an ignored attribute.
     */
    public function blockSupportsBorder(string $blockName, string $component): bool
    {
        if ( ! in_array($component, array( 'color', 'style', 'width' ), true) ) {
            return false;
        }

        $metadata = $this->blockMetadata($blockName);
        if ( null === $metadata ) {
            return false;
        }

        $supports = $metadata['supports'];

        $border = $supports['border'] ?? $supports['__experimentalBorder'] ?? false;
        if ( true === $border ) {
            return true;
        }

        return is_array($border) && true === ($border[ $component ] ?? false);
    }

    /**
     * Remove support attributes the target block cannot serialize. Unsupported
     * values remain available to the caller for its deterministic CSS carrier.
     *
     * @param array<string, mixed> $attrs
     * @return array{attrs: array<string, mixed>, fallbackStyle: array<string, mixed>}
     */
    public function normalizeBlockSupportAttributes(string $blockName, array $attrs): array
    {
        $metadata = $this->blockMetadata($blockName);
        if ( null === $metadata ) return array( 'attrs' => $attrs, 'fallbackStyle' => array() );
        $supports = $metadata['supports'];
        $fallback = array();
        if ( isset($attrs['layout']) && ! $this->supportsFeature($supports, 'layout', 'layout') ) unset($attrs['layout']);
        if ( 'grid' === ($attrs['layout']['type'] ?? null) && ! $this->supportsFeature($supports, 'layout', 'grid') ) unset($attrs['layout']);
        foreach ( array( 'width', 'height' ) as $dimension ) {
            if ( array_key_exists($dimension, $attrs) && $this->skipsFeatureSerialization($supports, 'dimensions', $dimension) ) {
                $fallback['dimensions'][ $dimension ] = $attrs[ $dimension ];
                unset($attrs[ $dimension ]);
            }
        }
        $style = is_array($attrs['style'] ?? null) ? $attrs['style'] : array();
        $this->filterStyleGroup($style, $fallback, $supports, 'dimensions', array( 'minHeight' => 'minHeight', 'maxWidth' => 'maxWidth' ));
        $this->filterSpacing($style, $fallback, $supports);
        $this->filterStyleGroup($style, $fallback, $supports, 'typography', array( 'fontFamily' => '__experimentalFontFamily', 'fontSize' => 'fontSize', 'fontWeight' => '__experimentalFontWeight', 'lineHeight' => 'lineHeight', 'letterSpacing' => '__experimentalLetterSpacing', 'textTransform' => '__experimentalTextTransform', 'textDecoration' => '__experimentalTextDecoration', 'fontStyle' => '__experimentalFontStyle' ));
        $this->filterStyleGroup($style, $fallback, $supports, 'color', array( 'text' => 'text', 'background' => 'background', 'gradient' => 'gradients' ), true);
        $this->filterBorder($style, $fallback, $supports);
        if ( isset($style['shadow']) && ! $this->supportsFeature($supports, 'shadow', 'shadow') ) { $fallback['shadow'] = $style['shadow']; unset($style['shadow']); }
        if ( array() === $style ) unset($attrs['style']); else $attrs['style'] = $style;
        foreach ( array( 'textColor' => 'text', 'backgroundColor' => 'background' ) as $attribute => $feature ) if ( isset($attrs[ $attribute ]) && ! $this->supportsFeature($supports, 'color', $feature, true) ) unset($attrs[ $attribute ]);
        return array( 'attrs' => $attrs, 'fallbackStyle' => $fallback );
    }

    /** @param array<string, mixed> $style @param array<string, mixed> $fallback @param array<string, mixed> $supports @param array<string, string> $features */
    private function filterStyleGroup(array &$style, array &$fallback, array $supports, string $group, array $features, bool $colorDefaults = false): void { $values = is_array($style[ $group ] ?? null) ? $style[ $group ] : array(); foreach ( $features as $key => $feature ) if ( array_key_exists($key, $values) && ! $this->supportsFeature($supports, $group, $feature, $colorDefaults) ) { $fallback[ $group ][ $key ] = $values[ $key ]; unset($values[ $key ]); } if ( array() === $values ) unset($style[ $group ]); else $style[ $group ] = $values; }

    /** @param array<string, mixed> $style @param array<string, mixed> $fallback @param array<string, mixed> $supports */
    private function filterSpacing(array &$style, array &$fallback, array $supports): void { $spacing = is_array($style['spacing'] ?? null) ? $style['spacing'] : array(); foreach ( array( 'margin', 'padding' ) as $box ) { $sides = is_array($spacing[ $box ] ?? null) ? $spacing[ $box ] : array(); foreach ( $sides as $side => $value ) if ( ! $this->supportsFeature($supports, 'spacing', $box, false, (string) $side) ) { $fallback['spacing'][ $box ][ $side ] = $value; unset($sides[ $side ]); } if ( array() === $sides ) unset($spacing[ $box ]); else $spacing[ $box ] = $sides; } if ( isset($spacing['blockGap']) && ! $this->supportsFeature($supports, 'spacing', 'blockGap') ) { $fallback['spacing']['blockGap'] = $spacing['blockGap']; unset($spacing['blockGap']); } if ( array() === $spacing ) unset($style['spacing']); else $style['spacing'] = $spacing; }

    /** @param array<string, mixed> $style @param array<string, mixed> $fallback @param array<string, mixed> $supports */
    private function filterBorder(array &$style, array &$fallback, array $supports): void { $border = is_array($style['border'] ?? null) ? $style['border'] : array(); foreach ( array( 'color', 'style', 'width', 'radius' ) as $feature ) if ( isset($border[ $feature ]) && ! $this->supportsFeature($supports, 'border', $feature) ) { $fallback['border'][ $feature ] = $border[ $feature ]; unset($border[ $feature ]); } foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) { $sideBorder = is_array($border[ $side ] ?? null) ? $border[ $side ] : array(); foreach ( array( 'color', 'style', 'width' ) as $feature ) if ( isset($sideBorder[ $feature ]) && ! $this->supportsFeature($supports, 'border', $feature) ) { $fallback['border'][ $side ][ $feature ] = $sideBorder[ $feature ]; unset($sideBorder[ $feature ]); } if ( array() === $sideBorder ) unset($border[ $side ]); else $border[ $side ] = $sideBorder; } if ( array() === $border ) unset($style['border']); else $style['border'] = $border; }

    /** @param array<string, mixed> $supports */
    private function supportsFeature(array $supports, string $group, string $feature, bool $colorDefaults = false, string $side = ''): bool { $declaration = 'border' === $group ? ($supports['border'] ?? $supports['__experimentalBorder'] ?? false) : ($supports[ $group ] ?? false); if ( true === $declaration ) return true; if ( ! is_array($declaration) || true === ($declaration['__experimentalSkipSerialization'] ?? false) ) return false; $skipped = $declaration['__experimentalSkipSerialization'] ?? array(); $skipFeature = lcfirst(str_replace('__experimental', '', $feature)); if ( is_array($skipped) && (in_array($feature, $skipped, true) || in_array($skipFeature, $skipped, true)) ) return false; if ( 'layout' === $group ) return false !== ($declaration['allowEditing'] ?? true) && ( 'grid' !== $feature || false !== ($declaration['allowSwitching'] ?? true) ); $value = $declaration[ $feature ] ?? ($colorDefaults ? true : false); return '' !== $side && is_array($value) ? in_array($side, $value, true) : true === $value; }

    /** @param array<string, mixed> $supports */
    private function skipsFeatureSerialization(array $supports, string $group, string $feature): bool
    {
        $declaration = $supports[ $group ] ?? false;
        if ( ! is_array($declaration) ) return false;
        $skipped = $declaration['__experimentalSkipSerialization'] ?? false;
        return true === $skipped || (is_array($skipped) && in_array($feature, $skipped, true));
    }

    /**
     * @return array<int, string>
     */
    private function registeredCoreBlockNames(): array
    {
        $names = array();
        foreach ( $this->registeredBlockTypes() as $key => $blockType ) {
            $name = is_string($key) ? $key : '';
            if ( '' === $name && is_object($blockType) && isset($blockType->name) && is_string($blockType->name) ) {
                $name = $blockType->name;
            }

            if ( str_starts_with($name, 'core/') ) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @return array<string|int, object>
     */
    private function registeredBlockTypes(): array
    {
        if ( ! class_exists('WP_Block_Type_Registry') || ! method_exists('WP_Block_Type_Registry', 'get_instance') ) {
            return array();
        }

        $registry = \WP_Block_Type_Registry::get_instance();
        if ( ! is_object($registry) || ! method_exists($registry, 'get_all_registered') ) {
            return array();
        }

        $registered = $registry->get_all_registered();
        return is_array($registered) ? $registered : array();
    }

    /**
     * Resolve a block's declared capabilities from WordPress first, then the
     * bundled core metadata snapshot for standalone transforms.
     *
     * @return array{supports: array<string, mixed>, attributes: array<string, mixed>, parent: array<int, string>, allowedBlocks: mixed, dynamic: bool, assets: array<string, mixed>}|null
     */
    public function blockMetadata(string $blockName): ?array
    {
        $blockType = $this->registeredBlockType($blockName);
        if ( is_object($blockType) ) {
            return $this->metadataFromRegisteredBlockType($blockType);
        }

        $this->loadFallbackCoreBlockMetadata();
        return $this->fallbackCoreBlockMetadata[ $blockName ] ?? null;
    }

    private function registeredBlockType(string $blockName): ?object
    {
        foreach ( $this->registeredBlockTypes() as $key => $blockType ) {
            $name = is_string($key) ? $key : '';
            if ( '' === $name && is_object($blockType) && isset($blockType->name) && is_string($blockType->name) ) {
                $name = $blockType->name;
            }
            if ( $blockName === $name && is_object($blockType) ) {
                return $blockType;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>>|null */
    private function blockAttributes(string $blockName): ?array
    {
        $metadata = $this->blockMetadata($blockName);
        return is_array($metadata['attributes'] ?? null) ? $metadata['attributes'] : null;
    }

    /** @return array{supports: array<string, mixed>, attributes: array<string, mixed>, parent: array<int, string>, allowedBlocks: mixed, dynamic: bool, assets: array<string, mixed>} */
    private function metadataFromRegisteredBlockType(object $blockType): array
    {
        $assets = array();
        foreach ( array( 'editor_script_handles' => 'editorScript', 'script_handles' => 'script', 'view_script_handles' => 'viewScript', 'view_script_module_ids' => 'viewScriptModule', 'style_handles' => 'style', 'editor_style_handles' => 'editorStyle', 'view_style_handles' => 'viewStyle' ) as $property => $field ) {
            if ( isset($blockType->{$property}) && is_array($blockType->{$property}) ) {
                $assets[ $field ] = array_values($blockType->{$property});
            }
        }

        return array(
            'supports'      => is_array($blockType->supports ?? null) ? $blockType->supports : array(),
            'attributes'    => is_array($blockType->attributes ?? null) ? $blockType->attributes : array(),
            'parent'        => is_array($blockType->parent ?? null) ? array_values($blockType->parent) : array(),
            'allowedBlocks' => $blockType->allowed_blocks ?? ($blockType->supports['allowedBlocks'] ?? null),
            'dynamic'       => method_exists($blockType, 'is_dynamic') ? $blockType->is_dynamic() : null !== ($blockType->render_callback ?? null),
            'assets'        => $assets,
        );
    }

    private function loadFallbackCoreBlockMetadata(): void
    {
        if ( null !== $this->fallbackCoreBlockMetadata ) return;

        $resourceDirectory = dirname(__DIR__, 2) . '/resources/';
        $supports = $this->snapshotBlocks($resourceDirectory . 'wordpress-latest-core-block-supports.json');
        $attributes = $this->snapshotBlocks($resourceDirectory . 'wordpress-latest-core-block-attributes.json');
        $capabilities = $this->snapshotBlocks($resourceDirectory . 'wordpress-latest-core-block-metadata.json');
        $names = array_unique(array_merge(array_keys($supports), array_keys($attributes), array_keys($capabilities)));
        $this->fallbackCoreBlockMetadata = array();
        foreach ( $names as $name ) {
            $capability = is_array($capabilities[ $name ] ?? null) ? $capabilities[ $name ] : array();
            $this->fallbackCoreBlockMetadata[ $name ] = array(
                'supports'      => is_array($supports[ $name ] ?? null) ? $supports[ $name ] : array(),
                'attributes'    => is_array($attributes[ $name ] ?? null) ? $attributes[ $name ] : array(),
                'parent'        => is_array($capability['parent'] ?? null) ? array_values($capability['parent']) : array(),
                'allowedBlocks' => $capability['allowedBlocks'] ?? ($supports[ $name ]['allowedBlocks'] ?? null),
                'dynamic'       => true === ($capability['dynamic'] ?? false),
                'assets'        => is_array($capability['assets'] ?? null) ? $capability['assets'] : array(),
            );
        }
    }

    /** @return array<string, mixed> */
    private function snapshotBlocks(string $path): array
    {
        $snapshot = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($snapshot['blocks'] ?? null) ? $snapshot['blocks'] : array();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseBlocks(string $content): array
    {
        $this->diagnostics = array();

        if ( $this->canParseBlocks() ) {
            return parse_blocks($content);
        }

        $this->addDiagnostic('wordpress_parse_blocks_unavailable', 'parse_blocks() is unavailable; using the PHP transformer serialized-block fallback.');

        $blocks = $this->parseSerializedBlocks($content);
        if ( null !== $blocks && ( array() !== $blocks || '' === trim($content) ) ) {
            return $blocks;
        }

        return '' === trim($content) ? array() : array(
            array(
                'blockName'    => null,
                'attrs'        => array(),
                'innerBlocks'  => array(),
                'innerHTML'    => $content,
                'innerContent' => array( $content ),
            ),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function serializeBlocks(array $blocks): string
    {
        $this->diagnostics = array();
        $blocks = $this->canonicalRuntimeBlocks($blocks);

        if ( $this->canSerializeBlocks() ) {
            return serialize_blocks($blocks);
        }

        $this->addDiagnostic('wordpress_serialize_blocks_unavailable', 'serialize_blocks() is unavailable; using the PHP transformer serialized-block fallback.');

        $serialized = '';
        foreach ( $blocks as $block ) {
            $serialized .= $this->serializeBlock($block);
        }

        return $serialized;
    }

    /**
     * @param array<string, mixed> $block
     */
    public function renderBlock(array $block): string
    {
        $this->diagnostics = array();
        $block = $this->canonicalRuntimeBlocks(array( $block ))[0];

        if ( $this->canRenderBlock() ) {
            return render_block($block);
        }

        $this->addDiagnostic('wordpress_render_block_unavailable', 'render_block() is unavailable; rendering static block HTML only.');

        return $this->renderStaticBlock($block);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    public function renderBlocks(array $blocks): string
    {
        $this->diagnostics = array();
        $blocks = $this->canonicalRuntimeBlocks($blocks);

        $html = '';
        foreach ( $blocks as $block ) {
            if ( $this->canRenderBlock() ) {
                $html .= render_block($block);
                continue;
            }

            $html .= $this->renderStaticBlock($block);
        }

        if ( ! $this->canRenderBlock() && array() !== $blocks ) {
            $this->addDiagnostic('wordpress_render_block_unavailable', 'render_block() is unavailable; rendering static block HTML only.');
        }

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function canonicalRuntimeBlocks(array $blocks): array
    {
        $canonical = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
            $attributes = $this->blockAttributes($name);
            if ( is_array($attributes) && is_array($block['attrs'] ?? null) ) {
                foreach ( $attributes as $attribute => $schema ) {
                    if ( is_array($schema) && ( array_key_exists('source', $schema) || 'local' === ($schema['role'] ?? null) ) ) {
                        unset($block['attrs'][ $attribute ]);
                    }
                }
            }

            if ( is_array($block['innerBlocks'] ?? null) ) {
                $block['innerBlocks'] = $this->canonicalRuntimeBlocks($block['innerBlocks']);
            }
            $canonical[] = $block;
        }

        return $canonical;
    }

    /**
     * @param string|array<int, array<string, mixed>> $serializedBlocksOrBlocks
     * @return array<string, mixed>
     */
    public function validateBlockSerialization(string|array $serializedBlocksOrBlocks): array
    {
        if ( is_string($serializedBlocksOrBlocks) ) {
            $blocks = $this->parseBlocks($serializedBlocksOrBlocks);
            $report = $this->buildBlockValidityReport($blocks);

            if ( array() === $blocks && str_contains($serializedBlocksOrBlocks, '<!-- wp:') ) {
                $report['status'] = 'warning';
                $report['summary']['finding_count'] = ((int) ($report['summary']['finding_count'] ?? 0)) + 1;
                $report['findings'][] = array(
                    'code'     => 'serialized_blocks_parse_failed',
                    'severity' => 'warning',
                    'category' => 'wp_block_validity',
                    'path'     => 'serialized_blocks',
                    'summary'  => 'Serialized block comments were present but could not be parsed into a balanced block tree.',
                );
            }

            return $report;
        }

        return $this->buildBlockValidityReport($serializedBlocksOrBlocks);
    }

    /**
     * Run the serialization-structure validator and the canonical save()-shape
     * validator over the same parsed block tree and merge their findings into a
     * single wp_block_validity report. Both are pure-PHP and need no WordPress
     * runtime, so the report stays usable in the standalone transformer loop.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function buildBlockValidityReport(array $blocks): array
    {
        $report = ( new BlockValidityValidator() )->validateBlocks($blocks);

        $saveShapeFindings = ( new CanonicalSaveShapeValidator() )->findings($blocks);
        if ( array() !== $saveShapeFindings ) {
            $report['findings'] = array_merge(
                is_array($report['findings'] ?? null) ? $report['findings'] : array(),
                $saveShapeFindings
            );
            $report['summary']['finding_count'] = count($report['findings']);
            $report['status'] = 'warning';
        }

        return $report;
    }

    public function stripAllTags(string $text, bool $removeBreaks = false): string
    {
        $this->diagnostics = array();

        if ( $this->canStripAllTags() ) {
            return wp_strip_all_tags($text, $removeBreaks);
        }

        $this->addDiagnostic('wordpress_strip_all_tags_unavailable', 'wp_strip_all_tags() is unavailable; using the PHP strip_tags() fallback.');

        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text) ?? $text;
        $text = strip_tags($text);

        return $removeBreaks ? preg_replace('/[\r\n\t ]+/', ' ', $text) ?? $text : $text;
    }

    public function containsShortcode(string $text): bool
    {
        return array() !== $this->parseShortcodes($text);
    }

    public function isShortcodeOnly(string $text): bool
    {
        $text = trim($text);
        if ( '' === $text ) {
            return false;
        }

        $shortcodes = $this->parseShortcodes($text);
        if ( 1 !== count($shortcodes) ) {
            return false;
        }

        return $shortcodes[0]['raw'] === $text;
    }

    public function preserveShortcodeText(string $text): string
    {
        return trim($text);
    }

    /**
     * @return array<int, array{name: string, attrs: array<string, mixed>, content: string|null, raw: string}>
     */
    public function parseShortcodes(string $text): array
    {
        if ( ! preg_match_all('/\[([A-Za-z][A-Za-z0-9_-]*)([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(\/)?\](?:(.*?)\[\/\1\])?/s', $text, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        $shortcodes = array();
        foreach ( $matches as $match ) {
            $raw = $match[0];
            if ( str_starts_with($raw, '[[') ) {
                continue;
            }

            $shortcodes[] = array(
                'name'    => $match[1],
                'attrs'   => $this->parseShortcodeAttributes(trim($match[2] ?? '')),
                'content' => array_key_exists(4, $match) && '' !== $match[4] ? $match[4] : null,
                'raw'     => $raw,
            );
        }

        return $shortcodes;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseShortcodeAttributes(string $text): array
    {
        if ( '' === $text ) {
            return array();
        }

        if ( $this->canParseShortcodeAttributes() ) {
            $attrs = shortcode_parse_atts($text);
            return is_array($attrs) ? $attrs : array();
        }

        $attrs = array();
        if ( preg_match_all('/([A-Za-z0-9_-]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s]+))|"([^"]*)"|\'([^\']*)\'|(\S+)/', $text, $matches, PREG_SET_ORDER) ) {
            foreach ( $matches as $match ) {
                if ( '' !== ($match[1] ?? '') ) {
                    $attrs[$match[1]] = $match[3] ?? $match[4] ?? $match[5] ?? '';
                    continue;
                }

                $attrs[] = $match[6] ?? $match[7] ?? $match[8] ?? '';
            }
        }

        return $attrs;
    }

    /**
     * @param mixed $data
     */
    public function encodeJson(mixed $data, int $flags = 0): string
    {
        $flags |= JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ( $this->canEncodeJson() ) {
            $json = wp_json_encode($data, $flags);
        } else {
            $json = json_encode($data, $flags);
        }

        return false === $json ? '' : $json;
    }

    public function escapeHtml(string $text): string
    {
        return $this->canEscapeHtml() ? esc_html($text) : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function escapeAttribute(string $text): string
    {
        return $this->canEscapeAttribute() ? esc_attr($text) : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Serialize a single block to canonical comment-delimited block markup, the
     * way WordPress core's serialize_block()/get_comment_delimited_block_content()
     * do. The inner content is built by {@see serializeInnerContent()}, which walks
     * innerContent and emits each nested block as block MARKUP (not rendered static
     * HTML). This keeps dynamic/nested blocks — core/navigation and its
     * navigation-link/submenu children, and any nested block — present in the
     * serialized string instead of collapsing them to a self-closing comment or
     * dropping them entirely.
     *
     * @param array<string, mixed> $block
     */
    private function serializeBlock(array $block): string
    {
        $blockContent = $this->serializeInnerContent($block);

        $blockName = isset($block['blockName']) ? (string) $block['blockName'] : '';
        if ( '' === $blockName ) {
            return $blockContent;
        }

        $name  = str_starts_with($blockName, 'core/') ? substr($blockName, 5) : $blockName;
        $attrs = empty($block['attrs']) || ! is_array($block['attrs']) ? '' : ' ' . $this->serializeBlockAttributes($block['attrs']);

        if ( '' === $blockContent ) {
            return '<!-- wp:' . $name . $attrs . ' /-->';
        }

        return '<!-- wp:' . $name . $attrs . ' -->' . $blockContent . '<!-- /wp:' . $name . ' -->';
    }

    /**
     * Serialize block attributes for the comment delimiter the way WordPress
     * core's serialize_block_attributes() does: JSON-encode, then escape the
     * characters that could otherwise break out of the surrounding HTML comment
     * (`\\`, `--`, `<`, `>`, `&`) plus escaped quotes. This keeps the delimiter
     * comment-safe and WP-canonical even when an attribute value embeds raw HTML
     * (e.g. a core/paragraph `content` carrying an inline `<a>`), so the comment
     * stays a single parseable token. The codebase's unescaped-slash/unicode JSON
     * convention is preserved via encodeJson().
     *
     * @param array<string, mixed> $attrs
     */
    private function serializeBlockAttributes(array $attrs): string
    {
        return strtr(
            $this->encodeJson($attrs),
            array(
                '\\\\' => '\\u005c',
                '--'   => '\\u002d\\u002d',
                '<'    => '\\u003c',
                '>'    => '\\u003e',
                '&'    => '\\u0026',
                '\\"'  => '\\u0022',
            )
        );
    }

    /**
     * Build a block's inner serialized content. Mirrors WordPress core's
     * serialize_block() inner loop: walk innerContent, append literal string
     * chunks verbatim, and replace each null placeholder with the recursively
     * serialized markup of the next inner block. When innerContent is not a
     * structured array, fall back to serializing any inner blocks as markup
     * followed by the saved innerHTML so no nested block is silently dropped.
     *
     * @param array<string, mixed> $block
     */
    private function serializeInnerContent(array $block): string
    {
        $innerBlocks  = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? array_values($block['innerBlocks']) : array();
        $innerContent = $block['innerContent'] ?? null;

        if ( ! is_array($innerContent) ) {
            $serialized = '';
            foreach ( $innerBlocks as $innerBlock ) {
                if ( is_array($innerBlock) ) {
                    $serialized .= $this->serializeBlock($innerBlock);
                }
            }

            return $serialized . (isset($block['innerHTML']) ? (string) $block['innerHTML'] : '');
        }

        $serialized = '';
        $blockIndex = 0;
        foreach ( $innerContent as $part ) {
            if ( null === $part ) {
                $innerBlock  = $innerBlocks[$blockIndex] ?? null;
                $serialized .= is_array($innerBlock) ? $this->serializeBlock($innerBlock) : '';
                ++$blockIndex;
                continue;
            }

            $serialized .= (string) $part;
        }

        return $serialized;
    }

    /**
     * Parse only the canonical comment delimiters this transformer emits. This
     * is deliberately not a replacement for WP_Block_Parser: WordPress returns
     * a best-effort tree for malformed input, while this fallback preserves any
     * malformed or unsupported document as one freeform block.
     *
     * @return array<int, array<string, mixed>>|null Null when the document is outside this bounded contract.
     */
    private function parseSerializedBlocks(string $content): ?array
    {
        $blocks = array();
        $stack  = array();
        $cursor = 0;
        $search = 0;
        $found  = false;

        while ( false !== ( $offset = strpos($content, '<!--', $search) ) ) {
            $end = strpos($content, '-->', $offset + 4);
            if ( false === $end ) {
                if ( preg_match('/^<!--\s*\/?wp:/', substr($content, $offset)) ) {
                    return null;
                }
                $this->appendStandaloneFreeform($blocks, $content, $cursor);
                return $blocks;
            }

            $raw       = substr($content, $offset, $end + 3 - $offset);
            $delimiter = $this->parseStandaloneBlockDelimiter($raw);
            if ( false === $delimiter ) {
                return null;
            }
            if ( null === $delimiter ) {
                $search = $end + 3;
                continue;
            }

            $found   = true;
            $between = substr($content, $cursor, $offset - $cursor);
            if ( '' !== $between && array() === $stack ) {
                $this->appendStandaloneFreeform($blocks, $between);
            } elseif ( '' !== $between ) {
                $stack[array_key_last($stack)]['innerContent'][] = $between;
            }

            $isClose = $delimiter['close'];
            $name    = $delimiter['name'];
            $attrs   = $delimiter['attrs'];
            $isVoid  = $delimiter['void'];

            if ( $isClose ) {
                $frame = array_pop($stack);
                if ( ! is_array($frame) || $frame['name'] !== $name ) {
                    return array();
                }

                $block = $this->createParsedBlock($name, $frame['attrs'], $frame['innerBlocks'], $frame['innerContent']);
                $this->appendParsedBlock($blocks, $stack, $block);
            } elseif ( $isVoid ) {
                $this->appendParsedBlock($blocks, $stack, $this->createParsedBlock($name, $attrs, array(), array()));
            } else {
                $stack[] = array(
                    'name'         => $name,
                    'attrs'        => $attrs,
                    'innerBlocks'  => array(),
                    'innerContent' => array(),
                );
            }

            $cursor = $end + 3;
            $search = $cursor;
        }

        if ( ! $found || array() !== $stack ) {
            return null;
        }

        $this->appendStandaloneFreeform($blocks, substr($content, $cursor));
        return $blocks;
    }

    /**
     * @return array{close: bool, name: string, attrs: array<string, mixed>, void: bool}|false|null
     */
    private function parseStandaloneBlockDelimiter(string $comment): array|false|null
    {
        if ( ! preg_match('/^<!--\s+.*(?:\s+|\/)-->$/s', $comment) ) {
            return false;
        }

        $body = trim(substr($comment, 4, -3));
        if ( ! str_starts_with($body, 'wp:') && ! str_starts_with($body, '/wp:') ) {
            return null;
        }

        if ( preg_match('/^\/wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)$/', $body, $match) ) {
            return array( 'close' => true, 'name' => $match[1], 'attrs' => array(), 'void' => false );
        }

        if ( ! preg_match('/^wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)(?:\s+(.+?))?$/s', $body, $match) ) {
            return false;
        }

        $payload = trim($match[2] ?? '');
        $isVoid  = str_ends_with($payload, '/');
        if ( $isVoid ) {
            $payload = trim(substr($payload, 0, -1));
        }

        if ( '' === $payload ) {
            return array( 'close' => false, 'name' => $match[1], 'attrs' => array(), 'void' => $isVoid );
        }

        if ( ! str_starts_with($payload, '{') || ! str_ends_with($payload, '}') ) {
            return false;
        }

        $attrs = json_decode($payload, true);
        if ( ! is_array($attrs) ) {
            return false;
        }

        return array( 'close' => false, 'name' => $match[1], 'attrs' => $attrs, 'void' => $isVoid );
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function appendStandaloneFreeform(array &$blocks, string $html): void
    {
        if ( '' !== $html ) {
            $blocks[] = array(
                'blockName'    => null,
                'attrs'        => array(),
                'innerBlocks'  => array(),
                'innerHTML'    => $html,
                'innerContent' => array( $html ),
            );
        }
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @param array<int, string|null> $innerContent
     * @return array<string, mixed>
     */
    private function createParsedBlock(string $name, array $attrs, array $innerBlocks, array $innerContent): array
    {
        $innerHTML = '';
        foreach ( $innerContent as $part ) {
            if ( null !== $part ) {
                $innerHTML .= $part;
            }
        }

        return array(
            'blockName'    => str_contains($name, '/') ? $name : 'core/' . $name,
            'attrs'        => $attrs,
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => $innerHTML,
            'innerContent' => $innerContent,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $stack
     * @param array<string, mixed> $block
     */
    private function appendParsedBlock(array &$blocks, array &$stack, array $block): void
    {
        if ( array() === $stack ) {
            $blocks[] = $block;
            return;
        }

        $key = array_key_last($stack);
        $stack[$key]['innerBlocks'][]  = $block;
        $stack[$key]['innerContent'][] = null;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function renderStaticBlock(array $block): string
    {
        $innerContent = $block['innerContent'] ?? null;
        $innerBlocks  = $block['innerBlocks'] ?? array();

        if ( is_array($innerContent) ) {
            $html       = '';
            $blockIndex = 0;
            foreach ( $innerContent as $part ) {
                if ( null === $part ) {
                    $innerBlock = is_array($innerBlocks) && isset($innerBlocks[$blockIndex]) && is_array($innerBlocks[$blockIndex]) ? $innerBlocks[$blockIndex] : null;
                    $html      .= null === $innerBlock ? '' : $this->renderStaticBlock($innerBlock);
                    ++$blockIndex;
                    continue;
                }

                $html .= (string) $part;
            }

            return $html;
        }

        return isset($block['innerHTML']) ? (string) $block['innerHTML'] : '';
    }

    private function addDiagnostic(string $code, string $message): void
    {
        $this->diagnostics[] = array(
            'code'    => $code,
            'message' => $message,
            'source'  => self::class,
        );
    }
}
