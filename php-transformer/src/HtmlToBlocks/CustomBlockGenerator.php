<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/**
 * Builds dynamic (PHP-only) custom block definitions for source subtrees that
 * the {@see Classification\SubtreeClassifier} identifies as cohesive custom-block
 * content units which map to nothing native/Automattic.
 *
 * This is the producer link of the classify -> route -> generate chain
 * (epic #497, keystone #491): the classifier decides a `core/html`-fallback
 * subtree IS a `custom_block`, and this generator turns it into an installable
 * dynamic block. The output shape (`name`, `block_json`, `render`) exactly
 * matches what the SSI companion-plugin scaffolder consumes
 * (Static_Site_Importer_Companion_Plugin::scaffold()) and what
 * {@see \Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CompanionPluginPayload}
 * packages into `companion_plugin_payload.blocks[]`.
 *
 * First-slice design (conservative):
 *  - Dynamic / server-rendered: the editable content lives in the block's
 *    `content` attribute and is echoed by render.php, so there is no static
 *    save()/render mismatch. The block type is content-agnostic; per-instance
 *    content travels on the self-closing block reference's attrs.
 *  - GENERIC only: the block definition (attributes + render) is identical
 *    across generated blocks; only the structurally-derived name differs. Names
 *    and titles derive from generic structure, never fixture/site strings.
 *  - Pure: no I/O, no global state; the same inputs always yield the same
 *    definition.
 */
final class CustomBlockGenerator
{
    /**
     * Block-editor category for generated blocks. `widgets` is the generic
     * catch-all category that always exists in a stock editor.
     */
    public const CATEGORY = 'widgets';

    /**
     * Build the block.json descriptor (as an array) for a generated block type.
     *
     * @param string $blockName Fully-qualified block name (`namespace/local`).
     * @param string $title     Human-readable, generically-derived title.
     * @return array<string, mixed>
     */
    public function blockJson(string $blockName, string $title): array
    {
        return array(
            'apiVersion' => 3,
            'name'       => $blockName,
            'title'      => $title,
            'category'   => self::CATEGORY,
            'attributes' => array(
                // The captured, sanitized subtree markup. Editable, so the block
                // is a real content unit rather than frozen raw HTML.
                'content' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
            ),
            // Dynamic block: no static save(), so no editor validation mismatch.
            'supports'   => array(
                'html' => false,
            ),
            'render'     => 'file:./render.php',
        );
    }

    /**
     * The render.php body for a generated dynamic block. Identical across all
     * generated blocks; the variable content arrives via the `content`
     * attribute, so this is a content-agnostic, server-rendered template.
     */
    public function render(): string
    {
        return <<<'PHP'
<?php
/**
 * Server-rendered output for a generated custom block.
 *
 * Dynamic (PHP-only) render: the editable content lives in the block's
 * `content` attribute, so there is no static save()/render mismatch. Generated
 * by the blocks-engine PHP transformer (issue #497).
 *
 * @var array<string,mixed> $attributes Block attributes.
 */

$content = isset( $attributes['content'] ) && is_scalar( $attributes['content'] ) ? (string) $attributes['content'] : '';
$content = function_exists( 'wp_kses_post' ) ? wp_kses_post( $content ) : $content;
$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';

// get_block_wrapper_attributes() returns markup WordPress has already escaped.
echo '<div' . ( '' !== $wrapper ? ' ' . $wrapper : '' ) . '>' . $content . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
PHP;
    }

    /**
     * Per-instance attributes for the self-closing block reference emitted in
     * the converted output. Carries only the captured content; no innerHTML.
     *
     * @return array<string, mixed>
     */
    public function referenceAttributes(string $content): array
    {
        return array(
            'content' => $content,
        );
    }
}
