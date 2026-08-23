<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

/** Builds an editable, bounded companion block for authored text marquees. */
final class AuthoredMarqueeBlockGenerator
{
    public const LOCAL_NAME = 'authored-marquee';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Animated Text',
            'category' => 'text',
            'description' => 'Editable scrolling text that respects reduced motion.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'content' => array( 'type' => 'string', 'default' => '' ),
                'direction' => array( 'type' => 'string', 'default' => 'left' ),
                'duration' => array( 'type' => 'number', 'default' => 40 ),
            ),
            'supports' => array( 'html' => false ),
            'render' => 'file:./render.php',
        );
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    function edit( props ) {
        return createElement( 'div', blockEditor.useBlockProps(), createElement( blockEditor.RichText, {
            tagName: 'p',
            value: props.attributes.content || '',
            onChange: function( content ) { props.setAttributes( { content: content } ); },
            placeholder: 'Animated text'
        } ) );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { content: { type: 'string', default: '' }, direction: { type: 'string', default: 'left' }, duration: { type: 'number', default: 40 } },
        supports: { html: false },
        edit: edit,
        save: function() { return null; }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;

        $render = <<<'PHP'
<?php
$content = isset( $attributes['content'] ) ? wp_kses_post( (string) $attributes['content'] ) : '';
$direction = 'right' === ( $attributes['direction'] ?? '' ) ? 'right' : 'left';
$duration = isset( $attributes['duration'] ) ? (float) $attributes['duration'] : 40;
$duration = min( 600, max( 1, $duration ) );
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'blocks-engine-authored-marquee', 'style' => '--blocks-engine-marquee-duration:' . esc_attr( $duration ) . 's' ) ); ?> data-direction="<?php echo esc_attr( $direction ); ?>">
    <div class="blocks-engine-authored-marquee__viewport">
        <div class="blocks-engine-authored-marquee__track"><span class="blocks-engine-authored-marquee__content"><?php echo $content; ?></span><span class="blocks-engine-authored-marquee__content" aria-hidden="true"><?php echo $content; ?></span></div>
    </div>
</div>
<style>
.blocks-engine-authored-marquee{max-width:100%;min-width:0}.blocks-engine-authored-marquee__viewport{max-width:100%;overflow-x:clip}@supports not (overflow:clip){.blocks-engine-authored-marquee__viewport{overflow:hidden}}.blocks-engine-authored-marquee__track{display:flex;width:max-content;min-width:100%;animation:blocks-engine-authored-marquee var(--blocks-engine-marquee-duration,40s) linear infinite}.blocks-engine-authored-marquee[data-direction="right"] .blocks-engine-authored-marquee__track{animation-direction:reverse}.blocks-engine-authored-marquee__content{flex:none;padding-inline-end:1rem}.blocks-engine-authored-marquee__content[aria-hidden="true"]{user-select:none}@keyframes blocks-engine-authored-marquee{to{transform:translateX(-50%)}}@media (prefers-reduced-motion:reduce){.blocks-engine-authored-marquee__track{width:auto;white-space:normal;animation:none;transform:none}.blocks-engine-authored-marquee__content[aria-hidden="true"]{display:none}}
</style>
PHP;

        return array( 'index.js' => str_replace('__BLOCK_NAME__', $blockName, $script), 'render.php' => $render );
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'assets' => $this->assets($namespace . '/' . self::LOCAL_NAME),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-element' ) ),
        );
    }
}
