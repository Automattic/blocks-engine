<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

/**
 * One editable link around a responsive image and a text label.
 *
 * WordPress 7.1 core/group has no navigable href — supports.color.link is link
 * color, and the static save is a div. core/image href is sourced from
 * `figure > a`, which wraps only the image, and block.json has no srcset or
 * sizes attribute, so save() cannot keep density candidates. Putting the same
 * href on the image and the label would be two tab stops.
 */
final class LinkedResponsiveContentBlockGenerator
{
    public const LOCAL_NAME = 'linked-responsive-content';

    /** @var array<int, string> */
    private const LABEL_TAGS = array( 'span', 'strong', 'em', 'b', 'i', 'small', 'mark' );

    public const LABEL_SELECTOR = 'a > span, a > strong, a > em, a > b, a > i, a > small, a > mark';

    /** @return array<string, mixed> */
    public function blockJson(string $namespace): array
    {
        $from = static fn (string $selector, string $attribute): array => array(
            'type' => 'string',
            'default' => '',
            'source' => 'attribute',
            'selector' => $selector,
            'attribute' => $attribute,
        );
        $data = static fn (): array => array( 'type' => 'object', 'default' => array() );

        return array(
            'apiVersion' => 3,
            'name' => $namespace . '/' . self::LOCAL_NAME,
            'title' => 'Linked content',
            'category' => 'design',
            'description' => 'One link containing an editable image and label.',
            'editorScript' => 'file:./index.js',
            'attributes' => array(
                'href' => $from('a', 'href'),
                'src' => $from('img', 'src'),
                'srcset' => $from('img', 'srcset'),
                'sizes' => $from('img', 'sizes'),
                'alt' => $from('img', 'alt'),
                'width' => $from('img', 'width'),
                'height' => $from('img', 'height'),
                'loading' => $from('img', 'loading'),
                'decoding' => $from('img', 'decoding'),
                'className' => $from('a', 'class'),
                'anchorStyle' => $from('a', 'style'),
                'imageClassName' => $from('img', 'class'),
                'imageStyle' => $from('img', 'style'),
                'label' => array( 'type' => 'string', 'default' => '', 'source' => 'html', 'selector' => self::LABEL_SELECTOR ),
                'labelClassName' => $from(self::LABEL_SELECTOR, 'class'),
                'labelStyle' => $from(self::LABEL_SELECTOR, 'style'),
                'labelTag' => array( 'type' => 'string', 'default' => 'span', 'source' => 'tag', 'selector' => self::LABEL_SELECTOR ),
                'linkTarget' => $from('a', 'target'),
                'rel' => $from('a', 'rel'),
                'anchorId' => $from('a', 'id'),
                'anchorTitle' => $from('a', 'title'),
                'ariaLabel' => $from('a', 'aria-label'),
                'ariaLabelledBy' => $from('a', 'aria-labelledby'),
                'ariaDescribedBy' => $from('a', 'aria-describedby'),
                'imageId' => $from('img', 'id'),
                'imageTitle' => $from('img', 'title'),
                'imageLabelledBy' => $from('img', 'aria-labelledby'),
                'imageDescribedBy' => $from('img', 'aria-describedby'),
                'labelId' => $from(self::LABEL_SELECTOR, 'id'),
                'labelTitle' => $from(self::LABEL_SELECTOR, 'title'),
                'labelLabelledBy' => $from(self::LABEL_SELECTOR, 'aria-labelledby'),
                'labelDescribedBy' => $from(self::LABEL_SELECTOR, 'aria-describedby'),
                'contentOrder' => array( 'type' => 'string', 'default' => 'image-first' ),
                'anchorData' => $data(),
                'imageData' => $data(),
                'labelData' => $data(),
                'mediaId' => array( 'type' => 'number', 'default' => 0 ),
            ),
            'supports' => array( 'html' => false, 'customClassName' => false ),
        );
    }

    /** @return array<string, string> */
    public function assets(string $blockName): array
    {
        $script = <<<'JS'
( function( blocks, blockEditor, components, element ) {
    var createElement = element.createElement;
    var RichText = blockEditor.RichText;
    var InspectorControls = blockEditor.InspectorControls;
    var MediaUpload = blockEditor.MediaUpload;
    var useBlockProps = blockEditor.useBlockProps;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var ToggleControl = components.ToggleControl;
    var Button = components.Button;
    var attributes = __BLOCK_ATTRIBUTES__;
    var labelTags = { span: true, strong: true, em: true, b: true, i: true, small: true, mark: true };
    function escapeAttribute( value ) { return String( value == null ? '' : value ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
    function safeHref( value ) { value = String( value || '' ).trim(); if ( ! value || /[\u0000-\u0020\u007f]/.test( value ) ) return ''; var match = /^([a-z][a-z0-9+.-]*):/i.exec( value ); if ( match && [ 'http', 'https', 'mailto', 'tel' ].indexOf( match[ 1 ].toLowerCase() ) < 0 && value.charAt( 0 ) !== '/' && value.charAt( 0 ) !== '#' && value.charAt( 0 ) !== '?' ) return ''; return value; }
    function labelTag( attrs ) { return labelTags[ attrs.labelTag ] ? attrs.labelTag : 'span'; }
    function dataAttributes( bag ) { if ( ! bag ) return ''; return Object.keys( bag ).sort().map( function( name ) { return ' ' + name + '="' + escapeAttribute( bag[ name ] ) + '"'; } ).join( '' ); }
    function styleObject( value ) { var style = {}; String( value || '' ).split( ';' ).forEach( function( declaration ) { var separator = declaration.indexOf( ':' ); if ( separator < 1 ) return; var name = declaration.slice( 0, separator ).trim(); var property = name.indexOf( '--' ) === 0 ? name : name.replace( /-([a-z])/g, function( _, letter ) { return letter.toUpperCase(); } ); style[ property ] = declaration.slice( separator + 1 ).trim(); } ); return style; }
    function imageMarkup( attrs ) {
        var output = '<img alt="' + escapeAttribute( attrs.alt || '' ) + '"';
        [ [ 'imageClassName', 'class' ], [ 'decoding', 'decoding' ], [ 'height', 'height' ], [ 'imageId', 'id' ], [ 'loading', 'loading' ], [ 'sizes', 'sizes' ], [ 'src', 'src' ], [ 'srcset', 'srcset' ], [ 'imageTitle', 'title' ], [ 'width', 'width' ], [ 'imageLabelledBy', 'aria-labelledby' ], [ 'imageDescribedBy', 'aria-describedby' ] ].forEach( function( item ) { if ( attrs[ item[ 0 ] ] ) output += ' ' + item[ 1 ] + '="' + escapeAttribute( attrs[ item[ 0 ] ] ) + '"'; } );
        output += dataAttributes( attrs.imageData );
        if ( attrs.imageStyle ) output += ' style="' + escapeAttribute( attrs.imageStyle ) + '"';
        return output + '>';
    }
    function labelMarkup( attrs ) {
        var tag = labelTag( attrs );
        var output = '<' + tag;
        [ [ 'labelClassName', 'class' ], [ 'labelId', 'id' ], [ 'labelTitle', 'title' ], [ 'labelLabelledBy', 'aria-labelledby' ], [ 'labelDescribedBy', 'aria-describedby' ] ].forEach( function( item ) { if ( attrs[ item[ 0 ] ] ) output += ' ' + item[ 1 ] + '="' + escapeAttribute( attrs[ item[ 0 ] ] ) + '"'; } );
        output += dataAttributes( attrs.labelData );
        if ( attrs.labelStyle ) output += ' style="' + escapeAttribute( attrs.labelStyle ) + '"';
        return output + '>' + ( attrs.label || '' ) + '</' + tag + '>';
    }
    function markup( attrs ) {
        var output = '<a';
        [ [ 'className', 'class' ], [ 'href', 'href' ], [ 'anchorId', 'id' ], [ 'linkTarget', 'target' ], [ 'rel', 'rel' ], [ 'ariaLabel', 'aria-label' ], [ 'ariaLabelledBy', 'aria-labelledby' ], [ 'ariaDescribedBy', 'aria-describedby' ], [ 'anchorTitle', 'title' ] ].forEach( function( item ) { if ( attrs[ item[ 0 ] ] ) output += ' ' + item[ 1 ] + '="' + escapeAttribute( attrs[ item[ 0 ] ] ) + '"'; } );
        output += dataAttributes( attrs.anchorData );
        if ( attrs.anchorStyle ) output += ' style="' + escapeAttribute( attrs.anchorStyle ) + '"';
        output += '>';
        var image = imageMarkup( attrs );
        var label = labelMarkup( attrs );
        return output + ( attrs.contentOrder === 'label-first' ? label + image : image + label ) + '</a>';
    }
    function selectMedia( props, media ) {
        var next = { src: media && media.url ? media.url : '', srcset: '', mediaId: media && media.id ? media.id : 0 };
        if ( media && typeof media.alt === 'string' && media.alt !== '' ) next.alt = media.alt;
        if ( ! props.attributes.width && media && media.width ) next.width = String( media.width );
        if ( ! props.attributes.height && media && media.height ) next.height = String( media.height );
        props.setAttributes( next );
    }
    function inspector( props ) {
        var attrs = props.attributes;
        return createElement( InspectorControls, null,
            createElement( PanelBody, { title: 'Link' },
                createElement( TextControl, { label: 'URL', value: attrs.href || '', onChange: function( value ) { props.setAttributes( { href: safeHref( value ) } ); } } ),
                createElement( ToggleControl, { label: 'Open in new tab', checked: attrs.linkTarget === '_blank', onChange: function( checked ) { props.setAttributes( { linkTarget: checked ? '_blank' : '' } ); } } ),
                createElement( TextControl, { label: 'Link rel', value: attrs.rel || '', onChange: function( value ) { props.setAttributes( { rel: value } ); } } )
            ),
            createElement( PanelBody, { title: 'Image' },
                createElement( MediaUpload, { onSelect: function( media ) { selectMedia( props, media ); }, allowedTypes: [ 'image' ], value: attrs.mediaId || undefined, render: function( obj ) { return createElement( Button, { variant: 'secondary', onClick: obj.open }, attrs.src ? 'Replace image' : 'Select image' ); } } ),
                createElement( TextControl, { label: 'Alternative text', value: attrs.alt || '', onChange: function( value ) { props.setAttributes( { alt: value } ); } } ),
                createElement( TextControl, { label: 'Width', value: attrs.width || '', onChange: function( value ) { props.setAttributes( { width: value } ); } } ),
                createElement( TextControl, { label: 'Height', value: attrs.height || '', onChange: function( value ) { props.setAttributes( { height: value } ); } } ),
                createElement( TextareaControl, { label: 'Density sources', help: 'srcset candidates, for example mark.png 1x, mark-2x.png 2x', value: attrs.srcset || '', onChange: function( value ) { props.setAttributes( { srcset: value } ); } } ),
                createElement( TextControl, { label: 'Sizes', value: attrs.sizes || '', onChange: function( value ) { props.setAttributes( { sizes: value } ); } } )
            )
        );
    }
    function edit( props ) {
        var attrs = props.attributes;
        var image = createElement( MediaUpload, { onSelect: function( media ) { selectMedia( props, media ); }, allowedTypes: [ 'image' ], value: attrs.mediaId || undefined, render: function( obj ) { return createElement( 'img', { alt: attrs.alt || '', className: attrs.imageClassName || undefined, height: attrs.height || undefined, src: attrs.src || undefined, srcSet: attrs.srcset || undefined, style: styleObject( attrs.imageStyle ), width: attrs.width || undefined, onClick: obj.open } ); } } );
        var label = createElement( RichText, { tagName: labelTag( attrs ), className: attrs.labelClassName || undefined, style: styleObject( attrs.labelStyle ), value: attrs.label || '', allowedFormats: [ 'core/bold', 'core/italic' ], placeholder: 'Label', onChange: function( value ) { props.setAttributes( { label: value } ); } } );
        var children = attrs.contentOrder === 'label-first' ? [ label, image ] : [ image, label ];
        return createElement( element.Fragment, null, inspector( props ), createElement( 'a', useBlockProps( { className: attrs.className || undefined, href: attrs.href || undefined, id: attrs.anchorId || undefined, style: styleObject( attrs.anchorStyle ), target: attrs.linkTarget || undefined, rel: attrs.rel || undefined, 'aria-label': attrs.ariaLabel || undefined, onClick: function( event ) { event.preventDefault(); } } ), children[ 0 ], children[ 1 ] ) );
    }
    function save( props ) { return createElement( element.RawHTML, null, markup( props.attributes ) ); }
    blocks.registerBlockType( '__BLOCK_NAME__', { attributes: attributes, supports: { html: false, customClassName: false }, edit: edit, save: save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element );
JS;

        return array(
            'index.js' => str_replace(
                array( '__BLOCK_NAME__', '__BLOCK_ATTRIBUTES__' ),
                array( $blockName, json_encode($this->blockJson(substr($blockName, 0, (int) strrpos($blockName, '/')))['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ),
                $script
            ),
        );
    }

    /** @param array<string, mixed> $attrs */
    public function markup(array $attrs): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $append = static function (string $markup, array $attrs, array $map) use ($escape): string {
            foreach ( $map as $key => $name ) {
                if ( '' !== (string) ($attrs[$key] ?? '') ) {
                    $markup .= ' ' . $name . '="' . $escape($attrs[$key]) . '"';
                }
            }

            return $markup;
        };
        $data = static function (mixed $bag) use ($escape): string {
            if ( ! is_array($bag) || array() === $bag ) {
                return '';
            }
            ksort($bag);
            $markup = '';
            foreach ( $bag as $name => $value ) {
                $markup .= ' ' . $name . '="' . $escape($value) . '"';
            }

            return $markup;
        };
        $markup = $append('<a', $attrs, array( 'className' => 'class', 'href' => 'href', 'anchorId' => 'id', 'linkTarget' => 'target', 'rel' => 'rel', 'ariaLabel' => 'aria-label', 'ariaLabelledBy' => 'aria-labelledby', 'ariaDescribedBy' => 'aria-describedby', 'anchorTitle' => 'title' ));
        $markup .= $data($attrs['anchorData'] ?? array());
        if ( '' !== (string) ($attrs['anchorStyle'] ?? '') ) {
            $markup .= ' style="' . $escape($attrs['anchorStyle']) . '"';
        }
        $image = $append('<img alt="' . $escape($attrs['alt'] ?? '') . '"', $attrs, array( 'imageClassName' => 'class', 'decoding' => 'decoding', 'height' => 'height', 'imageId' => 'id', 'loading' => 'loading', 'sizes' => 'sizes', 'src' => 'src', 'srcset' => 'srcset', 'imageTitle' => 'title', 'width' => 'width', 'imageLabelledBy' => 'aria-labelledby', 'imageDescribedBy' => 'aria-describedby' ));
        $image .= $data($attrs['imageData'] ?? array());
        if ( '' !== (string) ($attrs['imageStyle'] ?? '') ) {
            $image .= ' style="' . $escape($attrs['imageStyle']) . '"';
        }
        $image .= '>';
        $tag = in_array((string) ($attrs['labelTag'] ?? ''), self::LABEL_TAGS, true) ? (string) $attrs['labelTag'] : 'span';
        $label = $append('<' . $tag, $attrs, array( 'labelClassName' => 'class', 'labelId' => 'id', 'labelTitle' => 'title', 'labelLabelledBy' => 'aria-labelledby', 'labelDescribedBy' => 'aria-describedby' ));
        $label .= $data($attrs['labelData'] ?? array());
        if ( '' !== (string) ($attrs['labelStyle'] ?? '') ) {
            $label .= ' style="' . $escape($attrs['labelStyle']) . '"';
        }
        $label .= '>' . (string) ($attrs['label'] ?? '') . '</' . $tag . '>';
        $body = 'label-first' === ($attrs['contentOrder'] ?? '') ? $label . $image : $image . $label;

        return $markup . '>' . $body . '</a>';
    }

    /**
     * Comment attributes are only the fields Gutenberg does not extract from
     * saved markup. Markup-owned fields use source:attribute/html/tag.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public function commentAttributes(array $attrs): array
    {
        $comment = array_diff_key($attrs, array_flip($this->sourcedAttributeNames()));
        foreach ( $this->blockJson('custom')['attributes'] as $key => $schema ) {
            if ( isset($schema['source']) || ! array_key_exists($key, $comment) ) {
                continue;
            }
            if ( ($schema['default'] ?? null) === $comment[$key] ) {
                unset($comment[$key]);
            }
        }

        return $comment;
    }

    /** @return array<int, string> */
    public function sourcedAttributeNames(): array
    {
        $names = array();
        foreach ( $this->blockJson('custom')['attributes'] as $key => $schema ) {
            if ( isset($schema['source']) ) {
                $names[] = $key;
            }
        }

        return $names;
    }

    /**
     * Extract markup-owned attributes the way Gutenberg's getBlockAttributes does:
     * querySelector plus attribute, innerHTML, or tag, ignoring stale comment copies.
     *
     * @param array<string, mixed> $commentAttributes
     * @return array<string, mixed>
     */
    public function attributesFromMarkup(string $html, array $commentAttributes = array()): array
    {
        $document = \Dom\HTMLDocument::createFromString('<body>' . $html . '</body>', LIBXML_NOERROR);
        $attrs = array();
        foreach ( $this->blockJson('custom')['attributes'] as $key => $schema ) {
            $source = $schema['source'] ?? null;
            if ( ! is_string($source) ) {
                $attrs[$key] = $commentAttributes[$key] ?? ($schema['default'] ?? '');
                continue;
            }
            $match = $document->querySelector((string) ($schema['selector'] ?? ''));
            $value = null;
            if ( $match instanceof \Dom\Element ) {
                if ( 'attribute' === $source ) {
                    $attribute = (string) ($schema['attribute'] ?? '');
                    $value = $match->hasAttribute($attribute) ? $match->getAttribute($attribute) : null;
                } elseif ( 'html' === $source ) {
                    $value = $match->innerHTML;
                } elseif ( 'tag' === $source ) {
                    $value = strtolower($match->tagName);
                }
            }
            $attrs[$key] = null === $value || '' === $value ? ($schema['default'] ?? '') : $value;
        }

        return $attrs;
    }

    /** @return array<string, mixed> */
    public function definition(string $namespace): array
    {
        return array(
            'name' => self::LOCAL_NAME,
            'block_json' => $this->blockJson($namespace),
            'script_dependencies' => array( 'index.js' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ) ),
            'assets' => $this->assets($namespace . '/' . self::LOCAL_NAME),
        );
    }
}
