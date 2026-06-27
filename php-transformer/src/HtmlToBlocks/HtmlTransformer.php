<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\Contract\ConversionReportProjection;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformationOptions;
use Automattic\BlocksEngine\PhpTransformer\Contract\TransformerResult;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\LogoPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlTransformer
{
    private const MAX_INTERACTION_CANDIDATES = 100;

    private readonly BlockFactory $blockFactory;

    private readonly ButtonsPattern $buttonsPattern;

    private readonly DetailsPattern $detailsPattern;

    private readonly LogoPattern $logoPattern;

    private readonly PatternRecognizerRegistry $patternRecognizers;

    /**
     * @var array<string, string>
     */
    private array $fallbackProvenance = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $presentationProvenance = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $sourceProvenance = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $structureProvenance = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $scriptMetadata = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $runtimeIslands = array();

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $runtimeScriptMetadata = array();

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $assetMetadata = array();

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $generatedAssets = array();

    /**
     * @var array<string, array<int, string>>
     */
    private array $staticClassPromotions = array();

    /**
     * @var array<int, array{selector: string, declarations: array<string, string>}>
     */
    private array $staticStyleRules = array();

    /**
     * @var array<string, bool>
     */
    private array $runtimeDomSelectors = array();

    /**
     * @var array<string, bool>
     */
    private array $runtimeCanvasSelectors = array();

    private int $nextSourceProvenanceId = 1;

    public function __construct(private readonly Runtime $runtime = new Runtime())
    {
        $this->blockFactory      = new BlockFactory();
        $this->buttonsPattern    = new ButtonsPattern();
        $this->detailsPattern    = new DetailsPattern();
        $this->logoPattern       = new LogoPattern();
        $this->patternRecognizers = new PatternRecognizerRegistry(array(
            new NavigationPattern(),
        ));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function transform(string $html, array $options = array()): TransformerResult
    {
        $context                  = TransformationOptions::context($options);
        $startedAt                = hrtime(true);
        $this->fallbackProvenance = TransformationOptions::provenance($options);
        $this->presentationProvenance = array();
        $this->sourceProvenance = array();
        $this->structureProvenance = array();
        $this->scriptMetadata = array();
        $this->runtimeIslands = array();
        $this->runtimeScriptMetadata = $this->runtimeScriptMetadataFromOptions($options);
        $this->assetMetadata = $this->assetMetadataFromOptions($options);
        $this->generatedAssets = array();
        $this->staticClassPromotions = $this->detectStaticClassPromotions($html);
        $this->staticStyleRules = $this->staticStyleRules($html, (string) ($options['static_css'] ?? ''));
        $this->runtimeDomSelectors = $this->runtimeSelectorsFromOptions($options, 'runtime_dom_selectors');
        $this->runtimeCanvasSelectors = $this->runtimeCanvasSelectorsFromOptions($options);
        $this->nextSourceProvenanceId = 1;
        $provenance               = array(
            array_merge(array(
                'source_format' => 'html',
                'input_bytes'   => strlen($html),
                'transformer'   => self::class,
            ), $this->fallbackProvenance),
        );

        $normalizedHtml = $this->normalizeHtml5VoidElements($this->documentBodyHtml($html));
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?><body>' . $normalizedHtml . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            $diagnostics = array(
                array(
                    'code'    => 'html_parse_failed',
                    'message' => 'Unable to parse HTML input.',
                    'source'  => self::class,
                ),
            );
            $fallbacks = array(
                FallbackDiagnostic::build(array(
                    'type'            => 'html',
                    'reason'          => 'parse_failed',
                    'diagnostic_code' => 'html_parse_failed',
                    'source_format'   => 'html',
                    'html'            => $html,
                ), $this->fallbackProvenance),
            );

            $metrics = $this->metrics($html, array(), '', $fallbacks, $diagnostics, $startedAt);
            $sourceReports = array(
                'conversion_report' => ConversionReportProjection::fromResultParts('html', array(), $fallbacks, array(), array(), $provenance, $metrics),
            );

            return new TransformerResult(
                diagnostics: $diagnostics,
                sourceReports: $sourceReports,
                fallbacks: $fallbacks,
                provenance: $provenance,
                context: $context,
                metrics: $metrics
            );
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            $metrics = $this->metrics($html, array(), '', array(), array(), $startedAt);
            $sourceReports = array(
                'conversion_report' => ConversionReportProjection::fromResultParts('html', array(), array(), array(), array(), $provenance, $metrics),
            );

            return new TransformerResult(
                sourceReports: $sourceReports,
                provenance: $provenance,
                context: $context,
                metrics: $metrics
            );
        }

        $fallbacks   = array();
        $interactionCandidates = $this->interactionCandidates($body);
        $blocks      = $this->deduplicateNavigationBlocks($this->convertChildren($body, $fallbacks, true));
        $sourceProvenance = $this->sourceProvenanceForBlocks($blocks);
        $serializedBlocks = $this->runtime->serializeBlocks($blocks);
        $blockValidityReport = $this->runtime->validateBlockSerialization($blocks);
        $semanticParityReport = $this->semanticParityReport($body, $blocks, $sourceProvenance, $html, (string) ($options['static_css'] ?? ''));
        $diagnostics = array(
            array(
                'code'    => 'html_to_blocks_core_slice',
                'message' => 'Converted supported core text, layout, media, gallery, embed, file, table, button, shortcode, spacer, definition-list, details, navigation, safe inline SVG images, and wrapper elements; unsupported elements are reported as fallbacks.',
                'source'  => self::class,
            ),
        );

        foreach ( $this->scriptMetadata as $metadata ) {
            $diagnostics[] = array(
                'code'        => 'html_static_script_metadata',
                'message'     => 'Static script data was preserved as bounded metadata and does not require client script execution.',
                'source'      => self::class,
                'reason'      => 'script_static_metadata',
                'tag'         => 'script',
                'selector'    => $metadata['selector'] ?? null,
                'script_role' => $metadata['script_role'] ?? null,
            );
        }

        foreach ( $fallbacks as $fallback ) {
            if ( ! empty($fallback['diagnostic_code']) ) {
                $diagnostics[] = array(
                    'code'                => $fallback['diagnostic_code'],
                    'message'             => $fallback['message'] ?? 'HTML element preserved as fallback metadata.',
                    'source'              => self::class,
                    'reason'              => $fallback['reason'] ?? null,
                    'severity'            => $fallback['severity'] ?? null,
                    'conversion_classification' => $fallback['conversion_classification'] ?? null,
                    'preservation_strategy' => $fallback['preservation_strategy'] ?? null,
                    'runtime_requirement'             => $fallback['runtime_requirement'] ?? null,
                    'tag'                             => $fallback['tag'] ?? null,
                    'selector'                        => $fallback['selector'] ?? null,
                    'pattern_family'                  => $fallback['pattern_family'] ?? null,
                    'pattern_family_detail'           => $fallback['pattern_family_detail'] ?? null,
                    'source_selector'                 => $fallback['source_selector'] ?? null,
                    'source_selector_specificity'     => $fallback['source_selector_specificity'] ?? null,
                    'parent_reason'                   => $fallback['parent_reason'] ?? null,
                    'ancestor_reason'                 => $fallback['ancestor_reason'] ?? null,
                    'suggested_generic_repair_class' => $fallback['suggested_generic_repair_class'] ?? null,
                );
            }
        }

        foreach ( $this->runtimeIslands as $island ) {
            $diagnostics[] = array_filter(array(
                'code'                => 'preserved_runtime_island',
                'message'             => 'Runtime-dependent source markup was preserved as a bounded runtime island.',
                'source'              => self::class,
                'severity'            => 'info',
                'conversion_classification' => 'runtime_island_preserved',
                'loss_class'          => 'runtime_island_preserved',
                'diagnostic_class'    => 'runtime_island_preserved',
                'suggested_repair_class' => 'preserve_runtime_island',
                'preservation_strategy' => $island['preservation_strategy'] ?? 'bounded_raw_html_runtime_island',
                'runtime_requirement' => $island['runtime_requirement'] ?? null,
                'kind'                => $island['kind'] ?? null,
                'reason'              => $island['preservation_reason'] ?? null,
                'tag'                 => $island['tag'] ?? null,
                'selector'            => $island['selector'] ?? null,
                'pattern_family'                  => $island['pattern_family'] ?? null,
                'pattern_family_detail'           => $island['pattern_family_detail'] ?? null,
                'source_selector'                 => $island['source_selector'] ?? null,
                'source_selector_specificity'     => $island['source_selector_specificity'] ?? null,
                'parent_reason'                   => $island['parent_reason'] ?? null,
                'ancestor_reason'                 => $island['ancestor_reason'] ?? null,
                'suggested_generic_repair_class' => $island['suggested_generic_repair_class'] ?? null,
            ), static fn (mixed $value): bool => null !== $value && '' !== $value);
        }

        foreach ( $blockValidityReport['findings'] ?? array() as $finding ) {
            if ( ! is_array($finding) ) {
                continue;
            }

            $diagnostics[] = array(
                'code'       => 'wp_block_validity_' . (string) ($finding['code'] ?? 'warning'),
                'message'    => (string) ($finding['summary'] ?? 'Generated block serialization may trigger WordPress block invalidity warnings.'),
                'source'     => Runtime::class,
                'severity'   => $finding['severity'] ?? 'warning',
                'block_name' => $finding['block_name'] ?? null,
                'path'       => $finding['path'] ?? null,
            );
        }

        foreach ( $semanticParityReport['findings'] ?? array() as $finding ) {
            if ( ! is_array($finding) ) {
                continue;
            }

            $diagnostics[] = array(
                'code'     => 'html_semantic_parity_' . (string) ($finding['code'] ?? 'warning'),
                'message'  => (string) ($finding['summary'] ?? 'Generated blocks differ from source semantic structure.'),
                'source'   => self::class,
                'severity' => $finding['severity'] ?? 'warning',
                'selector' => $finding['selector'] ?? null,
            );
        }

        $metrics = $this->metrics($html, $blocks, $serializedBlocks, $fallbacks, $diagnostics, $startedAt);
        $sourceReports = array(
            'runtime_islands' => $this->runtimeIslands,
            'interaction_candidates' => $interactionCandidates,
            'wp_block_validity' => $blockValidityReport,
            'semantic_parity' => $semanticParityReport,
            'html' => array(
                'presentation_signals' => $this->presentationProvenance,
                'source_provenance'    => $sourceProvenance,
                'structure_signals'    => $this->structureProvenance,
                'script_metadata'      => $this->scriptMetadata,
                'runtime_islands'      => $this->runtimeIslands,
            ),
        );
        $sourceReports['conversion_report'] = ConversionReportProjection::fromResultParts('html', $blocks, $fallbacks, $sourceReports, array(), $provenance, $metrics);

        return new TransformerResult(
            status: $this->statusForFallbacks($fallbacks, $context),
            blocks: $blocks,
            serializedBlocks: $serializedBlocks,
            assets: array_values($this->generatedAssets),
            diagnostics: $diagnostics,
            fallbacks: $fallbacks,
            provenance: $provenance,
            sourceReports: $sourceReports,
            coverage: array(
                array(
                    'supported_blocks' => array( 'core/audio', 'core/button', 'core/buttons', 'core/code', 'core/column', 'core/columns', 'core/details', 'core/embed', 'core/file', 'core/gallery', 'core/group', 'core/heading', 'core/image', 'core/list', 'core/list-item', 'core/navigation', 'core/navigation-link', 'core/paragraph', 'core/preformatted', 'core/pullquote', 'core/quote', 'core/separator', 'core/shortcode', 'core/spacer', 'core/navigation-submenu', 'core/table', 'core/video', 'core/search' ),
                    'block_count'      => count($blocks),
                    'fallback_count'   => count($fallbacks),
                    'source_provenance_count' => count($sourceProvenance),
                ),
            ),
            context: $context,
            metrics: $metrics
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, array<string, mixed>> $diagnostics
     * @return array<string, int|float>
     */
    private function metrics(string $input, array $blocks, string $output, array $fallbacks, array $diagnostics, int $startedAt): array
    {
        return array(
            'input_bytes'           => strlen($input),
            'block_count'           => $this->countBlocks($blocks),
            'fallback_count'        => count($fallbacks),
            'diagnostic_count'      => count($diagnostics),
            'transform_duration_ms' => (hrtime(true) - $startedAt) / 1000000,
            'output_bytes'          => strlen($output),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function countBlocks(array $blocks): int
    {
        $count = 0;

        foreach ( $blocks as $block ) {
            ++$count;
            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $count += $this->countBlocks($block['innerBlocks']);
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateNavigationBlocks(array $blocks): array
    {
        $seen = array();
        return $this->deduplicateNavigationBlocksRecursive($blocks, $seen);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<string, mixed>
     */
    private function semanticParityReport(DOMElement $body, array $blocks, array $sourceProvenance, string $html = '', string $staticCss = ''): array
    {
        $sourceLandmarks = $this->sourceLandmarkReport($body);
        $blockLandmarks = $this->blockLandmarkReport($blocks, $sourceProvenance, $sourceLandmarks);
        $sourceMenus = $this->sourceNavigationMenus($body);
        $blockMenus = $this->blockNavigationMenus($blocks);
        $findings = $this->semanticParityFindings($sourceLandmarks, $blockLandmarks, $sourceMenus, $blockMenus);
        $findings = array_merge(
            $findings,
            ( new TypographyParityAnalyzer() )->findings($html, $staticCss, $this->inlineHeadingFontDeclarations($body))
        );

        $findings = array_map(
            fn (array $finding): array => $this->enrichSemanticParityFinding($finding, $body, $sourceProvenance),
            $findings
        );

        return array(
            'schema' => 'blocks-engine/php-transformer/semantic-parity/v1',
            'status' => array() === $findings ? 'pass' : 'warning',
            'landmarks' => array(
                'source' => $sourceLandmarks['counts'],
                'blocks' => $blockLandmarks['counts'],
            ),
            'navigation_menus' => array(
                'source' => $sourceMenus,
                'blocks' => $blockMenus,
            ),
            'findings' => $findings,
        );
    }

    /**
     * @return array{counts: array<string, int>, selectors: array<string, array<int, string>>}
     */
    private function sourceLandmarkReport(DOMElement $body): array
    {
        $counts = array('header' => 0, 'nav' => 0, 'main' => 0, 'footer' => 0);
        $selectors = array('header' => array(), 'nav' => array(), 'main' => array(), 'footer' => array());
        $seenNavigation = array();
        $this->collectSourceLandmarks($body, $counts, $selectors, $seenNavigation);

        return array('counts' => $counts, 'selectors' => $selectors);
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, array<int, string>> $selectors
     * @param array<string, bool> $seenNavigation
     */
    private function collectSourceLandmarks(DOMElement $element, array &$counts, array &$selectors, array &$seenNavigation): void
    {
        $landmark = $this->landmarkKindForElement($element);
        if ( '' !== $landmark ) {
            if ( 'nav' === $landmark ) {
                $signature = $this->sourceNavigationMenuSignature($this->sourceNavigationMenuItems($element));
                if ( '' !== $signature && isset($seenNavigation[$signature]) && $this->isMobileDuplicateSourceNavigation($element) ) {
                    return;
                }

                if ( '' !== $signature ) {
                    $seenNavigation[$signature] = true;
                }
            }

            ++$counts[$landmark];
            $selectors[$landmark][] = $this->elementSelector($element);
        }

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $this->collectSourceLandmarks($child, $counts, $selectors, $seenNavigation);
            }
        }
    }

    private function landmarkKindForElement(DOMElement $element): string
    {
        $tagName = strtolower($element->tagName);
        if ( 'footer' === $tagName && $this->hasAncestorTag($element, array( 'blockquote', 'figure' )) ) {
            return '';
        }

        if ( in_array($tagName, array( 'header', 'nav', 'main', 'footer' ), true) ) {
            return 'nav' === $tagName ? 'nav' : $tagName;
        }

        return match ( strtolower($this->attr($element, 'role')) ) {
            'banner' => 'header',
            'navigation' => 'nav',
            'main' => 'main',
            'contentinfo' => 'footer',
            default => '',
        };
    }

    /**
     * @param array<int, string> $tagNames
     */
    private function hasAncestorTag(DOMElement $element, array $tagNames): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement && 'body' !== strtolower($node->tagName); $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), $tagNames, true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @param array{counts: array<string, int>, selectors: array<string, array<int, string>>} $sourceLandmarks
     * @return array{counts: array<string, int>, selectors: array<string, array<int, string>>}
     */
    private function blockLandmarkReport(array $blocks, array $sourceProvenance, array $sourceLandmarks): array
    {
        $counts = array('header' => 0, 'nav' => 0, 'main' => 0, 'footer' => 0);
        $selectors = array('header' => array(), 'nav' => array(), 'main' => array(), 'footer' => array());
        $this->collectBlockNavigationLandmarks($blocks, $counts);

        foreach ( array( 'header', 'main', 'footer' ) as $kind ) {
            foreach ( $sourceLandmarks['selectors'][$kind] ?? array() as $sourceSelector ) {
                if ( $this->sourceSelectorHasBlockRepresentation((string) $sourceSelector, $sourceProvenance) ) {
                    $selectors[$kind][] = (string) $sourceSelector;
                }
            }
            $counts[$kind] = count($selectors[$kind]);
        }

        return array('counts' => $counts, 'selectors' => $selectors);
    }

    /**
     * @param array<int, array<string, mixed>> $sourceProvenance
     */
    private function sourceSelectorHasBlockRepresentation(string $sourceSelector, array $sourceProvenance): bool
    {
        if ( '' === $sourceSelector ) {
            return false;
        }

        foreach ( $sourceProvenance as $entry ) {
            if ( ! is_array($entry) ) {
                continue;
            }

            $blockSelector = (string) ($entry['selector'] ?? '');
            if ( $sourceSelector === $blockSelector || str_starts_with($blockSelector, $sourceSelector . ' > ') ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, int> $counts
     */
    private function collectBlockNavigationLandmarks(array $blocks, array &$counts): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( 'core/navigation' === ($block['blockName'] ?? '') ) {
                ++$counts['nav'];
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->collectBlockNavigationLandmarks($block['innerBlocks'], $counts);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sourceNavigationMenus(DOMElement $body): array
    {
        $menus = array();
        $seen = array();
        $this->collectSourceNavigationMenus($body, $menus, $seen);
        return $menus;
    }

    /**
     * @param array<int, array<string, mixed>> $menus
     * @param array<string, bool> $seen
     */
    private function collectSourceNavigationMenus(DOMElement $element, array &$menus, array &$seen): void
    {
        if ( 'nav' === strtolower($element->tagName) || 'navigation' === strtolower($this->attr($element, 'role')) ) {
            $items = $this->sourceNavigationMenuItems($element);

            $signature = $this->sourceNavigationMenuSignature($items);
            if ( '' !== $signature && isset($seen[$signature]) && $this->isMobileDuplicateSourceNavigation($element) ) {
                return;
            }

            if ( '' !== $signature ) {
                $seen[$signature] = true;
            }

            $menus[] = array(
                'selector' => $this->elementSelector($element),
                'item_count' => count($items),
                'items' => $items,
            );
        }

        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $this->collectSourceNavigationMenus($child, $menus, $seen);
            }
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function sourceNavigationMenuItems(DOMElement $element): array
    {
        if ( $this->hasSourceNavigationChrome($element) ) {
            return $this->sourceNavigationMenuItemsFromSignaledContainers($element);
        }

        $items = array();
        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( ! $anchor instanceof DOMElement || $this->isSourceNavigationChromeAnchor($anchor) ) {
                continue;
            }

            $label = $this->sourceNavigationAnchorLabel($anchor);
            if ( '' !== $label ) {
                $items[] = array(
                    'label' => $label,
                    'url' => $this->safeNavigationUrl($this->attr($anchor, 'href')),
                );
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function sourceNavigationMenuItemsFromSignaledContainers(DOMElement $element): array
    {
        $items = array();
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'ul', 'ol' ), true) && $this->hasSourceNavigationSignal($child) ) {
                foreach ( $this->sourceNavigationMenuItems($child) as $item ) {
                    $items[] = $item;
                }
                continue;
            }

            if ( in_array($tagName, array( 'div', 'span', 'section' ), true) ) {
                foreach ( $this->sourceNavigationMenuItemsFromSignaledContainers($child) as $item ) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function hasSourceNavigationChrome(DOMElement $element): bool
    {
        $hasToggle = false;
        foreach ( $element->getElementsByTagName('button') as $button ) {
            if ( $button instanceof DOMElement && $this->isSourceMenuToggleControl($button) ) {
                $hasToggle = true;
                break;
            }
        }

        if ( ! $hasToggle ) {
            return false;
        }

        $hasSignaledList = false;
        foreach ( $element->getElementsByTagName('ul') as $list ) {
            if ( $list instanceof DOMElement && $this->hasSourceNavigationSignal($list) ) {
                $hasSignaledList = true;
                break;
            }
        }

        if ( ! $hasSignaledList ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('a') as $anchor ) {
            if ( $anchor instanceof DOMElement && ! $this->hasAncestorTagWithin($anchor, array( 'ul', 'ol' ), $element) ) {
                return true;
            }
        }

        return false;
    }

    private function isSourceMenuToggleControl(DOMElement $element): bool
    {
        if ( 'button' !== strtolower($element->tagName) ) {
            return false;
        }

        if ( $element->hasAttribute('aria-controls') || $element->hasAttribute('aria-expanded') ) {
            return true;
        }

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:hamburger|menu|toggle)(?:[^a-z0-9]|$)/', strtolower($this->attr($element, 'class') . ' ' . $this->attr($element, 'aria-label')));
    }

    private function hasSourceNavigationSignal(DOMElement $element): bool
    {
        if ( 'navigation' === strtolower($this->attr($element, 'role')) ) {
            return true;
        }

        foreach ( array( 'class', 'id' ) as $attribute ) {
            foreach ( preg_split('/[^a-z0-9]+/', strtolower($this->attr($element, $attribute))) ?: array() as $token ) {
                if ( in_array($token, array( 'nav', 'navbar', 'navigation', 'menu', 'links' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $tagNames
     */
    private function hasAncestorTagWithin(DOMElement $element, array $tagNames, DOMElement $boundary): bool
    {
        for ( $node = $element->parentNode; $node instanceof DOMElement && ! $node->isSameNode($boundary); $node = $node->parentNode ) {
            if ( in_array(strtolower($node->tagName), $tagNames, true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, string>> $items
     */
    private function sourceNavigationMenuSignature(array $items): string
    {
        $links = array();
        foreach ( $items as $item ) {
            $links[] = trim((string) ($item['label'] ?? '')) . '>' . trim((string) ($item['url'] ?? ''));
        }

        return implode('|', $links);
    }

    private function isMobileDuplicateSourceNavigation(DOMElement $element): bool
    {
        $tokens = array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
        );

        for ( $parent = $element->parentNode; $parent instanceof DOMElement && 'body' !== strtolower($parent->tagName); $parent = $parent->parentNode ) {
            $tokens[] = $this->attr($parent, 'class');
            $tokens[] = $this->attr($parent, 'id');
        }

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:mobile|drawer|offcanvas|overlay|hamburger|menu-panel|nav-panel)(?:[^a-z0-9]|$)/', strtolower(implode(' ', $tokens)));
    }

    private function isSourceNavigationChromeAnchor(DOMElement $anchor): bool
    {
        if ( in_array(strtolower($this->attr($anchor, 'role')), array( 'separator', 'presentation', 'none' ), true) ) {
            return true;
        }

        if ( '' === trim($anchor->textContent ?? '') && '' === trim($this->attr($anchor, 'aria-label') . $this->attr($anchor, 'title')) ) {
            return true;
        }

        $tokens = strtolower($this->attr($anchor, 'class') . ' ' . $this->attr($anchor, 'id'));
        return (bool) preg_match('/(?:^|[^a-z0-9])(?:separator|divider)(?:[^a-z0-9]|$)/', $tokens);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function blockNavigationMenus(array $blocks): array
    {
        $menus = array();
        $this->collectBlockNavigationMenus($blocks, 'blocks', $menus);
        return $menus;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $menus
     */
    private function collectBlockNavigationMenus(array $blocks, string $path, array &$menus): void
    {
        foreach ( $blocks as $index => $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            $blockPath = $path . '.' . $index;
            if ( 'core/navigation' === ($block['blockName'] ?? '') ) {
                $items = array();
                $this->collectBlockNavigationItems(is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array(), $items);
                $menus[] = array(
                    'block_path' => $blockPath,
                    'represented_as_core_navigation' => true,
                    'item_count' => count($items),
                    'items' => $items,
                );
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->collectBlockNavigationMenus($block['innerBlocks'], $blockPath . '.innerBlocks', $menus);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, string>> $items
     */
    private function collectBlockNavigationItems(array $blocks, array &$items): void
    {
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( in_array($block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
                $items[] = array(
                    'label' => $this->normalizedNavigationLabel((string) ($attrs['label'] ?? '')),
                    'url' => (string) ($attrs['url'] ?? ''),
                );
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->collectBlockNavigationItems($block['innerBlocks'], $items);
            }
        }
    }

    private function normalizedNavigationLabel(string $label): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode($this->runtime->stripAllTags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $label);
    }

    private function sourceNavigationAnchorLabel(DOMElement $anchor): string
    {
        $label = $this->normalizedNavigationLabel($anchor->textContent ?? '');
        if ( '' !== $label ) {
            return $label;
        }

        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $label = $this->normalizedNavigationLabel($this->attr($anchor, $attribute));
            if ( '' !== $label ) {
                return $label;
            }
        }

        $image = $anchor->getElementsByTagName('img')->item(0);
        if ( $image instanceof DOMElement ) {
            return $this->normalizedNavigationLabel($this->attr($image, 'alt'));
        }

        return '';
    }

    /**
     * @param array{counts: array<string, int>, selectors: array<string, array<int, string>>} $sourceLandmarks
     * @param array{counts: array<string, int>, selectors: array<string, array<int, string>>} $blockLandmarks
     * @param array<int, array<string, mixed>> $sourceMenus
     * @param array<int, array<string, mixed>> $blockMenus
     * @return array<int, array<string, mixed>>
     */
    private function semanticParityFindings(array $sourceLandmarks, array $blockLandmarks, array $sourceMenus, array $blockMenus): array
    {
        $findings = array();
        foreach ( array( 'header', 'nav', 'main', 'footer' ) as $kind ) {
            $sourceCount = (int) ($sourceLandmarks['counts'][$kind] ?? 0);
            $blockCount = (int) ($blockLandmarks['counts'][$kind] ?? 0);
            if ( $sourceCount > $blockCount ) {
                $findings[] = array_filter(array(
                    'code' => 'landmark_count_mismatch',
                    'severity' => 'warning',
                    'kind' => $kind,
                    'source_count' => $sourceCount,
                    'block_count' => $blockCount,
                    'selector' => $sourceLandmarks['selectors'][$kind][0] ?? '',
                    'summary' => 'Source ' . $kind . ' landmarks exceed generated core block representation.',
                ), static fn (mixed $value): bool => '' !== $value);
            }
        }

        $matchedBlockMenuIndexes = array();
        foreach ( $sourceMenus as $index => $sourceMenu ) {
            $blockMenuIndex = $this->matchingBlockNavigationMenuIndex($sourceMenu, $blockMenus, $matchedBlockMenuIndexes, $index);
            $blockMenu = null === $blockMenuIndex ? null : ( $blockMenus[$blockMenuIndex] ?? null );
            if ( null !== $blockMenuIndex ) {
                $matchedBlockMenuIndexes[$blockMenuIndex] = true;
            }
            if ( ! is_array($blockMenu) ) {
                $sourceItems = is_array($sourceMenu['items'] ?? null) ? array_values($sourceMenu['items']) : array();
                $findings[] = array(
                    'code' => 'navigation_menu_missing',
                    'severity' => 'warning',
                    'selector' => $sourceMenu['selector'] ?? '',
                    'source_item_count' => $sourceMenu['item_count'] ?? 0,
                    'block_item_count' => 0,
                    'source_items' => $sourceItems,
                    'block_items' => array(),
                    'summary' => 'Source navigation menu was not represented as a core/navigation block.',
                );
                continue;
            }

            if ( true !== ($blockMenu['represented_as_core_navigation'] ?? false) ) {
                $findings[] = array(
                    'code' => 'navigation_core_block_missing',
                    'severity' => 'warning',
                    'selector' => $sourceMenu['selector'] ?? '',
                    'summary' => 'Generated navigation menu is not represented by core/navigation.',
                );
            }

            $sourceItems = is_array($sourceMenu['items'] ?? null) ? array_values($sourceMenu['items']) : array();
            $blockItems = is_array($blockMenu['items'] ?? null) ? array_values($blockMenu['items']) : array();
            if ( count($sourceItems) !== count($blockItems) ) {
                $findings[] = array(
                    'code' => 'navigation_item_count_mismatch',
                    'severity' => 'warning',
                    'selector' => $sourceMenu['selector'] ?? '',
                    'source_item_count' => count($sourceItems),
                    'block_item_count' => count($blockItems),
                    'source_items' => $sourceItems,
                    'block_items' => $blockItems,
                    'summary' => 'Source navigation item count differs from generated core navigation items.',
                );
                continue;
            }

            foreach ( $sourceItems as $itemIndex => $sourceItem ) {
                $blockItem = $blockItems[$itemIndex] ?? array();
                if ( ($sourceItem['label'] ?? '') !== ($blockItem['label'] ?? '') || ($sourceItem['url'] ?? '') !== ($blockItem['url'] ?? '') ) {
                    $findings[] = array(
                        'code' => 'navigation_item_mismatch',
                        'severity' => 'warning',
                        'selector' => $sourceMenu['selector'] ?? '',
                        'item_index' => $itemIndex,
                        'source_item' => $sourceItem,
                        'block_item' => $blockItem,
                        'summary' => 'Source navigation item label or URL differs from generated core navigation item.',
                    );
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * Make a semantic-parity finding fix-ready by attaching additive context:
     * a stable `reason_code`, the bounded `source_snippet` for its selector, and
     * the `observed_block` output produced for that region (or an explicit
     * "none"). Strictly additive — never alters parity pass/fail counts.
     *
     * @param array<string, mixed> $finding
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<string, mixed>
     */
    private function enrichSemanticParityFinding(array $finding, DOMElement $body, array $sourceProvenance): array
    {
        if ( ! isset($finding['reason_code']) && isset($finding['code']) ) {
            $finding['reason_code'] = (string) $finding['code'];
        }

        if ( ! isset($finding['source_snippet']) ) {
            $finding['source_snippet'] = $this->semanticParitySourceSnippet((string) ($finding['selector'] ?? ''), $body);
        }

        if ( ! isset($finding['observed_block']) ) {
            $finding['observed_block'] = $this->observedBlockForFinding($finding, $sourceProvenance);
        }

        return $finding;
    }

    /**
     * Bounded source HTML for a finding selector, reusing the same bounded-HTML
     * helper used for runtime-island findings. Returns "none" when the selector
     * cannot be resolved back to a source element.
     */
    private function semanticParitySourceSnippet(string $selector, DOMElement $body): string
    {
        $element = $this->resolveSelectorElement($body, $selector);
        if ( ! $element instanceof DOMElement ) {
            return 'none';
        }

        $bounded = $this->boundedFallbackHtml($this->safeFallbackHtml($element));

        return '' === $bounded['html'] ? 'none' : $bounded['html'];
    }

    /**
     * Resolve a `tag:nth-of-type(n) > …` selector produced by elementSelector()
     * back to the source element, so findings can carry the offending markup.
     */
    private function resolveSelectorElement(DOMElement $body, string $selector): ?DOMElement
    {
        $selector = trim($selector);
        if ( '' === $selector ) {
            return null;
        }

        $current = $body;
        foreach ( explode(' > ', $selector) as $part ) {
            if ( ! preg_match('/^([a-z0-9]+):nth-of-type\((\d+)\)$/i', trim($part), $match) ) {
                return null;
            }

            $tag = strtolower($match[1]);
            $target = (int) $match[2];
            $index = 0;
            $found = null;
            foreach ( $current->childNodes as $child ) {
                if ( $child instanceof DOMElement && strtolower($child->tagName) === $tag ) {
                    ++$index;
                    if ( $index === $target ) {
                        $found = $child;
                        break;
                    }
                }
            }

            if ( ! $found instanceof DOMElement ) {
                return null;
            }

            $current = $found;
        }

        return $current === $body ? null : $current;
    }

    /**
     * Observed block output produced for a finding's region. Prefers block data
     * already carried by the finding, then provenance lookup by selector, and
     * falls back to an explicit "none" when nothing was generated.
     *
     * @param array<string, mixed> $finding
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<string, mixed>|string
     */
    private function observedBlockForFinding(array $finding, array $sourceProvenance): array|string
    {
        if ( isset($finding['block_items']) && is_array($finding['block_items']) && array() !== $finding['block_items'] ) {
            return array( 'block_items' => $finding['block_items'] );
        }

        if ( isset($finding['block_item']) && is_array($finding['block_item']) && array() !== $finding['block_item'] ) {
            return array( 'block_item' => $finding['block_item'] );
        }

        $blockNames = $this->blockNamesForSelector((string) ($finding['selector'] ?? ''), $sourceProvenance);

        return array() === $blockNames ? 'none' : array( 'block_names' => $blockNames );
    }

    /**
     * Block names whose source provenance selector matches or descends from the
     * given selector.
     *
     * @param array<int, array<string, mixed>> $sourceProvenance
     * @return array<int, string>
     */
    private function blockNamesForSelector(string $selector, array $sourceProvenance): array
    {
        if ( '' === $selector ) {
            return array();
        }

        $names = array();
        foreach ( $sourceProvenance as $entry ) {
            if ( ! is_array($entry) ) {
                continue;
            }

            $blockSelector = (string) ($entry['selector'] ?? '');
            if ( $selector === $blockSelector || str_starts_with($blockSelector, $selector . ' > ') ) {
                $name = (string) ($entry['block_name'] ?? '');
                if ( '' !== $name ) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Heading-element inline `style="font-family:…"` declarations resolved from
     * the source DOM, for the typography parity diagnostic.
     *
     * @return array<int, array{family: string, selector: string, source_snippet: string}>
     */
    private function inlineHeadingFontDeclarations(DOMElement $body): array
    {
        $declarations = array();
        foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
            foreach ( $body->getElementsByTagName($tag) as $heading ) {
                if ( ! $heading instanceof DOMElement ) {
                    continue;
                }

                $style = $this->attr($heading, 'style');
                if ( '' === $style || ! preg_match('/font-family\s*:\s*([^;{}]+)/i', $style, $match) ) {
                    continue;
                }

                $bounded = $this->boundedFallbackHtml($this->safeFallbackHtml($heading));
                $declarations[] = array(
                    'family'         => trim((string) $match[1]),
                    'selector'       => $this->elementSelector($heading),
                    'source_snippet' => $bounded['html'],
                );
            }
        }

        return $declarations;
    }

    /**
     * @param array<string, mixed> $sourceMenu
     * @param array<int, array<string, mixed>> $blockMenus
     * @param array<int, bool> $matchedBlockMenuIndexes
     */
    private function matchingBlockNavigationMenuIndex(array $sourceMenu, array $blockMenus, array $matchedBlockMenuIndexes, int $fallbackIndex): ?int
    {
        $sourceItems = is_array($sourceMenu['items'] ?? null) ? array_values($sourceMenu['items']) : array();
        $sourceSignature = $this->navigationItemsSignature($sourceItems);
        if ( '' !== $sourceSignature ) {
            foreach ( $blockMenus as $index => $blockMenu ) {
                if ( isset($matchedBlockMenuIndexes[$index]) ) {
                    continue;
                }

                $blockItems = is_array($blockMenu['items'] ?? null) ? array_values($blockMenu['items']) : array();
                if ( $sourceSignature === $this->navigationItemsSignature($blockItems) ) {
                    return $index;
                }
            }
        }

        if ( isset($blockMenus[$fallbackIndex]) && ! isset($matchedBlockMenuIndexes[$fallbackIndex]) ) {
            return $fallbackIndex;
        }

        foreach ( $blockMenus as $index => $_blockMenu ) {
            if ( ! isset($matchedBlockMenuIndexes[$index]) ) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function navigationItemsSignature(array $items): string
    {
        $parts = array();
        foreach ( $items as $item ) {
            $parts[] = trim((string) ($item['label'] ?? '')) . '>' . trim((string) ($item['url'] ?? ''));
        }

        return implode('|', $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, bool> $seen
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateNavigationBlocksRecursive(array $blocks, array &$seen): array
    {
        $deduplicated = array();
        foreach ( $blocks as $block ) {
            if ( ! is_array($block) ) {
                continue;
            }

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $block['innerBlocks'] = $this->deduplicateNavigationBlocksRecursive($block['innerBlocks'], $seen);
                $block = $this->reconcileInnerContentChildPlaceholders($block);
            }

            if ( 'core/navigation' === ($block['blockName'] ?? '') ) {
                $signature = $this->navigationBlockSignature($block);
                if ( '' !== $signature && isset($seen[$signature]) && $this->isMobileDuplicateNavigationBlock($block) ) {
                    continue;
                }
                if ( '' !== $signature ) {
                    $seen[$signature] = true;
                }
            }

            $deduplicated[] = $block;
        }

        return $deduplicated;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function isMobileDuplicateNavigationBlock(array $block): bool
    {
        $provenanceId = $block['_source_provenance_id'] ?? null;
        $source = is_int($provenanceId) ? ( $this->sourceProvenance[$provenanceId] ?? array() ) : array();
        $attributes = is_array($source['source_attributes'] ?? null) ? $source['source_attributes'] : array();
        $context = is_array($source['context'] ?? null) ? $source['context'] : array();
        $classNames = is_array($context['class_names'] ?? null) ? implode(' ', $context['class_names']) : '';

        $haystack = strtolower(trim(implode(' ', array(
            (string) ($attributes['class'] ?? ''),
            (string) ($attributes['id'] ?? ''),
            $classNames,
        ))));

        return (bool) preg_match('/(?:^|[^a-z0-9])(?:mobile|drawer|offcanvas|overlay|hamburger|menu-panel|nav-panel)(?:[^a-z0-9]|$)/', $haystack);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function reconcileInnerContentChildPlaceholders(array $block): array
    {
        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? array_values($block['innerBlocks']) : array();
        $innerContent = is_array($block['innerContent'] ?? null) ? array_values($block['innerContent']) : null;
        if ( null === $innerContent ) {
            return $block;
        }

        $placeholderCount = 0;
        $firstPlaceholderIndex = null;
        $lastPlaceholderIndex = null;
        foreach ( $innerContent as $index => $part ) {
            if ( null !== $part ) {
                continue;
            }

            ++$placeholderCount;
            $firstPlaceholderIndex ??= $index;
            $lastPlaceholderIndex = $index;
        }

        if ( count($innerBlocks) === $placeholderCount ) {
            return $block;
        }

        if ( null === $firstPlaceholderIndex || null === $lastPlaceholderIndex ) {
            return $block;
        }

        $opening = array_slice($innerContent, 0, $firstPlaceholderIndex);
        $closing = array_slice($innerContent, $lastPlaceholderIndex + 1);
        $block['innerBlocks'] = $innerBlocks;
        $block['innerContent'] = array_merge($opening, array_fill(0, count($innerBlocks), null), $closing);
        $block['innerHTML'] = implode('', array_map(static fn ($part): string => null === $part ? '' : (string) $part, array_merge($opening, $closing)));

        return $block;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function navigationBlockSignature(array $block): string
    {
        $links = array();
        $this->collectNavigationBlockLinks($block, $links);
        return implode('|', $links);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<int, string> $links
     */
    private function collectNavigationBlockLinks(array $block, array &$links): void
    {
        if ( in_array($block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true) ) {
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            $links[] = $this->normalizedNavigationLabel((string) ($attrs['label'] ?? '')) . '>' . trim((string) ($attrs['url'] ?? ''));
        }

        foreach ( is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : array() as $innerBlock ) {
            if ( is_array($innerBlock) ) {
                $this->collectNavigationBlockLinks($innerBlock, $links);
            }
        }
    }

    private function normalizeHtml5VoidElements(string $html): string
    {
        return preg_replace('/<source\b([^>]*?)(?<!\/)\s*>/i', '<source$1></source>', $html) ?? $html;
    }

    private function documentBodyHtml(string $html): string
    {
        if ( ! preg_match('/<(?:!doctype|html|head|body)\b/i', $html) ) {
            return $html;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ( ! $loaded ) {
            return $html;
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if ( ! $body instanceof DOMElement ) {
            return $html;
        }

        return $this->innerHtml($body);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array{strict: bool, allow_fallbacks: bool} $context
     */
    private function statusForFallbacks(array $fallbacks, array $context): string
    {
        if ( array() === $fallbacks || $context['allow_fallbacks'] ) {
            return 'success';
        }

        return $context['strict'] ? 'failed' : 'success_with_warnings';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function convertChildren(DOMNode $parent, array &$fallbacks, bool $captureUnsupported = false): array
    {
        $blocks = array();

        foreach ( $parent->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $blocks = array_merge($blocks, $this->convertText($text));
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $block = $this->convertElement($child, $fallbacks, $captureUnsupported);
            if ( null !== $block ) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    private function patternContext(bool $includeRuntimeDomTarget = true): PatternContext
    {
        return new PatternContext(
            fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement),
            $includeRuntimeDomTarget ? fn (DOMElement $sourceElement): bool => $this->isRuntimeDomTarget($sourceElement) : null
        );
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertElement(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): ?array
    {
        $tagName = strtolower($element->tagName);

        if ( preg_match('/^h([1-6])$/', $tagName, $matches) ) {
            $content = $this->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/heading', array_merge($this->presentationAttributes($element), array(
                'content' => $content,
                'level'   => (int) $matches[1],
            )), array(), $element);
        }

        if ( 'p' === $tagName ) {
            $content = $this->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                $textBlocks = $this->convertText(trim($element->textContent ?? ''));
                return $textBlocks[0] ?? null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'address' === $tagName ) {
            $content = $this->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        $placeholderMedia = $this->placeholderMediaBlockFromElement($element);
        if ( null !== $placeholderMedia ) {
            return $placeholderMedia;
        }

        if ( $this->isInlineContentElement($tagName) ) {
            if ( $this->isRuntimeDomTarget($element) || ( '' === trim($element->textContent ?? '') && $this->hasIconLikeContext($element) ) ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), array(), $element);
            }

            $dynamicText = $this->dynamicTextContent($element);
            if ( null !== $dynamicText ) {
                return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $this->runtime->escapeHtml($dynamicText) )), array(), $element);
            }

            $content = $this->outerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                $children = $this->convertChildren($element, $fallbacks, true);
                if ( 1 === count($children) ) {
                    if ( array() !== $this->presentationAttributes($element) ) {
                        return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                    }
                    return $children[0];
                }
                if ( array() !== $children ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                }

                return null;
            }

            return $this->createBlock('core/paragraph', array( 'content' => $content ));
        }

        if ( 'ul' === $tagName || 'ol' === $tagName ) {
            $navigation = $this->patternRecognizers->firstMatch($element, $this->patternContext());
            if ( null !== $navigation ) {
                return $navigation;
            }

            $items = $this->listItems($element, $fallbacks);

            if ( array() === $items ) {
                return null;
            }

            return $this->createBlock('core/list', array_merge($this->presentationAttributes($element), 'ol' === $tagName ? array( 'ordered' => true ) : array()), $items, $element);
        }

        if ( 'dl' === $tagName ) {
            $items = $this->definitionListItems($element);
            if ( array() === $items ) {
                return null;
            }

            return $this->createBlock('core/list', $this->presentationAttributes($element), $items, $element);
        }

        if ( 'blockquote' === $tagName ) {
            $citation = $this->citationFromElement($element);
            $value = $this->innerHtmlWithoutTags($element, array( 'cite', 'footer' ));
            if ( '' === trim($this->runtime->stripAllTags($value)) ) {
                return null;
            }

            if ( $this->hasClass($element, 'wp-block-pullquote') ) {
                return $this->createBlock('core/pullquote', array_filter(array_merge($this->presentationAttributes($element), array(
                    'value'    => $value,
                    'citation' => $citation,
                )), static fn ($value): bool => '' !== $value), array(), $element);
            }

            $innerBlocks = $this->phrasingQuoteChildren($element, $value);
            if ( array() === $innerBlocks ) {
                $innerBlocks = $this->convertChildrenWithoutTags($element, $fallbacks, array( 'cite', 'footer' ));
            }
            if ( array() === $innerBlocks ) {
                $innerBlocks[] = $this->createBlock('core/paragraph', array( 'content' => $value ));
            }

            return $this->createBlock('core/quote', array_filter(array_merge($this->presentationAttributes($element), array( 'citation' => $citation )), static fn ($value): bool => '' !== $value), $innerBlocks, $element);
        }

        if ( 'address' === $tagName ) {
            $content = $this->innerHtml($element);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }

            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'figure' === $tagName ) {
            $gallery = $this->galleryBlockFromElement($element, $fallbacks);
            if ( null !== $gallery ) {
                return $gallery;
            }

            $codeWindow = $this->codeWindowBlockFromElement($element, $fallbacks);
            if ( null !== $codeWindow ) {
                return $codeWindow;
            }

            $linkedMedia = $this->figureLinkedMediaAnchor($element);
            if ( $linkedMedia instanceof DOMElement ) {
                $linkedPicture = $this->firstChildElement($linkedMedia, 'picture');
                if ( $linkedPicture instanceof DOMElement ) {
                    return $this->convertPictureElement($linkedPicture, $element, $linkedMedia);
                }

                $linkedImage = $this->firstChildElement($linkedMedia, 'img');
                if ( $linkedImage instanceof DOMElement ) {
                    return $this->convertImageElement($linkedImage, $element, null, $linkedMedia);
                }
            }

            $image = $this->figureMediaElement($element, 'img');
            if ( $image instanceof DOMElement ) {
                return $this->convertImageElement($image, $element);
            }

            $picture = $this->figureMediaElement($element, 'picture');
            if ( $picture instanceof DOMElement ) {
                return $this->convertPictureElement($picture, $element);
            }

            $blockquote = $this->firstChildElement($element, 'blockquote');
            if ( $blockquote instanceof DOMElement ) {
                return $this->convertFigureBlockquote($element, $blockquote, $fallbacks);
            }
        }

        if ( 'pre' === $tagName ) {
            $code = $this->firstChildElement($element, 'code');
            if ( $code instanceof DOMElement ) {
                return $this->createBlock('core/code', array_merge($this->codePresentationAttributes($element, $code), array( 'content' => $this->codeContent($code) )), array(), $element);
            }

            return $this->createBlock('core/preformatted', array_merge($this->presentationAttributes($element), array( 'content' => $this->innerHtmlPreservingWhitespace($element) )), array(), $element);
        }

        if ( 'plaintext' === $tagName ) {
            $content = $this->runtime->escapeHtml($element->textContent ?? '');
            if ( '' === trim($content) ) {
                return null;
            }

            return $this->createBlock('core/preformatted', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
        }

        if ( 'table' === $tagName ) {
            return $this->createBlock('core/table', array_merge($this->presentationAttributes($element), $this->tableAttributes($element)), array(), $element);
        }

        $parameterTable = $this->parameterTableBlockFromElement($element);
        if ( null !== $parameterTable ) {
            return $parameterTable;
        }

        if ( 'hr' === $tagName ) {
            return $this->createBlock('core/separator', $this->presentationAttributes($element), array(), $element);
        }

        if ( 'br' === $tagName ) {
            return null;
        }

        if ( 'details' === $tagName ) {
            return $this->detailsPattern->match(
                $element,
                $fallbacks,
                fn (DOMElement $sourceElement, array &$sourceFallbacks, array $excludedTags): array => $this->convertChildrenWithoutTags($sourceElement, $sourceFallbacks, $excludedTags),
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
        }

        if ( 'img' === $tagName ) {
            return $this->convertImageElement($element);
        }

        if ( 'picture' === $tagName ) {
            return $this->convertPictureElement($element);
        }

        if ( 'iframe' === $tagName ) {
            return $this->convertIframeElement($element, $fallbacks);
        }

        if ( in_array($tagName, array( 'audio', 'video' ), true) ) {
            return $this->convertMediaElement($element);
        }

        if ( 'a' === $tagName ) {
            $linkedImage = $this->imageBlockFromAnchor($element);
            if ( null !== $linkedImage ) {
                return $linkedImage;
            }

            if ( '' === trim($element->textContent ?? '') && '' !== $this->safeLinkUrl($this->attr($element, 'href')) && '' !== trim($this->attr($element, 'aria-label')) ) {
                return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $this->outerHtml($element) )), array(), $element);
            }

            if ( '' === trim($element->textContent ?? '') ) {
                return null;
            }

            $logo = $this->logoPattern->match(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourceElement): string => $this->outerHtml($sourceElement),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $logo ) {
                return $logo;
            }

            $button = $this->buttonsPattern->matchAnchor(
                $element,
                fn (DOMElement $anchor): ?array => $this->fileBlockFromAnchor($anchor),
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $button ) {
                return $button;
            }

            if ( $this->hasBlockContentChildren($element) ) {
                $children = $this->convertChildren($element, $fallbacks, true);
                if ( array() !== $children ) {
                    return $this->createBlock('core/group', array_merge($this->presentationAttributes($element), $this->cardLinkAttributes($element)), $children, $element);
                }
            }

            return $this->createBlock('core/paragraph', array( 'content' => $this->outerHtml($element) ), array(), $element);
        }

        if ( 'button' === $tagName ) {
            return $this->buttonsPattern->matchButton(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
        }

        if ( 'svg' === $tagName ) {
            if ( $this->isSafeDecorativeSvgElement($element) ) {
                if ( $this->hasIconLikeContext($element) ) {
                    return $this->inlineSvgBlockFromElement($element);
                }
                if ( $this->isVisualLayerElement($element) ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), array(), $element);
                }
                return null;
            }

            $svgBlock = $this->inlineSvgBlockFromElement($element);
            if ( null !== $svgBlock ) {
                return $svgBlock;
            }

            $this->captureInlineSvgFallback($element, $fallbacks);
            return null;
        }

        if ( 'canvas' === $tagName ) {
            if ( ! $this->isRuntimeCanvasTarget($element) ) {
                return null;
            }

            $this->captureCanvasFallback($element, $fallbacks);
            return null;
        }

        if ( 'script' === $tagName ) {
            if ( $this->captureStaticScriptMetadata($element) ) {
                return null;
            }

            $this->captureScriptFallback($element, $fallbacks);
            return null;
        }

        if ( 'form' === $tagName ) {
            $searchBlock = $this->searchBlockFromForm($element);
            if ( null !== $searchBlock ) {
                return $searchBlock;
            }

            $readableFormBlock = $this->readableFormBlockFromForm($element);
            if ( null !== $readableFormBlock && ! $this->formRequiresRuntimePreservation($element) ) {
                return $readableFormBlock;
            }

            $controls = $this->formControls($element);
            $readableFormBlock = $this->readableFormBlockFromForm($element, true);
            $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
            $this->recordRuntimeIsland($element, 'form', 'form_requires_runtime', 'server_or_client_form_handler', array(
                'form'            => $this->formMetadata($element),
                'controls'        => $controls,
                'control_count'   => count($controls),
                'events'          => $this->eventMetadata($element),
                'readable_blocks' => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
                'required_scripts' => $this->requiredScriptsForElement($element),
            ));

            if ( null !== $readableFormBlock ) {
                return $readableFormBlock;
            }

            $fallbacks[] = FallbackDiagnostic::build(array(
                'type'            => 'html',
                'reason'          => 'form_requires_runtime',
                'diagnostic_code' => 'html_form_fallback',
                'message'         => 'Form HTML requires runtime behavior and was preserved as safe fallback metadata.',
                'source_format'   => 'html',
                'tag'             => $tagName,
                'selector'        => $this->elementSelector($element),
                'attributes'      => $this->htmlAttributes($element),
                'form'            => $this->formMetadata($element),
                'context'         => $this->sourceContext($element),
                'events'          => $this->eventMetadata($element),
                'readable_blocks' => null !== $readableFormBlock ? array( $readableFormBlock ) : array(),
                'controls'        => $controls,
                'control_count'   => count($controls),
                'text_length'     => strlen(trim($element->textContent ?? '')),
                'child_count'     => $this->childElementCount($element),
                'html'            => $boundedHtml['html'],
                'html_bytes'      => $boundedHtml['bytes'],
                'html_truncated'  => $boundedHtml['truncated'],
            ), $this->fallbackProvenance);

            return null;
        }

        if ( 'nav' === $tagName ) {
            $navigation = $this->patternRecognizers->firstMatch($element, $this->patternContext(false));
            if ( null !== $navigation ) {
                return $navigation;
            }
        }

        if ( in_array($tagName, array( 'article', 'aside', 'body', 'center', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true) ) {
            $logo = $this->logoPattern->match(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourceElement): string => $this->outerHtml($sourceElement),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $logo ) {
                return $logo;
            }

            $spacer = $this->spacerBlockFromElement($element);
            if ( null !== $spacer ) {
                return $spacer;
            }

            $navigationSection = $this->navigationSectionBlockFromElement($element);
            if ( null !== $navigationSection ) {
                return $navigationSection;
            }

            if ( ! $this->shouldDeferNavigationPatternToChildren($element) ) {
                $navigation = $this->patternRecognizers->firstMatch($element, $this->patternContext());
                if ( null !== $navigation ) {
                    return $navigation;
                }
            }

            $columns = $this->columnsBlockFromElement($element, $fallbacks);
            if ( null !== $columns ) {
                return $columns;
            }

            $gallery = $this->galleryBlockFromElement($element, $fallbacks);
            if ( null !== $gallery ) {
                return $gallery;
            }

            $codeWindow = $this->codeWindowBlockFromElement($element, $fallbacks);
            if ( null !== $codeWindow ) {
                return $codeWindow;
            }

            $namePriceRow = $this->namePriceRowBlockFromElement($element, $fallbacks);
            if ( null !== $namePriceRow ) {
                return $namePriceRow;
            }

            $inlineTokenGroup = $this->inlineTokenGroupBlockFromElement($element, $fallbacks);
            if ( null !== $inlineTokenGroup ) {
                return $inlineTokenGroup;
            }

            $inlineContent = $this->paragraphBlockFromInlineContentWrapper($element);
            if ( null !== $inlineContent ) {
                return $inlineContent;
            }

            $standaloneSearch = $this->searchBlockFromStandaloneControl($element);
            if ( null !== $standaloneSearch ) {
                return $standaloneSearch;
            }

            $buttons = $this->buttonsPattern->matchContainer(
                $element,
                fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
                fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
                fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
                fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
            );
            if ( null !== $buttons ) {
                return $buttons;
            }

            $textFlow = $this->textFlowBlockFromElement($element);
            if ( null !== $textFlow ) {
                return $textFlow;
            }

            $children = $this->convertChildren($element, $fallbacks, true);
            $backgroundImage = $this->backgroundImageBlockFromElement($element);
            if ( null !== $backgroundImage && ! $this->hasDirectMediaChild($element) ) {
                array_unshift($children, $backgroundImage);
            }
            if ( 1 === count($children) ) {
                if ( $this->shouldPreserveWrapper($element) ) {
                    return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
                }
                return $children[0];
            }
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
            }
            if ( $this->shouldPreserveEmptyVisualElement($element) ) {
                return $this->createBlock('core/group', $this->presentationAttributes($element), array(), $element);
            }
            return null;
        }

        $readableControlBlock = $this->readableFormControlBlockFromElement($element);
        if ( null !== $readableControlBlock ) {
            return $readableControlBlock;
        }

        if ( $captureUnsupported ) {
            $fallback = array(
                'type'            => 'unsupported_element',
                'reason'          => 'unsupported_element',
                'diagnostic_code' => 'html_unsupported_element',
                'source_format'   => 'html',
                'tag'             => $tagName,
                'selector'        => $this->elementSelector($element),
                'attributes'      => $this->htmlAttributes($element),
                'context'         => $this->sourceContext($element),
                'events'          => $this->eventMetadata($element),
                'text_length'     => strlen(trim($element->textContent ?? '')),
                'child_count'     => $this->childElementCount($element),
                'html'            => $this->safeFallbackHtml($element),
            );

            $control = $this->formControlMetadata($element);
            if ( array() !== $control ) {
                $fallback['control'] = $control;
            }

            $fallbacks[] = FallbackDiagnostic::build($fallback, $this->fallbackProvenance);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function convertText(string $text): array
    {
        $blocks = array();
        if ( $this->runtime->isShortcodeOnly($text) ) {
            $blocks[] = $this->createBlock('core/shortcode', array( 'text' => $this->runtime->preserveShortcodeText($text) ));
            return $blocks;
        }

        $blocks[] = $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($text) ));
        return $blocks;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    private function createBlock(string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array
    {
        if ( $sourceElement instanceof DOMElement ) {
            $provenanceId = $this->nextSourceProvenanceId++;
            $this->recordPresentationProvenance($name, $attrs, $sourceElement);
            $this->recordStructureProvenance($name, $attrs, $sourceElement);
            $sourceTagName = strtolower($sourceElement->tagName);
            if ( $this->isRuntimeDomTarget($sourceElement) && ! $this->isFormControlElement($sourceElement) && ! in_array($sourceTagName, array( 'canvas', 'form', 'script' ), true) ) {
                $this->recordRuntimeIsland($sourceElement, 'dom', 'runtime_dom_target', 'client_script_execution', array(
                    'events'          => $this->eventMetadata($sourceElement),
                    'required_scripts' => $this->requiredScriptsForElement($sourceElement),
                ));
            }
            $this->sourceProvenance[$provenanceId] = $this->sourceProvenanceEntry($name, $sourceElement);
        }

        $block = $this->blockFactory->create($name, $attrs, $innerBlocks);
        if ( isset($provenanceId) ) {
            $block['_source_provenance_id'] = $provenanceId;
        }

        return $block;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function sourceProvenanceForBlocks(array &$blocks): array
    {
        $resolved = array();
        $this->resolveSourceProvenancePaths($blocks, 'blocks', $resolved);
        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array<string, mixed>> $resolved
     */
    private function resolveSourceProvenancePaths(array &$blocks, string $path, array &$resolved): void
    {
        foreach ( $blocks as $index => &$block ) {
            $blockPath = $path . '.' . $index;
            $provenanceId = $block['_source_provenance_id'] ?? null;
            if ( is_int($provenanceId) && isset($this->sourceProvenance[$provenanceId]) ) {
                $resolved[] = array_merge(array( 'block_path' => $blockPath ), $this->sourceProvenance[$provenanceId]);
            }
            unset($block['_source_provenance_id']);

            if ( ! empty($block['innerBlocks']) && is_array($block['innerBlocks']) ) {
                $this->resolveSourceProvenancePaths($block['innerBlocks'], $blockPath . '.innerBlocks', $resolved);
            }
        }
        unset($block);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceProvenanceEntry(string $blockName, DOMElement $element): array
    {
        return array_merge(array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'source_attributes' => $this->safeSourceAttributes($element),
            'source_fragment'   => $this->safeSourceFragment($element),
            'context'           => $this->sourceContext($element),
        ), $this->sourceConversionMetadata($blockName, $element));
    }

    /**
     * @return array{conversion_classification: string, preservation_strategy: string}
     */
    private function sourceConversionMetadata(string $blockName, DOMElement $element): array
    {
        $tagName = strtolower($element->tagName);

        if ( 'core/html' === $blockName ) {
            return array(
                'conversion_classification' => 'runtime_island_preserved',
                'preservation_strategy'     => 'bounded_raw_html_runtime_island',
            );
        }

        if ( $this->isRuntimeDomTarget($element) ) {
            return array(
                'conversion_classification' => 'runtime_island_preserved',
                'preservation_strategy'     => 'core_block_shell_with_runtime_target',
            );
        }

        if ( in_array($tagName, array('form', 'input', 'select', 'textarea'), true) && 'core/search' !== $blockName ) {
            return array(
                'conversion_classification' => 'editable_approximation',
                'preservation_strategy'     => 'readable_static_block_approximation',
            );
        }

        return array(
            'conversion_classification' => 'native_block_conversion',
            'preservation_strategy'     => 'core_block',
        );
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return trim($html);
    }

    private function innerHtmlPreservingWhitespace(DOMElement $element): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            $html .= $element->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    private function outerHtml(DOMElement $element): string
    {
        return trim($element->ownerDocument->saveHTML($element) ?: '');
    }

    private function attr(DOMElement $element, string $name): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function presentationAttributes(DOMElement $element): array
    {
        $style = $this->mergedPresentationStyle($element);

        return array_filter(array(
            'anchor'    => $this->safeAnchor($this->attr($element, 'id')),
            'className' => $this->promotedClassName($this->attr($element, 'class')),
            'style'     => $style,
            'layout'    => $this->layoutAttribute($element, $style),
        ), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    private function safeAnchor(string $id): string
    {
        $id = trim($id);
        if ( '' === $id || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $id) ) {
            return '';
        }

        return $id;
    }

    private function mergedPresentationStyle(DOMElement $element): string
    {
        $inlineStyle = $this->attr($element, 'style');
        if ( array() === $this->staticStyleRules || ! $this->isHighValueStyledElement($element) ) {
            return $inlineStyle;
        }

        $declarations = array();
        foreach ( $this->staticStyleRules as $rule ) {
            if ( $this->matchesCssSelector($element, $rule['selector']) ) {
                $declarations = array_merge($declarations, $rule['declarations']);
            }
        }

        if ( array() === $declarations ) {
            return $inlineStyle;
        }

        $declarations = array_merge($declarations, $this->cssDeclarations($inlineStyle));
        return $this->cssDeclarationString($declarations);
    }

    private function isHighValueStyledElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'button', 'nav', 'article' ), true) ) {
            return true;
        }

        $tokens = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'role'),
        ))));

        if ( preg_match('/(?:^|[^a-z0-9])(?:btn|button|cta|action|nav|menu|cards?|tile|panel|pricing|price|product|grid|columns|layout|stack|cluster|row|wrap|hero|masthead|banner|media|image|photo|gallery)(?:[^a-z0-9]|$)/', $tokens) ) {
            return true;
        }

        if ( 'a' === $tagName ) {
            for ( $node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
                $ancestorTokens = strtolower($this->attr($node, 'class') . ' ' . $this->attr($node, 'id'));
                if ( preg_match('/(?:^|[^a-z0-9])(?:actions?|btns?|buttons?|cta|nav|menu|card|tile|panel|pricing|product)(?:[^a-z0-9]|$)/', $ancestorTokens) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, array{selector: string, declarations: array<string, string>}>
     */
    private function staticStyleRules(string $html, string $linkedCss): array
    {
        $css = trim($linkedCss);
        if ( preg_match_all('@<style\b[^>]*>(.*?)</style>@is', $html, $matches) ) {
            $css .= ( '' === $css ? '' : "\n" ) . implode("\n", array_map('trim', $matches[1]));
        }

        if ( '' === trim($css) ) {
            return array();
        }

        $css = preg_replace('@/\*.*?\*/@s', '', $css) ?? $css;
        $rules = array();
        if ( ! preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $matches, PREG_SET_ORDER) ) {
            return array();
        }

        foreach ( $matches as $match ) {
            $declarations = $this->safeVisualDeclarations($this->cssDeclarations((string) $match[2]));
            if ( array() === $declarations ) {
                continue;
            }
            foreach ( explode(',', (string) $match[1]) as $selector ) {
                $selector = trim($selector);
                if ( '' !== $selector && $this->isSupportedCssSelector($selector) ) {
                    $rules[] = array(
                        'selector' => $selector,
                        'declarations' => $declarations,
                    );
                }
            }
        }

        return array_slice($rules, 0, 200);
    }

    /**
     * @param array<string, string> $declarations
     * @return array<string, string>
     */
    private function safeVisualDeclarations(array $declarations): array
    {
        $safe = array_flip(array(
            'background',
            'background-color',
            'background-image',
            'border',
            'border-color',
            'border-radius',
            'border-style',
            'border-width',
            'box-shadow',
            'color',
            'align-items',
            'column-gap',
            'display',
            'flex-direction',
            'flex-wrap',
            'font-size',
            'font-weight',
            'gap',
            'grid-template-columns',
            'grid-template-rows',
            'justify-content',
            'line-height',
            'margin',
            'margin-bottom',
            'margin-left',
            'margin-right',
            'margin-top',
            'padding',
            'padding-bottom',
            'padding-left',
            'padding-right',
            'padding-top',
            'row-gap',
            'text-align',
            'text-decoration',
            'text-transform',
        ));

        return array_intersect_key($declarations, $safe);
    }

    /**
     * @return array<string, string>
     */
    private function cssDeclarations(string $style): array
    {
        $declarations = array();
        foreach ( explode(';', $style) as $declaration ) {
            if ( ! str_contains($declaration, ':') ) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            $name = strtolower($name);
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            $allowsImageUrl = in_array($name, array( 'background', 'background-image' ), true) && ! preg_match('/(?:expression\s*\(|javascript\s*:)/i', $value);
            if ( '' !== $name && '' !== $value && ( $allowsImageUrl || ! preg_match('/(?:expression\s*\(|javascript\s*:|url\s*\()/i', $value) ) ) {
                $declarations[$name] = $value;
            }
        }

        return $declarations;
    }

    /**
     * @param array<string, string> $declarations
     */
    private function cssDeclarationString(array $declarations): string
    {
        $parts = array();
        foreach ( $declarations as $name => $value ) {
            $parts[] = $name . ':' . $value;
        }

        return implode(';', $parts);
    }

    private function isSupportedCssSelector(string $selector): bool
    {
        $selector = $this->normalizeCssSelector($selector);
        if ( '' === $selector || preg_match('/[>+~\[\]=]/', $selector) ) {
            return false;
        }

        foreach ( preg_split('/\s+/', $selector) ?: array() as $part ) {
            if ( ! preg_match('/^(?:[a-z][a-z0-9_-]*)?(?:\.[A-Za-z0-9_-]+)+$|^\.[A-Za-z0-9_-]+$/i', $part) ) {
                return false;
            }
        }

        return true;
    }

    private function matchesCssSelector(DOMElement $element, string $selector): bool
    {
        $parts = preg_split('/\s+/', $this->normalizeCssSelector($selector)) ?: array();
        if ( array() === $parts ) {
            return false;
        }

        if ( ! $this->matchesCssSelectorPart($element, $parts[count($parts) - 1]) ) {
            return false;
        }

        $current = $element->parentNode instanceof DOMElement ? $element->parentNode : null;
        for ( $index = count($parts) - 2; $index >= 0; --$index ) {
            $matched = false;
            for ( $node = $current; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null ) {
                if ( $this->matchesCssSelectorPart($node, $parts[$index]) ) {
                    $matched = true;
                    $current = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
                    break;
                }
            }
            if ( ! $matched ) {
                return false;
            }
        }

        return true;
    }

    private function normalizeCssSelector(string $selector): string
    {
        return trim(preg_replace('/:(?:hover|focus|active|visited|before|after|focus-visible)\b.*/', '', $selector) ?? $selector);
    }

    private function matchesCssSelectorPart(DOMElement $element, string $selector): bool
    {
        if ( ! preg_match('/^(?:(?<tag>[a-z][a-z0-9_-]*))?(?<classes>(?:\.[A-Za-z0-9_-]+)+)$/i', $selector, $match) ) {
            return false;
        }

        if ( ! empty($match['tag']) && strtolower($match['tag']) !== strtolower($element->tagName) ) {
            return false;
        }

        $classes = preg_split('/\./', ltrim((string) $match['classes'], '.')) ?: array();
        $elementClasses = preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array();
        foreach ( $classes as $class ) {
            if ( ! in_array($class, $elementClasses, true) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function detectStaticClassPromotions(string $html): array
    {
        if ( ! str_contains($html, 'classList.add') || ! str_contains($html, 'querySelectorAll') ) {
            return array();
        }

        if ( ! str_contains($html, 'IntersectionObserver') && ! str_contains($html, 'isIntersecting') ) {
            return array();
        }

        preg_match_all('/querySelectorAll\s*\(\s*([\'"`])\.([A-Za-z0-9_-]+)\1\s*\)/', $html, $selectorMatches);
        preg_match_all('/classList\.add\s*\(([^)]*)\)/', $html, $addMatches);

        $triggerClasses = array_values(array_unique($selectorMatches[2] ?? array()));
        $terminalClasses = array();
        foreach ( $addMatches[1] ?? array() as $args ) {
            preg_match_all('/[\'"`]([A-Za-z0-9_-]+)[\'"`]/', (string) $args, $classMatches);
            foreach ( $classMatches[1] ?? array() as $className ) {
                $terminalClasses[] = $className;
            }
        }

        $terminalClasses = array_values(array_unique($terminalClasses));
        if ( array() === $triggerClasses || array() === $terminalClasses ) {
            return array();
        }

        $promotions = array();
        foreach ( array_slice($triggerClasses, 0, 20) as $triggerClass ) {
            $promotions[$triggerClass] = array_values(array_diff(array_slice($terminalClasses, 0, 20), array( $triggerClass )));
        }

        return array_filter($promotions, static fn (array $classes): bool => array() !== $classes);
    }

    private function promotedClassName(string $className): string
    {
        if ( '' === trim($className) || array() === $this->staticClassPromotions ) {
            return $this->presentationClassName($className);
        }

        $classes = preg_split('/\s+/', trim($className)) ?: array();
        foreach ( $classes as $class ) {
            foreach ( $this->staticClassPromotions[$class] ?? array() as $terminalClass ) {
                if ( ! in_array($terminalClass, $classes, true) ) {
                    $classes[] = $terminalClass;
                }
            }
        }

        return $this->presentationClassName(implode(' ', $classes));
    }

    private function presentationClassName(string $className): string
    {
        $classes = preg_split('/\s+/', trim($className)) ?: array();
        $classes = array_filter($classes, static fn (string $class): bool => '' !== $class && ! self::isBehaviorHookClassName($class));

        return implode(' ', array_values(array_unique($classes)));
    }

    private static function isBehaviorHookClassName(string $className): bool
    {
        return 1 === preg_match('/^js(?:$|[-_:]|[A-Z])/', $className);
    }

    /**
     * @return array<string, string>
     */
    private function layoutAttribute(DOMElement $element, string $mergedStyle = ''): array
    {
        $declared = trim($this->attr($element, 'data-layout'));
        if ( '' === $declared ) {
            $declared = trim($this->attr($element, 'data-wp-layout'));
        }

        if ( '' !== $declared ) {
            $decoded = json_decode($declared, true);
            $type = is_array($decoded) ? (string) ($decoded['type'] ?? '') : $declared;
            if ( in_array($type, array( 'constrained', 'flex', 'flow', 'grid' ), true) ) {
                return array( 'type' => $type );
            }
        }

        $style = strtolower('' !== trim($mergedStyle) ? $mergedStyle : $this->attr($element, 'style'));
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?flex\b/', $style) ) {
            return array( 'type' => 'flex' );
        }
        if ( preg_match('/(?:^|;)\s*display\s*:\s*(inline-)?grid\b/', $style) ) {
            return array( 'type' => 'grid' );
        }

        if ( $this->hasGridLikeClass($element) && 1 < $this->cardLikeChildCount($element) ) {
            return array( 'type' => 'grid' );
        }

        return array();
    }

    private function hasGridLikeClass(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        return (bool) preg_match('/(?:^|[\s_-])(?:cards|features|services|providers|testimonials|resources|posts|projects|stats|badges|grid|grid-[0-9]+|tiles|collection|gallery)(?:$|[\s_-])/', $className);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function recordPresentationProvenance(string $blockName, array $attrs, DOMElement $element): void
    {
        $signals = array_intersect_key($attrs, array_flip(array( 'className', 'style', 'layout' )));
        $signals = array_filter($signals, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
        if ( array() === $signals ) {
            return;
        }

        $this->presentationProvenance[] = array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'signals'           => $signals,
            'source_attributes' => array_intersect_key($this->htmlAttributes($element), array_flip(array( 'class', 'style', 'data-layout', 'data-wp-layout' ))),
        );
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function recordStructureProvenance(string $blockName, array $attrs, DOMElement $element): void
    {
        $signals = $this->structureSignals($element, $attrs);
        if ( array() === $signals ) {
            return;
        }

        $this->structureProvenance[] = array(
            'block_name'        => $blockName,
            'tag'               => strtolower($element->tagName),
            'selector'          => $this->elementSelector($element),
            'signals'           => $signals,
            'source_attributes' => array_intersect_key($this->htmlAttributes($element), array_flip(array( 'class', 'id', 'role', 'style', 'data-layout', 'data-wp-layout' ))),
        );
    }

    private function shouldPreserveWrapper(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'article', 'aside', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true) && ( $this->isRuntimeDomTarget($element) || array() !== $this->presentationAttributes($element) || array() !== $this->structureSignals($element, array()) );
    }

    private function shouldDeferNavigationPatternToChildren(DOMElement $element): bool
    {
        if ( 'nav' === strtolower($element->tagName) || ! $this->shouldPreserveWrapper($element) ) {
            return false;
        }

        $hasNavigationDescendant = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( in_array(strtolower($child->tagName), array( 'a', 'ul', 'ol' ), true) ) {
                return false;
            }

            $hasNavigationDescendant = $hasNavigationDescendant || 'nav' === strtolower($child->tagName) || 0 < $child->getElementsByTagName('a')->length;
        }

        return $hasNavigationDescendant;
    }

    private function shouldPreserveEmptyVisualElement(DOMElement $element): bool
    {
        if ( '' !== trim($element->textContent ?? '') ) {
            return false;
        }

        if ( $this->shouldPreserveWrapper($element) ) {
            return true;
        }

        if ( 0 !== $this->childElementCount($element) ) {
            return false;
        }

        return in_array(strtolower($this->attr($element, 'role')), array( 'presentation', 'none' ), true) || 'true' === strtolower($this->attr($element, 'aria-hidden'));
    }

    private function isInlineContentElement(string $tagName): bool
    {
        return in_array($tagName, array( 'abbr', 'b', 'cite', 'code', 'em', 'font', 'i', 'kbd', 'mark', 'rp', 'rt', 'ruby', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'var' ), true);
    }

    private function hasBlockContentChildren(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            $tagName = $child instanceof DOMElement ? strtolower($child->tagName) : '';
            if ( $child instanceof DOMElement && 'br' !== $tagName && ! $this->isInlineContentElement($tagName) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function textFlowBlockFromElement(DOMElement $element): ?array
    {
        if ( 'div' !== strtolower($element->tagName) || '' !== trim($this->attr($element, 'id')) || '' !== trim($this->attr($element, 'role')) ) {
            return null;
        }

        $hasLineBreak = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'br' === $tagName ) {
                $hasLineBreak = true;
            }
            if ( 'br' !== $tagName && ! $this->isInlineContentElement($tagName) && 'a' !== $tagName ) {
                return null;
            }
        }

        if ( ! $hasLineBreak ) {
            return null;
        }

        $content = $this->innerHtml($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
    }

    private function inlineTokenGroupBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'footer', 'header', 'main', 'nav', 'section' ), true) ) {
            return null;
        }

        if ( ! $this->hasInlineTokenGroupSignal($element) ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    return null;
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( ! $this->isInlineTokenItemElement($child) ) {
                return null;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'a', 'button' ), true) ) {
                $block = $this->convertElement($child, $fallbacks, true);
                if ( null === $block ) {
                    return null;
                }
                $children[] = $block;
                continue;
            }

            $content = $this->innerHtml($child);
            if ( '' === trim($this->runtime->stripAllTags($content)) ) {
                return null;
            }
            $children[] = $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($child), array( 'content' => $content )), array(), $child);
        }

        if ( count($children) < 2 ) {
            return null;
        }

        return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
    }

    private function hasInlineTokenGroupSignal(DOMElement $element): bool
    {
        if ( $this->hasInlineTokenSignal($element) ) {
            return true;
        }

        $tokenChildren = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->isInlineTokenItemElement($child) ) {
                ++$tokenChildren;
            }
        }

        return 1 < $tokenChildren;
    }

    private function isInlineTokenItemElement(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( ! in_array($tagName, array( 'a', 'button' ), true) && ! $this->isInlineContentElement($tagName) ) {
            return false;
        }

        return $this->hasInlineTokenSignal($element);
    }

    private function hasInlineTokenSignal(DOMElement $element): bool
    {
        $tokens = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'role'),
            $this->attr($element, 'data-filter'),
            $this->attr($element, 'data-tag'),
        ))));

        return 1 === preg_match('/(?:^|[^a-z0-9])(?:chips?|pills?|badges?|tags?|filters?|facets?)(?:[^a-z0-9]|$)/', $tokens);
    }

    private function paragraphBlockFromInlineContentWrapper(DOMElement $element): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array( 'article', 'div', 'footer', 'header', 'main', 'section' ), true) ) {
            return null;
        }

        if ( ! $this->hasOnlyPhrasingChildren($element) ) {
            return null;
        }

        $content = $this->innerHtml($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $content )), array(), $element);
    }

    private function hasOnlyPhrasingChildren(DOMElement $element): bool
    {
        $nonAnchorText = false;

        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                if ( '' !== trim($child->textContent ?? '') ) {
                    $nonAnchorText = true;
                }
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'a' === $tagName ) {
                continue;
            }

            if ( 'br' === $tagName || $this->isInlineContentElement($tagName) ) {
                $nonAnchorText = true;
                continue;
            }

            return false;
        }

        return $nonAnchorText;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function phrasingQuoteChildren(DOMElement $element, string $value): array
    {
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'cite', 'footer' ), true) ) {
                continue;
            }
            if ( 'br' === $tagName || $this->isInlineContentElement($tagName) ) {
                continue;
            }

            return array();
        }

        return array( $this->createBlock('core/paragraph', array( 'content' => $value )) );
    }

    private function dynamicTextContent(DOMElement $element): ?string
    {
        $target = trim($this->attr($element, 'data-target'));
        if ( '' === $target || ! is_numeric($target) ) {
            return null;
        }

        $isFloat = 'true' === strtolower(trim($this->attr($element, 'data-float'))) || str_contains($target, '.');
        $value = $isFloat
            ? number_format((float) $target, 1, '.', ',')
            : number_format((float) $target, 0, '.', ',');

        return $this->attr($element, 'data-prefix') . $value . $this->attr($element, 'data-suffix');
    }

    private function hasClass(DOMElement $element, string $className): bool
    {
        return in_array($className, preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array(), true);
    }

    private function elementSelector(DOMElement $element): string
    {
        $parts = array();
        $current = $element;
        while ( $current instanceof DOMElement && 'body' !== strtolower($current->tagName) ) {
            $tagName = strtolower($current->tagName);
            $index = 1;
            for ( $sibling = $current->previousSibling; $sibling instanceof DOMNode; $sibling = $sibling->previousSibling ) {
                if ( $sibling instanceof DOMElement && strtolower($sibling->tagName) === $tagName ) {
                    ++$index;
                }
            }
            array_unshift($parts, $tagName . ':nth-of-type(' . $index . ')');
            $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
        }

        return implode(' > ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private function htmlAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $element->attributes ?? array() as $attribute ) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        ksort($attributes);
        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    private function safeSourceAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'alt', 'class', 'data-layout', 'data-wp-layout', 'height', 'href', 'id', 'media', 'open', 'sizes', 'src', 'srcset', 'style', 'title', 'type', 'width' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/^\s*javascript\s*:/i', $value) ) {
                $safe[$name] = $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceContext(DOMElement $element): array
    {
        return array_filter(array(
            'selector'                => $this->elementSelector($element),
            'parent_tag'              => $element->parentNode instanceof DOMElement && 'body' !== strtolower($element->parentNode->tagName) ? strtolower($element->parentNode->tagName) : '',
            'ancestor_tags'           => $this->ancestorTags($element),
            'nearest_heading'         => $this->nearestPreviousHeadingText($element),
            'role'                    => $this->attr($element, 'role'),
            'id'                      => $this->attr($element, 'id'),
            'class_names'             => $this->classNames($element),
            'data_attributes'         => $this->safeDataAttributes($element),
            'structure_signals'       => $this->structureSignals($element, array()),
            'interactive_attributes'  => $this->interactiveAttributes($element),
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
    }

    /**
     * @return array<int, string>
     */
    private function ancestorTags(DOMElement $element): array
    {
        $tags = array();
        for ( $parent = $element->parentNode; $parent instanceof DOMElement && 'body' !== strtolower($parent->tagName); $parent = $parent->parentNode ) {
            $tags[] = strtolower($parent->tagName);
        }

        return $tags;
    }

    private function nearestPreviousHeadingText(DOMElement $element): string
    {
        for ( $node = $element->previousSibling; $node instanceof DOMNode; $node = $node->previousSibling ) {
            if ( $node instanceof DOMElement && preg_match('/^h[1-6]$/i', $node->tagName) ) {
                return trim(preg_replace('/\s+/', ' ', $node->textContent ?? '') ?? '');
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function classNames(DOMElement $element): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array()));
    }

    /**
     * @return array<string, string>
     */
    private function safeDataAttributes(DOMElement $element): array
    {
        $data = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^data-[a-z0-9_-]+$/i', $name) && strlen($value) <= 300 && ! preg_match('/javascript\s*:/i', $value) ) {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    /**
     * @return array<string, bool|string>
     */
    private function interactiveAttributes(DOMElement $element): array
    {
        return array_filter(array(
            'tabindex'      => $this->attr($element, 'tabindex'),
            'aria-expanded' => $this->attr($element, 'aria-expanded'),
            'aria-controls' => $this->attr($element, 'aria-controls'),
            'has_events'    => array() !== $this->eventMetadata($element),
        ), static fn (mixed $value): bool => false !== $value && '' !== $value);
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function structureSignals(DOMElement $element, array $attrs): array
    {
        $className = strtolower(trim($this->attr($element, 'class') . ' ' . (string) ($attrs['className'] ?? '')));
        $style = strtolower(trim($this->attr($element, 'style') . ';' . (string) ($attrs['style'] ?? '')));
        $signals = array();

        if ( preg_match('/(?:^|[\s_-])(?:card|feature|service|provider|resource|post|project|stat|badge|tile|panel|item)(?:$|[\s_-])/', $className) || 'article' === strtolower($element->tagName) ) {
            $signals['card_like'] = true;
        }
        if ( preg_match('/(?:^|[\s_-])(?:cards|features|services|providers|testimonials|resources|posts|projects|stats|badges|grid|grid-[0-9]+|tiles|columns|collection|gallery)(?:$|[\s_-])/', $className) || preg_match('/(?:^|;)\s*(?:display\s*:\s*grid|grid-template-columns\s*:)/', $style) ) {
            $signals['grid_like'] = true;
        }
        if ( preg_match('/(?:^|[\s_-])(?:hero|masthead|intro|banner|container|wrap|wrapper|inner|shell)(?:$|[\s_-])/', $className) ) {
            $signals['section_container_like'] = true;
        }
        if ( $this->isVisualLayerElement($element) ) {
            $signals['visual_layer'] = true;
        }
        if ( $this->hasCommerceToken($element, array( 'badge', 'featured', 'popular', 'recommended' )) ) {
            $signals['featured_badge_like'] = true;
        }
        if ( $this->hasCommerceToken($element, array( 'price', 'pricing', 'amount', 'cost' )) || $this->looksLikePriceText($element->textContent ?? '') ) {
            $signals['price_like'] = true;
        }
        if ( $this->hasCommerceToken($element, array( 'product', 'menu', 'dish', 'plan', 'tier', 'name', 'title' )) ) {
            $signals['commerce_content_like'] = true;
        }
        if ( $this->looksLikeNamePriceRow($element) ) {
            $signals['name_price_row'] = true;
        }

        $itemCount = $this->cardLikeChildCount($element);
        if ( 1 < $itemCount ) {
            $signals['repeated_card_children'] = $itemCount;
        }

        return $signals;
    }

    private function cardLikeChildCount(DOMElement $element): int
    {
        $itemCount = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->isCardLikeElement($child) ) {
                ++$itemCount;
            }
        }

        return $itemCount;
    }

    private function isCardLikeElement(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        return 'article' === strtolower($element->tagName) || (bool) preg_match('/(?:^|[\s_-])(?:card|feature|service|provider|resource|post|project|stat|badge|tile|panel|item)(?:$|[\s_-])/', $className);
    }

    private function isVisualLayerElement(DOMElement $element): bool
    {
        $context = strtolower(trim(implode(' ', array(
            $this->attr($element, 'class'),
            $this->attr($element, 'id'),
            $this->attr($element, 'aria-label'),
        ))));
        $style = strtolower($this->attr($element, 'style'));

        if ( preg_match('/(?:^|[\s_-])(?:hero|decor|decorative|layer|overlay|grain|noise|texture|glow|atmosphere|ambient|aura|orb|blob|backdrop|background|bg)(?:$|[\s_-])/', $context) ) {
            return true;
        }

        return (bool) ( preg_match('/(?:^|;)\s*position\s*:\s*(?:fixed|absolute)\b/', $style)
            && preg_match('/(?:^|;)\s*(?:inset|top|right|bottom|left|z-index|pointer-events|mix-blend-mode|opacity|filter|background|background-image)\s*:/', $style) );
    }

    /**
     * @param array<int, string> $tokens
     */
    private function hasCommerceToken(DOMElement $element, array $tokens): bool
    {
        foreach ( array( 'class', 'id', 'itemprop' ) as $attribute ) {
            $value = strtolower($this->attr($element, $attribute));
            foreach ( preg_split('/[^a-z0-9]+/', $value) ?: array() as $token ) {
                if ( in_array($token, $tokens, true) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function looksLikePriceText(string $text): bool
    {
        return (bool) preg_match('/(?:\p{Sc}\s?\d|\d+(?:[.,]\d{2})?\s?(?:usd|eur|gbp|cad|aud)\b)/iu', trim($text));
    }

    private function looksLikeNamePriceRow(DOMElement $element): bool
    {
        return null !== $this->namePriceChildren($element);
    }

    private function safeSourceFragment(DOMElement $element): string
    {
        $html = $this->safeFallbackHtml($element);
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';

        if ( strlen($html) > 500 ) {
            return substr($html, 0, 500) . '...';
        }

        return $html;
    }

    private function childElementCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function interactionCandidates(DOMElement $root): array
    {
        $candidates = array();
        $seen = array();
        foreach ( $root->getElementsByTagName('*') as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            foreach ( $this->interactionCandidatesForElement($element) as $candidate ) {
                $key = json_encode($candidate, JSON_UNESCAPED_SLASHES);
                if ( ! is_string($key) || isset($seen[$key]) ) {
                    continue;
                }
                $seen[$key] = true;
                $candidates[] = $candidate;
                if ( count($candidates) >= self::MAX_INTERACTION_CANDIDATES ) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function interactionCandidatesForElement(DOMElement $element): array
    {
        $tagName = strtolower($element->tagName);
        $role = strtolower($this->attr($element, 'role'));
        $classes = strtolower($this->attr($element, 'class'));
        $id = strtolower($this->attr($element, 'id'));
        $data = $this->safeDataAttributes($element);
        $dataText = strtolower(implode(' ', array_merge(array_keys($data), array_values($data))));
        $nameText = trim($classes . ' ' . $id . ' ' . $dataText);
        $events = $this->eventMetadata($element);
        $actionDataAttributes = array_keys(array_filter($data, static fn (string $value, string $name): bool => preg_match('/^data-(?:action|on|event)$/i', $name) && '' !== trim($value), ARRAY_FILTER_USE_BOTH));
        $hasAriaControl = '' !== trim($this->attr($element, 'aria-controls')) || '' !== trim($this->attr($element, 'aria-expanded'));
        $candidates = array();

        if ( 'details' === $tagName ) {
            $candidates[] = $this->interactionCandidate($element, 'details', 'summary', $this->targetForElement($element), array('details_element'), 'high', 'native_toggle');
        }

        if ( 'form' === $tagName ) {
            $metadata = $this->formMetadata($element);
            $candidates[] = $this->interactionCandidate($element, 'form', 'submit', (string) ($metadata['action'] ?? ''), array_filter(array('form_element', (string) ($metadata['method'] ?? ''))), 'high', 'form_submission');
        }

        if ( in_array($tagName, array('button', 'a'), true) && ( array() !== $events || array() !== $actionDataAttributes || $hasAriaControl ) ) {
            $candidates[] = $this->interactionCandidate($element, 'control', $this->controlTrigger($element, $events), $this->controlledTarget($element), $this->controlEvidence($element, $events, $actionDataAttributes), $hasAriaControl ? 'high' : 'medium', 'client_runtime');
        }

        if ( 'dialog' === $tagName || in_array($role, array('dialog', 'alertdialog'), true) || preg_match('/(?:^|[\s_-])(?:modal|dialog|popup|lightbox)(?:$|[\s_-])/', $nameText) ) {
            $candidates[] = $this->interactionCandidate($element, 'modal', $this->modalTriggerHint($element), $this->targetForElement($element), array_filter(array('modal_like', 'dialog' === $tagName ? 'dialog_element' : '', '' !== $role ? 'role:' . $role : '')), 'medium', 'modal_runtime');
        }

        if ( in_array($role, array('tablist', 'tab', 'tabpanel'), true) ) {
            $candidates[] = $this->interactionCandidate($element, 'tabs', 'tab' === $role ? 'tab_select' : $role, $this->controlledTarget($element), array_filter(array('role:' . $role, '' !== $this->attr($element, 'aria-controls') ? 'aria-controls' : '')), 'high', 'tab_state');
        }

        if ( ( in_array($tagName, array('button', 'a'), true) || '' !== $role ) && ( preg_match('/(?:^|[\s_-])accordion(?:$|[\s_-])/', $nameText) || ( $hasAriaControl && 'tab' !== $role && '' !== trim($this->attr($element, 'aria-expanded')) ) ) ) {
            $candidates[] = $this->interactionCandidate($element, 'accordion', $this->controlTrigger($element, $events), $this->controlledTarget($element), array_filter(array('accordion_like', '' !== $this->attr($element, 'aria-expanded') ? 'aria-expanded' : '', '' !== $this->attr($element, 'aria-controls') ? 'aria-controls' : '')), 'medium', 'accordion_state');
        }

        if ( preg_match('/(?:^|[\s_-])(?:carousel|slider|slideshow|swiper)(?:$|[\s_-])/', $nameText) ) {
            $candidates[] = $this->interactionCandidate($element, 'carousel', $this->carouselTriggerHint($element), $this->targetForElement($element), array('carousel_like'), 'medium', 'carousel_runtime');
        }

        return $candidates;
    }

    /**
     * @param array<int, string> $evidence
     * @return array<string, mixed>
     */
    private function interactionCandidate(DOMElement $element, string $kind, string $trigger, string $target, array $evidence, string $confidence, string $runtimeRequirement): array
    {
        return array_filter(
            array(
                'selector'                => $this->elementSelector($element),
                'kind'                    => $kind,
                'trigger'                 => $trigger,
                'target'                  => $target,
                'evidence'                => array_values(array_unique(array_filter($evidence, static fn (string $value): bool => '' !== $value))),
                'confidence'              => $confidence,
                'runtime_requirement'     => $runtimeRequirement,
                'materialization_hint'    => $this->materializationHintForInteractionKind($kind),
            ),
            static fn (mixed $value): bool => '' !== $value && array() !== $value
        );
    }

    private function targetForElement(DOMElement $element): string
    {
        $id = trim($this->attr($element, 'id'));
        return '' !== $id ? '#' . $id : $this->elementSelector($element);
    }

    private function controlledTarget(DOMElement $element): string
    {
        $target = trim($this->attr($element, 'aria-controls'));
        return '' !== $target ? '#' . ltrim($target, '#') : $this->targetForElement($element);
    }

    /**
     * @param array<int, array<string, string>> $events
     */
    private function controlTrigger(DOMElement $element, array $events): string
    {
        if ( array() !== $events ) {
            return (string) ($events[0]['type'] ?? 'event');
        }

        $type = strtolower($this->attr($element, 'type'));
        return 'submit' === $type ? 'submit' : 'click';
    }

    /**
     * @param array<int, array<string, string>> $events
     * @param array<int, string> $actionDataAttributes
     * @return array<int, string>
     */
    private function controlEvidence(DOMElement $element, array $events, array $actionDataAttributes): array
    {
        $evidence = array();
        foreach ( $events as $event ) {
            $attribute = (string) ($event['attribute'] ?? '');
            if ( '' !== $attribute ) {
                $evidence[] = $attribute;
            }
        }
        foreach ( $actionDataAttributes as $attribute ) {
            $evidence[] = $attribute;
        }
        if ( '' !== trim($this->attr($element, 'aria-controls')) ) {
            $evidence[] = 'aria-controls';
        }
        if ( '' !== trim($this->attr($element, 'aria-expanded')) ) {
            $evidence[] = 'aria-expanded';
        }

        return $evidence;
    }

    private function modalTriggerHint(DOMElement $element): string
    {
        return '' !== trim($this->attr($element, 'open')) ? 'open' : 'show';
    }

    private function carouselTriggerHint(DOMElement $element): string
    {
        return preg_match('/(?:^|[\s_-])(?:next|prev|previous)(?:$|[\s_-])/', strtolower($this->attr($element, 'class'))) ? 'advance' : 'slide';
    }

    private function materializationHintForInteractionKind(string $kind): string
    {
        return match ( $kind ) {
            'details' => 'preserve_native_details',
            'form' => 'preserve_or_replace_form_runtime',
            'tabs' => 'materialize_tab_panels_or_runtime',
            'accordion' => 'materialize_expanded_state_or_runtime',
            'carousel' => 'preserve_static_slides_or_runtime',
            'modal' => 'preserve_dialog_markup_or_runtime',
            default => 'preserve_static_markup_with_runtime_note',
        };
    }

    private function closestTagName(DOMElement $element): ?string
    {
        return $element->parentNode instanceof DOMElement ? strtolower($element->parentNode->tagName) : null;
    }

    private function firstChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && strtolower($child->tagName) === $tagName ) {
                return $child;
            }
        }
        return null;
    }

    private function figureMediaElement(DOMElement $figure, string $tagName): ?DOMElement
    {
        $direct = $this->firstChildElement($figure, $tagName);
        if ( $direct instanceof DOMElement ) {
            return $direct;
        }

        $wrapper = null;
        foreach ( $figure->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'figcaption' === strtolower($child->tagName) ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement || null !== $wrapper ) {
                return null;
            }

            $wrapper = $child;
        }

        if ( ! $wrapper instanceof DOMElement || ! in_array(strtolower($wrapper->tagName), array( 'div', 'span' ), true) || '' !== trim($wrapper->textContent ?? '') ) {
            return null;
        }

        return $this->onlyChildElement($wrapper, $tagName);
    }

    private function figureLinkedMediaAnchor(DOMElement $figure): ?DOMElement
    {
        $anchor = null;
        foreach ( $figure->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'figcaption' === strtolower($child->tagName) ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement || 'a' !== strtolower($child->tagName) || null !== $anchor ) {
                return null;
            }

            $anchor = $child;
        }

        return $anchor instanceof DOMElement && $this->isImageOnlyAnchor($anchor) ? $anchor : null;
    }

    private function onlyChildElement(DOMElement $element, string $tagName): ?DOMElement
    {
        $match = null;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement || strtolower($child->tagName) !== $tagName || null !== $match ) {
                return null;
            }

            $match = $child;
        }

        return $match;
    }

    /**
     * @param array<int, string> $excludedTags
     */
    private function innerHtmlWithoutTags(DOMElement $element, array $excludedTags): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), $excludedTags, true) ) {
                continue;
            }
            $html .= $element->ownerDocument->saveHTML($child);
        }
        return trim($html);
    }

    private function citationFromElement(DOMElement $element): string
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'cite', 'footer', 'figcaption' ), true) ) {
                return $this->innerHtml($child);
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertFigureBlockquote(DOMElement $figure, DOMElement $blockquote, array &$fallbacks): ?array
    {
        $citation = $this->citationFromElement($blockquote);
        $caption = $this->firstChildElement($figure, 'figcaption');
        if ( '' === $citation && $caption instanceof DOMElement ) {
            $citation = $this->innerHtml($caption);
        }

        $value = $this->innerHtmlWithoutTags($blockquote, array( 'cite', 'footer' ));
        if ( '' === trim($this->runtime->stripAllTags($value)) ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->presentationAttributes($figure), array( 'citation' => $citation )), static fn ($value): bool => is_array($value) ? array() !== $value : '' !== $value);

        if ( $this->hasClass($figure, 'wp-block-pullquote') || $this->hasClass($blockquote, 'wp-block-pullquote') ) {
            return $this->createBlock('core/pullquote', array_merge($attrs, array( 'value' => $value )), array(), $figure);
        }

        $innerBlocks = $this->convertChildrenWithoutTags($blockquote, $fallbacks, array( 'cite', 'footer' ));
        if ( array() === $innerBlocks ) {
            $innerBlocks[] = $this->createBlock('core/paragraph', array( 'content' => $value ));
        }

        return $this->createBlock('core/quote', $attrs, $innerBlocks, $figure);
    }

    /**
     * @param array<int, string> $excludedTags
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function convertChildrenWithoutTags(DOMElement $element, array &$fallbacks, array $excludedTags): array
    {
        $blocks = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), $excludedTags, true) ) {
                continue;
            }

            if ( XML_TEXT_NODE === $child->nodeType ) {
                $text = trim($child->textContent ?? '');
                if ( '' !== $text ) {
                    $blocks = array_merge($blocks, $this->convertText($text));
                }
                continue;
            }

            if ( $child instanceof DOMElement ) {
                $block = $this->convertElement($child, $fallbacks, true);
                if ( null !== $block ) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function tableAttributes(DOMElement $table): array
    {
        $attrs = array();
        foreach ( array( 'thead' => 'head', 'tbody' => 'body', 'tfoot' => 'foot' ) as $sectionTag => $attrName ) {
            $rows = array();
            foreach ( $table->getElementsByTagName($sectionTag) as $section ) {
                foreach ( $section->getElementsByTagName('tr') as $row ) {
                    $rows[] = array( 'cells' => $this->tableCells($row) );
                }
            }
            if ( array() !== $rows ) {
                $attrs[$attrName] = $rows;
            }
        }

        if ( empty($attrs['body']) ) {
            $rows = array();
            foreach ( $table->getElementsByTagName('tr') as $row ) {
                if ( in_array($this->closestTagName($row), array( 'thead', 'tfoot' ), true) ) {
                    continue;
                }
                $rows[] = array( 'cells' => $this->tableCells($row) );
            }
            if ( array() !== $rows ) {
                $attrs['body'] = $rows;
            }
        }

        $caption = $this->firstChildElement($table, 'caption');
        if ( $caption instanceof DOMElement ) {
            $attrs['caption'] = $this->innerHtml($caption);
        }

        return $attrs;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function tableCells(DOMElement $row): array
    {
        $cells = array();
        foreach ( $row->childNodes as $cell ) {
            if ( ! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), array( 'td', 'th' ), true) ) {
                continue;
            }
            $cells[] = array(
                'content' => $this->innerHtml($cell),
                'tag'     => strtolower($cell->tagName),
            );
        }
        return $cells;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parameterTableBlockFromElement(DOMElement $element): ?array
    {
        if ( ! $this->hasClass($element, 'param-table') ) {
            return null;
        }

        $rows = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement || ! $this->hasClass($child, 'param-row') ) {
                return null;
            }

            $name = $this->firstDirectChildWithClass($child, 'param-name');
            $type = $this->firstDirectChildWithClass($child, 'param-type');
            $desc = $this->firstDirectChildWithClass($child, 'param-desc');
            if ( ! $name instanceof DOMElement || ! $type instanceof DOMElement || ! $desc instanceof DOMElement ) {
                return null;
            }

            $rows[] = array( 'cells' => array(
                array( 'content' => $this->innerHtml($name), 'tag' => 'td' ),
                array( 'content' => $this->innerHtml($type), 'tag' => 'td' ),
                array( 'content' => $this->innerHtml($desc), 'tag' => 'td' ),
            ) );
        }

        if ( array() === $rows ) {
            return null;
        }

        return $this->createBlock('core/table', array_merge($this->presentationAttributes($element), array(
            'head' => array( array( 'cells' => array(
                array( 'content' => 'Parameter', 'tag' => 'th' ),
                array( 'content' => 'Type', 'tag' => 'th' ),
                array( 'content' => 'Description', 'tag' => 'th' ),
            ) ) ),
            'body' => $rows,
        )), array(), $element);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitionListItems(DOMElement $list): array
    {
        $items = array();
        $term = '';

        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'dt' === $tagName ) {
                $term = $this->innerHtml($child);
                continue;
            }

            if ( 'dd' === $tagName ) {
                $description = $this->innerHtml($child);
                if ( '' === trim($this->runtime->stripAllTags($term . $description)) ) {
                    continue;
                }

                $prefix = '' !== trim($term) ? '<strong>' . $term . '</strong>' : '';
                $items[] = $this->createBlock('core/list-item', array_merge($this->presentationAttributes($child), array(
                    'content' => trim($prefix . ( '' !== $prefix && '' !== trim($description) ? ' ' : '' ) . $description),
                )), array(), $child);
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    private function listItems(DOMElement $list, array &$fallbacks): array
    {
        $items = array();
        foreach ( $list->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'li' !== strtolower($child->tagName) ) {
                continue;
            }

            $nested = array();
            foreach ( $child->childNodes as $itemChild ) {
                if ( $itemChild instanceof DOMElement && in_array(strtolower($itemChild->tagName), array( 'ul', 'ol' ), true) ) {
                    $nestedBlock = $this->convertElement($itemChild, $fallbacks, true);
                    if ( null !== $nestedBlock ) {
                        $nested[] = $nestedBlock;
                    }
                }
            }

            $content = $this->innerHtmlWithoutTags($child, array( 'ul', 'ol' ));
            if ( '' === trim($this->runtime->stripAllTags($content)) && array() === $nested ) {
                continue;
            }

            $items[] = $this->createBlock('core/list-item', array_merge($this->presentationAttributes($child), array( 'content' => $content )), $nested, $child);
        }

        return $items;
    }

    private function safeFallbackHtml(DOMElement $element): string
    {
        $html = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $this->outerHtml($element)) ?? '';
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(?:href|src|xlink:href)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|javascript:[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+srcdoc\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';

        return trim($html);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inlineSvgBlockFromElement(DOMElement $element): ?array
    {
        if ( ! $this->isSafeSvgContent($this->outerHtml($element)) ) {
            return null;
        }

        $html = $this->safeFallbackHtml($element);
        if ( ! $this->isSafeSvgContent($html) ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->presentationAttributes($element), array(
            'url'    => $this->materializeInlineSvgAsset($html, $element),
            'alt'    => $this->inlineSvgAltText($element),
            'title'  => $this->inlineSvgTitleText($element),
            'width'  => $this->attr($element, 'width'),
            'height' => $this->attr($element, 'height'),
        )), static fn ($value): bool => '' !== $value);

        return $this->createBlock('core/image', $attrs, array(), $element);
    }

    private function materializeInlineSvgAsset(string $html, DOMElement $element): string
    {
        $hash = hash('sha256', $html);
        $path = 'assets/inline-svg-' . substr($hash, 0, 16) . '.svg';

        if ( ! isset($this->generatedAssets[$path]) ) {
            $this->generatedAssets[$path] = array(
                'source'           => 'html-inline-svg',
                'path'             => $path,
                'target_path'      => $path,
                'kind'             => 'image',
                'role'             => 'image',
                'media_type'       => 'image/svg+xml',
                'mime_type'        => 'image/svg+xml',
                'bytes'            => strlen($html),
                'binary'           => false,
                'encoding'         => 'text',
                'content_encoding' => 'text',
                'content'          => $html,
                'hash'             => $hash,
                'references'       => array(
                    array_filter(array(
                        'selector'  => $this->elementSelector($element),
                        'element'   => 'svg',
                        'attribute' => 'generated-inline-svg',
                    )),
                ),
            );
        }

        return $path;
    }

    private function inlineSvgAltText(DOMElement $element): string
    {
        if ( 'true' === strtolower($this->attr($element, 'aria-hidden')) || 'presentation' === strtolower($this->attr($element, 'role')) ) {
            return '';
        }

        $ariaLabel = trim($this->attr($element, 'aria-label'));
        if ( '' !== $ariaLabel ) {
            return $ariaLabel;
        }

        return $this->inlineSvgTitleText($element);
    }

    private function inlineSvgTitleText(DOMElement $element): string
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && 'title' === strtolower($child->tagName) ) {
                return trim($child->textContent ?? '');
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureInlineSvgFallback(DOMElement $element, array &$fallbacks): void
    {
        $rawHtml = $this->outerHtml($element);
        $safe = $this->isSafeSvgContent($rawHtml);
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));

        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'inline_svg',
            'reason'          => $safe ? 'inline_svg_fallback' : 'unsafe_inline_svg',
            'diagnostic_code' => $safe ? 'html_inline_svg_fallback' : 'html_unsafe_inline_svg',
            'message'         => $safe ? 'Inline SVG was preserved as sanitized bounded fallback metadata.' : 'Inline SVG contains scriptable content and was preserved only as sanitized bounded fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'svg',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->safeSvgAttributes($element),
            'context'         => $this->sourceContext($element),
            'events'          => $this->eventMetadata($element),
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $this->fallbackProvenance);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureCanvasFallback(DOMElement $element, array &$fallbacks): void
    {
        if ( ! $this->isRuntimeCanvasTarget($element) ) {
            return;
        }

        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $id = trim($this->attr($element, 'id'));
        $this->recordRuntimeIsland($element, 'canvas', 'canvas_requires_runtime', 'canvas_element_and_client_script_execution', array(
            'script_dependency_hint' => '' !== $id
                ? 'Scripts may target #' . $id . ' and call canvas APIs such as getContext(); replacing it with a wrapper block changes runtime behavior.'
                : 'Scripts may target this canvas by selector and call canvas APIs such as getContext(); replacing it with a wrapper block changes runtime behavior.',
            'required_scripts' => $this->requiredScriptsForElement($element),
        ));

        $fallbacks[] = FallbackDiagnostic::build(array_filter(array(
            'type'            => 'html',
            'reason'          => 'canvas_requires_runtime',
            'diagnostic_code' => 'html_canvas_runtime_fallback',
            'message'         => 'Canvas HTML requires a native canvas element and client script runtime; core blocks cannot preserve it without raw HTML.',
            'source_format'   => 'html',
            'tag'             => 'canvas',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->safeCanvasAttributes($element),
            'context'         => $this->sourceContext($element),
            'events'                 => $this->eventMetadata($element),
            'script_dependency_hint' => '' !== $id
                ? 'Scripts may target #' . $id . ' and call canvas APIs such as getContext(); replacing it with a wrapper block changes runtime behavior.'
                : 'Scripts may target this canvas by selector and call canvas APIs such as getContext(); replacing it with a wrapper block changes runtime behavior.',
            'text_length'            => strlen(trim($element->textContent ?? '')),
            'child_count'            => $this->childElementCount($element),
            'html'                   => $boundedHtml['html'],
            'html_bytes'             => $boundedHtml['bytes'],
            'html_truncated'         => $boundedHtml['truncated'],
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value), $this->fallbackProvenance);
    }

    private function isRuntimeCanvasTarget(DOMElement $element): bool
    {
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id && isset($this->runtimeCanvasSelectors['#' . $id]) ) {
            return true;
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class && isset($this->runtimeCanvasSelectors['.' . $class]) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    private function runtimeCanvasSelectorsFromOptions(array $options): array
    {
        return $this->runtimeSelectorsFromOptions($options, 'runtime_canvas_selectors');
    }

    private function isRuntimeDomTarget(DOMElement $element): bool
    {
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id && isset($this->runtimeDomSelectors['#' . $id]) ) {
            return true;
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class && isset($this->runtimeDomSelectors['.' . $class]) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordRuntimeIsland(DOMElement $element, string $kind, string $reason, string $runtimeRequirement, array $metadata = array()): void
    {
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $island = FallbackDiagnostic::withGenericFindingMetadata(array_filter(array_merge(array(
            'kind'                => $kind,
            'selector'            => $this->runtimeIslandSelector($element),
            'tag'                 => strtolower($element->tagName),
            'diagnostic_code'     => 'preserved_runtime_island',
            'preservation_reason' => $reason,
            'runtime_requirement' => $runtimeRequirement,
            'source_snippet'      => $boundedHtml['html'],
            'source_bytes'        => $boundedHtml['bytes'],
            'source_truncated'    => $boundedHtml['truncated'],
            'attributes'          => $this->htmlAttributes($element),
            'context'             => $this->sourceContext($element),
            'required_assets'     => array(),
            'required_scripts'    => array(),
        ), $metadata), static fn (mixed $value): bool => null !== $value && '' !== $value && array() !== $value));

        $key = json_encode(array(
            'kind'     => $island['kind'] ?? '',
            'selector' => $island['selector'] ?? '',
            'snippet'  => $island['source_snippet'] ?? '',
        ), JSON_UNESCAPED_SLASHES);
        foreach ( $this->runtimeIslands as $existing ) {
            $existingKey = json_encode(array(
                'kind'     => $existing['kind'] ?? '',
                'selector' => $existing['selector'] ?? '',
                'snippet'  => $existing['source_snippet'] ?? '',
            ), JSON_UNESCAPED_SLASHES);
            if ( $key === $existingKey ) {
                return;
            }
        }

        $this->runtimeIslands[] = $island;
    }

    private function runtimeIslandSelector(DOMElement $element): string
    {
        $id = trim($this->attr($element, 'id'));
        if ( '' !== $id ) {
            return '#' . $id;
        }

        foreach ( preg_split('/\s+/', trim($this->attr($element, 'class'))) ?: array() as $class ) {
            if ( '' !== $class ) {
                return '.' . $class;
            }
        }

        return $this->elementSelector($element);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requiredScriptsForElement(DOMElement $element): array
    {
        $scripts = $this->runtimeScriptMetadata;

        $owner = $element->ownerDocument;
        if ( ! $owner instanceof DOMDocument ) {
            return $scripts;
        }

        foreach ( $owner->getElementsByTagName('script') as $script ) {
            if ( ! $script instanceof DOMElement || 'runtime' !== $this->scriptRole($script) ) {
                continue;
            }

            $scripts[] = array_filter(array(
                'selector'           => $this->elementSelector($script),
                'attributes'         => $this->safeScriptAttributes($script),
                'script_role'        => 'runtime',
                'script_source_kind' => '' !== trim($this->attr($script, 'src')) ? 'external' : 'inline',
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }

        return $this->dedupeArrayRows($scripts);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function runtimeScriptMetadataFromOptions(array $options): array
    {
        $metadata = array();
        foreach ( $options['runtime_script_metadata'] ?? array() as $script ) {
            if ( ! is_array($script) ) {
                continue;
            }

            $metadata[] = array_filter(array(
                'path'               => is_string($script['path'] ?? null) ? $script['path'] : '',
                'selector'           => is_string($script['selector'] ?? null) ? $script['selector'] : '',
                'attributes'         => is_array($script['attributes'] ?? null) ? $script['attributes'] : array(),
                'script_role'        => 'runtime',
                'script_source_kind' => is_string($script['script_source_kind'] ?? null) ? $script['script_source_kind'] : 'external',
            ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
        }

        return $this->dedupeArrayRows($metadata);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function dedupeArrayRows(array $rows): array
    {
        $seen = array();
        $deduped = array();
        foreach ( $rows as $row ) {
            $key = json_encode($row, JSON_UNESCAPED_SLASHES);
            if ( ! is_string($key) || isset($seen[$key]) ) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    private function runtimeSelectorsFromOptions(array $options, string $key): array
    {
        $selectors = array();
        foreach ( $options[$key] ?? array() as $selector ) {
            if ( is_string($selector) && preg_match('/^[#.][A-Za-z][A-Za-z0-9_-]*$/', $selector) ) {
                $selectors[$selector] = true;
            }
        }

        return $selectors;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function captureScriptFallback(DOMElement $element, array &$fallbacks): void
    {
        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($element));
        $boundedBody = $this->boundedFallbackText(trim($element->textContent ?? ''));
        $scriptRole = $this->scriptRole($element);
        $this->recordRuntimeIsland($element, 'script', 'script_requires_runtime', 'client_script_execution', array(
            'attributes'         => $this->safeScriptAttributes($element),
            'script_role'        => $scriptRole,
            'script_source_kind' => '' !== trim($this->attr($element, 'src')) ? 'external' : 'inline',
        ));
        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'html',
            'reason'          => 'script_requires_runtime',
            'diagnostic_code' => 'html_script_fallback',
            'message'         => 'Script HTML requires runtime behavior and was preserved as scoped safe fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'script',
            'selector'        => $this->elementSelector($element),
            'attributes'      => $this->safeScriptAttributes($element),
            'context'         => $this->sourceContext($element),
            'events'          => $this->eventMetadata($element),
            'script_role'        => $scriptRole,
            'script_source_kind' => '' !== trim($this->attr($element, 'src')) ? 'external' : 'inline',
            'text_length'     => strlen(trim($element->textContent ?? '')),
            'child_count'     => $this->childElementCount($element),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
            'body'            => $boundedBody['text'],
            'body_bytes'      => $boundedBody['bytes'],
            'body_truncated'  => $boundedBody['truncated'],
        ), $this->fallbackProvenance);
    }

    private function captureStaticScriptMetadata(DOMElement $element): bool
    {
        if ( '' !== trim($this->attr($element, 'src')) ) {
            return false;
        }

        $scriptRole = $this->scriptRole($element);
        if ( 'data' !== $scriptRole ) {
            $scriptRole = $this->staticScriptMetadataRole($element);
        }
        if ( null === $scriptRole ) {
            return false;
        }

        $boundedBody = $this->boundedFallbackText(trim($element->textContent ?? ''));
        $this->scriptMetadata[] = array(
            'type'               => 'script_metadata',
            'reason'             => 'script_static_metadata',
            'source_format'      => 'html',
            'tag'                => 'script',
            'selector'           => $this->elementSelector($element),
            'attributes'         => $this->safeScriptAttributes($element),
            'context'            => $this->sourceContext($element),
            'script_role'        => $scriptRole,
            'script_source_kind' => 'inline',
            'body'               => $boundedBody['text'],
            'body_bytes'         => $boundedBody['bytes'],
            'body_truncated'     => $boundedBody['truncated'],
        );

        return true;
    }

    private function scriptRole(DOMElement $element): string
    {
        $type = strtolower(trim($this->attr($element, 'type')));
        if ( '' === $type || in_array($type, array( 'text/javascript', 'application/javascript', 'module' ), true) ) {
            return 'runtime';
        }

        if ( str_starts_with($type, 'application/ld+json') || in_array($type, array( 'application/json', 'importmap', 'speculationrules' ), true) ) {
            return 'data';
        }

        if ( str_starts_with($type, 'text/') && ! in_array($type, array( 'text/javascript', 'text/ecmascript' ), true) ) {
            return 'data';
        }

        return 'runtime';
    }

    private function staticScriptMetadataRole(DOMElement $element): ?string
    {
        $body = trim($element->textContent ?? '');
        if ( '' === $body || $this->scriptBodyHasExecutableRuntimeSignals($body) ) {
            return null;
        }

        $type = strtolower(trim($this->attr($element, 'type')));
        if ( 'module' === $type && $this->scriptBodyContainsOnlyStaticImports($body) ) {
            return 'static_import';
        }

        if ( $this->scriptBodyContainsOnlyStaticConfig($body) ) {
            return 'static_config';
        }

        return null;
    }

    private function scriptBodyHasExecutableRuntimeSignals(string $body): bool
    {
        return 1 === preg_match('/\b(?:document|location|navigator|history|customElements)\b|\b(?:addEventListener|removeEventListener|querySelector|getElementById|appendChild|insertBefore|replaceChild|removeChild|classList|innerHTML|outerHTML|fetch|XMLHttpRequest|setTimeout|setInterval|requestAnimationFrame|import\s*\()\b|\b(?:function|class|new)\b|=>/', $body);
    }

    private function scriptBodyContainsOnlyStaticImports(string $body): bool
    {
        $withoutImports = preg_replace('/^\s*import\s+(?:(?:[\s\S]*?\s+from\s+)?[\'\"][^\'\"]+[\'\"]|[\'\"][^\'\"]+[\'\"])\s*;?\s*/m', '', $body);

        return is_string($withoutImports) && '' === trim($withoutImports);
    }

    private function scriptBodyContainsOnlyStaticConfig(string $body): bool
    {
        $statementPattern = '(?:const|let|var)\s+[A-Za-z_$][A-Za-z0-9_$]*\s*=\s*(?:\{[\s\S]*?\}|\[[\s\S]*?\]|[\'\"][\s\S]*?[\'\"]|[0-9.]+|true|false|null)\s*;?';
        $globalConfigPattern = '(?:window|globalThis)\.[A-Za-z_$][A-Za-z0-9_$.]*(?:CONFIG|Config|config|SETTINGS|Settings|settings|DATA|Data|data|PROPS|Props|props)[A-Za-z0-9_$.]*\s*=\s*(?:\{[\s\S]*?\}|\[[\s\S]*?\]|[\'\"][\s\S]*?[\'\"]|[0-9.]+|true|false|null)\s*;?';

        return 1 === preg_match('/^\s*(?:' . $statementPattern . '|' . $globalConfigPattern . ')+\s*$/', $body);
    }

    /**
     * @return array<string, string>
     */
    private function safeScriptAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'async', 'class', 'defer', 'id', 'src', 'type' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/javascript\s*:/i', $value) ) {
                $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
            }
        }

        return $safe;
    }

    /**
     * @return array<string, string>
     */
    private function safeCanvasAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'aria-label', 'class', 'height', 'id', 'role', 'style', 'title', 'width' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) ) {
                $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
            }
        }

        return $safe;
    }

    /**
     * @return array{html: string, bytes: int, truncated: bool}
     */
    private function boundedFallbackHtml(string $html): array
    {
        $bytes = strlen($html);
        if ( $bytes > 2000 ) {
            return array(
                'html'      => substr($html, 0, 2000) . '...',
                'bytes'     => $bytes,
                'truncated' => true,
            );
        }

        return array(
            'html'      => $html,
            'bytes'     => $bytes,
            'truncated' => false,
        );
    }

    /**
     * @return array{text: string, bytes: int, truncated: bool}
     */
    private function boundedFallbackText(string $text): array
    {
        $bytes = strlen($text);
        if ( $bytes > 2000 ) {
            return array(
                'text'      => substr($text, 0, 2000) . '...',
                'bytes'     => $bytes,
                'truncated' => true,
            );
        }

        return array(
            'text'      => $text,
            'bytes'     => $bytes,
            'truncated' => false,
        );
    }

    /**
     * @return array<string, string>
     */
    private function safeSvgAttributes(DOMElement $element): array
    {
        $attributes = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^on[a-z]+$/i', $name) || preg_match('/javascript\s*:/i', $value) ) {
                continue;
            }
            $attributes[$name] = strlen($value) > 200 ? substr($value, 0, 200) . '...' : $value;
        }

        return $attributes;
    }

    private function isSafeDecorativeSvgElement(DOMElement $element): bool
    {
        if ( ! $this->isSafeSvgContent($this->outerHtml($element)) || ! $this->isPassiveSvgMarkup($element) ) {
            return false;
        }

        $role = strtolower(trim($this->attr($element, 'role')));
        if ( 'true' === strtolower(trim($this->attr($element, 'aria-hidden'))) || in_array($role, array( 'presentation', 'none' ), true) ) {
            return true;
        }

        return $this->hasIconLikeContext($element);
    }

    private function hasIconLikeContext(DOMElement $element): bool
    {
        for ( $current = $element; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null ) {
            $context = strtolower(trim(implode(' ', array(
                $this->attr($current, 'class'),
                $this->attr($current, 'id'),
                $this->attr($current, 'aria-label'),
                $this->attr($current, 'title'),
            ))));

            if ( preg_match('/(?:^|[\s_-])(?:icon|logo)(?:$|[\s_-])/', $context) ) {
                return true;
            }

            if ( in_array(strtolower($current->tagName), array( 'body', 'main', 'article', 'section' ), true) ) {
                return false;
            }
        }

        return false;
    }

    private function isPassiveSvgMarkup(DOMElement $element): bool
    {
        $allowedTags = array_flip(array( 'circle', 'clippath', 'defs', 'desc', 'ellipse', 'g', 'line', 'lineargradient', 'mask', 'path', 'polygon', 'polyline', 'radialgradient', 'rect', 'stop', 'svg', 'title' ));
        $allowedAttributes = array_flip(array( 'aria-hidden', 'class', 'clip-path', 'clip-rule', 'cx', 'cy', 'd', 'fill', 'fill-opacity', 'fill-rule', 'gradienttransform', 'gradientunits', 'height', 'id', 'offset', 'opacity', 'points', 'preserveaspectratio', 'r', 'role', 'rx', 'ry', 'stop-color', 'stop-opacity', 'stroke', 'stroke-dasharray', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity', 'stroke-width', 'style', 'transform', 'viewbox', 'width', 'x', 'x1', 'x2', 'xmlns', 'y', 'y1', 'y2' ));

        foreach ( $element->getElementsByTagName('*') as $child ) {
            if ( ! $child instanceof DOMElement || ! $this->isPassiveSvgElement($child, $allowedTags, $allowedAttributes) ) {
                return false;
            }
        }

        return $this->isPassiveSvgElement($element, $allowedTags, $allowedAttributes);
    }

    /**
     * @param array<string, int> $allowedTags
     * @param array<string, int> $allowedAttributes
     */
    private function isPassiveSvgElement(DOMElement $element, array $allowedTags, array $allowedAttributes): bool
    {
        if ( ! isset($allowedTags[strtolower($element->tagName)]) ) {
            return false;
        }

        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            $name = strtolower($name);
            if ( ! isset($allowedAttributes[$name]) || preg_match('/^on[a-z]+$|(?:^|:)href$/i', $name) || preg_match('/javascript\s*:|\b(?:expression|behavior)\s*:/i', $value) ) {
                return false;
            }
            if ( preg_match('/\burl\s*\((?!\s*["\']?#[-_a-z0-9]+["\']?\s*\))/i', $value) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formControls(DOMElement $form): array
    {
        $controls = array();
        foreach ( $this->formControlElements($form) as $control ) {
            $metadata = $this->formControlMetadata($control);
            if ( array() !== $metadata ) {
                $controls[] = $metadata;
            }
        }

        return $controls;
    }

    /**
     * @return array<string, string>
     */
    private function formMetadata(DOMElement $form): array
    {
        return array_filter(
            array(
                'action'  => $this->attr($form, 'action'),
                'method'  => strtolower($this->attr($form, 'method')),
                'enctype' => $this->attr($form, 'enctype'),
            ),
            static fn (string $value): bool => '' !== $value
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchBlockFromForm(DOMElement $form): ?array
    {
        $method = strtolower(trim($this->attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method ) {
            return null;
        }

        if ( 0 < $form->getElementsByTagName('script')->length || array() !== $this->eventMetadata($form) ) {
            return null;
        }

        $textInput = null;
        $submitControl = null;
        foreach ( $this->formControlElements($form) as $control ) {
            if ( array() !== $this->eventMetadata($control) ) {
                return null;
            }

            $tagName = strtolower($control->tagName);
            $type = $this->formControlType($control);
            if ( 'input' === $tagName && in_array($type, array( 'text', 'search' ), true) ) {
                if ( null !== $textInput ) {
                    return null;
                }
                $textInput = $control;
                continue;
            }

            if ( ( 'button' === $tagName || 'input' === $tagName ) && 'submit' === $type ) {
                if ( null !== $submitControl ) {
                    return null;
                }
                $submitControl = $control;
                continue;
            }

            return null;
        }

        if ( ! $textInput instanceof DOMElement || ! $this->hasSearchFormSignal($form, $textInput) ) {
            return null;
        }

        $inputLabel = $this->formControlLabel($textInput);
        $attrs = array_filter(array_merge(
            $this->presentationAttributes($form),
            $this->searchInputRuntimeAttributes($textInput),
            array(
                'label'       => '' !== $inputLabel ? $inputLabel : 'Search',
                'placeholder' => $this->attr($textInput, 'placeholder'),
                'buttonText'  => $submitControl instanceof DOMElement ? $this->submitButtonText($submitControl) : '',
            )
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));

        return $this->createBlock('core/search', $attrs, array(), $form);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function searchBlockFromStandaloneControl(DOMElement $element): ?array
    {
        if ( 0 < $element->getElementsByTagName('form')->length || 0 < $element->getElementsByTagName('script')->length || array() !== $this->eventMetadata($element) ) {
            return null;
        }

        $inputs = array();
        foreach ( $element->getElementsByTagName('input') as $input ) {
            if ( $input instanceof DOMElement && $input->parentNode === $element && 'search' === $this->formControlType($input) ) {
                $inputs[] = $input;
            }
        }
        if ( 1 !== count($inputs) || array() !== $this->eventMetadata($inputs[0]) ) {
            return null;
        }

        $controls = $this->formControlElements($element);
        if ( 1 !== count($controls) ) {
            return null;
        }

        $searchInput = $inputs[0];
        if ( ! $this->hasStandaloneSearchSignal($element, $searchInput) ) {
            return null;
        }

        $label = $this->formControlLabel($searchInput);
        if ( '' === $label ) {
            $label = $this->attr($searchInput, 'aria-label');
        }
        if ( '' === $label ) {
            $label = $this->attr($searchInput, 'placeholder');
        }
        if ( '' === $label ) {
            $label = 'Search';
        }

        $attrs = array_filter(array_merge(
            $this->presentationAttributes($element),
            $this->searchInputRuntimeAttributes($searchInput),
            array(
                'label'       => $label,
                'placeholder' => $this->attr($searchInput, 'placeholder'),
            )
        ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));

        return $this->createBlock('core/search', $attrs, array(), $element);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableFormBlockFromForm(DOMElement $form, bool $allowFormEvents = false): ?array
    {
        if ( 0 < $form->getElementsByTagName('script')->length || ( ! $allowFormEvents && array() !== $this->eventMetadata($form) ) ) {
            return null;
        }

        $contentBlocks = array();
        $buttonBlocks = array();
        foreach ( $this->formControlElements($form) as $control ) {
            if ( array() !== $this->eventMetadata($control) || ! $this->isReadableFormControl($control) ) {
                return null;
            }

            if ( 'submit' === $this->formControlType($control) ) {
                $buttonBlocks[] = $this->createBlock('core/button', array_merge($this->presentationAttributes($control), array(
                    'text' => $this->runtime->escapeHtml($this->readableSubmitText($control)),
                )), array(), $control);
                continue;
            }

            if ( $this->isRuntimeDomTarget($control) ) {
                $this->recordRuntimeIsland($control, 'control', 'runtime_dom_target', 'client_script_execution', array(
                    'control'          => $this->formControlMetadata($control),
                    'events'           => $this->eventMetadata($control),
                    'required_scripts' => $this->requiredScriptsForElement($control),
                ));
            }

            $readableControlBlock = $this->readableFormControlBlockFromElement($control);
            if ( null !== $readableControlBlock ) {
                $contentBlocks[] = $readableControlBlock;
            }
        }

        if ( array() !== $buttonBlocks ) {
            $contentBlocks[] = $this->createBlock('core/buttons', array(), $buttonBlocks, $form);
        }

        if ( array() === $contentBlocks ) {
            return null;
        }

        return $this->createBlock('core/group', $this->presentationAttributes($form), $contentBlocks, $form);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableFormControlBlockFromElement(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        if ( 'label' === $tagName ) {
            $controls = $this->formControlElements($element);
            if ( array() !== $controls ) {
                $blocks = array();
                foreach ( $controls as $control ) {
                    if ( ! $this->isReadableFormControl($control) || array() !== $this->eventMetadata($control) ) {
                        return null;
                    }

                    $summary = $this->readableFormControlText($control);
                    if ( '' !== $summary ) {
                        $blocks[] = $this->createBlock('core/paragraph', array( 'content' => $summary ), array(), $control);
                    }
                }

                if ( 1 === count($blocks) ) {
                    return $blocks[0];
                }

                return array() !== $blocks ? $this->createBlock('core/group', $this->presentationAttributes($element), $blocks, $element) : null;
            }

            $label = $this->normalizedControlLabelText($element);
            if ( '' === $label ) {
                $label = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
            }

            return '' !== $label ? $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $element) : null;
        }

        if ( ! $this->isFormControlElement($element) || ! $this->isReadableFormControl($element) || array() !== $this->eventMetadata($element) ) {
            return null;
        }

        if ( 'input' === $tagName && 'search' === $this->formControlType($element) ) {
            $label = $this->formControlLabel($element);
            if ( '' === $label ) {
                $label = $this->attr($element, 'aria-label');
            }
            if ( '' === $label ) {
                $label = 'Search';
            }

            $attrs = array_filter(array_merge(
                $this->presentationAttributes($element),
                $this->searchInputRuntimeAttributes($element),
                array(
                    'label'       => $label,
                    'placeholder' => $this->attr($element, 'placeholder'),
                )
            ), static fn (mixed $value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));

            return $this->createBlock('core/search', $attrs, array(), $element);
        }

        if ( $this->isRuntimeDomTarget($element) ) {
            $this->recordRuntimeIsland($element, 'control', 'runtime_dom_target', 'client_script_execution', array(
                'control'          => $this->formControlMetadata($element),
                'events'           => $this->eventMetadata($element),
                'required_scripts' => $this->requiredScriptsForElement($element),
            ));
        }

        if ( 'select' === $tagName ) {
            $selectBlock = $this->readableSelectBlockFromElement($element);
            if ( null !== $selectBlock ) {
                return $selectBlock;
            }
        }

        $summary = $this->readableFormControlText($element);
        if ( '' === $summary ) {
            return null;
        }

        return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $summary )), array(), $element);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readableSelectBlockFromElement(DOMElement $select): ?array
    {
        $label = $this->readableFormControlLabel($select);
        $optionBlocks = array();

        foreach ( $this->selectOptions($select) as $option ) {
            $optionLabel = trim((string) ($option['label'] ?? ''));
            if ( '' === $optionLabel ) {
                continue;
            }

            if ( true === ($option['selected'] ?? false) ) {
                $optionLabel .= ' (selected)';
            }

            $optionBlocks[] = $this->createBlock('core/list-item', array( 'content' => $this->runtime->escapeHtml($optionLabel) ));
        }

        if ( array() === $optionBlocks ) {
            return null;
        }

        return $this->createBlock('core/group', $this->presentationAttributes($select), array(
            $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) ), array(), $select),
            $this->createBlock('core/list', array(), $optionBlocks, $select),
        ), $select);
    }

    /**
     * @return array<string, string>
     */
    private function searchInputRuntimeAttributes(DOMElement $input): array
    {
        if ( ! $this->isRuntimeDomTarget($input) ) {
            return array();
        }

        return array_filter(array(
            'inputAnchor'    => $this->safeAnchor($this->attr($input, 'id')),
            'inputClassName' => $this->promotedClassName($this->attr($input, 'class')),
        ), static fn (string $value): bool => '' !== trim($value));
    }

    private function formRequiresRuntimePreservation(DOMElement $form): bool
    {
        return 0 < $form->getElementsByTagName('script')->length
            || array() !== $this->eventMetadata($form)
            || array() !== $this->formMetadata($form);
    }

    private function isReadableFormControl(DOMElement $control): bool
    {
        $tagName = strtolower($control->tagName);
        if ( in_array($tagName, array( 'select', 'textarea' ), true) ) {
            return true;
        }

        return 'button' === $tagName || ( 'input' === $tagName && in_array($this->formControlType($control), array( 'checkbox', 'email', 'number', 'radio', 'range', 'search', 'submit', 'tel', 'text', 'url' ), true) );
    }

    private function readableFormControlText(DOMElement $control): string
    {
        $label = $this->readableFormControlLabel($control);

        $type = $this->formControlType($control);
        if ( '' === $label ) {
            $label = 'select' === $type ? 'Select option' : ucfirst($type);
        }

        $details = array();
        if ( 'select' === strtolower($control->tagName) ) {
            $options = array();
            $selected = array();
            foreach ( $this->selectOptions($control) as $option ) {
                $optionLabel = (string) ($option['label'] ?? '');
                if ( '' === $optionLabel ) {
                    continue;
                }
                $options[] = $optionLabel;
                if ( true === ($option['selected'] ?? false) ) {
                    $selected[] = $optionLabel;
                }
            }
            if ( array() !== $options ) {
                $details[] = implode(', ', $options);
            }
            if ( array() !== $selected ) {
                $details[] = 'selected: ' . implode(', ', $selected);
            }
        } elseif ( 'range' === $type ) {
            $value = trim($this->attr($control, 'value'));
            if ( '' !== $value ) {
                $details[] = $value;
            }

            $bounds = array();
            foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
                $value = trim($this->attr($control, $attribute));
                if ( '' !== $value ) {
                    $bounds[] = $attribute . ' ' . $value;
                }
            }
            if ( array() !== $bounds ) {
                $details[] = implode(', ', $bounds);
            }
        } else {
            foreach ( array( 'value', 'placeholder' ) as $attribute ) {
                $value = trim($this->attr($control, $attribute));
                if ( '' !== $value ) {
                    $details[] = $value;
                    break;
                }
            }
        }

        $text = $label;
        if ( array() !== $details ) {
            $text .= ': ' . implode(' (', $details) . ( count($details) > 1 ? ')' : '' );
        }
        if ( $control->hasAttribute('required') ) {
            $text .= ' (required)';
        }

        return $this->runtime->escapeHtml($text);
    }

    private function readableFormControlLabel(DOMElement $control): string
    {
        $label = $this->formControlLabel($control);
        if ( '' === $label ) {
            $label = $this->attr($control, 'aria-label');
        }
        if ( '' === $label ) {
            $label = $this->attr($control, 'placeholder');
        }
        if ( '' === $label ) {
            $label = $this->attr($control, 'name');
        }

        $type = $this->formControlType($control);
        if ( '' === $label ) {
            return 'select' === $type ? 'Select option' : ucfirst($type);
        }

        return $label;
    }

    private function readableSubmitText(DOMElement $control): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        $value = trim($this->attr($control, 'value'));
        return '' !== $value ? $value : 'Submit';
    }

    /**
     * @return array<int, DOMElement>
     */
    private function formControlElements(DOMElement $form): array
    {
        $controls = array();
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( $control instanceof DOMElement && $this->isFormControlElement($control) ) {
                $controls[] = $control;
            }
        }

        return $controls;
    }

    private function hasSearchFormSignal(DOMElement $form, DOMElement $input): bool
    {
        if ( 'search' === $this->formControlType($input) || 'search' === strtolower(trim($this->attr($form, 'role'))) ) {
            return true;
        }

        $queryName = strtolower(trim($this->attr($input, 'name')));
        if ( in_array($queryName, array( 's', 'q', 'query', 'search' ), true) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            $this->attr($form, 'action'),
            $this->attr($form, 'aria-label'),
            $this->attr($form, 'id'),
            $this->attr($form, 'class'),
        )));

        return str_contains($haystack, 'search');
    }

    private function hasStandaloneSearchSignal(DOMElement $element, DOMElement $input): bool
    {
        if ( 'search' === $this->formControlType($input) || 'search' === strtolower(trim($this->attr($element, 'role'))) ) {
            return true;
        }

        $haystack = strtolower(implode(' ', array(
            $this->attr($element, 'aria-label'),
            $this->attr($element, 'id'),
            $this->attr($element, 'class'),
            $this->attr($input, 'aria-label'),
            $this->attr($input, 'id'),
            $this->attr($input, 'class'),
            $this->attr($input, 'name'),
            $this->attr($input, 'placeholder'),
        )));

        return str_contains($haystack, 'search');
    }

    private function submitButtonText(DOMElement $control): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $control->textContent ?? '') ?? '');
        if ( '' !== $text ) {
            return $text;
        }

        $value = trim($this->attr($control, 'value'));
        return '' !== $value ? $value : 'Search';
    }

    /**
     * @return array<string, mixed>
     */
    private function formControlMetadata(DOMElement $control): array
    {
        if ( ! $this->isFormControlElement($control) ) {
            return array();
        }

        $tagName = strtolower($control->tagName);
        $metadata = array_filter(array(
            'tag'         => $tagName,
            'selector'    => $this->elementSelector($control),
            'name'        => $this->attr($control, 'name'),
            'type'        => $this->formControlType($control),
            'label'       => $this->formControlLabel($control),
            'placeholder' => $this->attr($control, 'placeholder'),
        ), static fn (string $value): bool => '' !== $value);

        if ( $control->hasAttribute('required') ) {
            $metadata['required'] = true;
        }
        if ( $control->hasAttribute('disabled') ) {
            $metadata['disabled'] = true;
        }

        $value = $this->attr($control, 'value');
        if ( '' !== $value && 'select' !== $tagName ) {
            $metadata['value'] = $value;
        }

        if ( 'select' === $tagName ) {
            $options = $this->selectOptions($control);
            if ( array() !== $options ) {
                $metadata['options'] = $options;
            }
        }

        return $metadata;
    }

    private function isFormControlElement(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'button', 'input', 'select', 'textarea' ), true);
    }

    private function formControlType(DOMElement $control): string
    {
        $tagName = strtolower($control->tagName);
        if ( 'input' === $tagName ) {
            $type = strtolower(trim($this->attr($control, 'type')));
            return '' !== $type ? $type : 'text';
        }
        if ( 'button' === $tagName ) {
            $type = strtolower(trim($this->attr($control, 'type')));
            return '' !== $type ? $type : 'submit';
        }
        if ( 'select' === $tagName && $control->hasAttribute('multiple') ) {
            return 'select-multiple';
        }

        return $tagName;
    }

    private function formControlLabel(DOMElement $control): string
    {
        $ariaLabel = trim($this->attr($control, 'aria-label'));
        if ( '' !== $ariaLabel ) {
            return $ariaLabel;
        }

        $id = $this->attr($control, 'id');
        if ( '' !== $id && $control->ownerDocument instanceof DOMDocument ) {
            foreach ( $control->ownerDocument->getElementsByTagName('label') as $label ) {
                if ( $label instanceof DOMElement && $id === $this->attr($label, 'for') ) {
                    return $this->normalizedControlLabelText($label);
                }
            }
        }

        for ( $parent = $control->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode ) {
            if ( 'label' === strtolower($parent->tagName) ) {
                return $this->normalizedControlLabelText($parent);
            }
        }

        return '';
    }

    private function normalizedControlLabelText(DOMElement $label): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->labelTextWithoutControls($label)) ?? '');
    }

    private function labelTextWithoutControls(DOMNode $node): string
    {
        if ( XML_TEXT_NODE === $node->nodeType ) {
            return $node->textContent ?? '';
        }

        if ( $node instanceof DOMElement && $this->isFormControlElement($node) ) {
            return '';
        }

        $text = '';
        foreach ( $node->childNodes as $child ) {
            $text .= $this->labelTextWithoutControls($child);
        }

        return $text;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function selectOptions(DOMElement $select): array
    {
        $options = array();
        foreach ( $select->getElementsByTagName('option') as $option ) {
            if ( ! $option instanceof DOMElement ) {
                continue;
            }

            $value = $this->attr($option, 'value');
            $optionMetadata = array(
                'label' => trim(preg_replace('/\s+/', ' ', $option->textContent ?? '') ?? ''),
                'value' => '' !== $value ? $value : trim($option->textContent ?? ''),
            );
            if ( $option->hasAttribute('selected') ) {
                $optionMetadata['selected'] = true;
            }
            if ( $option->hasAttribute('disabled') ) {
                $optionMetadata['disabled'] = true;
            }

            $options[] = $optionMetadata;
        }

        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function galleryBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        $images = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }

            $tagName = strtolower($child->tagName);
            if ( 'figcaption' === $tagName ) {
                continue;
            }

            if ( 'figure' === $tagName ) {
                $linkedMedia = $this->figureLinkedMediaAnchor($child);
                if ( $linkedMedia instanceof DOMElement ) {
                    $linkedPicture = $this->firstChildElement($linkedMedia, 'picture');
                    if ( $linkedPicture instanceof DOMElement ) {
                        $images[] = $this->convertPictureElement($linkedPicture, $child, $linkedMedia);
                        continue;
                    }

                    $linkedImage = $this->firstChildElement($linkedMedia, 'img');
                    if ( $linkedImage instanceof DOMElement ) {
                        $images[] = $this->convertImageElement($linkedImage, $child, null, $linkedMedia);
                        continue;
                    }
                }

                $image = $this->firstChildElement($child, 'img');
                if ( $image instanceof DOMElement ) {
                    $images[] = $this->convertImageElement($image, $child);
                    continue;
                }

                $picture = $this->firstChildElement($child, 'picture');
                if ( $picture instanceof DOMElement ) {
                    $images[] = $this->convertPictureElement($picture, $child);
                    continue;
                }
            }

            if ( 'img' === $tagName ) {
                $images[] = $this->convertImageElement($child);
                continue;
            }

            if ( 'picture' === $tagName ) {
                $images[] = $this->convertPictureElement($child);
                continue;
            }

            return null;
        }

        $images = array_values(array_filter($images));
        if ( count($images) < 2 ) {
            return null;
        }

        $attrs = $this->presentationAttributes($element);
        $caption = $this->firstChildElement($element, 'figcaption');
        if ( $caption instanceof DOMElement ) {
            $attrs['caption'] = $this->innerHtml($caption);
        }

        return $this->createBlock('core/gallery', array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value)), $images, $element);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backgroundImageBlockFromElement(DOMElement $element): ?array
    {
        $url = $this->backgroundImageUrlFromStyle($this->mergedPresentationStyle($element));
        if ( '' === $url ) {
            return null;
        }

        return $this->createBlock('core/image', array_filter(array(
            'url'       => $this->resolvedAssetImageUrl($url),
            'alt'       => $this->backgroundImageAlt($element),
            'className' => 'blocks-engine-background-image',
        ), static fn (string $value): bool => '' !== $value), array(), $element);
    }

    private function backgroundImageUrlFromStyle(string $style): string
    {
        if ( ! preg_match('/(?:^|;)\s*background(?:-image)?\s*:\s*[^;]*url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', $style, $matches) ) {
            return '';
        }

        $url = trim(html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    private function backgroundImageAlt(DOMElement $element): string
    {
        foreach ( array( 'aria-label', 'title' ) as $attribute ) {
            $value = trim($this->attr($element, $attribute));
            if ( '' !== $value ) {
                return $value;
            }
        }

        return '';
    }

    private function hasDirectMediaChild(DOMElement $element): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && in_array(strtolower($child->tagName), array( 'img', 'picture', 'svg', 'video', 'audio' ), true) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function namePriceRowBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        $children = $this->namePriceChildren($element);
        if ( null === $children ) {
            return null;
        }

        $rowFallbacks = array();
        $columns = array();
        foreach ( $children as $child ) {
            $converted = array_filter(array( $this->convertElement($child, $rowFallbacks, true) ));
            if ( array() === $converted ) {
                return null;
            }

            $columns[] = $this->createBlock('core/column', $this->presentationAttributes($child), $converted, $child);
        }
        array_push($fallbacks, ...$rowFallbacks);

        return $this->createBlock('core/columns', $this->presentationAttributes($element), $columns, $element);
    }

    /**
     * @return array<int, DOMElement>|null
     */
    private function namePriceChildren(DOMElement $element): ?array
    {
        if ( ! in_array(strtolower($element->tagName), array( 'div', 'header', 'section' ), true) ) {
            return null;
        }

        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }
            if ( ! $child instanceof DOMElement ) {
                return null;
            }
            if ( ! $this->isInlineCommerceRowChild($child) ) {
                return null;
            }
            $children[] = $child;
        }

        if ( 2 !== count($children) ) {
            return null;
        }

        $first = $children[0];
        $second = $children[1];
        $firstIsPrice = $this->isPriceElement($first);
        $secondIsPrice = $this->isPriceElement($second);
        if ( $firstIsPrice === $secondIsPrice ) {
            return null;
        }

        $other = $firstIsPrice ? $second : $first;
        if ( ! $this->isNameElement($other) && ! $this->hasCommerceToken($element, array( 'menu', 'product', 'pricing', 'price', 'plan', 'tier', 'dish', 'item', 'row' )) ) {
            return null;
        }

        return $children;
    }

    private function isInlineCommerceRowChild(DOMElement $element): bool
    {
        $tagName = strtolower($element->tagName);
        if ( in_array($tagName, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'span', 'strong', 'em', 'small' ), true) ) {
            return ! $this->hasBlockContentChildren($element);
        }

        return false;
    }

    private function isPriceElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'price', 'amount', 'cost' )) || $this->looksLikePriceText($element->textContent ?? '');
    }

    private function isNameElement(DOMElement $element): bool
    {
        return $this->hasCommerceToken($element, array( 'name', 'title', 'product', 'dish', 'item', 'plan', 'tier' )) || preg_match('/^h[1-6]$/', strtolower($element->tagName));
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function columnsBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        if ( ! $this->looksLikeColumnsContainer($element) ) {
            return null;
        }

		$elementChildren = array();
		foreach ( $element->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
				continue;
            }

            if ( ! $child instanceof DOMElement ) {
                return null;
            }
			$elementChildren[] = $child;
		}

		if ( count($elementChildren) < 2 ) {
			return null;
		}

		$columns = array();
		$columnFallbacks = array();
		foreach ( $elementChildren as $child ) {
			$children = $this->isColumnWrapperElement($child)
				? $this->convertChildren($child, $columnFallbacks, true)
				: array_filter(array( $this->convertElement($child, $columnFallbacks, true) ));
			$columns[] = $this->createBlock('core/column', $this->presentationAttributes($child), $children, $child);
		}
		array_push($fallbacks, ...$columnFallbacks);

		return $this->createBlock('core/columns', $this->presentationAttributes($element), $columns, $element);
	}

    private function isColumnWrapperElement(DOMElement $element): bool
    {
        return in_array(strtolower($element->tagName), array( 'article', 'aside', 'div', 'footer', 'header', 'main', 'nav', 'section' ), true);
    }

    private function looksLikeColumnsContainer(DOMElement $element): bool
    {
        if ( $this->hasClass($element, 'wp-block-columns') ) {
            return true;
        }

        $className = strtolower($this->attr($element, 'class'));
        $style = strtolower($this->attr($element, 'style'));

        if ( preg_match('/(?:^|;)\s*display\s*:\s*(?:inline-)?flex\b/', $style) && $this->hasDirectChildElement($element, 'svg') ) {
            return false;
        }

        return (bool) preg_match('/(?:^|[\s_-])columns?(?:$|[\s_-])/', $className)
            || ( $this->looksLikeSplitLayout($element) && 1 < $this->directElementChildCount($element) )
            || ( $this->looksLikeDocumentationLayout($element) && $this->hasSidebarAndContentChildren($element) )
            || preg_match('/(?:^|;)\s*(?:display\s*:\s*(?:inline-)?flex|grid-template-columns\s*:)/', $style);
    }

    private function looksLikeSplitLayout(DOMElement $element): bool
    {
        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id')));

        return (bool) preg_match('/(?:^|[\s_-])(?:split|two[\s_-]?col|media[\s_-]?text|text[\s_-]?media|feature[\s_-]?row|hero[\s_-]?(?:inner|grid|content|layout)|content[\s_-]?grid)(?:$|[\s_-])/', $name);
    }

    private function directElementChildCount(DOMElement $element): int
    {
        $count = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                ++$count;
            }
        }

        return $count;
    }

    private function looksLikeDocumentationLayout(DOMElement $element): bool
    {
        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id')));
        return (bool) preg_match('/(?:^|[\s_-])(?:docs?|documentation|article|content)(?:[\s_-]+(?:layout|shell|page|with[\s_-]+sidebar)|$)|(?:^|[\s_-])sidebar[\s_-]+layout(?:$|[\s_-])/', $name);
    }

    private function hasSidebarAndContentChildren(DOMElement $element): bool
    {
        $hasSidebar = false;
        $hasContent = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $name = strtolower(trim($child->tagName . ' ' . $this->attr($child, 'class') . ' ' . $this->attr($child, 'id') . ' ' . $this->attr($child, 'role')));
            $hasSidebar = $hasSidebar || (bool) preg_match('/(?:^|[\s_-])(?:aside|sidebar|toc|table[\s_-]+of[\s_-]+contents)(?:$|[\s_-])/', $name);
            $hasContent = $hasContent || in_array(strtolower($child->tagName), array( 'article', 'main', 'section' ), true)
                || (bool) preg_match('/(?:^|[\s_-])(?:main|content|article|docs?[\s_-]+content|documentation[\s_-]+content)(?:$|[\s_-])/', $name);
        }

        return $hasSidebar && $hasContent;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function navigationSectionBlockFromElement(DOMElement $element): ?array
    {
        $heading = null;
        $anchors = array();
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' === trim($child->textContent ?? '') ) {
                continue;
            }

            if ( $child instanceof DOMElement && $this->isNavigationSectionHeading($child) ) {
                if ( $heading instanceof DOMElement ) {
                    return null;
                }
                $heading = $child;
                continue;
            }

            if ( $child instanceof DOMElement && 'a' === strtolower($child->tagName) && '' !== trim($child->textContent ?? '') ) {
                $anchors[] = $child;
                continue;
            }

            return null;
        }

        if ( ! $heading instanceof DOMElement || array() === $anchors ) {
            return null;
        }

        if ( ! $this->hasNavigationContainerSignal($element) && ! $this->hasSoftNavigationSectionHeadingSignal($heading) ) {
            return null;
        }

        $sectionFallbacks = array();
        $blocks = array( $this->convertElement($heading, $sectionFallbacks, true) );
        $links = array();
        foreach ( $anchors as $anchor ) {
            $links[] = $this->createBlock('core/navigation-link', array_filter(array(
                'label' => $this->innerHtml($anchor),
                'url'   => $this->safeNavigationUrl($this->attr($anchor, 'href')),
                'kind'  => 'custom',
            ), static fn ($value): bool => '' !== $value), array(), $anchor);
        }
        $blocks[] = $this->createBlock('core/navigation', array(), $links, $element);

        return $this->createBlock('core/group', $this->presentationAttributes($element), array_values(array_filter($blocks)), $element);
    }

    private function isNavigationSectionHeading(DOMElement $element): bool
    {
        if ( preg_match('/^h[1-6]$/i', $element->tagName) ) {
            return true;
        }

        if ( ! in_array(strtolower($element->tagName), array( 'div', 'p', 'span' ), true) || '' === trim($element->textContent ?? '') ) {
            return false;
        }

        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id') . ' ' . $this->attr($element, 'role') . ' ' . $this->attr($element, 'aria-label')));
        return (bool) preg_match('/(?:^|[\s_-])(?:heading|label|title)(?:$|[\s_-])/', $name);
    }

    private function hasSoftNavigationSectionHeadingSignal(DOMElement $element): bool
    {
        return ! preg_match('/^h[1-6]$/i', $element->tagName) && $this->isNavigationSectionHeading($element);
    }

    private function hasNavigationContainerSignal(DOMElement $element): bool
    {
        if ( 'navigation' === strtolower($this->attr($element, 'role')) ) {
            return true;
        }

        $name = strtolower(trim($this->attr($element, 'class') . ' ' . $this->attr($element, 'id')));
        return (bool) preg_match('/(?:^|[\s_-])(?:nav|navbar|navigation|menu|links)(?:$|[\s_-])/', $name);
    }

    private function safeNavigationUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    private function firstDirectChildWithClass(DOMElement $element, string $className): ?DOMElement
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $this->hasClass($child, $className) ) {
                return $child;
            }
        }

        return null;
    }

    private function hasDirectChildElement(DOMElement $element, string $tagName): bool
    {
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement && $tagName === strtolower($child->tagName) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function spacerBlockFromElement(DOMElement $element): ?array
    {
        if ( '' !== trim($element->textContent ?? '') || 0 !== $this->childElementCount($element) ) {
            return null;
        }

        $height = $this->spacerHeightFromStyle($this->attr($element, 'style'));
        if ( '' === $height ) {
            return null;
        }

        if ( ! $this->hasClass($element, 'wp-block-spacer') && ! $this->hasClass($element, 'spacer') ) {
            return null;
        }

        $attrs = $this->presentationAttributes($element);
        $attrs['height'] = $height;
        unset($attrs['style']);

        return $this->createBlock('core/spacer', $attrs, array(), $element);
    }

    private function spacerHeightFromStyle(string $style): string
    {
        if ( ! preg_match('/(?:^|;)\s*height\s*:\s*([^;]+)/i', $style, $matches) ) {
            return '';
        }

        $height = trim($matches[1]);
        if ( '' === $height || preg_match('/[{}]/', $height) || strlen($height) > 80 ) {
            return '';
        }

        return $height;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function placeholderMediaBlockFromElement(DOMElement $element): ?array
    {
        if ( ! $this->isPlaceholderMediaElement($element) ) {
            return null;
        }

        $attrs = $this->presentationAttributes($element);
        $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), 'blocks-engine-placeholder-media');

        $style = trim((string) ($attrs['style'] ?? ''));
        $ratio = $this->placeholderAspectRatio($element);
        if ( '' !== $ratio && ! preg_match('/(?:^|;)\s*aspect-ratio\s*:/i', $style) ) {
            $style = rtrim($style, ';') . ( '' !== $style ? ';' : '' ) . 'aspect-ratio:' . $ratio;
        }
        if ( '' !== $style ) {
            $attrs['style'] = $style;
        }

        $label = $this->placeholderLabel($element);
        $children = '' !== $label ? array( $this->createBlock('core/paragraph', array( 'content' => $this->runtime->escapeHtml($label) )) ) : array();

        return $this->createBlock('core/group', array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value)), $children, $element);
    }

    private function isPlaceholderMediaElement(DOMElement $element): bool
    {
        $className = strtolower($this->attr($element, 'class'));
        if ( ! preg_match('/(?:^|\s)(?:ph|placeholder|media-placeholder|image-placeholder|video-placeholder)(?:\s|$)/', $className) && ! preg_match('/(?:^|\s)ratio-[0-9]+(?:x|:|-)[0-9]+(?:\s|$)/', $className) ) {
            return false;
        }

        return '' !== $this->placeholderAspectRatio($element)
            || preg_match('/(?:^|;)\s*aspect-ratio\s*:/i', $this->attr($element, 'style'))
            || preg_match('/(?:^|\s)(?:media|image|video|thumb|thumbnail|poster|avatar)(?:\s|$)/', $className);
    }

    private function placeholderAspectRatio(DOMElement $element): string
    {
        if ( preg_match('/(?:^|;)\s*aspect-ratio\s*:\s*([0-9.]+\s*\/\s*[0-9.]+|[0-9.]+)\s*(?:;|$)/i', $this->attr($element, 'style'), $styleMatch) ) {
            return preg_replace('/\s+/', '', $styleMatch[1]) ?? '';
        }

        $className = strtolower($this->attr($element, 'class'));
        if ( preg_match('/(?:^|\s)ratio-([0-9]+)(?:x|:|-)([0-9]+)(?:\s|$)/', $className, $classMatch) ) {
            return $classMatch[1] . '/' . $classMatch[2];
        }

        return '';
    }

    private function placeholderLabel(DOMElement $element): string
    {
        foreach ( $element->getElementsByTagName('span') as $span ) {
            if ( ! $span instanceof DOMElement ) {
                continue;
            }

            $className = strtolower($this->attr($span, 'class'));
            if ( preg_match('/(?:^|\s)(?:label|caption|placeholder-label)(?:\s|$)/', $className) ) {
                return trim(preg_replace('/\s+/', ' ', $span->textContent ?? '') ?? '');
            }
        }

        $directText = trim(preg_replace('/\s+/', ' ', $element->textContent ?? '') ?? '');
        return strlen($directText) <= 80 ? $directText : '';
    }

    private function convertMediaElement(DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);
        $src = $this->safeMediaUrl($this->attr($element, 'src'));
        if ( '' === $src ) {
            $source = $this->firstChildElement($element, 'source');
            $src = $source instanceof DOMElement ? $this->safeMediaUrl($this->attr($source, 'src')) : '';
        }
        if ( '' === $src ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->presentationAttributes($element), array(
            'src'      => $src,
            'poster'   => 'video' === $tagName ? $this->attr($element, 'poster') : '',
            'preload'  => $this->attr($element, 'preload'),
            'width'    => $this->attr($element, 'width'),
            'height'   => $this->attr($element, 'height'),
            'controls' => $element->hasAttribute('controls'),
        )), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);

        return $this->createBlock('core/' . $tagName, $attrs, array(), $element);
    }

    private function safeMediaUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    private function fileBlockFromAnchor(DOMElement $anchor): ?array
    {
        $href = $this->safeFileUrl($this->attr($anchor, 'href'));
        if ( '' === $href ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->presentationAttributes($anchor), array(
            'href'               => $href,
            'url'                => $href,
            'text'               => $this->innerHtml($anchor),
            'showDownloadButton' => $anchor->hasAttribute('download'),
        )), static fn (mixed $value): bool => is_bool($value) ? $value : '' !== $value);

        return $this->createBlock('core/file', $attrs, array(), $anchor);
    }

    private function safeFileUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, array( 'doc', 'docx', 'odp', 'ods', 'odt', 'pdf', 'ppt', 'pptx', 'rtf', 'txt', 'xls', 'xlsx', 'zip' ), true) ? $url : '';
    }

    private function convertPictureElement(DOMElement $picture, ?DOMElement $figure = null, ?DOMElement $link = null): ?array
    {
        $image = $this->firstChildElement($picture, 'img');
        if ( ! $image instanceof DOMElement ) {
            return null;
        }

        return $this->convertImageElement($image, $figure ?? $picture, $picture, $link);
    }

    private function imageBlockFromAnchor(DOMElement $anchor): ?array
    {
        $href = $this->safeLinkUrl($this->attr($anchor, 'href'));
        if ( '' === $href || ! $this->isImageOnlyAnchor($anchor) ) {
            return null;
        }

        $picture = $this->firstChildElement($anchor, 'picture');
        if ( $picture instanceof DOMElement ) {
            $image = $this->firstChildElement($picture, 'img');
            return $image instanceof DOMElement ? $this->convertImageElement($image, null, $picture, $anchor) : null;
        }

        $image = $this->firstChildElement($anchor, 'img');
        return $image instanceof DOMElement ? $this->convertImageElement($image, null, null, $anchor) : null;
    }

    private function isImageOnlyAnchor(DOMElement $anchor): bool
    {
        $imageChildren = 0;
        foreach ( $anchor->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                if ( ! in_array(strtolower($child->tagName), array( 'img', 'picture' ), true) ) {
                    return false;
                }
                ++$imageChildren;
                continue;
            }

            if ( '' !== trim($child->textContent ?? '') ) {
                return false;
            }
        }

        return 1 === $imageChildren;
    }

    private function convertImageElement(DOMElement $image, ?DOMElement $figure = null, ?DOMElement $picture = null, ?DOMElement $link = null): ?array
    {
        $originalUrl = $this->safeImageUrl($this->attr($image, 'src'));
        $url = $this->resolvedAssetImageUrl($originalUrl);
        if ( '' === $url ) {
            return null;
        }

        $attrs = $this->imagePresentationAttributes($image, $figure);
        if ( null !== $picture && ! $figure instanceof DOMElement ) {
            $attrs = array_merge($this->presentationAttributes($picture), $attrs);
        }
        $width = $this->attr($image, 'width');
        $height = $this->attr($image, 'height');
        $sourceAttrs = $picture instanceof DOMElement ? $this->pictureSourceAttributes($picture) : array();
        if ( '' !== $width || '' !== $height ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), 'is-resized');
        }

        $attrs = array_filter(array_merge($attrs, array(
            'url'    => $url,
            'alt'    => $this->attr($image, 'alt'),
            'title'  => $this->attr($image, 'title'),
            'srcset' => $this->resolvedAssetImageSrcset('' !== $this->attr($image, 'srcset') ? $this->attr($image, 'srcset') : (string) ($sourceAttrs['srcset'] ?? '')),
            'sizes'  => '' !== $this->attr($image, 'sizes') ? $this->attr($image, 'sizes') : (string) ($sourceAttrs['sizes'] ?? ''),
            'width'  => $width,
            'height' => $height,
        )), static fn ($value): bool => '' !== $value);

        $attrs = array_filter(array_merge($attrs, $this->imageIdentityAttributes($image, $figure)), static fn ($value): bool => '' !== $value);
        $attrs = array_filter(array_merge($attrs, $this->assetMetadataImageAttributes($originalUrl)), static fn ($value): bool => '' !== $value);

        if ( $figure instanceof DOMElement ) {
            $caption = $this->firstChildElement($figure, 'figcaption');
            if ( $caption instanceof DOMElement ) {
                $attrs['caption'] = $this->innerHtml($caption);
            }
        }

        if ( $link instanceof DOMElement ) {
            $attrs = array_filter(array_merge($attrs, $this->imageLinkAttributes($link)), static fn ($value): bool => '' !== $value);
        }

        return $this->createBlock('core/image', $attrs, array(), $figure ?? $image);
    }

    /**
     * @return array<string, string>
     */
    private function imageLinkAttributes(DOMElement $link): array
    {
        $attrs = array(
            'href'            => $this->safeLinkUrl($this->attr($link, 'href')),
            'linkDestination' => 'custom',
            'linkAnchor'      => $this->safeAnchor($this->attr($link, 'id')),
            'linkTarget'      => $this->attr($link, 'target'),
            'rel'             => $this->attr($link, 'rel'),
            'linkClass'       => $this->attr($link, 'class'),
            'linkAriaLabel'   => $this->attr($link, 'aria-label'),
            'linkAriaHidden'  => $this->attr($link, 'aria-hidden'),
            'linkTabIndex'    => $this->attr($link, 'tabindex'),
        );

        return array_filter($attrs, static fn (string $value): bool => '' !== trim($value));
    }

    private function safeLinkUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $url;
    }

    /**
     * @return array<string, string>
     */
    private function cardLinkAttributes(DOMElement $anchor): array
    {
        $href = $this->safeLinkUrl($this->attr($anchor, 'href'));
        if ( '' === $href ) {
            return array();
        }

        return array_filter(array(
            'href'      => $href,
            'target'    => $this->attr($anchor, 'target'),
            'rel'       => $this->attr($anchor, 'rel'),
            'ariaLabel' => $this->attr($anchor, 'aria-label'),
        ), static fn (string $value): bool => '' !== trim($value));
    }

    /**
     * @return array<string, string>
     */
    private function pictureSourceAttributes(DOMElement $picture): array
    {
        foreach ( $picture->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || 'source' !== strtolower($child->tagName) ) {
                continue;
            }

            $srcset = $this->attr($child, 'srcset');
            if ( '' === $srcset || preg_match('/javascript\s*:/i', $srcset) ) {
                continue;
            }

            return array_filter(array(
                'srcset' => $srcset,
                'sizes'  => $this->attr($child, 'sizes'),
            ), static fn (string $value): bool => '' !== $value);
        }

        return array();
    }

    private function safeEmbedUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || ! preg_match('#^https?://#i', $url) ) {
            return '';
        }

        return preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ? '' : $url;
    }

    private function canonicalEmbedUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ( ( str_ends_with($host, 'youtube.com') || str_ends_with($host, 'youtube-nocookie.com') ) && preg_match('~^/embed/([^/?#]+)~', $path, $matches) ) {
            return 'https://www.youtube.com/watch?v=' . $matches[1];
        }

        if ( 'youtu.be' === $host && '' !== trim($path, '/') ) {
            return 'https://www.youtube.com/watch?v=' . trim($path, '/');
        }

        if ( str_ends_with($host, 'vimeo.com') && preg_match('#/(?:video/)?(\d+)#', $path, $matches) ) {
            return 'https://vimeo.com/' . $matches[1];
        }

        return $url;
    }

    private function embedProviderSlug(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ( str_ends_with($host, 'youtube.com') || str_ends_with($host, 'youtube-nocookie.com') || 'youtu.be' === $host ) {
            return 'youtube';
        }
        if ( str_ends_with($host, 'vimeo.com') ) {
            return 'vimeo';
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function safeEmbedAttributes(DOMElement $element): array
    {
        $safe = array();
        $allowed = array_flip(array( 'allow', 'allowfullscreen', 'class', 'height', 'loading', 'referrerpolicy', 'sandbox', 'src', 'title', 'width' ));
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( isset($allowed[$name]) && ! preg_match('/javascript\s*:/i', $value) ) {
                $safe[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
            }
        }

        return $safe;
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertIframeElement(DOMElement $iframe, array &$fallbacks): ?array
    {
        $url = $this->safeEmbedUrl($this->attr($iframe, 'src'));
        $providerNameSlug = '' === $url ? '' : $this->embedProviderSlug($url);
        if ( '' !== $providerNameSlug ) {
            return $this->createBlock('core/embed', array_filter(array_merge($this->presentationAttributes($iframe), array(
                'url'              => $this->canonicalEmbedUrl($url),
                'type'             => 'video',
                'providerNameSlug' => $providerNameSlug,
            )), static fn ($value): bool => '' !== $value), array(), $iframe);
        }

        $boundedHtml = $this->boundedFallbackHtml($this->safeFallbackHtml($iframe));
        $this->recordRuntimeIsland($iframe, 'iframe', 'iframe_requires_embed_runtime', 'third_party_embed_runtime', array(
            'preservation_strategy' => 'sanitized_embed_markup',
            'attributes'            => $this->safeEmbedAttributes($iframe),
        ));
        $fallbacks[] = FallbackDiagnostic::build(array(
            'type'            => 'html',
            'reason'          => 'iframe_embed_fallback',
            'diagnostic_code' => 'html_iframe_embed_fallback',
            'message'         => 'Iframe embed HTML was preserved as sanitized bounded fallback metadata.',
            'source_format'   => 'html',
            'tag'             => 'iframe',
            'selector'        => $this->elementSelector($iframe),
            'attributes'      => $this->safeEmbedAttributes($iframe),
            'context'         => $this->sourceContext($iframe),
            'events'          => $this->eventMetadata($iframe),
            'html'            => $boundedHtml['html'],
            'html_bytes'      => $boundedHtml['bytes'],
            'html_truncated'  => $boundedHtml['truncated'],
        ), $this->fallbackProvenance);

        return null;
    }

    private function safeImageUrl(string $url): string
    {
        if ( ! preg_match('#^data:image/svg\+xml(?:[;,][^,]*)?,#i', $url) ) {
            return $url;
        }

        $parts = explode(',', $url, 2);
        if ( 2 !== count($parts) ) {
            return '';
        }

        $metadata = strtolower($parts[0]);
        $svg = str_contains($metadata, ';base64') ? base64_decode($parts[1], true) : rawurldecode($parts[1]);
        if ( false === $svg || ! $this->isSafeSvgContent($svg) ) {
            return '';
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    private function assetMetadataFromOptions(array $options): array
    {
        $metadata = array();

        foreach ( array( $options['provenance'] ?? null, $options['context'] ?? null, $options ) as $container ) {
            if ( ! is_array($container) || ! isset($container['asset_metadata']) || ! is_array($container['asset_metadata']) ) {
                continue;
            }

            foreach ( $container['asset_metadata'] as $path => $asset ) {
                if ( ! is_string($path) || '' === trim($path) || ! is_array($asset) ) {
                    continue;
                }

                $metadata[trim($path)] = $asset;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, int|string>
     */
    private function assetMetadataImageAttributes(string $url): array
    {
        $asset = $this->assetMetadataForUrl($url);
        if ( null === $asset ) {
            return array();
        }

        $attrs = array();
        if ( isset($asset['id']) && ( is_int($asset['id']) || ( is_string($asset['id']) && ctype_digit($asset['id']) ) ) ) {
            $attrs['id'] = (int) $asset['id'];
        }

        if ( isset($asset['url']) && is_string($asset['url']) ) {
            $resolvedUrl = $this->safeResolvedAssetImageUrl(trim($asset['url']));
            if ( '' !== $resolvedUrl ) {
                $attrs['url'] = $resolvedUrl;
            }
        }

        return $attrs;
    }

    private function resolvedAssetImageUrl(string $url): string
    {
        if ( '' === $url ) {
            return '';
        }

        $asset = $this->assetMetadataForUrl($url);
        if ( ! is_array($asset) || ! isset($asset['url']) || ! is_string($asset['url']) ) {
            return $url;
        }

        $resolvedUrl = $this->safeResolvedAssetImageUrl(trim($asset['url']));
        return '' !== $resolvedUrl ? $resolvedUrl : $url;
    }

    private function resolvedAssetImageSrcset(string $srcset): string
    {
        if ( '' === trim($srcset) ) {
            return '';
        }

        $candidates = array();
        foreach ( explode(',', $srcset) as $candidate ) {
            $candidate = trim($candidate);
            if ( '' === $candidate ) {
                continue;
            }

            $parts = preg_split('/\s+/', $candidate, 2);
            if ( ! is_array($parts) || '' === ($parts[0] ?? '') ) {
                continue;
            }

            $url = $this->safeImageUrl((string) $parts[0]);
            if ( '' === $url ) {
                continue;
            }

            $descriptor = trim((string) ($parts[1] ?? ''));
            $candidates[] = trim($this->resolvedAssetImageUrl($url) . ('' !== $descriptor ? ' ' . $descriptor : ''));
        }

        return implode(', ', $candidates);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assetMetadataForUrl(string $url): ?array
    {
        foreach ( $this->assetMetadataLookupKeys($url) as $key ) {
            if ( isset($this->assetMetadata[$key]) ) {
                return $this->assetMetadata[$key];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function assetMetadataLookupKeys(string $url): array
    {
        $keys = array();
        foreach ( array( trim($url), ltrim(trim($url), '/') ) as $key ) {
            if ( '' !== $key && ! in_array($key, $keys, true) ) {
                $keys[] = $key;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        if ( is_string($path) ) {
            foreach ( array( $path, ltrim($path, '/') ) as $key ) {
                if ( '' !== $key && ! in_array($key, $keys, true) ) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    private function safeResolvedAssetImageUrl(string $url): string
    {
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        return $this->safeImageUrl($url);
    }

    private function isSafeSvgContent(string $content): bool
    {
        return '' !== trim($content) && preg_match('/<svg(?:\s|>)/i', $content) && ! preg_match('/<\s*script\b|\son[a-z]+\s*=|javascript\s*:/i', $content);
    }

    /**
     * @return array<string, string>
     */
    private function imagePresentationAttributes(DOMElement $image, ?DOMElement $figure): array
    {
        $attrs = $this->presentationAttributes($figure ?? $image);
        if ( $figure instanceof DOMElement ) {
            $attrs['className'] = $this->mergeClassNames($this->nonCoreImageFigureClassName($figure), $this->nonCoreImageClassName($image));
        }

        return array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    /**
     * @return array<string, int|string>
     */
    private function imageIdentityAttributes(DOMElement $image, ?DOMElement $figure = null): array
    {
        $attrs = array();
        $className = trim($this->attr($image, 'class') . ' ' . ( $figure instanceof DOMElement ? $this->attr($figure, 'class') : '' ));
        if ( preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $className, $matches) ) {
            $attrs['id'] = (int) $matches[1];
        }
        if ( preg_match('/(?:^|\s)size-([a-z0-9_-]+)(?:\s|$)/i', $className, $matches) ) {
            $attrs['sizeSlug'] = strtolower($matches[1]);
        }

        return $attrs;
    }

    private function nonCoreImageClassName(DOMElement $image): string
    {
        $classes = array_filter(preg_split('/\s+/', trim($this->attr($image, 'class'))) ?: array(), static function (string $className): bool {
            return ! preg_match('/^(?:wp-image-\d+|size-[a-z0-9_-]+)$/i', $className);
        });

        return implode(' ', $classes);
    }

    private function nonCoreImageFigureClassName(DOMElement $figure): string
    {
        $classes = array_filter(preg_split('/\s+/', trim($this->attr($figure, 'class'))) ?: array(), static function (string $className): bool {
            return ! preg_match('/^(?:wp-block-image|size-[a-z0-9_-]+)$/i', $className);
        });

        return implode(' ', $classes);
    }

    private function mergeClassNames(string ...$classNames): string
    {
        $classes = array();
        foreach ( $classNames as $className ) {
            foreach ( preg_split('/\s+/', trim($className)) ?: array() as $class ) {
                if ( '' !== $class && ! in_array($class, $classes, true) ) {
                    $classes[] = $class;
                }
            }
        }

        return implode(' ', $classes);
    }

    /**
     * @return array<string, mixed>
     */
    private function codePresentationAttributes(DOMElement $pre, DOMElement $code): array
    {
        $attrs = $this->presentationAttributes($pre);
        $codeClassName = $this->attr($code, 'class');
        if ( '' !== trim($codeClassName) ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), $codeClassName);
        }

        return array_filter($attrs, static fn ($value): bool => is_array($value) ? array() !== $value : '' !== trim((string) $value));
    }

    private function codeContent(DOMElement $code): string
    {
        foreach ( $code->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                return $this->sanitizedSyntaxHtml($code);
            }
        }

        return $code->textContent ?? '';
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function codeWindowBlockFromElement(DOMElement $element, array &$fallbacks): ?array
    {
        $pre = $this->firstChildElement($element, 'pre');
        if ( ! $pre instanceof DOMElement ) {
            return null;
        }

        $code = $this->firstChildElement($pre, 'code');
        if ( ! $code instanceof DOMElement ) {
            return null;
        }

        $label = $this->codeWindowLabel($element, $pre);
        if ( '' === $label && ! $this->hasClass($element, 'code-window') && ! $this->hasClass($element, 'code-frame') ) {
            return null;
        }

        $children = array();
        if ( '' !== $label ) {
            $children[] = $this->createBlock('core/paragraph', array( 'content' => $label ));
        }
        $children[] = $this->createBlock('core/code', array_merge($this->codePresentationAttributes($pre, $code), array( 'content' => $this->codeContent($code) )), array(), $pre);

        return $this->createBlock('core/group', $this->presentationAttributes($element), $children, $element);
    }

    private function codeWindowLabel(DOMElement $element, DOMElement $pre): string
    {
        foreach ( array( 'data-label', 'data-title', 'data-filename', 'aria-label' ) as $attribute ) {
            $value = trim($this->attr($element, $attribute));
            if ( '' !== $value ) {
                return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement || $child->isSameNode($pre) ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( 'figcaption' === $tagName || 'header' === $tagName || $this->hasClass($child, 'code-label') || $this->hasClass($child, 'filename') || $this->hasClass($child, 'window-title') ) {
                return $this->innerHtml($child);
            }
        }

        return '';
    }

    private function sanitizedSyntaxHtml(DOMElement $element): string
    {
        $html = '';
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType ) {
                $html .= htmlspecialchars($child->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            $tagName = strtolower($child->tagName);
            if ( in_array($tagName, array( 'span', 'mark', 'b', 'strong', 'i', 'em' ), true) ) {
                $attrs = array_intersect_key($this->htmlAttributes($child), array_flip(array( 'class', 'data-token', 'title' )));
                $attrs = array_filter($attrs, static fn (string $value): bool => '' !== $value && strlen($value) <= 200 && ! preg_match('/javascript\s*:/i', $value));
                $html .= '<' . $tagName . $this->htmlAttributeString($attrs) . '>' . $this->sanitizedSyntaxHtml($child) . '</' . $tagName . '>';
                continue;
            }

            $html .= htmlspecialchars($child->textContent ?? '', ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function htmlAttributeString(array $attrs): string
    {
        $html = '';
        foreach ( $attrs as $name => $value ) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function eventMetadata(DOMElement $element): array
    {
        $events = array();
        foreach ( $this->htmlAttributes($element) as $name => $value ) {
            if ( preg_match('/^on([a-z]+)$/i', $name, $matches) ) {
                $events[] = array(
                    'type'      => strtolower($matches[1]),
                    'attribute' => strtolower($name),
                );
            }
            if ( preg_match('/^data-(?:action|on|event)$/i', $name) && '' !== trim($value) ) {
                $events[] = array(
                    'type'      => 'declared',
                    'attribute' => $name,
                );
            }
        }

        return $events;
    }

}
