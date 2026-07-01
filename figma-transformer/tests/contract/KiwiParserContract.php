<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCapability;
use Automattic\BlocksEngine\FigmaTransformer\Compression\ZstdCommandDecoder;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigArchiveReader;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiDecoder;
use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiParser;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;

function blocks_engine_figma_transformer_run_kiwi_parser_contract(callable $assert, callable $fileContent): void
{
    $fixture = blocks_engine_figma_transformer_create_fig_wrapper_fixture();
    $fileResult = blocks_engine_figma_transformer_transform_file($fixture);
    @unlink($fixture);
    
    $canvas = $fileResult['source_reports']['figma']['archive']['canvas'] ?? array();
    $chunks = $canvas['chunks'] ?? array();
    $diagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $fileResult['diagnostics'] ?? array()
    );
    $zstdCapability = new ZstdCapability();
    $zstdStatus = $zstdCapability->status();
    $zstdCapabilityDiagnostic = $zstdCapability->diagnostic('ContractTest', 0);
    $zstdCapabilityCode = (string) ($zstdCapabilityDiagnostic['code'] ?? '');
    $zstdDiagnostic = null;
    foreach ( $fileResult['diagnostics'] ?? array() as $diagnostic ) {
        if ( $zstdCapabilityCode === ($diagnostic['code'] ?? null) ) {
            $zstdDiagnostic = $diagnostic;
            break;
        }
    }
    
    $assert('success_with_warnings' === ($fileResult['status'] ?? null), 'file-transform-status');
    $assert('fig-kiwi' === ($canvas['prelude'] ?? null), 'fig-kiwi-prelude');
    $assert(106 === ($canvas['version'] ?? null), 'fig-kiwi-version');
    $assert('inner.fig' === ($fileResult['source_reports']['figma']['input']['nested_fig'] ?? null), 'wrapper-nested-fig');
    $assert(5 === count($chunks), 'fig-kiwi-chunk-count');
    $assert('zlib' === ($chunks[0]['compression'] ?? null), 'fig-kiwi-first-chunk-zlib');
    $assert('json' === ($chunks[0]['payload']['classification'] ?? null), 'fig-kiwi-first-chunk-json');
    $assert(isset($chunks[0]['payload']['json']['nodes']), 'fig-kiwi-first-chunk-nodes-candidate');
    $assert('json_invalid' === ($chunks[1]['payload']['classification'] ?? null), 'fig-kiwi-second-chunk-json-invalid');
    $assert(isset($chunks[2]['payload']['json']['NODE_CHANGES']), 'fig-kiwi-third-chunk-node-changes');
    $assert('binary' === ($chunks[3]['payload']['classification'] ?? null), 'fig-kiwi-fourth-chunk-binary');
    $assert('zstd' === ($chunks[4]['compression'] ?? null), 'fig-kiwi-fifth-chunk-zstd');
    $assert(in_array($zstdCapabilityCode, $diagnosticCodes, true), 'fig-kiwi-zstd-capability-diagnostic');
    $assert(is_bool($zstdStatus['available'] ?? null), 'zstd-status-available-bool');
    $assert(is_bool($zstdStatus['extension_loaded'] ?? null), 'zstd-status-extension-loaded-bool');
    $assert(is_array($zstdStatus['functions'] ?? null), 'zstd-status-functions-array');
    $assert(array_key_exists('zstd_uncompress', $zstdStatus['functions'] ?? array()), 'zstd-status-uncompress-function');
    $assert(array_key_exists('adapter_registered', $zstdStatus), 'zstd-status-adapter-registered-key');
    $assert(array_key_exists('wordpress_filter_registered', $zstdStatus), 'zstd-status-wordpress-filter-registered-key');
    $assert(($zstdStatus['available'] ?? null) === (null !== ($zstdStatus['provider'] ?? null)), 'zstd-status-available-matches-provider');
    $assert(($zstdStatus['available'] ?? null) === ($zstdDiagnostic['context']['available'] ?? null), 'fig-kiwi-zstd-diagnostic-availability-context');
    if ( true === ($zstdStatus['available'] ?? false) && function_exists('zstd_compress') ) {
        $zstdCompressed = zstd_compress('contract zstd round trip');
        $zstdRoundTrip = false !== $zstdCompressed ? $zstdCapability->uncompress($zstdCompressed, 'ContractTest', 1) : array('data' => null, 'diagnostics' => array());
        $assert('contract zstd round trip' === ($zstdRoundTrip['data'] ?? null), 'zstd-real-round-trip');
        $assert(isset($chunks[4]['inflated_bytes']), 'fig-kiwi-zstd-real-fixture-inflated');
    } else {
        $zstdUnavailable = $zstdCapability->uncompress("\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame', 'ContractTest', 1);
        $assert(null === ($zstdUnavailable['data'] ?? null), 'zstd-unavailable-returns-null');
        $assert(in_array((string) ($zstdUnavailable['diagnostics'][0]['code'] ?? ''), array('figma_transformer_zstd_extension_missing', 'figma_transformer_zstd_function_missing'), true), 'zstd-unavailable-diagnostic-code');
    }
    
    $adapterCapability = new ZstdCapability(static function (string $payload): string|false {
        if ( "\x28\xb5\x2f\xfd" !== substr($payload, 0, 4) ) {
            return false;
        }
    
        return json_encode(array('NODE_CHANGES' => array()), JSON_THROW_ON_ERROR);
    });
    $adapterStatus = $adapterCapability->status();
    $adapterResult = $adapterCapability->uncompress("\x28\xb5\x2f\xfd" . 'adapter-frame', 'ContractTest', 2);
    $adapterCanvasResult = ( new FigKiwiParser($adapterCapability) )->parse(
        'fig-kiwi'
        . pack('V', 106)
        . blocks_engine_figma_transformer_kiwi_chunk("\x28\xb5\x2f\xfd" . 'adapter-frame')
    );
    $failingAdapterResult = ( new ZstdCapability(static fn (): false => false) )->uncompress("\x28\xb5\x2f\xfd" . 'adapter-frame', 'ContractTest', 3);
    $commandAdapterResult = ( new ZstdCapability(new ZstdCommandDecoder(array(PHP_BINARY, '-r', '$payload = stream_get_contents(STDIN); fwrite(STDOUT, $payload);'))) )->uncompress('command adapter bytes', 'ContractTest', 4);
    
    $assert(true === ($adapterStatus['available'] ?? null), 'zstd-adapter-status-available');
    $assert('adapter' === ($adapterStatus['provider'] ?? null) || 'ext-zstd' === ($adapterStatus['provider'] ?? null), 'zstd-adapter-status-provider');
    $assert('{"NODE_CHANGES":[]}' === ($adapterResult['data'] ?? null), 'zstd-adapter-decodes-payload');
    $assert('json' === ($adapterCanvasResult['canvas']['chunks'][0]['payload']['classification'] ?? null), 'fig-kiwi-zstd-adapter-classifies-json');
    $assert('figma_transformer_zstd_adapter_failed' === ($failingAdapterResult['diagnostics'][0]['code'] ?? null), 'zstd-adapter-failure-diagnostic');
    $assert('command adapter bytes' === ($commandAdapterResult['data'] ?? null), 'zstd-command-adapter-decodes-payload');
    $assert('figma_transformer_zstd_command_used' === ($commandAdapterResult['diagnostics'][1]['code'] ?? null), 'zstd-command-adapter-diagnostic');
    $assert(! empty($fileResult['files']), 'file-transform-renders-decoded-scenegraph');
    $assert(4 === ($fileResult['metrics']['node_count'] ?? null), 'file-transform-node-count');
    $assert(2 === ($fileResult['metrics']['decoded_payload_candidate_count'] ?? null), 'file-transform-decoded-candidate-count');
    $assert(2 === ($fileResult['metrics']['selected_decoded_payload_index'] ?? null), 'file-transform-selected-node-changes-index');
    $assert('NODE_CHANGES' === ($fileResult['source_reports']['figma']['decoded_scenegraph']['shape'] ?? null), 'file-transform-selected-node-changes-shape');
    $assert(isset($fileResult['source_reports']['figma']['html']), 'file-transform-html-source-report');
    $assert('blocks-engine/figma-transformer/compiled-site/v1' === ($fileResult['source_reports']['compiled_site']['schema'] ?? null), 'file-transform-compiled-site-source-report');
    $assert('synthetic' === ($fileResult['source_reports']['figma']['assets'][0]['id'] ?? null), 'archive-asset-id');
    $assert('images/synthetic' === ($fileResult['source_reports']['figma']['assets'][0]['path'] ?? null), 'archive-asset-path');
    $assert('asset' === ($fileResult['source_reports']['figma']['assets'][0]['content'] ?? null), 'archive-asset-content');
    $assert('assets/synthetic.bin' === ($fileResult['assets'][0]['path'] ?? null), 'archive-asset-emitted-from-decoded-scenegraph');
    
    $assetMetadataFixture = SyntheticFigKiwiFixtureBuilder::figArchive(
        SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::jsonZlibChunk(array('metadata' => array('ignored' => true))))),
        array('images/metadata-only' => "\x89PNG\r\n\x1a\n" . str_repeat('asset-bytes', 20))
    );
    $assetMetadataResult = ( new FigArchiveReader() )->read($assetMetadataFixture, array('include_asset_content' => false));
    @unlink($assetMetadataFixture);
    $assert('metadata-only' === ($assetMetadataResult['assets'][0]['id'] ?? null), 'archive-asset-metadata-id');
    $assert('image/png' === ($assetMetadataResult['assets'][0]['mime_type'] ?? null), 'archive-asset-metadata-sniffs-mime');
    $assert(! array_key_exists('content', $assetMetadataResult['assets'][0] ?? array()), 'archive-asset-metadata-omits-content');
    
    $pendingFixture = blocks_engine_figma_transformer_create_pending_decoder_fig_wrapper_fixture();
    $pendingResult = blocks_engine_figma_transformer_transform_file($pendingFixture);
    @unlink($pendingFixture);
    $pendingDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $pendingResult['diagnostics'] ?? array()
    );
    $assert('unsupported_decoder_pending' === ($pendingResult['status'] ?? null), 'pending-decoder-status');
    $assert(in_array('figma_transformer_decoded_scenegraph_missing', $pendingDiagnosticCodes, true), 'pending-decoder-diagnostic');
    
    $kiwiSchemaBytes = blocks_engine_figma_transformer_kiwi_schema_fixture();
    $kiwiMessageBytes = blocks_engine_figma_transformer_kiwi_message_fixture();
    $kiwiDecoder = new FigKiwiDecoder();
    $kiwiSchemaResult = $kiwiDecoder->decodeSchema($kiwiSchemaBytes);
    $kiwiMessageResult = $kiwiDecoder->decodeMessage($kiwiMessageBytes, $kiwiSchemaResult['schema'] ?? array());
    $assert(null !== ($kiwiSchemaResult['schema'] ?? null), 'kiwi-schema-decodes');
    $assert('NODE_CHANGES' === ($kiwiMessageResult['message']['type'] ?? null), 'kiwi-message-enum-decodes');
    $assert(array('alpha', 'beta') === ($kiwiMessageResult['message']['nodeChanges'] ?? null), 'kiwi-message-array-decodes');
    
    $injectedParser = new FigKiwiParser(new ZstdCapability(static fn (string $payload, array $context): string => $kiwiMessageBytes));
    $injectedCanvas = $injectedParser->parse(
        'fig-kiwi'
        . pack('V', 106)
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate($kiwiSchemaBytes))
        . blocks_engine_figma_transformer_kiwi_chunk("\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame')
    );
    $injectedChunks = $injectedCanvas['canvas']['chunks'] ?? array();
    $injectedDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $injectedCanvas['diagnostics'] ?? array()
    );
    $assert('kiwi_schema' === ($injectedChunks[0]['payload']['classification'] ?? null), 'kiwi-parser-classifies-schema');
    $assert('kiwi_message' === ($injectedChunks[1]['payload']['classification'] ?? null), 'kiwi-parser-classifies-message');
    $assert('NODE_CHANGES' === ($injectedChunks[1]['payload']['kiwi_message']['type'] ?? null), 'kiwi-parser-message-type');
    $assert(in_array('figma_transformer_zstd_adapter_available', $injectedDiagnosticCodes, true), 'zstd-injected-decoder-diagnostic');
    
    $guardedCanvas = ( new FigKiwiParser(new ZstdCapability(static fn (string $payload, array $context): string => $kiwiMessageBytes)) )->parse(
        'fig-kiwi'
        . pack('V', 106)
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate($kiwiSchemaBytes))
        . blocks_engine_figma_transformer_kiwi_chunk("\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame'),
        array('max_kiwi_message_decode_bytes' => 1)
    );
    $guardedChunks = $guardedCanvas['canvas']['chunks'] ?? array();
    $guardedDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $guardedCanvas['diagnostics'] ?? array()
    );
    $assert('kiwi_message' === ($guardedChunks[1]['payload']['classification'] ?? null), 'kiwi-parser-selectively-decodes-oversized-message');
    $assert('selective' === ($guardedChunks[1]['payload']['kiwi_message_decode'] ?? null), 'kiwi-parser-selective-message-mode');
    $assert(in_array('figma_transformer_kiwi_message_selective_decode_used', $guardedDiagnosticCodes, true), 'kiwi-parser-selective-message-diagnostic');
    
    $kiwiFrameMaskDecoder = new FigKiwiDecoder();
    $kiwiFrameMaskSchema = $kiwiFrameMaskDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_frame_mask_schema_fixture());
    $kiwiFrameMaskMessage = $kiwiFrameMaskDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_frame_mask_message_fixture(),
        $kiwiFrameMaskSchema['schema'] ?? array()
    );
    $kiwiFrameMaskNode = $kiwiFrameMaskMessage['message']['nodeChanges'][0] ?? array();
    $assert(false === ($kiwiFrameMaskNode['frameMaskDisabled'] ?? null), 'kiwi-selective-decodes-frame-mask-disabled');
    $assert(true === ($kiwiFrameMaskNode['mask'] ?? null), 'kiwi-selective-decodes-mask');
    $assert('ALPHA' === ($kiwiFrameMaskNode['maskType'] ?? null), 'kiwi-selective-decodes-mask-type');

    $kiwiMaskNormalizer = new ScenegraphNormalizer();
    $kiwiMaskNormalized = $kiwiMaskNormalizer->normalize(array(
        'name'  => 'Kiwi Mask Metadata Fixture',
        'nodes' => array(
            array(
                'id'       => 'kiwi:mask-source',
                'type'     => 'VECTOR',
                'name'     => 'Mask Source',
                'width'    => 24,
                'height'   => 24,
                'mask'     => true,
                'maskType' => 'ALPHA',
            ),
        ),
    ));
    $kiwiMaskNormalizedNode = $kiwiMaskNormalized['nodes'][0] ?? array();
    $assert(true === ($kiwiMaskNormalizedNode['figma_mask']['is_mask'] ?? null), 'kiwi-mask-normalizes-mask-role');
    $assert('ALPHA' === ($kiwiMaskNormalizedNode['figma_mask']['type'] ?? null), 'kiwi-mask-normalizes-mask-type');
    $assert(! isset($kiwiMaskNormalizedNode['layout']['clips_content']), 'kiwi-mask-source-does-not-force-clips-content');

    $kiwiImagePaintSchema = $kiwiDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_image_paint_schema_fixture());
    $kiwiImagePaintMessage = $kiwiDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_image_paint_message_fixture(),
        $kiwiImagePaintSchema['schema'] ?? array()
    );
    $kiwiDirectImagePaint = $kiwiImagePaintMessage['message']['nodeChanges'][0]['fillPaints'][0] ?? array();
    $kiwiOverrideImagePaint = $kiwiImagePaintMessage['message']['nodeChanges'][0]['symbolData']['symbolOverrides'][0]['fillPaints'][0] ?? array();
    $kiwiImagePaintNode = $kiwiImagePaintMessage['message']['nodeChanges'][0] ?? array();
    $kiwiDirectFillStyle = $kiwiImagePaintMessage['message']['nodeChanges'][0]['styleIdForFill']['guid'] ?? array();
    $kiwiDirectStrokeStyle = $kiwiImagePaintMessage['message']['nodeChanges'][0]['styleIdForStrokeFill']['guid'] ?? array();
    $kiwiOverrideFillStyle = $kiwiImagePaintMessage['message']['nodeChanges'][0]['symbolData']['symbolOverrides'][0]['styleIdForFill']['guid'] ?? array();
    $kiwiOverrideStrokeStyle = $kiwiImagePaintMessage['message']['nodeChanges'][0]['symbolData']['symbolOverrides'][0]['styleIdForStrokeFill']['guid'] ?? array();
    $assert(true === ($kiwiDirectImagePaint['imageShouldColorManage'] ?? null), 'kiwi-selective-decodes-image-color-management');
    $assert(15.0 === round((float) ($kiwiDirectImagePaint['rotation'] ?? 0.0), 4), 'kiwi-selective-decodes-image-rotation');
    $assert(2.0 === round((float) ($kiwiDirectImagePaint['scale'] ?? 0.0), 4), 'kiwi-selective-decodes-image-scale');
    $assert(7 === ($kiwiDirectImagePaint['animationFrame'] ?? null), 'kiwi-selective-decodes-image-animation-frame');
    $assert('abc' === ($kiwiDirectImagePaint['thumbHash'] ?? null), 'kiwi-selective-decodes-image-thumb-hash');
    $assert(0.5 === round((float) ($kiwiDirectImagePaint['imageTransform']['m00'] ?? 0.0), 4), 'kiwi-selective-decodes-image-transform');
    $assert(640.0 === ($kiwiDirectImagePaint['image']['width'] ?? null), 'kiwi-selective-decodes-image-width');
    $assert(480.0 === ($kiwiDirectImagePaint['image']['height'] ?? null), 'kiwi-selective-decodes-image-height');
    $assert('direct-hash-asset-id' === ($kiwiDirectImagePaint['image']['assetRef']['id'] ?? null), 'kiwi-selective-decodes-image-asset-ref-id');
    $assert('9003:301' === blocks_engine_figma_transformer_kiwi_inventory_format_guid($kiwiDirectImagePaint['image']['assetRef']['guid'] ?? null), 'kiwi-selective-decodes-image-asset-ref-guid');
    $assert('direct-hash-source-hash' === ($kiwiDirectImagePaint['image']['sourceImage']['hash'] ?? null), 'kiwi-selective-decodes-source-image-hash');
    $assert(320.0 === ($kiwiDirectImagePaint['imageThumbnail']['width'] ?? null), 'kiwi-selective-decodes-thumbnail-width');
    $assert('Alt text direct-hash' === ($kiwiDirectImagePaint['altText'] ?? null), 'kiwi-selective-decodes-image-alt-text');
    $assert('PNG' === ($kiwiDirectImagePaint['exportSettings'][0]['format'] ?? null), 'kiwi-selective-decodes-paint-export-settings-format');
    $assert('SCALE' === ($kiwiDirectImagePaint['exportSettings'][0]['constraint']['type'] ?? null), 'kiwi-selective-decodes-paint-export-constraint-type');
    $assert(2.0 === ($kiwiDirectImagePaint['exportSettings'][0]['constraint']['value'] ?? null), 'kiwi-selective-decodes-paint-export-constraint-value');
    $assert('SVG' === ($kiwiImagePaintNode['exportSettings'][0]['format'] ?? null), 'kiwi-selective-decodes-node-export-settings');
    $assert('publish-asset-node' === ($kiwiImagePaintNode['publishID'] ?? null), 'kiwi-selective-decodes-node-publish-id');
    $assert('library-asset-node' === ($kiwiImagePaintNode['sourceLibraryKey'] ?? null), 'kiwi-selective-decodes-node-source-library-key');
    $assert(false === ($kiwiOverrideImagePaint['imageShouldColorManage'] ?? null), 'kiwi-selective-decodes-override-image-color-management');
    $assert(9 === ($kiwiOverrideImagePaint['animationFrame'] ?? null), 'kiwi-selective-decodes-override-image-animation-frame');
    $assert(9001 === ($kiwiDirectFillStyle['sessionID'] ?? null) && 101 === ($kiwiDirectFillStyle['localID'] ?? null), 'kiwi-selective-decodes-style-id-for-fill');
    $assert(9001 === ($kiwiDirectStrokeStyle['sessionID'] ?? null) && 102 === ($kiwiDirectStrokeStyle['localID'] ?? null), 'kiwi-selective-decodes-style-id-for-stroke-fill');
    $assert(9002 === ($kiwiOverrideFillStyle['sessionID'] ?? null) && 201 === ($kiwiOverrideFillStyle['localID'] ?? null), 'kiwi-selective-decodes-override-style-id-for-fill');
    $assert(9002 === ($kiwiOverrideStrokeStyle['sessionID'] ?? null) && 202 === ($kiwiOverrideStrokeStyle['localID'] ?? null), 'kiwi-selective-decodes-override-style-id-for-stroke-fill');
    $kiwiImageNormalizer = new ScenegraphNormalizer();
    $kiwiImagePaintNode['id'] = 'kiwi:asset-node';
    $kiwiImageNormalized = $kiwiImageNormalizer->normalize(array('name' => 'Kiwi Asset Metadata Fixture', 'nodes' => array($kiwiImagePaintNode)));
    $kiwiImageNormalizedNode = $kiwiImageNormalized['nodes'][0] ?? array();
    $kiwiNormalizedPaint = $kiwiImageNormalizedNode['figma_paints']['fills'][0] ?? array();
    $assert('direct-hash-asset-id' === ($kiwiNormalizedPaint['image']['assetRef']['id'] ?? null), 'kiwi-normalizes-image-asset-ref-id');
    $assert('direct-hash-source-hash' === ($kiwiNormalizedPaint['image']['sourceImage']['hash'] ?? null), 'kiwi-normalizes-source-image-ref');
    $assert(640.0 === ($kiwiNormalizedPaint['image']['width'] ?? null), 'kiwi-normalizes-image-width');
    $assert('Alt text direct-hash' === ($kiwiNormalizedPaint['altText'] ?? null), 'kiwi-normalizes-image-alt-text');
    $assert('PNG' === ($kiwiNormalizedPaint['exportSettings'][0]['format'] ?? null), 'kiwi-normalizes-paint-export-settings');
    $assert('SVG' === ($kiwiImageNormalizedNode['figma_asset_metadata']['exportSettings'][0]['format'] ?? null), 'kiwi-normalizes-node-export-settings');
    $assert('publish-asset-node' === ($kiwiImageNormalizedNode['figma_asset_metadata']['publishID'] ?? null), 'kiwi-normalizes-node-publish-id');
    $assetReferenceMap = array();
    foreach ( $kiwiImageNormalized['asset_references'] ?? array() as $reference ) {
        $assetReferenceMap[$reference['source_key'] ?? ''] = $reference['ref'] ?? '';
    }
    $assert('direct-hash-asset-id' === ($assetReferenceMap['image.assetRef.id'] ?? null), 'kiwi-asset-reference-includes-nested-asset-ref');
    $assert('direct-hash-source-hash' === ($assetReferenceMap['image.sourceImage.hash'] ?? null), 'kiwi-asset-reference-includes-source-image-hash');
    
    $kiwiDerivedTextSchema = $kiwiDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_derived_text_schema_fixture());
    $kiwiDerivedTextMessage = $kiwiDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_derived_text_message_fixture(),
        $kiwiDerivedTextSchema['schema'] ?? array()
    );
    $kiwiDerivedText = $kiwiDerivedTextMessage['message']['nodeChanges'][0]['derivedTextData'] ?? array();
    $assert(120.0 === ($kiwiDerivedText['layoutSize']['x'] ?? null), 'kiwi-selective-decodes-derived-text-layout-width');
    $assert(1 === count($kiwiDerivedText['baselines'] ?? array()), 'kiwi-selective-decodes-derived-text-baselines');
    $assert(24.0 === ($kiwiDerivedText['baselines'][0]['lineHeight'] ?? null), 'kiwi-selective-decodes-derived-text-baseline-line-height');
    $assert(! array_key_exists('glyphs', $kiwiDerivedText), 'kiwi-selective-skips-derived-text-glyphs');
    $assert('Inter' === ($kiwiDerivedText['fontMetaData']['key']['family'] ?? null), 'kiwi-selective-decodes-derived-text-font-family');
    $assert(700 === ($kiwiDerivedText['fontMetaData']['fontWeight'] ?? null), 'kiwi-selective-decodes-derived-text-font-weight');
    $assert(3 === ($kiwiDerivedText['truncationStartIndex'] ?? null), 'kiwi-selective-decodes-derived-text-truncation-start');
    $assert(24.0 === ($kiwiDerivedText['truncatedHeight'] ?? null), 'kiwi-selective-decodes-derived-text-truncated-height');
    $assert(array(0.0, 12.5) === ($kiwiDerivedText['logicalIndexToCharacterOffsetMap'] ?? null), 'kiwi-selective-decodes-derived-text-logical-offset-map');
    $assert('RTL' === ($kiwiDerivedText['derivedLines'][0]['directionality'] ?? null), 'kiwi-selective-decodes-derived-text-line-directionality');
    $kiwiDerivedTextWithGlyphsMessage = $kiwiDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_derived_text_message_fixture(),
        $kiwiDerivedTextSchema['schema'] ?? array(),
        'Message',
        $kiwiDecoder->scenegraphFieldPolicyWithTextGlyphs()
    );
    $kiwiDerivedTextWithGlyphs = $kiwiDerivedTextWithGlyphsMessage['message']['nodeChanges'][0]['derivedTextData'] ?? array();
    $assert(7 === ($kiwiDerivedTextWithGlyphs['glyphs'][0]['commandsBlob'] ?? null), 'kiwi-selective-opt-in-decodes-derived-text-glyph-blob-ref');

    $kiwiAutoLayoutSchema = $kiwiDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_auto_layout_schema_fixture());
    $kiwiAutoLayoutMessage = $kiwiDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_auto_layout_message_fixture(),
        $kiwiAutoLayoutSchema['schema'] ?? array()
    );
    $kiwiAutoLayoutNode = $kiwiAutoLayoutMessage['message']['nodeChanges'][0] ?? array();
    $assert(320.0 === ($kiwiAutoLayoutNode['stackWidth'] ?? null), 'kiwi-selective-decodes-stack-width');
    $assert(180.0 === ($kiwiAutoLayoutNode['stackHeight'] ?? null), 'kiwi-selective-decodes-stack-height');
    $assert('HORIZONTAL' === ($kiwiAutoLayoutNode['stackMode'] ?? null), 'kiwi-selective-decodes-stack-mode');
    $assert('RESIZE_TO_FIT' === ($kiwiAutoLayoutNode['stackPrimarySizing'] ?? null), 'kiwi-selective-decodes-stack-primary-sizing');
    $assert(24.0 === ($kiwiAutoLayoutNode['stackCounterSpacing'] ?? null), 'kiwi-selective-decodes-stack-counter-spacing');
    $assert('WRAP' === ($kiwiAutoLayoutNode['stackWrap'] ?? null), 'kiwi-selective-decodes-stack-wrap');
    $assert(true === ($kiwiAutoLayoutNode['stackReverseZIndex'] ?? null), 'kiwi-selective-decodes-stack-reverse-z-index');
    $assert(1.0 === ($kiwiAutoLayoutNode['layoutGrow'] ?? null), 'kiwi-selective-decodes-layout-grow-alias');
    $assert('STRETCH' === ($kiwiAutoLayoutNode['layoutAlign'] ?? null), 'kiwi-selective-decodes-layout-align-alias');
    $assert('LEFT_RIGHT' === ($kiwiAutoLayoutNode['constraints']['horizontal'] ?? null), 'kiwi-selective-decodes-constraints-horizontal');
    $assert(64.0 === ($kiwiAutoLayoutNode['minSize']['x'] ?? null), 'kiwi-selective-decodes-min-size-width');

    $kiwiAutoLayoutNode['id'] = 'kiwi:auto-layout';
    $kiwiAutoLayoutNormalized = ( new ScenegraphNormalizer() )->normalize(array('name' => 'Kiwi Auto Layout Fixture', 'nodes' => array($kiwiAutoLayoutNode)));
    $kiwiAutoLayoutNormalizedNode = $kiwiAutoLayoutNormalized['nodes'][0] ?? array();
    $assert(320.0 === ($kiwiAutoLayoutNormalizedNode['box']['width'] ?? null), 'kiwi-normalizes-stack-width-to-box-width');
    $assert(180.0 === ($kiwiAutoLayoutNormalizedNode['box']['height'] ?? null), 'kiwi-normalizes-stack-height-to-box-height');
    $assert('flex' === ($kiwiAutoLayoutNormalizedNode['layout']['display'] ?? null), 'kiwi-normalizes-stack-mode-to-flex');
    $assert('wrap' === ($kiwiAutoLayoutNormalizedNode['layout']['flex_wrap'] ?? null), 'kiwi-normalizes-stack-wrap-to-css-wrap');
    $assert(24.0 === ($kiwiAutoLayoutNormalizedNode['layout']['counter_axis_spacing'] ?? null), 'kiwi-normalizes-counter-axis-spacing');
    $assert(true === ($kiwiAutoLayoutNormalizedNode['layout']['reverse_z_index'] ?? null), 'kiwi-normalizes-reverse-z-index');
    $assert('LEFT_RIGHT' === ($kiwiAutoLayoutNormalizedNode['layout']['constraints']['horizontal'] ?? null), 'kiwi-normalizes-nested-constraints');
    $assert(64.0 === ($kiwiAutoLayoutNormalizedNode['layout']['min_width'] ?? null), 'kiwi-normalizes-min-width');
    $assert(1.0 === ($kiwiAutoLayoutNormalizedNode['layout']['grow'] ?? null), 'kiwi-normalizes-layout-grow-alias');
    $assert('STRETCH' === ($kiwiAutoLayoutNormalizedNode['layout']['align'] ?? null), 'kiwi-normalizes-layout-align-alias');

    $kiwiStateGroupSchema = $kiwiDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_state_group_schema_fixture());
    $kiwiStateGroupMessage = $kiwiDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_state_group_message_fixture(),
        $kiwiStateGroupSchema['schema'] ?? array()
    );
    $kiwiStateGroupNode = $kiwiStateGroupMessage['message']['nodeChanges'][0] ?? array();
    $kiwiVariantNode = $kiwiStateGroupMessage['message']['nodeChanges'][1] ?? array();
    $assert(true === ($kiwiStateGroupNode['isStateGroup'] ?? null), 'kiwi-selective-decodes-state-group-flag');
    $assert('Screen Size' === ($kiwiStateGroupNode['stateGroupPropertyValueOrders'][0]['property'] ?? null), 'kiwi-selective-decodes-state-group-order-property');
    $assert(array('Desktop', 'Mobile') === ($kiwiStateGroupNode['stateGroupPropertyValueOrders'][0]['values'] ?? null), 'kiwi-selective-decodes-state-group-order-values');
    $assert('Desktop' === ($kiwiVariantNode['variantPropSpecs'][0]['value'] ?? null), 'kiwi-selective-decodes-variant-prop-spec-value');

    $kiwiDerivedSymbolSchema = $kiwiDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_derived_symbol_schema_fixture());
    $kiwiDerivedSymbolMessage = $kiwiDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_derived_symbol_message_fixture(),
        $kiwiDerivedSymbolSchema['schema'] ?? array()
    );
    $kiwiDerivedSymbolNode = $kiwiDerivedSymbolMessage['message']['nodeChanges'][0] ?? array();
    $kiwiDerivedSymbolOverride = $kiwiDerivedSymbolNode['derivedSymbolData']['symbolOverrides'][0] ?? array();
    $assert('40:1' === blocks_engine_figma_transformer_kiwi_format_guid($kiwiDerivedSymbolNode['derivedSymbolData']['symbolID']['guid'] ?? null), 'kiwi-selective-decodes-derived-symbol-id');
    $assert('40:2' === blocks_engine_figma_transformer_kiwi_format_guid($kiwiDerivedSymbolOverride['guidPath']['guids'][0] ?? null), 'kiwi-selective-decodes-derived-symbol-override-guid-path');
    $assert('Derived override' === ($kiwiDerivedSymbolOverride['textData']['characters'] ?? null), 'kiwi-selective-decodes-derived-symbol-override-text');
    $kiwiDerivedSymbolResolverDiagnostics = array();
    $kiwiDerivedSymbolResolverFields = ( new \Automattic\BlocksEngine\FigmaTransformer\Scenegraph\InstanceResolver() )->normalizeInstanceOverrides($kiwiDerivedSymbolNode, 'kiwi-derived-symbol:instance', $kiwiDerivedSymbolResolverDiagnostics);
    $assert('Derived override' === ($kiwiDerivedSymbolResolverFields['40:2']['characters'] ?? null), 'kiwi-derived-symbol-resolver-reads-struct-overrides');

    $kiwiStateGroupNormalizer = new ScenegraphNormalizer();
    $kiwiStateGroupNormalized = $kiwiStateGroupNormalizer->normalize(array(
        'name'  => 'Kiwi State Group Metadata Fixture',
        'nodes' => array(
            array(
                'id'                             => 'state-group:root',
                'type'                           => 'FRAME',
                'name'                           => 'Newsletter Signup',
                'isStateGroup'                   => true,
                'stateGroupPropertyValueOrders'  => array(
                    array('property' => 'Screen Size', 'values' => array('Desktop', 'Mobile')),
                ),
                'componentPropDefs'              => array(
                    array('id' => array('sessionID' => 2422394609, 'localID' => 4048757538), 'name' => 'Screen Size'),
                ),
            ),
            array(
                'id'               => 'state-group:desktop',
                'type'             => 'SYMBOL',
                'name'             => 'Screen Size=Desktop',
                'variantPropSpecs' => array(
                    array('propDefId' => array('sessionID' => 2422394609, 'localID' => 4048757538), 'value' => 'Desktop'),
                ),
                'componentPropRefs' => array(
                    array('defID' => array('sessionID' => 2422394609, 'localID' => 4048757538), 'componentPropNodeField' => 'VARIANT'),
                ),
            ),
        ),
    ));
    $kiwiStateGroupMetadata = $kiwiStateGroupNormalized['nodes'][0]['figma_component'] ?? array();
    $kiwiVariantMetadata = $kiwiStateGroupNormalized['nodes'][1]['figma_component'] ?? array();
    $assert(true === ($kiwiStateGroupMetadata['state_group'] ?? null), 'kiwi-state-group-normalizes-component-flag');
    $assert('Screen Size' === ($kiwiStateGroupMetadata['state_group_property_value_orders'][0]['property'] ?? null), 'kiwi-state-group-normalizes-order-property');
    $assert(array('Desktop', 'Mobile') === ($kiwiStateGroupMetadata['state_group_property_value_orders'][0]['values'] ?? null), 'kiwi-state-group-normalizes-order-values');
    $assert('Screen Size' === ($kiwiStateGroupMetadata['component_prop_defs'][0]['name'] ?? null), 'kiwi-component-prop-defs-preserved-in-metadata');
    $assert('2422394609:4048757538' === ($kiwiVariantMetadata['variant_prop_specs'][0]['prop_def_id'] ?? null), 'kiwi-variant-normalizes-prop-def-id');
    $assert('Desktop' === ($kiwiVariantMetadata['variant_prop_specs'][0]['value'] ?? null), 'kiwi-variant-normalizes-value');
    $assert('VARIANT' === ($kiwiVariantMetadata['component_prop_refs'][0]['componentPropNodeField'] ?? null), 'kiwi-component-prop-refs-preserved-in-metadata');

    $kiwiDocumentMetadataSchema = $kiwiDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_document_metadata_schema_fixture());
    $kiwiDocumentMetadataMessage = $kiwiDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_document_metadata_message_fixture(),
        $kiwiDocumentMetadataSchema['schema'] ?? array()
    );
    $kiwiDocumentMetadataRoot = $kiwiDocumentMetadataMessage['message'] ?? array();
    $kiwiDocumentMetadataNode = $kiwiDocumentMetadataRoot['nodeChanges'][0] ?? array();
    $assert(42 === ($kiwiDocumentMetadataRoot['fileVersion'] ?? null), 'kiwi-document-metadata-decodes-root-file-version');
    $assert('COMPLETED' === ($kiwiDocumentMetadataRoot['sectionStatus']['status'] ?? null), 'kiwi-document-metadata-decodes-root-section-status');
    $assert('DESIGN' === ($kiwiDocumentMetadataNode['phase'] ?? null), 'kiwi-document-metadata-decodes-phase');
    $assert(false === ($kiwiDocumentMetadataNode['autoRename'] ?? null), 'kiwi-document-metadata-decodes-auto-rename');
    $assert('editor-1' === ($kiwiDocumentMetadataNode['editInfo']['userID'] ?? null), 'kiwi-document-metadata-decodes-edit-info');
    $assert('plugin-1' === ($kiwiDocumentMetadataNode['pluginData']['pluginID'] ?? null), 'kiwi-document-metadata-decodes-plugin-data');
    $assert(7 === ($kiwiDocumentMetadataNode['version'] ?? null), 'kiwi-document-metadata-decodes-version');
    $assert('v7-public' === ($kiwiDocumentMetadataNode['userFacingVersion'] ?? null), 'kiwi-document-metadata-decodes-user-facing-version');
    $assert('pub-123' === ($kiwiDocumentMetadataNode['publishID'] ?? null), 'kiwi-document-metadata-decodes-publish-id');
    $assert('library-key-1' === ($kiwiDocumentMetadataNode['sourceLibraryKey'] ?? null), 'kiwi-document-metadata-decodes-source-library-key');
    $assert('annotation-1' === ($kiwiDocumentMetadataNode['annotations'][0]['id'] ?? null), 'kiwi-document-metadata-decodes-annotations');
    $assert('category-1' === ($kiwiDocumentMetadataNode['annotationCategories'][0]['id'] ?? null), 'kiwi-document-metadata-decodes-annotation-categories');
    $assert('BUILD' === ($kiwiDocumentMetadataNode['sectionStatus'] ?? null), 'kiwi-document-metadata-decodes-section-status');
    $assert('COMPLETED' === ($kiwiDocumentMetadataNode['sectionStatusInfo']['status'] ?? null), 'kiwi-document-metadata-decodes-section-status-info');
    $assert('BUILD' === ($kiwiDocumentMetadataNode['handoffStatus']['status'] ?? null), 'kiwi-document-metadata-decodes-handoff-status');
    $assert(true === ($kiwiDocumentMetadataNode['internalOnly'] ?? null), 'kiwi-document-metadata-decodes-internal-only');
    $assert(true === ($kiwiDocumentMetadataNode['isPageDivider'] ?? null), 'kiwi-document-metadata-decodes-page-divider');
    $assert('origin-file-1' === ($kiwiDocumentMetadataNode['originFileKey'] ?? null), 'kiwi-document-metadata-decodes-origin-file-key');
    $assert('session-1' === ($kiwiDocumentMetadataNode['sessionID'] ?? null), 'kiwi-document-metadata-decodes-session-id');

    $kiwiDocumentMetadataNormalizer = new ScenegraphNormalizer();
    $kiwiDocumentMetadataNormalized = $kiwiDocumentMetadataNormalizer->normalize(array(
        'name'          => 'Kiwi Document Metadata Fixture',
        'fileVersion'   => 42,
        'sectionStatus' => array('status' => 'COMPLETED', 'description' => 'File complete'),
        'nodes'         => array(
            array_merge($kiwiDocumentMetadataNode, array('id' => 'metadata:page', 'width' => 320, 'height' => 180)),
        ),
    ));
    $kiwiDocumentMetadata = $kiwiDocumentMetadataNormalized['nodes'][0]['figma_metadata'] ?? array();
    $kiwiDocumentMetadataReport = $kiwiDocumentMetadataNormalized['source_report']['figma_metadata'] ?? array();
    $assert(42 === ($kiwiDocumentMetadataReport['file_version'] ?? null), 'kiwi-document-metadata-normalizes-root-file-version');
    $assert('DESIGN' === ($kiwiDocumentMetadata['phase'] ?? null), 'kiwi-document-metadata-normalizes-phase');
    $assert(false === ($kiwiDocumentMetadata['auto_rename'] ?? null), 'kiwi-document-metadata-normalizes-auto-rename');
    $assert('editor-1' === ($kiwiDocumentMetadata['edit_info']['user_id'] ?? null), 'kiwi-document-metadata-normalizes-edit-info');
    $assert('plugin-1' === ($kiwiDocumentMetadata['plugin_data']['plugin_id'] ?? null), 'kiwi-document-metadata-normalizes-plugin-data');
    $assert('v7-public' === ($kiwiDocumentMetadata['user_facing_version'] ?? null), 'kiwi-document-metadata-normalizes-user-facing-version');
    $assert('pub-123' === ($kiwiDocumentMetadata['publish_id'] ?? null), 'kiwi-document-metadata-normalizes-publish-id');
    $assert('library-key-1' === ($kiwiDocumentMetadata['source_library_key'] ?? null), 'kiwi-document-metadata-normalizes-source-library-key');
    $assert('annotation-1' === ($kiwiDocumentMetadata['annotations'][0]['id'] ?? null), 'kiwi-document-metadata-normalizes-annotations');
    $assert('category-1' === ($kiwiDocumentMetadata['annotation_categories'][0]['id'] ?? null), 'kiwi-document-metadata-normalizes-annotation-categories');
    $assert(true === ($kiwiDocumentMetadata['internal_only'] ?? null), 'kiwi-document-metadata-normalizes-internal-only');
    $assert(true === ($kiwiDocumentMetadata['is_page_divider'] ?? null), 'kiwi-document-metadata-normalizes-page-divider');
    $assert('origin-file-1' === ($kiwiDocumentMetadata['origin_file_key'] ?? null), 'kiwi-document-metadata-normalizes-origin-file-key');
    $assert('session-1' === ($kiwiDocumentMetadata['session_id'] ?? null), 'kiwi-document-metadata-normalizes-session-id');

    $metadataVisualBaseline = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Metadata Visual Baseline',
        'nodes' => array(array('id' => 'metadata:visual', 'type' => 'FRAME', 'name' => 'Metadata Visual', 'width' => 320, 'height' => 180)),
    ));
    $metadataVisualWithFields = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Metadata Visual Baseline',
        'nodes' => array(array_merge($kiwiDocumentMetadataNode, array('id' => 'metadata:visual', 'type' => 'FRAME', 'name' => 'Metadata Visual', 'width' => 320, 'height' => 180))),
    ));
    $assert($fileContent($metadataVisualBaseline, 'index.html') === $fileContent($metadataVisualWithFields, 'index.html'), 'kiwi-document-metadata-does-not-change-html');
    $assert($fileContent($metadataVisualBaseline, 'style.css') === $fileContent($metadataVisualWithFields, 'style.css'), 'kiwi-document-metadata-does-not-change-css');

    $kiwiFrameMaskResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'document' => array(
            'id'       => 'kiwi-mask:document',
            'type'     => 'DOCUMENT',
            'name'     => 'Document',
            'children' => array(
                array(
                    'id'                => 'kiwi-mask:frame',
                    'type'              => 'FRAME',
                    'name'              => 'Kiwi Mask Frame',
                    'width'             => 120,
                    'height'            => 80,
                    'frameMaskDisabled' => false,
                ),
            ),
        ),
    ));
    $kiwiFrameMaskDisabledResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'document' => array(
            'id'       => 'kiwi-mask:disabled-document',
            'type'     => 'DOCUMENT',
            'name'     => 'Document',
            'children' => array(
                array(
                    'id'                => 'kiwi-mask:disabled-frame',
                    'type'              => 'FRAME',
                    'name'              => 'Kiwi Disabled Mask Frame',
                    'width'             => 120,
                    'height'            => 80,
                    'frameMaskDisabled' => true,
                ),
            ),
        ),
    ));
    $kiwiFrameMaskCss = $fileContent($kiwiFrameMaskResult, 'style.css');
    $kiwiFrameMaskDisabledCss = $fileContent($kiwiFrameMaskDisabledResult, 'style.css');
    $assert(str_contains($kiwiFrameMaskCss, '.figma-node-kiwi-mask-frame-kiwi-mask-frame{width:120px;height:80px;overflow:hidden}'), 'kiwi-frame-mask-disabled-false-clips-content');
    $assert(str_contains($kiwiFrameMaskDisabledCss, '.figma-node-kiwi-mask-disabled-frame-kiwi-disabled-mask-frame{width:120px;height:80px}'), 'kiwi-frame-mask-disabled-true-does-not-clip');
    
    $wirePayload = SyntheticFigKiwiFixtureBuilder::sampleWirePayload();
    $wireCanvasResult = ( new FigKiwiParser() )->parse(
        SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::zlibChunk($wirePayload)))
    );
    $wire = $wireCanvasResult['canvas']['chunks'][0]['payload']['wire'] ?? array();
    $wireRecords = $wire['records'] ?? array();
    
    $assert('binary' === ($wireCanvasResult['canvas']['chunks'][0]['payload']['classification'] ?? null), 'fig-kiwi-wire-payload-remains-binary');
    $assert('protobuf_wire' === ($wire['format'] ?? null), 'fig-kiwi-wire-format');
    $assert(true === ($wire['complete'] ?? null), 'fig-kiwi-wire-complete');
    $assert(3 === ($wire['record_count'] ?? null), 'fig-kiwi-wire-record-count');
    $assert(1 === ($wireRecords[0]['field_number'] ?? null), 'fig-kiwi-wire-varint-field-number');
    $assert(0 === ($wireRecords[0]['wire_type'] ?? null), 'fig-kiwi-wire-varint-type');
    $assert(150 === ($wireRecords[0]['value'] ?? null), 'fig-kiwi-wire-varint-value');
    $assert('hello' === ($wireRecords[1]['text_preview'] ?? null), 'fig-kiwi-wire-length-text-preview');
    $assert('01020304' === ($wireRecords[2]['preview_hex'] ?? null), 'fig-kiwi-wire-fixed32-preview');
    
    $unknownBinaryResult = ( new FigKiwiParser() )->parse(
        SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::zlibChunk("\x00\x01\x02unknown")))
    );
    $unknownBinaryPayload = $unknownBinaryResult['canvas']['chunks'][0]['payload'] ?? array();
    $assert('binary' === ($unknownBinaryPayload['classification'] ?? null), 'fig-kiwi-unknown-binary-classification');
    $assert(10 === ($unknownBinaryPayload['bytes'] ?? null), 'fig-kiwi-unknown-binary-byte-count');
    $assert('000102756e6b6e6f776e' === ($unknownBinaryPayload['preview_hex'] ?? null), 'fig-kiwi-unknown-binary-preview');
    $assert('zero_field_key' === ($unknownBinaryPayload['wire']['reason'] ?? null), 'fig-kiwi-unknown-binary-wire-stop-reason');
    
    $truncatedVarintResult = ( new FigKiwiParser() )->parse(
        SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::zlibChunk(SyntheticFigKiwiFixtureBuilder::wireVarint(8) . "\x80")))
    );
    $truncatedWire = $truncatedVarintResult['canvas']['chunks'][0]['payload']['wire'] ?? array();
    $assert(false === ($truncatedWire['complete'] ?? null), 'fig-kiwi-truncated-varint-incomplete');
    $assert(true === ($truncatedWire['truncated'] ?? null), 'fig-kiwi-truncated-varint-flag');
    $assert('truncated_varint_value' === ($truncatedWire['reason'] ?? null), 'fig-kiwi-truncated-varint-reason');
    
    $unsupportedWireResult = ( new FigKiwiParser() )->parse(
        SyntheticFigKiwiFixtureBuilder::canvas(array(SyntheticFigKiwiFixtureBuilder::zlibChunk(SyntheticFigKiwiFixtureBuilder::wireVarint(11) . 'tail')))
    );
    $unsupportedWire = $unsupportedWireResult['canvas']['chunks'][0]['payload']['wire'] ?? array();
    $assert(false === ($unsupportedWire['complete'] ?? null), 'fig-kiwi-unsupported-wire-incomplete');
    $assert(false === ($unsupportedWire['truncated'] ?? null), 'fig-kiwi-unsupported-wire-not-truncated');
    $assert('unsupported_wire_type' === ($unsupportedWire['reason'] ?? null), 'fig-kiwi-unsupported-wire-reason');
    
    $multiCandidateFixture = SyntheticFigKiwiFixtureBuilder::figArchive(
        SyntheticFigKiwiFixtureBuilder::canvas(array(
            SyntheticFigKiwiFixtureBuilder::jsonZlibChunk(array('metadata' => array('ignored' => true))),
            SyntheticFigKiwiFixtureBuilder::jsonZlibChunk(SyntheticFigKiwiFixtureBuilder::nodeChangesPayload('First Candidate')),
            SyntheticFigKiwiFixtureBuilder::jsonZlibChunk(SyntheticFigKiwiFixtureBuilder::nodeChangesPayload('Second Candidate')),
        ))
    );
    $multiCandidateResult = blocks_engine_figma_transformer_transform_file($multiCandidateFixture);
    @unlink($multiCandidateFixture);
    $multiCandidateHtml = $fileContent($multiCandidateResult, 'index.html');
    $assert('success' === ($multiCandidateResult['status'] ?? null), 'fig-kiwi-multiple-candidates-transform-success');
    $assert(str_contains($multiCandidateHtml, 'First Candidate First'), 'fig-kiwi-multiple-candidates-renders-first-scenegraph');
    $assert(! str_contains($multiCandidateHtml, 'Second Candidate First'), 'fig-kiwi-multiple-candidates-stops-after-first-scenegraph');
    
    $nodeChangesResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'         => 'Node Changes Fixture',
        'NODE_CHANGES' => array(
            '3:1' => array(
                'node' => array(
                    'id'                  => '3:1',
                    'type'                => 'FRAME',
                    'name'                => 'Landing',
                    'absoluteBoundingBox' => array('x' => 0, 'y' => 0),
                    'children'            => array(
                        array(
                            'id'                  => '3:3',
                            'type'                => 'TEXT',
                            'name'                => 'Body',
                            'characters'          => 'Second',
                            'absoluteBoundingBox' => array('x' => 0, 'y' => 120),
                        ),
                        array(
                            'id'                  => '3:2',
                            'type'                => 'TEXT',
                            'name'                => 'Heading',
                            'characters'          => 'First',
                            'absoluteBoundingBox' => array('x' => 0, 'y' => 20),
                        ),
                        array(
                            'id'    => '3:4',
                            'type'  => 'RECTANGLE',
                            'name'  => 'Photo',
                            'fills' => array(
                                array('type' => 'IMAGE', 'imageRef' => 'image-hash-1'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    
    $nodeChangesHtml = (string) ($nodeChangesResult['files'][0]['content'] ?? '');
    $scenegraphReport = $nodeChangesResult['source_reports']['figma']['scenegraph'] ?? array();
    
    $assert('success' === ($nodeChangesResult['status'] ?? null), 'node-changes-transform-success');
    $assert(4 === ($nodeChangesResult['metrics']['node_count'] ?? null), 'node-changes-node-count');
    $assert(2 === ($nodeChangesResult['metrics']['text_node_count'] ?? null), 'node-changes-text-count');
    $assert(1 === ($nodeChangesResult['metrics']['asset_reference_count'] ?? null), 'node-changes-asset-count');
    $assert('3:1' === ($scenegraphReport['selected_frame_id'] ?? null), 'node-changes-selected-frame');
    $assert(false !== strpos($nodeChangesHtml, 'First') && false !== strpos($nodeChangesHtml, 'Second'), 'node-changes-html-text');
    $assert(strpos($nodeChangesHtml, 'First') < strpos($nodeChangesHtml, 'Second'), 'node-changes-stable-child-sort');
    
    $layerOrderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'         => 'Layer Order Fixture',
        'NODE_CHANGES' => array(
            'layer:root' => array(
                'node' => array(
                    'id'          => 'layer:root',
                    'type'        => 'FRAME',
                    'name'        => 'Layer root',
                    'width'       => 300,
                    'height'      => 200,
                    'resizeToFit' => true,
                    'children'    => array(
                        array(
                            'id'          => 'layer:top',
                            'type'        => 'RECTANGLE',
                            'name'        => 'Top bubble',
                            'width'       => 100,
                            'height'      => 40,
                            'parentIndex' => array('position' => 'b'),
                        ),
                        array(
                            'id'          => 'layer:bottom',
                            'type'        => 'RECTANGLE',
                            'name'        => 'Bottom image',
                            'width'       => 200,
                            'height'      => 120,
                            'parentIndex' => array('position' => 'a'),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $layerOrderHtml = $fileContent($layerOrderResult, 'index.html');
    $assert(strpos($layerOrderHtml, 'data-figma-node-id="layer:bottom"') < strpos($layerOrderHtml, 'data-figma-node-id="layer:top"'), 'freeform-layer-order-uses-parent-index-position');

    $numericLayerOrderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'         => 'Numeric Layer Order Fixture',
        'NODE_CHANGES' => array(
            'numeric-layer:root' => array(
                'node' => array(
                    'id'          => 'numeric-layer:root',
                    'type'        => 'FRAME',
                    'name'        => 'Numeric layer root',
                    'width'       => 300,
                    'height'      => 200,
                    'resizeToFit' => true,
                    'children'    => array(
                        array(
                            'id'          => 'numeric-layer:front',
                            'type'        => 'RECTANGLE',
                            'name'        => 'Front',
                            'width'       => 100,
                            'height'      => 40,
                            'parentIndex' => array('position' => '10'),
                        ),
                        array(
                            'id'          => 'numeric-layer:back',
                            'type'        => 'RECTANGLE',
                            'name'        => 'Back',
                            'width'       => 200,
                            'height'      => 120,
                            'parentIndex' => array('position' => '2'),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $numericLayerOrderHtml = $fileContent($numericLayerOrderResult, 'index.html');
    $assert(strpos($numericLayerOrderHtml, 'data-figma-node-id="numeric-layer:back"') < strpos($numericLayerOrderHtml, 'data-figma-node-id="numeric-layer:front"'), 'freeform-layer-order-uses-numeric-parent-index-position');

    $overflowWrapperResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Overflow Wrapper Fixture',
        'nodes' => array(
            array(
                'id'       => 'overflow:root',
                'type'     => 'FRAME',
                'name'     => 'Overflow Root',
                'width'    => 100,
                'height'   => 100,
                'children' => array(
                    array(
                        'id'       => 'overflow:wrapper',
                        'type'     => 'FRAME',
                        'name'     => 'Overflow Wrapper',
                        'width'    => 1,
                        'height'   => 10,
                        'children' => array(
                            array(
                                'id'     => 'overflow:child',
                                'type'   => 'RECTANGLE',
                                'name'   => 'Overflow Child',
                                'x'      => 4,
                                'y'      => 2,
                                'width'  => 20,
                                'height' => 12,
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $overflowWrapperCss = $fileContent($overflowWrapperResult, 'style.css');
    $assert(str_contains($overflowWrapperCss, '.figma-node-overflow-wrapper-overflow-wrapper{width:1px;height:10px;position:relative}'), 'overflow-wrapper-becomes-positioned-container');
    $assert(str_contains($overflowWrapperCss, '.figma-node-overflow-child-overflow-child{width:20px;height:12px;position:absolute;left:4px;top:2px}'), 'overflow-wrapper-child-keeps-local-position');
}

function blocks_engine_figma_transformer_create_fig_wrapper_fixture(): string
{
    $inner = tempnam(sys_get_temp_dir(), 'blocks-engine-inner-fig-');
    $outer = tempnam(sys_get_temp_dir(), 'blocks-engine-wrapper-fig-');
    if ( false === $inner || false === $outer ) {
        throw new RuntimeException('Could not create temporary fig fixture paths.');
    }

    $canvas = 'fig-kiwi'
        . pack('V', 106)
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate(json_encode(blocks_engine_figma_transformer_nodes_candidate_fixture(), JSON_THROW_ON_ERROR)))
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate('{"NODE_CHANGES":'))
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate(json_encode(blocks_engine_figma_transformer_node_changes_fixture(), JSON_THROW_ON_ERROR)))
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate('synthetic kiwi dictionary'))
        . blocks_engine_figma_transformer_kiwi_chunk(blocks_engine_figma_transformer_zstd_fixture_payload());

    $innerZip = new ZipArchive();
    if ( true !== $innerZip->open($inner, ZipArchive::OVERWRITE) ) {
        throw new RuntimeException('Could not open inner fig ZIP.');
    }
    $innerZip->addFromString('canvas.fig', $canvas);
    $innerZip->addFromString('meta.json', json_encode(array('name' => 'Synthetic Fixture'), JSON_THROW_ON_ERROR));
    $innerZip->addFromString('images/synthetic', 'asset');
    $innerZip->close();

    $outerZip = new ZipArchive();
    if ( true !== $outerZip->open($outer, ZipArchive::OVERWRITE) ) {
        throw new RuntimeException('Could not open wrapper fig ZIP.');
    }
    $outerZip->addFromString('inner.fig', (string) file_get_contents($inner));
    $outerZip->close();

    @unlink($inner);

    return $outer;
}

function blocks_engine_figma_transformer_create_pending_decoder_fig_wrapper_fixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'blocks-engine-pending-fig-');
    if ( false === $path ) {
        throw new RuntimeException('Could not create temporary pending fig fixture path.');
    }

    $canvas = 'fig-kiwi'
        . pack('V', 106)
        . blocks_engine_figma_transformer_kiwi_chunk(gzdeflate('synthetic undecoded canvas payload'));

    $zip = new ZipArchive();
    if ( true !== $zip->open($path, ZipArchive::OVERWRITE) ) {
        throw new RuntimeException('Could not open pending fig ZIP.');
    }
    $zip->addFromString('canvas.fig', $canvas);
    $zip->close();

    return $path;
}

function blocks_engine_figma_transformer_kiwi_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('MessageType')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_schema_field('NODE_CHANGES', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', -6, true, 4);
}

function blocks_engine_figma_transformer_kiwi_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('alpha')
        . blocks_engine_figma_transformer_kiwi_string('beta')
        . blocks_engine_figma_transformer_wire_varint(0);
}

function blocks_engine_figma_transformer_kiwi_frame_mask_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(4)
        // def0: ENUM MessageType { NODE_CHANGES = 1 }
        . blocks_engine_figma_transformer_kiwi_string('MessageType')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_schema_field('NODE_CHANGES', 0, false, 1)
        // def1: MESSAGE NodeChange { type, name, frameMaskDisabled, mask, maskType }
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('frameMaskDisabled', -1, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('mask', -1, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('maskType', 2, false, 5)
        // def2: ENUM MaskType { ALPHA = 1, VECTOR = 2, LUMINANCE = 3 }
        . blocks_engine_figma_transformer_kiwi_string('MaskType')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('ALPHA', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('VECTOR', 0, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('LUMINANCE', 0, false, 3)
        // def3: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 1, true, 2);
}

function blocks_engine_figma_transformer_kiwi_frame_mask_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('FRAME')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Masked Frame')
        . blocks_engine_figma_transformer_wire_varint(3)
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(4)
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}

function blocks_engine_figma_transformer_kiwi_image_paint_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(13)
        // def0: ENUM MessageType { NODE_CHANGES = 1 }
        . blocks_engine_figma_transformer_kiwi_string('MessageType')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_schema_field('NODE_CHANGES', 0, false, 1)
        // def1: STRUCT Matrix { m00, m01, m02, m10, m11, m12 }
        . blocks_engine_figma_transformer_kiwi_string('Matrix')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_kiwi_schema_field('m00', -5, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('m01', -5, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('m02', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('m10', -5, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('m11', -5, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('m12', -5, false, 6)
        // def2: STRUCT GUID { sessionID, localID }
        . blocks_engine_figma_transformer_kiwi_string('GUID')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('sessionID', -4, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('localID', -4, false, 2)
        // def3: STRUCT AssetRef { id, key, guid }
        . blocks_engine_figma_transformer_kiwi_string('AssetRef')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('id', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('key', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('guid', 2, false, 3)
        // def4: STRUCT SourceImage { hash, name, width, height, thumbHash, assetRef }
        . blocks_engine_figma_transformer_kiwi_string('SourceImage')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_kiwi_schema_field('hash', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('width', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('height', -5, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('thumbHash', -2, true, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('assetRef', 3, false, 6)
        // def5: STRUCT Image { hash, name, width, height, thumbHash, assetRef, sourceImage }
        . blocks_engine_figma_transformer_kiwi_string('Image')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_kiwi_schema_field('hash', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('width', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('height', -5, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('thumbHash', -2, true, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('assetRef', 3, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('sourceImage', 4, false, 7)
        // def6: STRUCT ExportConstraint { type, value }
        . blocks_engine_figma_transformer_kiwi_string('ExportConstraint')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('value', -5, false, 2)
        // def7: STRUCT ExportSettings { format, suffix, constraint, contentsOnly, useAbsoluteBounds }
        . blocks_engine_figma_transformer_kiwi_string('ExportSettings')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_kiwi_schema_field('format', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('suffix', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('constraint', 6, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('contentsOnly', -1, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('useAbsoluteBounds', -1, false, 5)
        // def8: STRUCT Paint with image metadata fields observed in raw Kiwi fills and symbol overrides.
        . blocks_engine_figma_transformer_kiwi_string('Paint')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(15)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('image', 5, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('imageScaleMode', -6, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('imageShouldColorManage', -1, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('rotation', -5, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('scale', -5, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('animationFrame', -4, false, 7)
        . blocks_engine_figma_transformer_kiwi_schema_field('thumbHash', -2, true, 8)
        . blocks_engine_figma_transformer_kiwi_schema_field('imageTransform', 1, false, 9)
        . blocks_engine_figma_transformer_kiwi_schema_field('originalImageWidth', -5, false, 10)
        . blocks_engine_figma_transformer_kiwi_schema_field('originalImageHeight', -5, false, 11)
        . blocks_engine_figma_transformer_kiwi_schema_field('altText', -6, false, 12)
        . blocks_engine_figma_transformer_kiwi_schema_field('imageThumbnail', 5, false, 13)
        . blocks_engine_figma_transformer_kiwi_schema_field('exportSettings', 7, true, 14)
        . blocks_engine_figma_transformer_kiwi_schema_field('sourceImage', 4, false, 15)
        // def9: STRUCT StyleId { guid }
        . blocks_engine_figma_transformer_kiwi_string('StyleId')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_schema_field('guid', 2, false, 1)
        // def10: STRUCT SymbolData { symbolOverrides[] }
        . blocks_engine_figma_transformer_kiwi_string('SymbolData')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_schema_field('symbolOverrides', 11, true, 1)
        // def11: MESSAGE NodeChange { type, fillPaints[], symbolData, styleIdForFill, styleIdForStrokeFill, exportSettings, publishID, sourceLibraryKey }
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(8)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('fillPaints', 8, true, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('symbolData', 10, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('styleIdForFill', 9, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('styleIdForStrokeFill', 9, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('exportSettings', 7, true, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('publishID', -6, false, 7)
        . blocks_engine_figma_transformer_kiwi_schema_field('sourceLibraryKey', -6, false, 8)
        // def12: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 11, true, 2);
}

function blocks_engine_figma_transformer_kiwi_image_paint_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('INSTANCE')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_image_paint_bytes('direct-hash', true, 15.0, 2.0, 7, 'abc', 0.5)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_style_id_bytes(9001, 101)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_kiwi_style_id_bytes(9001, 102)
        . blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_export_settings_bytes('SVG', '-node', 'WIDTH', 1200.0, true, false)
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_kiwi_string('publish-asset-node')
        . blocks_engine_figma_transformer_wire_varint(8)
        . blocks_engine_figma_transformer_kiwi_string('library-asset-node')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('RECTANGLE')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_image_paint_bytes('override-hash', false, 30.0, 1.25, 9, 'xyz', 0.25)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_style_id_bytes(9002, 201)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_kiwi_style_id_bytes(9002, 202)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}

function blocks_engine_figma_transformer_kiwi_style_id_bytes(int $sessionID, int $localID): string
{
    return blocks_engine_figma_transformer_wire_varint($sessionID)
        . blocks_engine_figma_transformer_wire_varint($localID);
}

function blocks_engine_figma_transformer_kiwi_image_paint_bytes(string $hash, bool $colorManaged, float $rotation, float $scale, int $animationFrame, string $thumbHash, float $m00): string
{
    return blocks_engine_figma_transformer_kiwi_string('IMAGE')
        . blocks_engine_figma_transformer_kiwi_image_bytes($hash, $hash . '.png', 640.0, 480.0, 'image-thumb', $hash . '-asset-id', $hash . '-asset-key', 9003, 301, $hash . '-source-hash')
        . blocks_engine_figma_transformer_kiwi_string('STRETCH')
        . chr($colorManaged ? 1 : 0)
        . blocks_engine_figma_transformer_kiwi_varfloat($rotation)
        . blocks_engine_figma_transformer_kiwi_varfloat($scale)
        . blocks_engine_figma_transformer_wire_varint($animationFrame)
        . blocks_engine_figma_transformer_wire_varint(strlen($thumbHash))
        . $thumbHash
        . blocks_engine_figma_transformer_kiwi_varfloat($m00)
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(0.1)
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(0.75)
        . blocks_engine_figma_transformer_kiwi_varfloat(0.2)
        . blocks_engine_figma_transformer_kiwi_varfloat(640.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(480.0)
        . blocks_engine_figma_transformer_kiwi_string('Alt text ' . $hash)
        . blocks_engine_figma_transformer_kiwi_image_bytes($hash . '-thumbnail', $hash . '-thumbnail.png', 320.0, 240.0, 'thumbnail-thumb', $hash . '-thumbnail-asset-id', $hash . '-thumbnail-asset-key', 9003, 302, $hash . '-thumbnail-source-hash')
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_export_settings_bytes('PNG', '@2x', 'SCALE', 2.0, true, true)
        . blocks_engine_figma_transformer_kiwi_source_image_bytes($hash . '-paint-source-hash', $hash . '-paint-source.png', 800.0, 600.0, 'paint-source-thumb', $hash . '-paint-source-asset-id', $hash . '-paint-source-asset-key', 9003, 303);
}

function blocks_engine_figma_transformer_kiwi_image_bytes(string $hash, string $name, float $width, float $height, string $thumbHash, string $assetId, string $assetKey, int $sessionID, int $localID, string $sourceHash): string
{
    return blocks_engine_figma_transformer_kiwi_string($hash)
        . blocks_engine_figma_transformer_kiwi_string($name)
        . blocks_engine_figma_transformer_kiwi_varfloat($width)
        . blocks_engine_figma_transformer_kiwi_varfloat($height)
        . blocks_engine_figma_transformer_wire_varint(strlen($thumbHash))
        . $thumbHash
        . blocks_engine_figma_transformer_kiwi_asset_ref_bytes($assetId, $assetKey, $sessionID, $localID)
        . blocks_engine_figma_transformer_kiwi_source_image_bytes($sourceHash, $sourceHash . '.png', $width, $height, 'source-thumb', $assetId . '-source', $assetKey . '-source', $sessionID, $localID + 10);
}

function blocks_engine_figma_transformer_kiwi_source_image_bytes(string $hash, string $name, float $width, float $height, string $thumbHash, string $assetId, string $assetKey, int $sessionID, int $localID): string
{
    return blocks_engine_figma_transformer_kiwi_string($hash)
        . blocks_engine_figma_transformer_kiwi_string($name)
        . blocks_engine_figma_transformer_kiwi_varfloat($width)
        . blocks_engine_figma_transformer_kiwi_varfloat($height)
        . blocks_engine_figma_transformer_wire_varint(strlen($thumbHash))
        . $thumbHash
        . blocks_engine_figma_transformer_kiwi_asset_ref_bytes($assetId, $assetKey, $sessionID, $localID);
}

function blocks_engine_figma_transformer_kiwi_asset_ref_bytes(string $id, string $key, int $sessionID, int $localID): string
{
    return blocks_engine_figma_transformer_kiwi_string($id)
        . blocks_engine_figma_transformer_kiwi_string($key)
        . blocks_engine_figma_transformer_kiwi_style_id_bytes($sessionID, $localID);
}

function blocks_engine_figma_transformer_kiwi_export_settings_bytes(string $format, string $suffix, string $constraintType, float $constraintValue, bool $contentsOnly, bool $useAbsoluteBounds): string
{
    return blocks_engine_figma_transformer_kiwi_string($format)
        . blocks_engine_figma_transformer_kiwi_string($suffix)
        . blocks_engine_figma_transformer_kiwi_string($constraintType)
        . blocks_engine_figma_transformer_kiwi_varfloat($constraintValue)
        . chr($contentsOnly ? 1 : 0)
        . chr($useAbsoluteBounds ? 1 : 0);
}

function blocks_engine_figma_transformer_kiwi_derived_text_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(11)
        // def0: ENUM MessageType { NODE_CHANGES = 1 }
        . blocks_engine_figma_transformer_kiwi_string('MessageType')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_schema_field('NODE_CHANGES', 0, false, 1)
        // def1: STRUCT Vector { x, y }
        . blocks_engine_figma_transformer_kiwi_string('Vector')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('x', -5, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('y', -5, false, 2)
        // def2: STRUCT FontName { family, style, postscript }
        . blocks_engine_figma_transformer_kiwi_string('FontName')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('family', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('style', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('postscript', -6, false, 3)
        // def3: STRUCT Baseline { position, width, lineY, lineHeight, lineAscent, firstCharacter, endCharacter }
        . blocks_engine_figma_transformer_kiwi_string('Baseline')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_kiwi_schema_field('position', 1, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('width', -5, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('lineY', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('lineHeight', -5, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('lineAscent', -5, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('firstCharacter', -4, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('endCharacter', -4, false, 7)
        // def4: STRUCT Glyph { commandsBlob, position, fontSize, firstCharacter, endCharacter, advance, rotation, styleID }
        . blocks_engine_figma_transformer_kiwi_string('Glyph')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(8)
        . blocks_engine_figma_transformer_kiwi_schema_field('commandsBlob', -4, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('position', 1, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('fontSize', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('firstCharacter', -4, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('endCharacter', -4, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('advance', -5, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('rotation', -5, false, 7)
        . blocks_engine_figma_transformer_kiwi_schema_field('styleID', -4, false, 8)
        // def5: STRUCT FontMetaData { key, fontLineHeight, fontWeight }
        . blocks_engine_figma_transformer_kiwi_string('FontMetaData')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('key', 2, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('fontLineHeight', -5, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('fontWeight', -3, false, 3)
        // def6: ENUM Directionality { UNKNOWN, LTR, RTL }
        . blocks_engine_figma_transformer_kiwi_string('Directionality')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('UNKNOWN', 0, false, 0)
        . blocks_engine_figma_transformer_kiwi_schema_field('LTR', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('RTL', 0, false, 2)
        // def7: MESSAGE DerivedTextLineData { directionality }
        . blocks_engine_figma_transformer_kiwi_string('DerivedTextLineData')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_schema_field('directionality', 6, false, 1)
        // def8: STRUCT DerivedTextData { layoutSize, baselines[], glyphs[], fontMetaData, truncationStartIndex, truncatedHeight, logicalIndexToCharacterOffsetMap[], derivedLines[] }
        . blocks_engine_figma_transformer_kiwi_string('DerivedTextData')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(8)
        . blocks_engine_figma_transformer_kiwi_schema_field('layoutSize', 1, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('baselines', 3, true, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('glyphs', 4, true, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('fontMetaData', 5, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('truncationStartIndex', -3, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('truncatedHeight', -5, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('logicalIndexToCharacterOffsetMap', -5, true, 7)
        . blocks_engine_figma_transformer_kiwi_schema_field('derivedLines', 7, true, 8)
        // def9: MESSAGE NodeChange { type, name, derivedTextData }
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('derivedTextData', 8, false, 3)
        // def10: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 9, true, 2);
}

function blocks_engine_figma_transformer_kiwi_derived_text_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('TEXT')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Glyph Text')
        . blocks_engine_figma_transformer_wire_varint(3)
        // DerivedTextData.layoutSize.
        . blocks_engine_figma_transformer_kiwi_varfloat(120.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(32.0)
        // DerivedTextData.baselines[].
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(22.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(120.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(22.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(24.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(18.0)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(1)
        // DerivedTextData.glyphs[].
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_kiwi_varfloat(1.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(22.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(20.0)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_varfloat(0.5)
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)
        . blocks_engine_figma_transformer_wire_varint(9)
        // DerivedTextData.fontMetaData.
        . blocks_engine_figma_transformer_kiwi_string('Inter')
        . blocks_engine_figma_transformer_kiwi_string('Bold')
        . blocks_engine_figma_transformer_kiwi_string('Inter-Bold')
        . blocks_engine_figma_transformer_kiwi_varfloat(24.0)
        . blocks_engine_figma_transformer_wire_varint_signed(700)
        // DerivedTextData.truncationStartIndex, truncatedHeight, logicalIndexToCharacterOffsetMap[].
        . blocks_engine_figma_transformer_wire_varint_signed(3)
        . blocks_engine_figma_transformer_kiwi_varfloat(24.0)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(12.5)
        // DerivedTextData.derivedLines[].
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}

function blocks_engine_figma_transformer_kiwi_auto_layout_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(5)
        // def0: STRUCT OptionalVector { x, y }
        . blocks_engine_figma_transformer_kiwi_string('OptionalVector')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('x', -5, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('y', -5, false, 2)
        // def1: STRUCT Constraints { horizontal, vertical }
        . blocks_engine_figma_transformer_kiwi_string('Constraints')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('horizontal', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('vertical', -6, false, 2)
        // def2: MESSAGE NodeChange with generic Auto Layout fields seen in REST and Kiwi schemas.
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(28)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackWidth', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackHeight', -5, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackMode', -6, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackPrimarySizing', -6, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackCounterSizing', -6, false, 7)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackSpacing', -5, false, 8)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackCounterSpacing', -5, false, 9)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackWrap', -6, false, 10)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackPrimaryAlignItems', -6, false, 11)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackCounterAlignItems', -6, false, 12)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackPadding', -5, false, 13)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackPaddingLeft', -5, false, 14)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackPaddingRight', -5, false, 15)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackPaddingTop', -5, false, 16)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackPaddingBottom', -5, false, 17)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackChildPrimaryGrow', -5, false, 18)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackChildAlignSelf', -6, false, 19)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackReverseZIndex', -1, false, 20)
        . blocks_engine_figma_transformer_kiwi_schema_field('stackPositioning', -6, false, 21)
        . blocks_engine_figma_transformer_kiwi_schema_field('horizontalConstraint', -6, false, 22)
        . blocks_engine_figma_transformer_kiwi_schema_field('verticalConstraint', -6, false, 23)
        . blocks_engine_figma_transformer_kiwi_schema_field('minSize', 0, false, 24)
        . blocks_engine_figma_transformer_kiwi_schema_field('maxSize', 0, false, 25)
        . blocks_engine_figma_transformer_kiwi_schema_field('layoutGrow', -5, false, 26)
        . blocks_engine_figma_transformer_kiwi_schema_field('layoutAlign', -6, false, 27)
        . blocks_engine_figma_transformer_kiwi_schema_field('constraints', 1, false, 28)
        // def3: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 2, true, 2)
        // def4: intentionally unrelated enum to keep definition indexes realistic.
        . blocks_engine_figma_transformer_kiwi_string('UnusedEnum')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_schema_field('UNUSED', -3, false, 1);
}

function blocks_engine_figma_transformer_kiwi_auto_layout_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('NODE_CHANGES')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('FRAME')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Auto Layout Frame')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_varfloat(320.0)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_varfloat(180.0)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_kiwi_string('HORIZONTAL')
        . blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_kiwi_string('RESIZE_TO_FIT')
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_kiwi_string('FIXED')
        . blocks_engine_figma_transformer_wire_varint(8)
        . blocks_engine_figma_transformer_kiwi_varfloat(12.0)
        . blocks_engine_figma_transformer_wire_varint(9)
        . blocks_engine_figma_transformer_kiwi_varfloat(24.0)
        . blocks_engine_figma_transformer_wire_varint(10)
        . blocks_engine_figma_transformer_kiwi_string('WRAP')
        . blocks_engine_figma_transformer_wire_varint(11)
        . blocks_engine_figma_transformer_kiwi_string('SPACE_BETWEEN')
        . blocks_engine_figma_transformer_wire_varint(12)
        . blocks_engine_figma_transformer_kiwi_string('CENTER')
        . blocks_engine_figma_transformer_wire_varint(13)
        . blocks_engine_figma_transformer_kiwi_varfloat(8.0)
        . blocks_engine_figma_transformer_wire_varint(14)
        . blocks_engine_figma_transformer_kiwi_varfloat(16.0)
        . blocks_engine_figma_transformer_wire_varint(15)
        . blocks_engine_figma_transformer_kiwi_varfloat(20.0)
        . blocks_engine_figma_transformer_wire_varint(16)
        . blocks_engine_figma_transformer_kiwi_varfloat(4.0)
        . blocks_engine_figma_transformer_wire_varint(17)
        . blocks_engine_figma_transformer_kiwi_varfloat(6.0)
        . blocks_engine_figma_transformer_wire_varint(18)
        . blocks_engine_figma_transformer_kiwi_varfloat(2.0)
        . blocks_engine_figma_transformer_wire_varint(19)
        . blocks_engine_figma_transformer_kiwi_string('STRETCH')
        . blocks_engine_figma_transformer_wire_varint(20)
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(21)
        . blocks_engine_figma_transformer_kiwi_string('ABSOLUTE')
        . blocks_engine_figma_transformer_wire_varint(22)
        . blocks_engine_figma_transformer_kiwi_string('STRETCH')
        . blocks_engine_figma_transformer_wire_varint(23)
        . blocks_engine_figma_transformer_kiwi_string('CENTER')
        . blocks_engine_figma_transformer_wire_varint(24)
        . blocks_engine_figma_transformer_kiwi_varfloat(64.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(32.0)
        . blocks_engine_figma_transformer_wire_varint(25)
        . blocks_engine_figma_transformer_kiwi_varfloat(640.0)
        . blocks_engine_figma_transformer_kiwi_varfloat(360.0)
        . blocks_engine_figma_transformer_wire_varint(26)
        . blocks_engine_figma_transformer_kiwi_varfloat(1.0)
        . blocks_engine_figma_transformer_wire_varint(27)
        . blocks_engine_figma_transformer_kiwi_string('STRETCH')
        . blocks_engine_figma_transformer_wire_varint(28)
        . blocks_engine_figma_transformer_kiwi_string('LEFT_RIGHT')
        . blocks_engine_figma_transformer_kiwi_string('TOP_BOTTOM')
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}

function blocks_engine_figma_transformer_kiwi_state_group_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_kiwi_string('GUID')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('sessionID', -4, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('localID', -4, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('StateGroupPropertyValueOrder')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('property', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('values', -6, true, 2)
        . blocks_engine_figma_transformer_kiwi_string('VariantPropSpec')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('propDefId', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('value', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_kiwi_schema_field('guid', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('isStateGroup', -1, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('stateGroupPropertyValueOrders', 1, true, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('variantPropSpecs', 2, true, 6)
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 3, true, 2);
}

function blocks_engine_figma_transformer_kiwi_state_group_message_fixture(): string
{
    $stateGroupOrder = blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('Screen Size')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Desktop')
        . blocks_engine_figma_transformer_kiwi_string('Mobile')
        . blocks_engine_figma_transformer_wire_varint(0);

    $stateGroupNode = blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(4166)
        . blocks_engine_figma_transformer_wire_varint(11733)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('FRAME')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_string('Newsletter Signup')
        . blocks_engine_figma_transformer_wire_varint(4)
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_wire_varint(1)
        . $stateGroupOrder
        . blocks_engine_figma_transformer_wire_varint(0);

    $variantPropSpec = blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(2422394609)
        . blocks_engine_figma_transformer_wire_varint(4048757538)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Desktop')
        . blocks_engine_figma_transformer_wire_varint(0);

    $variantNode = blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(3266)
        . blocks_engine_figma_transformer_wire_varint(75449)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('SYMBOL')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_string('Screen Size=Desktop')
        . blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_wire_varint(1)
        . $variantPropSpec
        . blocks_engine_figma_transformer_wire_varint(0);

    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('NODE_CHANGES')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . $stateGroupNode
        . $variantNode
        . blocks_engine_figma_transformer_wire_varint(0);
}

function blocks_engine_figma_transformer_kiwi_document_metadata_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(9)
        . blocks_engine_figma_transformer_kiwi_string('GUID')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('sessionID', -4, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('localID', -4, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('EditInfo')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('userID', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('userName', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('PluginData')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('pluginID', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('data', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('Annotation')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('id', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('label', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('AnnotationCategory')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('id', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('SectionStatus')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('BUILD', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('COMPLETED', 0, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('SectionStatusInfo')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('status', 5, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('description', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(20)
        . blocks_engine_figma_transformer_kiwi_schema_field('guid', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('phase', -6, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('autoRename', -1, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('editInfo', 1, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('pluginData', 2, false, 7)
        . blocks_engine_figma_transformer_kiwi_schema_field('version', -4, false, 8)
        . blocks_engine_figma_transformer_kiwi_schema_field('userFacingVersion', -6, false, 9)
        . blocks_engine_figma_transformer_kiwi_schema_field('publishID', -6, false, 10)
        . blocks_engine_figma_transformer_kiwi_schema_field('sourceLibraryKey', -6, false, 11)
        . blocks_engine_figma_transformer_kiwi_schema_field('annotations', 3, true, 12)
        . blocks_engine_figma_transformer_kiwi_schema_field('annotationCategories', 4, true, 13)
        . blocks_engine_figma_transformer_kiwi_schema_field('sectionStatus', 5, false, 14)
        . blocks_engine_figma_transformer_kiwi_schema_field('sectionStatusInfo', 6, false, 15)
        . blocks_engine_figma_transformer_kiwi_schema_field('handoffStatus', 6, false, 16)
        . blocks_engine_figma_transformer_kiwi_schema_field('internalOnly', -1, false, 17)
        . blocks_engine_figma_transformer_kiwi_schema_field('isPageDivider', -1, false, 18)
        . blocks_engine_figma_transformer_kiwi_schema_field('originFileKey', -6, false, 19)
        . blocks_engine_figma_transformer_kiwi_schema_field('sessionID', -6, false, 20)
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('fileVersion', -4, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('sectionStatus', 6, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 7, true, 4);
}

function blocks_engine_figma_transformer_kiwi_document_metadata_message_fixture(): string
{
    $annotation = blocks_engine_figma_transformer_kiwi_string('annotation-1')
        . blocks_engine_figma_transformer_kiwi_string('Primary annotation');
    $category = blocks_engine_figma_transformer_kiwi_string('category-1')
        . blocks_engine_figma_transformer_kiwi_string('Accessibility');
    $completedInfo = blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Complete');
    $buildInfo = blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('Ready');
    $node = blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(10)
        . blocks_engine_figma_transformer_wire_varint(20)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('FRAME')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_string('Metadata Page')
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_string('DESIGN')
        . blocks_engine_figma_transformer_wire_varint(5)
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(6)
        . blocks_engine_figma_transformer_kiwi_string('editor-1')
        . blocks_engine_figma_transformer_kiwi_string('Editor One')
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_kiwi_string('plugin-1')
        . blocks_engine_figma_transformer_kiwi_string('{"ok":true}')
        . blocks_engine_figma_transformer_wire_varint(8)
        . blocks_engine_figma_transformer_wire_varint(7)
        . blocks_engine_figma_transformer_wire_varint(9)
        . blocks_engine_figma_transformer_kiwi_string('v7-public')
        . blocks_engine_figma_transformer_wire_varint(10)
        . blocks_engine_figma_transformer_kiwi_string('pub-123')
        . blocks_engine_figma_transformer_wire_varint(11)
        . blocks_engine_figma_transformer_kiwi_string('library-key-1')
        . blocks_engine_figma_transformer_wire_varint(12)
        . blocks_engine_figma_transformer_wire_varint(1)
        . $annotation
        . blocks_engine_figma_transformer_wire_varint(13)
        . blocks_engine_figma_transformer_wire_varint(1)
        . $category
        . blocks_engine_figma_transformer_wire_varint(14)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(15)
        . $completedInfo
        . blocks_engine_figma_transformer_wire_varint(16)
        . $buildInfo
        . blocks_engine_figma_transformer_wire_varint(17)
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(18)
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(19)
        . blocks_engine_figma_transformer_kiwi_string('origin-file-1')
        . blocks_engine_figma_transformer_wire_varint(20)
        . blocks_engine_figma_transformer_kiwi_string('session-1')
        . blocks_engine_figma_transformer_wire_varint(0);

    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('NODE_CHANGES')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(42)
        . blocks_engine_figma_transformer_wire_varint(3)
        . $completedInfo
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_wire_varint(1)
        . $node
        . blocks_engine_figma_transformer_wire_varint(0);
}

function blocks_engine_figma_transformer_kiwi_derived_symbol_schema_fixture(): string
{
    $field = 'blocks_engine_figma_transformer_kiwi_schema_field';
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $v = 'blocks_engine_figma_transformer_wire_varint';

    return $v(8)
        . $str('GUID') . chr(1) . $v(2)
        . $field('sessionID', -4, false, 1)
        . $field('localID', -4, false, 2)
        . $str('GUIDPath') . chr(1) . $v(1)
        . $field('guids', 0, true, 1)
        . $str('TextData') . chr(2) . $v(1)
        . $field('characters', -6, false, 1)
        . $str('SymbolId') . chr(1) . $v(1)
        . $field('guid', 0, false, 1)
        . $str('SymbolOverride') . chr(2) . $v(2)
        . $field('guidPath', 1, false, 1)
        . $field('textData', 2, false, 2)
        . $str('DerivedSymbolData') . chr(1) . $v(3)
        . $field('symbolID', 3, false, 1)
        . $field('symbolOverrides', 4, true, 2)
        . $field('uniformScaleFactor', -5, false, 3)
        . $str('NodeChange') . chr(2) . $v(3)
        . $field('type', -6, false, 1)
        . $field('name', -6, false, 2)
        . $field('derivedSymbolData', 5, false, 3)
        . $str('Message') . chr(2) . $v(2)
        . $field('type', -6, false, 1)
        . $field('nodeChanges', 6, true, 2);
}

function blocks_engine_figma_transformer_kiwi_derived_symbol_message_fixture(): string
{
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $v = 'blocks_engine_figma_transformer_wire_varint';
    $symbolId = $v(40) . $v(1);
    $guidPath = $v(1) . $v(40) . $v(2);
    $textData = $v(1) . $str('Derived override') . $v(0);
    $override = $v(1) . $guidPath
        . $v(2) . $textData
        . $v(0);
    $derivedSymbolData = $symbolId
        . $v(1) . $override
        . blocks_engine_figma_transformer_kiwi_float(1.0);
    $node = $v(1) . $str('INSTANCE')
        . $v(2) . $str('Derived Symbol Instance')
        . $v(3) . $derivedSymbolData
        . $v(0);

    return $v(1) . $str('NODE_CHANGES')
        . $v(2) . $v(1) . $node
        . $v(0);
}

function blocks_engine_figma_transformer_kiwi_format_guid(mixed $guid): ?string
{
    if ( ! is_array($guid) || ! isset($guid['sessionID'], $guid['localID']) ) {
        return null;
    }

    return (string) $guid['sessionID'] . ':' . (string) $guid['localID'];
}

/**
 * Kiwi schema (version-106 shape) that defines a dev-status enum plus a
 * NodeChange/Message pair carrying `sectionStatus`. Proves the field-policy
 * extension (#280) reads the status through the REAL generic decoder.
 */
function blocks_engine_figma_transformer_kiwi_dev_status_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(3)
        // def0: ENUM SectionStatus { BUILD = 1, COMPLETED = 2 } (real Figma enum; BUILD = "Ready for dev")
        . blocks_engine_figma_transformer_kiwi_string('SectionStatus')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('BUILD', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('COMPLETED', 0, false, 2)
        // def1: MESSAGE NodeChange { type, name, sectionStatus }
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('sectionStatus', 0, false, 3)
        // def2: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 1, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_dev_status_schema_fixture()}:
 * one NodeChange of type SECTION with sectionStatus = COMPLETED (enum value 2).
 */
function blocks_engine_figma_transformer_kiwi_dev_status_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('DOCUMENT')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('SECTION')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Completed Section')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}

/**
 * Encode a float using Kiwi's varFloat format (the inverse of
 * {@see FigKiwiByteReader::readVarFloat()}): rotate the IEEE-754 bits left by 9
 * so the sign/exponent land in the low byte, and collapse exact 0.0 to one byte.
 */
function blocks_engine_figma_transformer_kiwi_varfloat(float $value): string
{
    $bits = unpack('V', pack('f', $value));
    $raw = is_array($bits) ? (int) $bits[1] : 0;
    if ( 0 === $raw ) {
        return chr(0);
    }

    $rotated = ( ( $raw << 9 ) & 0xffffffff ) | ( ( $raw >> 23 ) & 0x1ff );
    return pack('V', $rotated);
}

/**
 * Encode a float in the Kiwi "compressed float" form that
 * {@see FigKiwiByteReader::readVarFloat()} consumes: a single 0 byte for zero,
 * otherwise the IEEE-754 bits rotated right by 23 and emitted little-endian.
 */
function blocks_engine_figma_transformer_kiwi_float(float $value): string
{
    if ( 0.0 === $value ) {
        return chr(0);
    }

    $bits = unpack('V', pack('f', $value));
    $ieee = is_array($bits) ? (int) $bits[1] : 0;
    // Inverse of the decoder's rotate-left-by-23 so the round trip reproduces $value.
    $rotated = ( ( $ieee << 9 ) & 0xffffffff ) | ( ( $ieee >> 23 ) & 0x1ff );
    return pack('V', $rotated);
}

/**
 * Kiwi schema defining a StrokeAlign enum plus a NodeChange/Message pair that
 * carries `strokeWeight` (float), `strokeAlign` (enum) and `dashPattern`
 * (float[]). Proves the field-policy extension (#328) reads stroke geometry
 * through the REAL generic decoder instead of dropping it at the whitelist.
 */
function blocks_engine_figma_transformer_kiwi_stroke_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(3)
        // def0: ENUM StrokeAlign { CENTER = 1, INSIDE = 2, OUTSIDE = 3 }
        . blocks_engine_figma_transformer_kiwi_string('StrokeAlign')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('CENTER', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('INSIDE', 0, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('OUTSIDE', 0, false, 3)
        // def1: MESSAGE NodeChange { type, name, strokeWeight, strokeAlign, dashPattern[] }
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('strokeWeight', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('strokeAlign', 0, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('dashPattern', -5, true, 5)
        // def2: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 1, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_stroke_schema_fixture()}:
 * one RECTANGLE NodeChange with strokeWeight = 3, strokeAlign = INSIDE and a
 * dashPattern of [4, 2].
 */
function blocks_engine_figma_transformer_kiwi_stroke_message_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('DOCUMENT')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(1)
        // NodeChange[0]
        . blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('RECTANGLE')
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Bordered Rect')
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_float(3.0)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_wire_varint(5)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_float(4.0)
        . blocks_engine_figma_transformer_kiwi_float(2.0)
        . blocks_engine_figma_transformer_wire_varint(0)
        . blocks_engine_figma_transformer_wire_varint(0);
}

/**
 * Kiwi schema for component-property text overrides. The field names and nested
 * shape match the FSE Pilot production schema: instance assignments carry
 * ComponentPropAssignment.defID/value.textValue.characters while master text
 * nodes carry ComponentPropRef.defID/componentPropNodeField = TEXT_DATA.
 */
function blocks_engine_figma_transformer_kiwi_component_prop_schema_fixture(): string
{
    $field = 'blocks_engine_figma_transformer_kiwi_schema_field';
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $varint = 'blocks_engine_figma_transformer_wire_varint';

    return $varint(10)
        // def0: ENUM ComponentPropNodeField { TEXT_DATA = 1 }
        . $str('ComponentPropNodeField') . chr(0) . $varint(1)
        . $field('TEXT_DATA', 0, false, 1)
        // def1: STRUCT GUID { sessionID, localID }
        . $str('GUID') . chr(1) . $varint(2)
        . $field('sessionID', -4, false, 1)
        . $field('localID', -4, false, 2)
        // def2: MESSAGE TextData { characters }
        . $str('TextData') . chr(2) . $varint(1)
        . $field('characters', -6, false, 1)
        // def3: MESSAGE ComponentPropValue { textValue }
        . $str('ComponentPropValue') . chr(2) . $varint(1)
        . $field('textValue', 2, false, 2)
        // def4: MESSAGE VariableAnyValue { textDataValue }
        . $str('VariableAnyValue') . chr(2) . $varint(1)
        . $field('textDataValue', 2, false, 10)
        // def5: MESSAGE VariableData { value, dataType, resolvedDataType }
        . $str('VariableData') . chr(2) . $varint(3)
        . $field('value', 4, false, 1)
        . $field('dataType', -6, false, 2)
        . $field('resolvedDataType', -6, false, 3)
        // def6: MESSAGE ComponentPropAssignment { defID, value, varValue }
        . $str('ComponentPropAssignment') . chr(2) . $varint(3)
        . $field('defID', 1, false, 1)
        . $field('value', 3, false, 2)
        . $field('varValue', 5, false, 3)
        // def7: MESSAGE ComponentPropRef { defID, componentPropNodeField }
        . $str('ComponentPropRef') . chr(2) . $varint(2)
        . $field('defID', 1, false, 2)
        . $field('componentPropNodeField', 0, false, 4)
        // def8: MESSAGE NodeChange { type, name, componentPropAssignments[], componentPropRefs[], pluginData }
        . $str('NodeChange') . chr(2) . $varint(5)
        . $field('type', -6, false, 1)
        . $field('name', -6, false, 2)
        . $field('componentPropAssignments', 6, true, 3)
        . $field('componentPropRefs', 7, true, 4)
        . $field('pluginData', -2, true, 5)
        // def9: MESSAGE Message { type, nodeChanges[] }
        . $str('Message') . chr(2) . $varint(2)
        . $field('type', -6, false, 1)
        . $field('nodeChanges', 8, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_component_prop_schema_fixture()}.
 */
function blocks_engine_figma_transformer_kiwi_component_prop_message_fixture(): string
{
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $v = 'blocks_engine_figma_transformer_wire_varint';

    $defId = $v(9) . $v(10); // GUID struct body; structs carry no terminator.
    $textData = $v(1) . $str('Selected label') . $v(0);
    $value = $v(2) . $textData . $v(0);
    $varTextData = $v(1) . $str('Selected label via variable') . $v(0);
    $varAnyValue = $v(10) . $varTextData . $v(0);
    $varValue = $v(1) . $varAnyValue
        . $v(2) . $str('TEXT')
        . $v(3) . $str('TEXT')
        . $v(0);
    $assignment = $v(1) . $defId
        . $v(2) . $value
        . $v(3) . $varValue
        . $v(0);
    $ref = $v(2) . $defId
        . $v(4) . $v(1)
        . $v(0);
    $node = $v(1) . $str('INSTANCE')
        . $v(2) . $str('Component Property Instance')
        . $v(3) . $v(1) . $assignment
        . $v(4) . $v(1) . $ref
        . $v(5) . $v(3) . 'raw'
        . $v(0);

    return $v(1) . $str('DOCUMENT')
        . $v(2) . $v(1) . $node
        . $v(0);
}

/**
 * Kiwi schema (version-106 shape) that defines the prototype-link surface (#328):
 * a `Hyperlink` struct plus the `PrototypeInteraction`/`PrototypeAction` graph
 * hanging off `NodeChange`, with the real Figma `ConnectionType`/`NavigationType`
 * enums. Proves the field-policy additions read links through the REAL decoder.
 */
function blocks_engine_figma_transformer_kiwi_link_schema_fixture(): string
{
    $field = 'blocks_engine_figma_transformer_kiwi_schema_field';
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $varint = 'blocks_engine_figma_transformer_wire_varint';

    return $varint(10)
        // def0: STRUCT GUID { sessionID, localID }
        . $str('GUID') . chr(1) . $varint(2)
        . $field('sessionID', -4, false, 1)
        . $field('localID', -4, false, 2)
        // def1: ENUM InteractionType { ON_CLICK = 0 }
        . $str('InteractionType') . chr(0) . $varint(1)
        . $field('ON_CLICK', 0, false, 0)
        // def2: ENUM ConnectionType { NONE = 0, INTERNAL_NODE = 1, URL = 2 }
        . $str('ConnectionType') . chr(0) . $varint(3)
        . $field('NONE', 0, false, 0)
        . $field('INTERNAL_NODE', 0, false, 1)
        . $field('URL', 0, false, 2)
        // def3: ENUM NavigationType { NAVIGATE = 0, OVERLAY = 1, SWAP = 2 }
        . $str('NavigationType') . chr(0) . $varint(3)
        . $field('NAVIGATE', 0, false, 0)
        . $field('OVERLAY', 0, false, 1)
        . $field('SWAP', 0, false, 2)
        // def4: MESSAGE PrototypeEvent { interactionType }
        . $str('PrototypeEvent') . chr(2) . $varint(1)
        . $field('interactionType', 1, false, 1)
        // def5: MESSAGE PrototypeAction { transitionNodeID, connectionType, connectionURL, navigationType, overlay/swap/open-url metadata }
        . $str('PrototypeAction') . chr(2) . $varint(9)
        . $field('transitionNodeID', 0, false, 1)
        . $field('connectionType', 2, false, 7)
        . $field('connectionURL', -6, false, 8)
        . $field('navigationType', 3, false, 10)
        . $field('overlayPositionType', -6, false, 11)
        . $field('preserveScrollPosition', -1, false, 12)
        . $field('openUrlInNewTab', -1, false, 13)
        . $field('urlTarget', -6, false, 14)
        . $field('resetScrollPosition', -1, false, 15)
        // def6: MESSAGE PrototypeInteraction { event, actions[] }
        . $str('PrototypeInteraction') . chr(2) . $varint(2)
        . $field('event', 4, false, 2)
        . $field('actions', 5, true, 3)
        // def7: MESSAGE Hyperlink { url, guid }
        . $str('Hyperlink') . chr(2) . $varint(2)
        . $field('url', -6, false, 1)
        . $field('guid', 0, false, 2)
        // def8: MESSAGE NodeChange { type, name, hyperlink, prototypeInteractions[] }
        . $str('NodeChange') . chr(2) . $varint(4)
        . $field('type', -6, false, 1)
        . $field('name', -6, false, 2)
        . $field('hyperlink', 7, false, 4)
        . $field('prototypeInteractions', 6, true, 5)
        // def9: MESSAGE Message { type, nodeChanges[] }
        . $str('Message') . chr(2) . $varint(2)
        . $field('type', -6, false, 1)
        . $field('nodeChanges', 8, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_link_schema_fixture()}:
 * a TEXT NodeChange with a URL `hyperlink`, and a FRAME NodeChange with an
 * ON_CLICK `prototypeInteractions` entry whose actions carry a URL connection
 * and a node-navigation connection (GUID destination).
 */
function blocks_engine_figma_transformer_kiwi_link_message_fixture(): string
{
    $str = 'blocks_engine_figma_transformer_kiwi_string';
    $v = 'blocks_engine_figma_transformer_wire_varint';

    // Hyperlink { url = "https://example.com/about" }
    $hyperlink = $v(1) . $str('https://example.com/about') . $v(0);

    // PrototypeEvent { interactionType = ON_CLICK (0) }
    $event = $v(1) . $v(0) . $v(0);

    // PrototypeAction { connectionType = URL (2), connectionURL, openUrlInNewTab, urlTarget }
    $actionUrl = $v(7) . $v(2)
        . $v(8) . $str('https://example.com/cta')
        . $v(13) . chr(1)
        . $v(14) . $str('NEW_TAB')
        . $v(0);

    // PrototypeAction { transitionNodeID = GUID{7,42}, connectionType = INTERNAL_NODE (1), navigationType = OVERLAY (1), overlayPositionType }
    $actionNode = $v(1) . $v(7) . $v(42) // GUID struct body (sessionID, localID; structs carry no terminator)
        . $v(7) . $v(1)
        . $v(10) . $v(1)
        . $v(11) . $str('CENTER')
        . $v(12) . chr(1)
        . $v(15) . chr(1)
        . $v(0);

    // PrototypeInteraction { event, actions = [actionUrl, actionNode] }
    $interaction = $v(2) . $event
        . $v(3) . $v(2) . $actionUrl . $actionNode
        . $v(0);

    // NodeChange A: TEXT { hyperlink }
    $nodeA = $v(1) . $str('TEXT')
        . $v(2) . $str('External link')
        . $v(4) . $hyperlink
        . $v(0);

    // NodeChange B: FRAME { prototypeInteractions = [interaction] }
    $nodeB = $v(1) . $str('FRAME')
        . $v(2) . $str('CTA')
        . $v(5) . $v(1) . $interaction
        . $v(0);

    // Message { type = "DOCUMENT", nodeChanges = [nodeA, nodeB] }
    return $v(1) . $str('DOCUMENT')
        . $v(2) . $v(2) . $nodeA . $nodeB
        . $v(0);
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_node_changes_fixture(): array
{
    return array(
        'name'         => 'Decoded Node Changes Fixture',
        'NODE_CHANGES' => array(
            '4:1' => array(
                'node' => array(
                    'id'       => '4:1',
                    'type'     => 'FRAME',
                    'name'     => 'Decoded Landing',
                    'children' => array(
                        array(
                            'id'         => '4:2',
                            'type'       => 'TEXT',
                            'name'       => 'Heading',
                            'characters' => 'Decoded First',
                        ),
                        array(
                            'id'         => '4:3',
                            'type'       => 'TEXT',
                            'name'       => 'Body',
                            'characters' => 'Decoded Second',
                        ),
                        array(
                            'id'   => '4:4',
                            'type' => 'RECTANGLE',
                            'name' => 'Decoded Photo',
                            'fills' => array(
                                array('type' => 'IMAGE', 'imageHash' => 'synthetic'),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
}

/**
 * @return array<string, mixed>
 */
function blocks_engine_figma_transformer_nodes_candidate_fixture(): array
{
    return array(
        'name'  => 'Lower Priority Nodes Candidate',
        'nodes' => array(
            array(
                'id'       => 'candidate:1',
                'type'     => 'FRAME',
                'name'     => 'Lower Priority Frame',
                'children' => array(
                    array(
                        'id'         => 'candidate:2',
                        'type'       => 'TEXT',
                        'name'       => 'Lower Priority Text',
                        'characters' => 'This earlier payload must not be selected.',
                    ),
                ),
            ),
        ),
    );
}

function blocks_engine_figma_transformer_zstd_fixture_payload(): string
{
    if ( function_exists('zstd_compress') ) {
        $compressed = zstd_compress('synthetic zstd payload');
        if ( false !== $compressed ) {
            return $compressed;
        }
    }

    return "\x28\xb5\x2f\xfd" . 'synthetic-zstd-frame';
}
