<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

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
                'items' => array( 'type' => 'array', 'default' => array() ),
                'direction' => array( 'type' => 'string', 'default' => 'left' ),
                'duration' => array( 'type' => 'number', 'default' => 40 ),
                'decorative' => array( 'type' => 'boolean', 'default' => false ),
            ),
            'supports' => array( 'html' => false ),
            'style' => 'file:./style.css',
        );
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, element ) {
    var createElement = element.createElement;
    var RichText = blockEditor.RichText;
    function duration( value ) { value = Number( value ); return Math.min( 600, Math.max( 1, Number.isFinite( value ) ? value : 40 ) ); }
    function items( attributes ) { return Array.isArray( attributes.items ) && attributes.items.length ? attributes.items : [ { content: attributes.content || '', className: '', marker: '' } ]; }
    function itemProps( item, key ) { var props = { key: key, tagName: 'span', className: item.className || undefined, value: item.content || '' }; if ( item.marker ) { props[ 'data-blocks-engine-richtext-marker' ] = item.marker; } return props; }
    function edit( props ) {
        var authoredItems = items( props.attributes );
        return createElement( 'div', blockEditor.useBlockProps(), authoredItems.map( function( item, index ) {
            return createElement( RichText, { key: index, tagName: 'p', value: item.content || '', onChange: function( content ) { var next = authoredItems.slice(); next[ index ] = Object.assign( {}, item, { content: content } ); props.setAttributes( { items: next, content: '' } ); }, allowedFormats: [], placeholder: 'Animated text' } );
        } ) );
    }
    blocks.registerBlockType( '__BLOCK_NAME__', {
        attributes: { content: { type: 'string', default: '' }, items: { type: 'array', default: [] }, direction: { type: 'string', default: 'left' }, duration: { type: 'number', default: 40 }, decorative: { type: 'boolean', default: false } },
        supports: { html: false },
        edit: edit,
        save: function( props ) {
            var attributes = props.attributes;
            var direction = 'right' === attributes.direction ? 'right' : 'left';
            var authoredItems = items( attributes );
            var sequence = function( hidden ) { return createElement( 'span', { className: 'blocks-engine-authored-marquee__content' + ( attributes.items && attributes.items.length ? ' blocks-engine-authored-marquee__content--items' : '' ), 'aria-hidden': hidden ? true : undefined, inert: hidden ? '' : undefined }, authoredItems.map( function( item, index ) { return createElement( RichText.Content, itemProps( item, index ) ); } ) ); };
            return createElement( 'div', { className: 'blocks-engine-authored-marquee', style: { '--blocks-engine-marquee-duration': duration( attributes.duration ) + 's' }, 'data-direction': direction, 'aria-hidden': attributes.decorative ? true : undefined }, createElement( 'div', { className: 'blocks-engine-authored-marquee__viewport' }, createElement( 'div', { className: 'blocks-engine-authored-marquee__track' }, sequence( false ), sequence( true ) ) ) );
        }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element );
JS;

        $style = '.blocks-engine-authored-marquee{max-width:100%;min-width:0}.blocks-engine-authored-marquee__viewport{max-width:100%;overflow-x:clip}@supports not (overflow:clip){.blocks-engine-authored-marquee__viewport{overflow:hidden}}.blocks-engine-authored-marquee__track{display:flex;width:max-content;min-width:100%;animation:blocks-engine-authored-marquee var(--blocks-engine-marquee-duration,40s) linear infinite}.blocks-engine-authored-marquee:hover .blocks-engine-authored-marquee__track{animation-play-state:paused}.blocks-engine-authored-marquee[data-direction="right"] .blocks-engine-authored-marquee__track{animation-direction:reverse}.blocks-engine-authored-marquee__content{display:flex;flex:none;align-items:center;padding-inline-end:1rem}.blocks-engine-authored-marquee__content--items{min-height:1lh;padding-inline-end:0}.blocks-engine-authored-marquee__content[aria-hidden="true"]{user-select:none}@keyframes blocks-engine-authored-marquee{to{transform:translateX(-50%)}}@media (prefers-reduced-motion:reduce){.blocks-engine-authored-marquee__track{width:auto;white-space:normal;animation:none;transform:none}.blocks-engine-authored-marquee__content[aria-hidden="true"]{display:none}}';

        return array( 'index.js' => str_replace('__BLOCK_NAME__', $blockName, $script), 'style.css' => $style );
    }

    /** @param array<string, mixed> $attributes */
    public function markup(array $attributes): string
    {
        $items = is_array($attributes['items'] ?? null) && array() !== $attributes['items']
            ? $attributes['items']
            : array(array( 'content' => (string) ($attributes['content'] ?? ''), 'className' => '', 'marker' => '' ));
        $direction = 'right' === ($attributes['direction'] ?? '') ? 'right' : 'left';
        $duration = min(600, max(1, (float) ($attributes['duration'] ?? 40)));
        $decorative = true === ($attributes['decorative'] ?? false) ? ' aria-hidden="true"' : '';
        $contentClass = 'blocks-engine-authored-marquee__content' . (is_array($attributes['items'] ?? null) && array() !== $attributes['items'] ? ' blocks-engine-authored-marquee__content--items' : '');
        $content = '';
        foreach ( $items as $item ) {
            if ( ! is_array($item) ) {
                continue;
            }
            $className = $this->safeTokenList((string) ($item['className'] ?? ''));
            $marker = $this->safeToken((string) ($item['marker'] ?? ''));
            $content .= '<span'
                . ('' !== $className ? ' class="' . $className . '"' : '')
                . ('' !== $marker ? ' data-blocks-engine-richtext-marker="' . $marker . '"' : '')
                . '>' . (string) ($item['content'] ?? '') . '</span>';
        }

        return '<div class="blocks-engine-authored-marquee" style="--blocks-engine-marquee-duration:' . $duration . 's" data-direction="' . $direction . '"' . $decorative . '><div class="blocks-engine-authored-marquee__viewport"><div class="blocks-engine-authored-marquee__track"><span class="' . $contentClass . '">' . $content . '</span><span class="' . $contentClass . '" aria-hidden="true" inert="">' . $content . '</span></div></div></div>';
    }

    private function safeTokenList(string $value): string
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: array();
        return implode(' ', array_filter($tokens, static fn (string $token): bool => 1 === preg_match('/^[A-Za-z0-9_-]+$/', $token)));
    }

    private function safeToken(string $value): string
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]+$/', $value) ? $value : '';
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
