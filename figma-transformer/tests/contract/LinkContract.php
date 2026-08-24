<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiDecoder;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;

/**
 * @param callable(bool, string): void $assert
 */
function blocks_engine_figma_transformer_run_link_contract(callable $assert, callable $fileContent, callable $artifactQualitySignalCodes): void
{
    // Real anchor tags: a TEXT node carrying a URL hyperlink emits a real <a href>.
    $urlLinkResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Url Link Fixture',
        'nodes' => array(
            array(
                'id'       => 'link:1',
                'type'     => 'FRAME',
                'name'     => 'Footer',
                'width'    => 1200,
                'height'   => 200,
                'children' => array(
                    array(
                        'id'         => 'link:2',
                        'type'       => 'TEXT',
                        'name'       => 'External link',
                        'characters' => 'Visit Automattic',
                        'hyperlink'  => array('type' => 'URL', 'url' => 'https://automattic.com/about'),
                    ),
                ),
            ),
        ),
    ));
    $urlLinkHtml = $fileContent($urlLinkResult, 'index.html');
    $urlLinkCss = $fileContent($urlLinkResult, 'style.css');
    $urlLinks = $urlLinkResult['source_reports']['figma']['html']['transform_diagnostics']['links'] ?? array();
    $assert(str_contains($urlLinkHtml, '<a class="figma-link" href="https://automattic.com/about" data-figma-link-type="url">'), 'url-hyperlink-emits-anchor');
    $assert(str_contains($urlLinkHtml, '<a class="figma-link" href="https://automattic.com/about" data-figma-link-type="url"><p class="figma-node-link-2-external-link"'), 'url-hyperlink-wraps-element-transparently');
    $assert(str_contains($urlLinkCss, 'a.figma-link{display:contents'), 'figma-link-display-contents-rule');
    $assert(1 === ($urlLinks['sources_found'] ?? null) && 1 === ($urlLinks['anchors_emitted'] ?? null) && 1 === ($urlLinks['url_links'] ?? null) && 0 === ($urlLinks['unresolved'] ?? null), 'url-hyperlink-link-coverage');
    
    // Real anchor tags: a NODE/prototype link resolving to a planned frame emits the slug href.
    $navScenegraph = array(
        'name'  => 'Nav Link Fixture',
        'nodes' => array(
            array(
                'id'       => 'nav:home',
                'type'     => 'FRAME',
                'name'     => 'Home',
                'width'    => 1280,
                'height'   => 900,
                'children' => array(
                    array(
                        'id'         => 'nav:home-link',
                        'type'       => 'TEXT',
                        'name'       => 'About nav item',
                        'characters' => 'About',
                        'hyperlink'  => array('type' => 'NODE', 'nodeID' => 'nav:about'),
                    ),
                    array(
                        'id'         => 'nav:proto-link',
                        'type'       => 'FRAME',
                        'name'       => 'CTA button',
                        'width'      => 160,
                        'height'     => 48,
                        'reactions'  => array(
                            array(
                                'trigger' => array('type' => 'ON_CLICK'),
                                'action'  => array('type' => 'NODE', 'navigation' => 'NAVIGATE', 'destinationId' => 'nav:about'),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'id'       => 'nav:about',
                'type'     => 'FRAME',
                'name'     => 'About',
                'width'    => 1280,
                'height'   => 900,
                'children' => array(
                    array('id' => 'nav:about-title', 'type' => 'TEXT', 'name' => 'About title', 'characters' => 'About us'),
                ),
            ),
        ),
    );
    $navResult = blocks_engine_figma_transformer_transform_scenegraph($navScenegraph, array('include_all_pages' => true, 'entry_frame_id' => 'nav:home'));
    $navHomeHtml = $fileContent($navResult, 'index.html');
    $assert(str_contains($navHomeHtml, '<a class="figma-link" href="about.html" data-figma-link-type="node">'), 'node-hyperlink-resolves-to-slug');
    $assert(2 === substr_count($navHomeHtml, 'href="about.html"'), 'node-and-prototype-links-both-resolve');
    $navOverrideResult = blocks_engine_figma_transformer_transform_scenegraph($navScenegraph, array(
        'include_all_pages' => true,
        'entry_frame_id' => 'nav:home',
        'link_target_paths' => array('nav:about' => 'custom-root.html'),
    ));
    $assert(str_contains($fileContent($navOverrideResult, 'index.html'), 'href="custom-root.html" data-figma-link-type="node"'), 'explicit-root-link-target-path-is-preserved');
    $navPagePaths = array_values(array_map(
        static fn (array $page): string => (string) ($page['path'] ?? ''),
        array_filter($navResult['source_reports']['figma']['html']['pages'] ?? array(), 'is_array')
    ));
    $navHtmlPaths = array_values(array_map(
        static fn (array $file): string => (string) ($file['path'] ?? ''),
        array_filter($navResult['files'] ?? array(), static fn (array $file): bool => 'text/html' === ($file['mime_type'] ?? null))
    ));
    $assert($navHtmlPaths === $navPagePaths, 'multi-page-routes-and-source-reports-remain-in-emission-order');
    
    // Prototype links may target a descendant inside a planned page frame rather than the frame itself.
    $descendantTargetScenegraph = array(
        'name'  => 'Descendant Prototype Target Fixture',
        'nodes' => array(
            array(
                'id'       => 'desc:home',
                'type'     => 'FRAME',
                'name'     => 'Home',
                'width'    => 1280,
                'height'   => 900,
                'children' => array(
                    array(
                        'id'         => 'desc:cta',
                        'type'       => 'TEXT',
                        'name'       => 'About CTA',
                        'characters' => 'About',
                        'reactions'  => array(
                            array(
                                'action' => array('type' => 'NODE', 'navigation' => 'NAVIGATE', 'destinationId' => 'desc:about:title'),
                            ),
                        ),
                    ),
                ),
            ),
            array(
                'id'       => 'desc:about',
                'type'     => 'FRAME',
                'name'     => 'About',
                'width'    => 1280,
                'height'   => 900,
                'children' => array(
                    array('id' => 'desc:about:title', 'type' => 'TEXT', 'name' => 'About title', 'characters' => 'About us', 'fontSize' => 32),
                ),
            ),
        ),
    );
    $descendantTargetResult = blocks_engine_figma_transformer_transform_scenegraph($descendantTargetScenegraph, array('include_all_pages' => true, 'entry_frame_id' => 'desc:home'));
    $descendantTargetHomeHtml = $fileContent($descendantTargetResult, 'index.html');
    $descendantTargetLinks = $descendantTargetResult['source_reports']['figma']['html']['transform_diagnostics']['links'] ?? array();
    $assert(str_contains($descendantTargetHomeHtml, '<a class="figma-link" href="about.html#about-us" data-figma-link-type="node">'), 'descendant-prototype-target-resolves-to-containing-page');
    $assert(0 === ($descendantTargetLinks['unresolved'] ?? null) && ($descendantTargetLinks['node_links'] ?? 0) >= 1, 'descendant-prototype-target-link-coverage-resolved');
    $descendantOverrideResult = blocks_engine_figma_transformer_transform_scenegraph($descendantTargetScenegraph, array(
        'include_all_pages' => true,
        'entry_frame_id' => 'desc:home',
        'link_target_paths' => array('desc:about:title' => 'custom-descendant.html#provided'),
    ));
    $descendantOverrideHtml = $fileContent($descendantOverrideResult, 'index.html');
    $assert(str_contains($descendantOverrideHtml, 'href="custom-descendant.html#provided" data-figma-link-type="node"'), 'explicit-descendant-link-target-path-is-preserved');
    $preFragmentScenegraph = (new ScenegraphNormalizer())->normalize($descendantTargetScenegraph, array(
        'render_document' => true,
        'document_frame_ids' => array('desc:home', 'desc:about'),
    ));
    $preFragmentResult = (new StaticHtmlEmitter())->emitSite($preFragmentScenegraph, array(
        'pages' => array(
            array('frame_id' => 'desc:home', 'name' => 'Home', 'path' => 'index.html', 'entrypoint' => true),
            array('frame_id' => 'desc:about', 'name' => 'About', 'path' => 'about.html#provided', 'entrypoint' => false),
        ),
    ));
    $preFragmentHomeHtml = $fileContent($preFragmentResult, 'index.html');
    $assert(str_contains($preFragmentHomeHtml, 'href="about.html#about-us" data-figma-link-type="node"') && ! str_contains($preFragmentHomeHtml, 'about.html#provided#'), 'pre-fragmented-page-path-emits-one-generated-fragment');
    
    // Real anchor tags: an unresolved NODE link is counted in the diagnostic without inventing href="#".
    $unresolvedResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Unresolved Link Fixture',
        'nodes' => array(
            array(
                'id'       => 'dead:1',
                'type'     => 'FRAME',
                'name'     => 'Nav',
                'width'    => 1200,
                'height'   => 120,
                'children' => array(
                    array(
                        'id'         => 'dead:2',
                        'type'       => 'TEXT',
                        'name'       => 'Broken link',
                        'characters' => 'Missing page',
                        'hyperlink'  => array('type' => 'NODE', 'nodeID' => 'does:not:exist'),
                    ),
                ),
            ),
        ),
    ));
    $unresolvedHtml = $fileContent($unresolvedResult, 'index.html');
    $unresolvedLinks = $unresolvedResult['source_reports']['figma']['html']['transform_diagnostics']['links'] ?? array();
    $unresolvedSignalCodes = $artifactQualitySignalCodes($unresolvedResult);
    $unresolvedDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $unresolvedResult['diagnostics'] ?? array()
    );
    $assert(! str_contains($unresolvedHtml, 'href="#" data-figma-link-type="node"'), 'unresolved-node-link-does-not-emit-placeholder-anchor');
    $assert(str_contains($unresolvedHtml, 'data-figma-node-id="dead:2"'), 'unresolved-node-link-preserves-source-element');
    $assert(1 === ($unresolvedLinks['unresolved'] ?? null) && 1 === ($unresolvedLinks['node_links'] ?? null) && 0 === ($unresolvedLinks['anchors_emitted'] ?? null), 'unresolved-link-counted-in-coverage-without-anchor');
    $assert('does:not:exist' === ($unresolvedLinks['unresolved_targets'][0]['target_node_id'] ?? null), 'unresolved-link-records-target-node-id');
    $assert(in_array('link_target_unresolved', $unresolvedSignalCodes, true), 'unresolved-link-artifact-quality-signal');
    $assert(in_array('link_target_unresolved', $unresolvedDiagnosticCodes, true), 'unresolved-link-diagnostic-code');
    
    // ---------------------------------------------------------------------------
    // Figma links from .fig Kiwi shapes (#328): the normalizer + emitter turn the
    // raw decoded `hyperlink` struct and `prototypeInteractions` list (Kiwi field
    // names, distinct from the REST `reactions`) into real <a href> anchors.
    // ---------------------------------------------------------------------------
    
    // (a) A node carrying the Kiwi `Hyperlink` shape ({url} with no REST `type`)
    // emits a real URL anchor.
    $kiwiHyperlinkResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Hyperlink Fixture',
        'nodes' => array(
            array(
                'id'       => 'kiwilink:1',
                'type'     => 'FRAME',
                'name'     => 'Footer',
                'width'    => 1200,
                'height'   => 200,
                'children' => array(
                    array(
                        'id'         => 'kiwilink:2',
                        'type'       => 'TEXT',
                        'name'       => 'External link',
                        'characters' => 'Visit Automattic',
                        'hyperlink'  => array('url' => 'https://automattic.com/work'),
                    ),
                ),
            ),
        ),
    ));
    $kiwiHyperlinkHtml = $fileContent($kiwiHyperlinkResult, 'index.html');
    $assert(str_contains($kiwiHyperlinkHtml, '<a class="figma-link" href="https://automattic.com/work" data-figma-link-type="url">'), 'kiwi-hyperlink-url-emits-anchor');
    
    // (b) A node carrying an ON_CLICK `prototypeInteractions` entry whose action is
    // a Kiwi URL connection (connectionType/connectionURL) emits a real URL anchor.
    $kiwiReactionResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Reaction Fixture',
        'nodes' => array(
            array(
                'id'       => 'kiwireact:1',
                'type'     => 'FRAME',
                'name'     => 'Home',
                'width'    => 1280,
                'height'   => 900,
                'children' => array(
                    array(
                        'id'                    => 'kiwireact:cta',
                        'type'                  => 'FRAME',
                        'name'                  => 'CTA button',
                        'width'                 => 160,
                        'height'                => 48,
                        'prototypeInteractions' => array(
                            array(
                                'event'   => array('interactionType' => 'ON_CLICK'),
                                'actions' => array(
                                    array('connectionType' => 'URL', 'connectionURL' => 'https://automattic.com/cta'),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $kiwiReactionHtml = $fileContent($kiwiReactionResult, 'index.html');
    $assert(str_contains($kiwiReactionHtml, '<a class="figma-link" href="https://automattic.com/cta" data-figma-link-type="url">'), 'kiwi-prototype-interaction-url-emits-anchor');
    
    // DECODE: the extended field policy carries `hyperlink` and the Kiwi
    // `prototypeInteractions` list through the REAL generic decoder, resolving the
    // connection enums to their token strings and the GUID destination struct.
    $linkDecoder = new FigKiwiDecoder();
    $linkSchema = $linkDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_link_schema_fixture());
    $assert(null !== ($linkSchema['schema'] ?? null), 'link-kiwi-schema-decodes');
    $linkMessage = $linkDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_link_message_fixture(),
        $linkSchema['schema'] ?? array()
    );
    $linkNodeChanges = $linkMessage['message']['nodeChanges'] ?? array();
    $assert('https://example.com/about' === ($linkNodeChanges[0]['hyperlink']['url'] ?? null), 'link-field-policy-carries-hyperlink-url');
    $linkInteraction = $linkNodeChanges[1]['prototypeInteractions'][0] ?? array();
    $assert('ON_CLICK' === ($linkInteraction['event']['interactionType'] ?? null), 'link-field-policy-carries-interaction-trigger');
    $assert('URL' === ($linkInteraction['actions'][0]['connectionType'] ?? null), 'link-field-policy-carries-connection-type');
    $assert('https://example.com/cta' === ($linkInteraction['actions'][0]['connectionURL'] ?? null), 'link-field-policy-carries-connection-url');
    $assert(true === ($linkInteraction['actions'][0]['openUrlInNewTab'] ?? null), 'link-field-policy-carries-open-url-new-tab');
    $assert('NEW_TAB' === ($linkInteraction['actions'][0]['urlTarget'] ?? null), 'link-field-policy-carries-url-target');
    $assert('INTERNAL_NODE' === ($linkInteraction['actions'][1]['connectionType'] ?? null), 'link-field-policy-carries-node-connection-type');
    $assert(array('sessionID' => 7, 'localID' => 42) === ($linkInteraction['actions'][1]['transitionNodeID'] ?? null), 'link-field-policy-carries-transition-node-guid');
    $assert('OVERLAY' === ($linkInteraction['actions'][1]['navigationType'] ?? null), 'link-field-policy-carries-overlay-navigation-type');
    $assert('CENTER' === ($linkInteraction['actions'][1]['overlayPositionType'] ?? null), 'link-field-policy-carries-overlay-position-type');
    $assert(true === ($linkInteraction['actions'][1]['preserveScrollPosition'] ?? null), 'link-field-policy-carries-preserve-scroll-position');
    $assert(true === ($linkInteraction['actions'][1]['resetScrollPosition'] ?? null), 'link-field-policy-carries-reset-scroll-position');
    
    $prototypeMetadataNormalizer = new ScenegraphNormalizer();
    $prototypeMetadataNormalized = $prototypeMetadataNormalizer->normalize(array(
        'name'  => 'Kiwi Prototype Metadata Fixture',
        'nodes' => array(
            array(
                'id'       => 'prototype-meta:home',
                'type'     => 'FRAME',
                'name'     => 'Home',
                'children' => array(
                    array(
                        'id'                    => 'prototype-meta:overlay-trigger',
                        'type'                  => 'FRAME',
                        'name'                  => 'Open Overlay',
                        'prototypeInteractions' => array(
                            array(
                                'id'      => 'interaction:overlay',
                                'event'   => array('interactionType' => 'ON_CLICK'),
                                'actions' => array(
                                    array(
                                        'connectionType'          => 'INTERNAL_NODE',
                                        'transitionNodeID'        => array('sessionID' => 9, 'localID' => 77),
                                        'navigationType'          => 'OVERLAY',
                                        'overlayPositionType'     => 'CENTER',
                                        'preserveScrollPosition'  => true,
                                        'overlayRelativePosition' => array('x' => 24, 'y' => 32),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $prototypeMetadataLink = $prototypeMetadataNormalized['node_map']['prototype-meta:overlay-trigger']['figma_link'] ?? array();
    $assert('node' === ($prototypeMetadataLink['type'] ?? null), 'prototype-metadata-link-keeps-node-target');
    $assert('9:77' === ($prototypeMetadataLink['target_node_id'] ?? null), 'prototype-metadata-link-normalizes-transition-guid');
    $assert('OVERLAY' === ($prototypeMetadataLink['prototype_navigation_type'] ?? null), 'prototype-metadata-link-carries-navigation-type');
    $assert('CENTER' === ($prototypeMetadataLink['prototype_overlay_position_type'] ?? null), 'prototype-metadata-link-carries-overlay-position-type');
    $assert(true === ($prototypeMetadataLink['prototype_preserve_scroll_position'] ?? null), 'prototype-metadata-link-carries-preserve-scroll-position');
    $assert(array('x' => 24, 'y' => 32) === ($prototypeMetadataLink['prototype_overlay_relative_position'] ?? null), 'prototype-metadata-link-carries-overlay-relative-position');
    $assert('ON_CLICK' === ($prototypeMetadataLink['prototype_event'] ?? null), 'prototype-metadata-link-carries-event');
    $assert('interaction:overlay' === ($prototypeMetadataLink['prototype_interaction_id'] ?? null), 'prototype-metadata-link-carries-interaction-id');
}
