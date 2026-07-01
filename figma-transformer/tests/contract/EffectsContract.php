<?php

declare(strict_types=1);

use Automattic\BlocksEngine\FigmaTransformer\FigFile\FigKiwiDecoder;
use Automattic\BlocksEngine\FigmaTransformer\Html\StaticHtmlEmitter;
use Automattic\BlocksEngine\FigmaTransformer\Scenegraph\ScenegraphNormalizer;

function blocks_engine_figma_transformer_run_effects_contract(callable $assert, callable $fileContent): void
{
    $effectsResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Effects Fixture',
        'nodes' => array(
            array(
                'id'      => 'effects:1',
                'type'    => 'FRAME',
                'name'    => 'Effects frame',
                'width'   => 100,
                'height'  => 100,
                'effects' => array(
                    array(
                        'type'                 => 'DROP_SHADOW',
                        'offset'               => array('x' => 0, 'y' => 6),
                        'radius'               => 6,
                        'spread'               => 2,
                        'blendMode'            => 'MULTIPLY',
                        'showShadowBehindNode' => true,
                        'color'                => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0.16),
                    ),
                    array(
                        'type'   => 'INNER_SHADOW',
                        'offset' => array('x' => 1, 'y' => 2),
                        'radius' => 3,
                        'spread' => 4,
                        'color'  => array('r' => 1, 'g' => 0, 'b' => 0, 'a' => 0.5),
                    ),
                    array('type' => 'LAYER_BLUR', 'radius' => 2),
                    array('type' => 'BACKGROUND_BLUR', 'radius' => 5),
                ),
                'children' => array(
                    array(
                        'id'         => 'effects:2',
                        'type'       => 'TEXT',
                        'name'       => 'Shadow text',
                        'characters' => 'Shadow',
                        'effects'    => array(
                            array(
                                'type'   => 'DROP_SHADOW',
                                'offset' => array('x' => 1, 'y' => 1),
                                'radius' => 2,
                                'color'  => array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0.4),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $effectsCss = $fileContent($effectsResult, 'style.css');
    $effectsDiagnostics = $effectsResult['source_reports']['figma']['html']['transform_diagnostics'] ?? array();
    $effectsDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $effectsResult['diagnostics'] ?? array()
    );
    $assert(str_contains($effectsCss, 'box-shadow:0px 6px 6px 2px rgba(0,0,0,0.16),inset 1px 2px 3px 4px rgba(255,0,0,0.5)'), 'effects-box-shadow-css');
    $assert(str_contains($effectsCss, 'filter:blur(2px)'), 'effects-layer-blur-css');
    $assert(str_contains($effectsCss, 'backdrop-filter:blur(5px)'), 'effects-background-blur-css');
    $assert(str_contains($effectsCss, 'text-shadow:1px 1px 2px 0px rgba(0,0,0,0.4)'), 'effects-text-shadow-css');
    $assert(2 === ($effectsDiagnostics['effects']['source_effect_node_count'] ?? null), 'effects-diagnostics-source-node-count');
    $assert(1 === ($effectsDiagnostics['effects']['field_coverage']['blend_mode'] ?? null), 'effects-diagnostics-blend-mode-coverage');
    $assert(1 === ($effectsDiagnostics['effects']['field_coverage']['show_shadow_behind_node'] ?? null), 'effects-diagnostics-show-shadow-behind-node-coverage');
    $assert(5 === ($effectsDiagnostics['effects']['field_coverage']['source_type'] ?? null), 'effects-diagnostics-source-type-coverage');
    $assert(array('background_blur', 'drop_shadow', 'inner_shadow', 'layer_blur') === array_keys($effectsDiagnostics['effects']['by_type'] ?? array()), 'effects-diagnostics-effect-type-inventory');
    $assert(! in_array('unsupported_figma_effect_type', $effectsDiagnosticCodes, true), 'effects-supported-no-diagnostic');

    // ---------------------------------------------------------------------------
    // Effects from a REAL Kiwi binary (#328): the field policy was starved, so
    // shadows/blur never decoded even though the normalizer + emitter were written.
    // DECODE the binary through the generic selective decoder, then prove the
    // decoded Kiwi effects (carrying the `FOREGROUND_BLUR` token) emit the exact CSS.
    // ---------------------------------------------------------------------------
    $kiwiEffectsDecoder = new FigKiwiDecoder();
    $kiwiEffectsSchema = $kiwiEffectsDecoder->decodeSchema(blocks_engine_figma_transformer_kiwi_effects_schema_fixture());
    $assert(null !== ($kiwiEffectsSchema['schema'] ?? null), 'kiwi-effects-schema-decodes');
    $kiwiEffectsMessage = $kiwiEffectsDecoder->decodeMessageSelective(
        blocks_engine_figma_transformer_kiwi_effects_message_fixture(),
        $kiwiEffectsSchema['schema'] ?? array()
    );
    $kiwiEffectsNodeChange = $kiwiEffectsMessage['message']['nodeChanges'][0] ?? array();
    $kiwiDecodedEffects = is_array($kiwiEffectsNodeChange['effects'] ?? null) ? $kiwiEffectsNodeChange['effects'] : array();
    $assert(2 === count($kiwiDecodedEffects), 'kiwi-effects-field-policy-decodes-effects-array');
    $assert('DROP_SHADOW' === ($kiwiDecodedEffects[0]['type'] ?? null), 'kiwi-effects-decodes-drop-shadow-token');
    $assert('FOREGROUND_BLUR' === ($kiwiDecodedEffects[1]['type'] ?? null), 'kiwi-effects-decodes-foreground-blur-token');
    $assert(6 === (int) round((float) ($kiwiDecodedEffects[0]['offset']['y'] ?? 0)), 'kiwi-effects-decodes-shadow-offset');
    $assert(true === ($kiwiDecodedEffects[0]['showShadowBehindNode'] ?? null), 'kiwi-effects-decodes-show-shadow-behind-node');
    $assert('MULTIPLY' === ($kiwiDecodedEffects[0]['blendMode'] ?? null), 'kiwi-effects-decodes-blend-mode');
    $assert(4 === (int) round((float) ($kiwiDecodedEffects[0]['spread'] ?? 0)), 'kiwi-effects-decodes-spread');
    $assert(8 === (int) round((float) ($kiwiDecodedEffects[1]['radius'] ?? 0)), 'kiwi-effects-decodes-blur-radius');

    $kiwiEffectsNormalizer = new ScenegraphNormalizer();
    $kiwiEffectsNormalized = $kiwiEffectsNormalizer->normalize(array(
        'name'  => 'Kiwi Effects Normalizer Fixture',
        'nodes' => array(
            array(
                'id'      => 'kiwi:effects-normalized-frame',
                'type'    => 'FRAME',
                'name'    => 'Effects Frame',
                'width'   => 200,
                'height'  => 120,
                'effects' => array($kiwiDecodedEffects[0]),
            ),
        ),
    ));
    $kiwiEffectsNormalizedNode = $kiwiEffectsNormalized['nodes'][0] ?? array();
    $assert(true === ($kiwiEffectsNormalizedNode['figma_effects'][0]['show_shadow_behind_node'] ?? null), 'kiwi-effects-normalizes-show-shadow-behind-node');
    $assert('MULTIPLY' === ($kiwiEffectsNormalizedNode['figma_effects'][0]['blend_mode'] ?? null), 'kiwi-effects-normalizes-blend-mode');
    $assert('DROP_SHADOW' === ($kiwiEffectsNormalizedNode['figma_effects'][0]['source_type'] ?? null), 'kiwi-effects-normalizes-source-type');

    // EMIT: feed the decoded Kiwi effects verbatim through normalize -> emit and
    // assert the exact box-shadow + filter CSS reaches style.css.
    $kiwiEffectsRenderResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Kiwi Effects Fixture',
        'nodes' => array(
            array(
                'id'      => 'kiwi:effects-frame',
                'type'    => (string) ($kiwiEffectsNodeChange['type'] ?? 'FRAME'),
                'name'    => (string) ($kiwiEffectsNodeChange['name'] ?? 'Effects Frame'),
                'width'   => 200,
                'height'  => 120,
                'effects' => $kiwiDecodedEffects,
            ),
        ),
    ));
    $kiwiEffectsCss = $fileContent($kiwiEffectsRenderResult, 'style.css');
    $kiwiEffectsDiagnosticCodes = array_map(
        static fn (array $diagnostic): string => (string) ($diagnostic['code'] ?? ''),
        $kiwiEffectsRenderResult['diagnostics'] ?? array()
    );
    $assert(str_contains($kiwiEffectsCss, 'box-shadow:0px 6px 6px 4px rgba(0,0,0,0.5)'), 'kiwi-effects-emits-drop-shadow-css');
    $assert(str_contains($kiwiEffectsCss, 'filter:blur(8px)'), 'kiwi-effects-emits-foreground-blur-css');
    $assert(! in_array('unsupported_figma_effect_type', $kiwiEffectsDiagnosticCodes, true), 'kiwi-effects-foreground-blur-no-diagnostic');

    $clippedVectorGlowResult = blocks_engine_figma_transformer_transform_scenegraph(array(
        'name'  => 'Clipped Vector Glow Fixture',
        'nodes' => array(
            array(
                'id'                => 'effects:clipped-frame',
                'type'              => 'FRAME',
                'name'              => 'Clipped frame',
                'width'             => 96,
                'height'            => 96,
                'frameMaskDisabled' => false,
                'children'          => array(
                    array(
                        'id'                 => 'effects:vector-glow',
                        'type'               => 'VECTOR',
                        'name'               => 'Vector glow',
                        'width'              => 96,
                        'height'             => 96,
                        'fillPaints'         => array(array('type' => 'SOLID', 'color' => array('r' => 1, 'g' => 0.8117647059, 'b' => 0, 'a' => 1))),
                        'figma_vector_paths' => array(array('data' => 'M48 0A48 48 0 1 1 47.99 0M48 24A24 24 0 1 0 48.01 24Z', 'windingRule' => 'EVENODD')),
                        'effects'            => array(array(
                            'type'                 => 'DROP_SHADOW',
                            'offset'               => array('x' => 0, 'y' => 0),
                            'radius'               => 16,
                            'spread'               => 0,
                            'visible'              => true,
                            'showShadowBehindNode' => false,
                            'color'                => array('r' => 1, 'g' => 0.8117647059, 'b' => 0, 'a' => 0.5),
                        )),
                    ),
                ),
            ),
        ),
    ));
    $clippedVectorGlowHtml = $fileContent($clippedVectorGlowResult, 'index.html');
    $clippedVectorGlowCss = $fileContent($clippedVectorGlowResult, 'style.css');
    $assert(str_contains($clippedVectorGlowHtml, 'data-figma-node-id="effects:vector-glow"') && str_contains($clippedVectorGlowHtml, 'data-figma-vector="true"'), 'effects-vector-glow-renders-inline-svg');
    $assert(str_contains($clippedVectorGlowCss, '.figma-node-effects-clipped-frame-clipped-frame{width:96px;height:96px') && ! str_contains($clippedVectorGlowCss, '.figma-node-effects-clipped-frame-clipped-frame{width:96px;height:96px;overflow:hidden'), 'effects-vector-glow-parent-keeps-shadow-overflow-visible');
    $assert(str_contains($clippedVectorGlowCss, '.figma-node-effects-vector-glow-vector-glow{') && str_contains($clippedVectorGlowCss, 'filter:drop-shadow(0px 0px 16px rgba(255,207,0,0.5))'), 'effects-vector-glow-emits-alpha-drop-shadow-filter');
    $assert(! str_contains($clippedVectorGlowCss, '.figma-node-effects-vector-glow-vector-glow{width:96px;height:96px;box-shadow:'), 'effects-vector-glow-no-rectangular-box-shadow');

    $componentCloneGlowResult = ( new StaticHtmlEmitter() )->emit(array(
        'name'  => 'Component Clone Glow Fixture',
        'nodes' => array(
            array(
                'id'       => 'clone:frame',
                'type'     => 'FRAME',
                'name'     => 'Clone frame',
                'box'      => array('x' => 0, 'y' => 0, 'width' => 160, 'height' => 120, 'coordinate_space' => 'local'),
                'layout'   => array('display' => 'flex', 'flex_direction' => 'column'),
                'children' => array(
                    array(
                        'id'                              => 'clone:clip',
                        'type'                            => 'FRAME',
                        'name'                            => 'Clone clipped glow parent',
                        'box'                             => array('x' => 32, 'y' => -16, 'width' => 96, 'height' => 96, 'coordinate_space' => 'local'),
                        'layout'                          => array('positioning' => 'absolute', 'clips_content' => true),
                        '_component_source_clone_geometry' => true,
                        '_component_source_clone_source_box' => array('x' => 560, 'y' => -57, 'width' => 96, 'height' => 96),
                        'children'                        => array(
                            array(
                                'id'                              => 'clone:glow',
                                'type'                            => 'VECTOR',
                                'name'                            => 'Clone glow vector',
                                'box'                             => array('x' => 0, 'y' => 0, 'width' => 106, 'height' => 106, 'coordinate_space' => 'local'),
                                'figma_paints'                    => array(array('type' => 'SOLID', 'color' => '#ffcf00', 'opacity' => 1)),
                                'figma_vector_paths'              => array(array('data' => 'M53 0A53 53 0 1 1 52.99 0Z', 'windingRule' => 'NONZERO')),
                                'figma_effects'                   => array(array(
                                    'type'     => 'drop_shadow',
                                    'offset_x' => 0,
                                    'offset_y' => 0,
                                    'radius'   => 16,
                                    'spread'   => 0,
                                    'visible'  => true,
                                    'color'    => array('r' => 1, 'g' => 0.8117647059, 'b' => 0, 'a' => 0.5),
                                )),
                                '_component_source_clone_geometry' => true,
                                '_component_source_clone_source_box' => array('x' => 560, 'y' => 0, 'width' => 106, 'height' => 106),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ));
    $componentCloneGlowCss = $fileContent($componentCloneGlowResult, 'style.css');
    $assert(! str_contains($componentCloneGlowCss, '.figma-node-clone-clip-clone-clipped-glow-parent{width:96px;height:96px;overflow:hidden'), 'effects-component-clone-glow-parent-keeps-shadow-overflow-visible');
    $assert(str_contains($componentCloneGlowCss, '.figma-node-clone-glow-clone-glow-vector{width:106px;height:106px;position:absolute;left:0px;top:0px;') && str_contains($componentCloneGlowCss, 'filter:drop-shadow(0px 0px 16px '), 'effects-component-clone-local-glow-keeps-local-offset');
    $assert(! str_contains($componentCloneGlowCss, '.figma-node-clone-glow-clone-glow-vector{width:106px;height:106px;position:absolute;left:560px;top:0px'), 'effects-component-clone-local-glow-no-source-x-fallback');
}

/**
 * Kiwi schema (version-106 shape) defining Color/Vector structs, the EffectType
 * enum (with the real `FOREGROUND_BLUR` token), an Effect struct, and a
 * NodeChange/Message pair carrying `effects`. Proves the #328 field-policy
 * additions decode shadows + blur through the REAL generic decoder.
 */
function blocks_engine_figma_transformer_kiwi_effects_schema_fixture(): string
{
    return blocks_engine_figma_transformer_wire_varint(6)
        // def0: STRUCT Color { float r, g, b, a }
        . blocks_engine_figma_transformer_kiwi_string('Color')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_schema_field('r', -5, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('g', -5, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('b', -5, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('a', -5, false, 4)
        // def1: STRUCT Vector { float x, y }
        . blocks_engine_figma_transformer_kiwi_string('Vector')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('x', -5, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('y', -5, false, 2)
        // def2: ENUM EffectType (real Figma tokens/values)
        . blocks_engine_figma_transformer_kiwi_string('EffectType')
        . chr(0)
        . blocks_engine_figma_transformer_wire_varint(4)
        . blocks_engine_figma_transformer_kiwi_schema_field('INNER_SHADOW', 0, false, 0)
        . blocks_engine_figma_transformer_kiwi_schema_field('DROP_SHADOW', 0, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('FOREGROUND_BLUR', 0, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('BACKGROUND_BLUR', 0, false, 3)
        // def3: STRUCT Effect { EffectType type; Color color; Vector offset; float radius; float spread; bool visible; string blendMode; bool showShadowBehindNode }
        . blocks_engine_figma_transformer_kiwi_string('Effect')
        . chr(1)
        . blocks_engine_figma_transformer_wire_varint(8)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', 2, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('color', 0, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('offset', 1, false, 3)
        . blocks_engine_figma_transformer_kiwi_schema_field('radius', -5, false, 4)
        . blocks_engine_figma_transformer_kiwi_schema_field('spread', -5, false, 5)
        . blocks_engine_figma_transformer_kiwi_schema_field('visible', -1, false, 6)
        . blocks_engine_figma_transformer_kiwi_schema_field('blendMode', -6, false, 7)
        . blocks_engine_figma_transformer_kiwi_schema_field('showShadowBehindNode', -1, false, 8)
        // def4: MESSAGE NodeChange { type, name, effects[] }
        . blocks_engine_figma_transformer_kiwi_string('NodeChange')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(3)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('name', -6, false, 2)
        . blocks_engine_figma_transformer_kiwi_schema_field('effects', 3, true, 3)
        // def5: MESSAGE Message { type, nodeChanges[] }
        . blocks_engine_figma_transformer_kiwi_string('Message')
        . chr(2)
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_schema_field('type', -6, false, 1)
        . blocks_engine_figma_transformer_kiwi_schema_field('nodeChanges', 4, true, 2);
}

/**
 * Kiwi message for {@see blocks_engine_figma_transformer_kiwi_effects_schema_fixture()}:
 * one FRAME NodeChange carrying a DROP_SHADOW (offset 0,6 / radius 6 / black @ 0.5)
 * and a FOREGROUND_BLUR (radius 8). Struct fields decode sequentially.
 */
function blocks_engine_figma_transformer_kiwi_effects_message_fixture(): string
{
    $dropShadow = blocks_engine_figma_transformer_wire_varint(1) // EffectType DROP_SHADOW
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.r
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.g
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.b
        . blocks_engine_figma_transformer_kiwi_varfloat(0.5)   // color.a
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // offset.x
        . blocks_engine_figma_transformer_kiwi_varfloat(6.0)   // offset.y
        . blocks_engine_figma_transformer_kiwi_varfloat(6.0)   // radius
        . blocks_engine_figma_transformer_kiwi_varfloat(4.0)   // spread
        . chr(1)                                                // visible
        . blocks_engine_figma_transformer_kiwi_string('MULTIPLY') // blendMode
        . chr(1);                                               // showShadowBehindNode

    $foregroundBlur = blocks_engine_figma_transformer_wire_varint(2) // EffectType FOREGROUND_BLUR
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.r
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.g
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.b
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // color.a
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // offset.x
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // offset.y
        . blocks_engine_figma_transformer_kiwi_varfloat(8.0)   // radius
        . blocks_engine_figma_transformer_kiwi_varfloat(0.0)   // spread
        . chr(1)                                                // visible
        . blocks_engine_figma_transformer_kiwi_string('NORMAL') // blendMode
        . chr(0);                                               // showShadowBehindNode

    $nodeChange = blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('FRAME')          // type
        . blocks_engine_figma_transformer_wire_varint(2)
        . blocks_engine_figma_transformer_kiwi_string('Effects Frame')  // name
        . blocks_engine_figma_transformer_wire_varint(3)                // effects field
        . blocks_engine_figma_transformer_wire_varint(2)                // array length
        . $dropShadow
        . $foregroundBlur
        . blocks_engine_figma_transformer_wire_varint(0);              // end NodeChange

    return blocks_engine_figma_transformer_wire_varint(1)
        . blocks_engine_figma_transformer_kiwi_string('DOCUMENT')      // Message.type
        . blocks_engine_figma_transformer_wire_varint(2)              // nodeChanges field
        . blocks_engine_figma_transformer_wire_varint(1)             // array length
        . $nodeChange
        . blocks_engine_figma_transformer_wire_varint(0);            // end Message
}
