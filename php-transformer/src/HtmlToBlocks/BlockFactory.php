<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleAttributeMapper;
use Automattic\BlocksEngine\PhpTransformer\WordPress\GeneratedGutenbergClassPolicy;

/**
 * @internal Block construction is owned by HtmlTransformer.
 */
final class BlockFactory
{
    /**
     * Semantic container tags `core/group` may render as its wrapper element.
     * `div` is the canonical default; the rest mirror the semantic HTML5
     * landmarks core's group block exposes via its `tagName` attribute.
     *
     * @var array<int, string>
     */
    private const GROUP_TAG_NAMES = array( 'div', 'header', 'nav', 'section', 'article', 'aside', 'footer', 'main' );

    private ?StyleAttributeMapper $styleMapper = null;

    private function styleMapper(): StyleAttributeMapper
    {
        return $this->styleMapper ??= new StyleAttributeMapper();
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function create(string $name, array $attrs = array(), array $innerBlocks = array()): array
    {
        $attrs = $this->normalizeAttrsForBlock($name, $attrs);
        $innerHtml = $this->blockHtml($name, $attrs, $innerBlocks);
        if ( is_array($innerHtml) ) {
            $innerContent = array( $innerHtml['opening'] );
            foreach ( $innerBlocks as $_ ) {
                $innerContent[] = null;
            }
            $innerContent[] = $innerHtml['closing'];
            $innerHtml      = $innerHtml['opening'] . $innerHtml['closing'];
        } else {
            $innerContent = array( $innerHtml );
        }

        return array(
            'blockName'    => $name,
            'attrs'        => $this->commentAttrs($name, $attrs),
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => $innerHtml,
            'innerContent' => $innerContent,
        );
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function normalizeAttrsForBlock(string $name, array $attrs): array
    {
        $attrs = $this->normalizeClassNameAttr($attrs);

        // Paragraph save() does not reproduce dimensions.maxWidth. Inline
        // max-width is retained by the generated geometry carrier stylesheet.
        if ( in_array($name, array( 'core/group', 'core/columns', 'core/paragraph' ), true) ) {
            unset($attrs['style']['dimensions']['maxWidth']);
            if ( empty($attrs['style']['dimensions']) ) {
                unset($attrs['style']['dimensions']);
            }
            if ( empty($attrs['style']) ) {
                unset($attrs['style']);
            }
        }

        if ( in_array($name, array( 'core/buttons', 'core/column', 'core/columns', 'core/group', 'core/heading', 'core/list', 'core/list-item', 'core/paragraph' ), true) ) {
            unset($attrs['style']['spacing']['blockGap']);
            if ( empty($attrs['style']['spacing']) ) {
                unset($attrs['style']['spacing']);
            }
            if ( empty($attrs['style']) ) {
                unset($attrs['style']);
            }
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function normalizeClassNameAttr(array $attrs): array
    {
        if ( ! is_string($attrs['className'] ?? null) ) {
            return $attrs;
        }

        $classes = array();
        foreach ( preg_split('/\s+/', trim($attrs['className'])) ?: array() as $class ) {
            if ( '' === $class || GeneratedGutenbergClassPolicy::isGeneratedClassName($class) || in_array($class, $classes, true) ) {
                continue;
            }
            $classes[] = $class;
        }

        if ( array() === $classes ) {
            unset($attrs['className']);
            return $attrs;
        }

        $attrs['className'] = implode(' ', $classes);
        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function commentAttrs(string $name, array $attrs): array
    {
        unset($attrs['inlineGeometryStyle']);
        if ( 'core/paragraph' === $name && preg_match('/^\s*<a\b/i', (string) ($attrs['content'] ?? '')) ) {
            unset($attrs['content']);
        }

        return $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return string|array{opening: string, closing: string}
     */
    private function blockHtml(string $name, array $attrs, array $innerBlocks): string|array
    {
        if ( 'core/heading' === $name ) {
            $level = (int) ($attrs['level'] ?? 2);
            $level = max(1, min(6, $level));
            return '<h' . $level . $this->blockSupportAttrs($attrs, 'wp-block-heading') . '>' . $this->preserveRichTextPunctuation((string) ($attrs['content'] ?? '')) . '</h' . $level . '>';
        }

        if ( 'core/paragraph' === $name ) {
            return '<p' . $this->blockSupportAttrs($attrs) . '>' . $this->preserveRichTextPunctuation((string) ($attrs['content'] ?? '')) . '</p>';
        }

        if ( 'core/list-item' === $name ) {
            $content = $this->preserveRichTextPunctuation((string) ($attrs['content'] ?? ''));
            if ( array() !== $innerBlocks ) {
                return array( 'opening' => '<li' . $this->blockSupportAttrs($attrs) . '>' . $content, 'closing' => '</li>' );
            }

            return '<li' . $this->blockSupportAttrs($attrs) . '>' . $content . '</li>';
        }

        if ( 'core/list' === $name ) {
            $tagName = ! empty($attrs['ordered']) ? 'ol' : 'ul';
            return array( 'opening' => '<' . $tagName . $this->blockSupportAttrs($attrs, 'wp-block-list') . '>', 'closing' => '</' . $tagName . '>' );
        }

        if ( 'core/quote' === $name ) {
            $citation = $this->preserveRichTextPunctuation((string) ($attrs['citation'] ?? ''));
            $closing = '' !== $citation ? '<cite>' . $citation . '</cite></blockquote>' : '</blockquote>';
            return array( 'opening' => '<blockquote' . $this->blockSupportAttrs($attrs, 'wp-block-quote') . '>', 'closing' => $closing );
        }

        if ( 'core/pullquote' === $name ) {
            $citation = $this->preserveRichTextPunctuation((string) ($attrs['citation'] ?? ''));
            $citation = '' !== $citation ? '<cite>' . $citation . '</cite>' : '';
            return '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-pullquote') . '><blockquote>' . $this->preserveRichTextPunctuation((string) ($attrs['value'] ?? '')) . $citation . '</blockquote></figure>';
        }

        if ( 'core/code' === $name ) {
            $content = (string) ($attrs['content'] ?? '');
            if ( ! preg_match('/<(?:span|mark|b|strong|i|em)\b/i', $content) ) {
                $content = htmlspecialchars($content, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            return '<pre class="wp-block-code"><code>' . $content . '</code></pre>';
        }

        if ( 'core/math' === $name ) {
            return '<div' . $this->blockSupportAttrs($attrs, 'wp-block-math') . '>' . ($attrs['content'] ?? '') . '</div>';
        }

        if ( 'core/icon' === $name ) {
            return '';
        }

        if ( 'core/preformatted' === $name ) {
            return '<pre' . $this->blockSupportAttrs($attrs, 'wp-block-preformatted') . '>' . ($attrs['content'] ?? '') . '</pre>';
        }

        if ( 'core/table' === $name ) {
            return $this->tableHtml($attrs);
        }

        if ( 'core/separator' === $name ) {
            return '<hr' . $this->blockSupportAttrs($attrs, implode(' ', GeneratedGutenbergClassPolicy::classesForBlock('core/separator'))) . ' />';
        }

        if ( 'core/spacer' === $name ) {
            $height = (string) ($attrs['height'] ?? '');
            $style = trim('' !== $height ? 'height:' . $height : (string) ($attrs['style'] ?? ''));
            $attrs['style'] = $style;
            return '<div' . $this->blockSupportAttrs($attrs, 'wp-block-spacer') . ' aria-hidden="true"></div>';
        }

        if ( 'core/columns' === $name ) {
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-columns') . '>', 'closing' => '</div>' );
        }

        if ( 'core/column' === $name ) {
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-column') . '>', 'closing' => '</div>' );
        }

        if ( 'core/details' === $name ) {
            return array(
                'opening' => '<details' . $this->blockSupportAttrs($attrs, 'wp-block-details') . '><summary>' . ($attrs['summary'] ?? '') . '</summary>',
                'closing' => '</details>',
            );
        }

        if ( 'core/accordion' === $name ) {
            return $this->roleWrapperHtml('group', $attrs, 'wp-block-accordion');
        }

        if ( 'core/accordion-item' === $name ) {
            $attrs['className'] = $this->mergeClassNames((string) ($attrs['className'] ?? ''), ! empty($attrs['openByDefault']) ? 'is-open' : '');
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-accordion-item') . '>', 'closing' => '</div>' );
        }

        if ( 'core/accordion-heading' === $name ) {
            $level = (int) ($attrs['level'] ?? 3);
            $level = max(1, min(6, $level));
            $showIcon = ! array_key_exists('showIcon', $attrs) || false !== $attrs['showIcon'];
            $icon = $showIcon ? '<span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>' : '';
            $title = '<span class="wp-block-accordion-heading__toggle-title">' . ($attrs['title'] ?? '') . '</span>';
            $children = 'left' === ($attrs['iconPosition'] ?? 'right') ? $icon . $title : $title . $icon;
            return '<h' . $level . $this->blockSupportAttrs($attrs, 'wp-block-accordion-heading') . '><button type="button" class="wp-block-accordion-heading__toggle">' . $children . '</button></h' . $level . '>';
        }

        if ( 'core/accordion-panel' === $name ) {
            return $this->roleWrapperHtml('region', $attrs, 'wp-block-accordion-panel');
        }

        if ( 'core/image' === $name ) {
            return $this->imageHtml($attrs);
        }

        if ( 'core/gallery' === $name ) {
            $caption = ! empty($attrs['caption']) ? '<figcaption class="blocks-gallery-caption wp-element-caption">' . $attrs['caption'] . '</figcaption>' : '';
            return array( 'opening' => '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-gallery') . '>', 'closing' => $caption . '</figure>' );
        }

        if ( 'core/embed' === $name ) {
            return $this->embedHtml($attrs);
        }

        if ( 'core/file' === $name ) {
            return $this->fileHtml($attrs);
        }

        if ( 'core/video' === $name ) {
            return $this->mediaHtml('video', $attrs);
        }

        if ( 'core/audio' === $name ) {
            return $this->mediaHtml('audio', $attrs);
        }

        if ( 'core/search' === $name ) {
            return $this->searchHtml($attrs);
        }

        if ( 'core/html' === $name ) {
            return (string) ($attrs['content'] ?? '');
        }

        if ( 'core/buttons' === $name ) {
            return array( 'opening' => '<div' . $this->blockSupportAttrs($attrs, 'wp-block-buttons') . '>', 'closing' => '</div>' );
        }

        if ( 'core/button' === $name ) {
            return $this->buttonHtml($attrs);
        }

        // The navigation family (`core/navigation`, `core/navigation-link`,
        // `core/navigation-submenu`) are dynamic, server-rendered blocks:
        // `supports.html` is false and each registers a `render_callback`, so
        // their `save()` returns null. WordPress stores only the block comment
        // delimiters (plus serialized inner blocks); the `<nav>`/`<ul>`/`<li>`
        // chrome is produced at render time. Emitting that static markup into the
        // stored block makes `wp.blocks.validateBlock` re-run `save()` (empty),
        // see the leftover tags, and flag every navigation block invalid in the
        // editor. The label/url/className ride in the comment attributes, so the
        // canonical save()-matching shape carries no inner HTML at all.
        if ( 'core/navigation' === $name || 'core/navigation-submenu' === $name ) {
            return array( 'opening' => '', 'closing' => '' );
        }

        if ( 'core/navigation-link' === $name ) {
            return '';
        }

        if ( 'core/shortcode' === $name ) {
            return '<div class="wp-block-shortcode">' . ($attrs['text'] ?? '') . '</div>';
        }

        if ( 'core/group' === $name ) {
            $tag = $this->groupTagName($attrs['tagName'] ?? null);
            return array( 'opening' => '<' . $tag . $this->blockSupportAttrs($attrs, 'wp-block-group') . '>', 'closing' => '</' . $tag . '>' );
        }

        return '';
    }

    /**
     * Resolve the wrapper tag for a `core/group`. Core's group `save()` renders
     * `<TagName>` from the `tagName` attribute, defaulting to `div`. Only the
     * semantic container tags core treats as group wrappers are honored; any
     * other value falls back to `div` so output never diverges from `save()`.
     */
    private function groupTagName(mixed $tagName): string
    {
        return is_string($tagName) && in_array($tagName, self::GROUP_TAG_NAMES, true) ? $tagName : 'div';
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array{opening: string, closing: string}
     */
    private function roleWrapperHtml(string $role, array $attrs, string $baseClass): array
    {
        return array( 'opening' => '<div role="' . $role . '"' . $this->blockSupportAttrs($attrs, $baseClass) . '>', 'closing' => '</div>' );
    }

    /**
     * Match core/button save(): useBlockProps.save() lives on the OUTER wrapper
     * <div>, so the block className and anchor id belong on the wrapper. The
     * inner <a>/<button> carries only structural classes plus color/border
     * support classes/styles.
     *
     * @param array<string, mixed> $attrs
     */
    private function buttonHtml(array $attrs): string
    {
        $support = $this->buttonStyleSupport($attrs);

        $wrapperAttrs = array(
            'id'    => (string) ($attrs['anchor'] ?? ''),
            'class' => $this->mergeClassNames('wp-block-button', $this->buttonWidthClasses($attrs), (string) ($attrs['className'] ?? '')),
            'style' => (string) ($attrs['inlineGeometryStyle'] ?? ''),
        );

        $controlAttrs = array(
            'class' => $this->mergeClassNames('wp-block-button__link', $support['classes'], 'wp-element-button'),
            'style' => $support['style'],
        );

        if ( 'button' === ($attrs['tagName'] ?? '') ) {
            $controlAttrs = array( 'type' => (string) ($attrs['type'] ?? 'button') ) + $controlAttrs;

            return '<div' . $this->htmlAttrs($wrapperAttrs) . '><button' . $this->htmlAttrs($controlAttrs) . '>' . $this->preserveRichTextPunctuation((string) ($attrs['text'] ?? '')) . '</button></div>';
        }

        $href = '' !== ($attrs['url'] ?? '') ? ' href="' . htmlspecialchars((string) $attrs['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' : '';
        return '<div' . $this->htmlAttrs($wrapperAttrs) . '><a' . $this->htmlAttrs($controlAttrs) . $href . '>' . $this->preserveRichTextPunctuation((string) ($attrs['text'] ?? '')) . '</a></div>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function buttonWidthClasses(array $attrs): string
    {
        $width = (int) ($attrs['width'] ?? 0);
        if ( ! in_array($width, array( 25, 50, 75, 100 ), true) ) {
            return '';
        }

        return 'has-custom-width wp-block-button__width-' . $width;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function tableHtml(array $attrs): string
    {
        $tableAttrs = array(
            'class' => empty($attrs['hasFixedLayout']) && array_key_exists('hasFixedLayout', $attrs) ? '' : 'has-fixed-layout',
        );
        $html = '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-table') . '><table' . $this->htmlAttrs($tableAttrs) . '>';
        foreach ( array( 'head' => 'thead', 'body' => 'tbody', 'foot' => 'tfoot' ) as $attrName => $tagName ) {
            if ( empty($attrs[$attrName]) || ! is_array($attrs[$attrName]) ) {
                continue;
            }
            $html .= '<' . $tagName . '>';
            foreach ( $attrs[$attrName] as $row ) {
                $html .= '<tr>';
                foreach ( $row['cells'] ?? array() as $cell ) {
                    $cellTag = 'th' === ($cell['tag'] ?? '') ? 'th' : 'td';
                    $html .= '<' . $cellTag . '>' . $this->preserveRichTextPunctuation((string) ($cell['content'] ?? '')) . '</' . $cellTag . '>';
                }
                $html .= '</tr>';
            }
            $html .= '</' . $tagName . '>';
        }
        $html .= '</table>';
        if ( ! empty($attrs['caption']) ) {
            $html .= '<figcaption class="wp-element-caption">' . $this->preserveRichTextPunctuation((string) $attrs['caption']) . '</figcaption>';
        }
        return $html . '</figure>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function imageHtml(array $attrs): string
    {
        $figureAttrs = $attrs;
        if ( ! empty($attrs['sizeSlug']) ) {
            $figureAttrs['className'] = $this->mergeClassNames((string) ($figureAttrs['className'] ?? ''), 'size-' . (string) $attrs['sizeSlug']);
        }
        if ( '' !== (string) ($attrs['width'] ?? '') || '' !== (string) ($attrs['height'] ?? '') ) {
            $figureAttrs['className'] = $this->mergeClassNames('is-resized', (string) ($figureAttrs['className'] ?? ''));
        }

        $imageAttrs = array(
            'src'    => $attrs['url'] ?? '',
            'alt'    => $attrs['alt'] ?? '',
            'title'  => $attrs['title'] ?? '',
            'srcset' => $attrs['srcset'] ?? '',
            'sizes'  => $attrs['sizes'] ?? '',
            'class'  => ! empty($attrs['id']) ? 'wp-image-' . (string) $attrs['id'] : '',
            'style'  => $this->imageDimensionStyle($attrs),
        );

        $img = '<img' . $this->htmlAttrs($imageAttrs, array( 'alt' )) . '/>';
        if ( ! empty($attrs['href']) ) {
            $linkAttrs = array(
                'href'        => (string) $attrs['href'],
                'id'          => (string) ($attrs['linkAnchor'] ?? ''),
                'target'      => (string) ($attrs['linkTarget'] ?? ''),
                'rel'         => (string) ($attrs['rel'] ?? ''),
                'class'       => (string) ($attrs['linkClass'] ?? ''),
                'aria-label'  => (string) ($attrs['linkAriaLabel'] ?? ''),
                'aria-hidden' => (string) ($attrs['linkAriaHidden'] ?? ''),
                'tabindex'    => (string) ($attrs['linkTabIndex'] ?? ''),
            );
            $img = '<a' . $this->htmlAttrs($linkAttrs) . '>' . $img . '</a>';
        }
        $caption = ! empty($attrs['caption']) ? '<figcaption class="wp-element-caption">' . $this->preserveRichTextPunctuation((string) $attrs['caption']) . '</figcaption>' : '';
        return '<figure' . $this->blockSupportAttrs($figureAttrs, 'wp-block-image') . '>' . $img . $caption . '</figure>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function imageDimensionStyle(array $attrs): string
    {
        if ( ! array_key_exists('width', $attrs) && ! array_key_exists('height', $attrs) ) {
            return '';
        }

        $style = array();
        if ( array_key_exists('width', $attrs) && null !== $attrs['width'] ) {
            $style[] = 'width:' . (string) $attrs['width'];
        }

        if ( ! array_key_exists('height', $attrs) || null === $attrs['height'] ) {
            // Gutenberg's image save shape keeps percentage widths as width-only
            // styles. The image's intrinsic dimensions (including an SVG viewBox)
            // provide the automatic aspect ratio without serializing height:auto.
            if ( ! $this->isPercentageWidth((string) ($attrs['width'] ?? '')) ) {
                $style[] = 'height:auto';
            }
        } else {
            $style[] = 'height:' . (string) $attrs['height'];
        }

        return implode(';', $style);
    }

    private function isPercentageWidth(string $width): bool
    {
        return 1 === preg_match('/%\s*$/', trim($width));
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function embedHtml(array $attrs): string
    {
        $url = htmlspecialchars((string) ($attrs['url'] ?? ''), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $classes = array('wp-block-embed');
        if ( ! empty($attrs['type']) ) {
            $classes[] = 'is-type-' . (string) $attrs['type'];
        }
        if ( ! empty($attrs['providerNameSlug']) ) {
            $classes[] = 'is-provider-' . (string) $attrs['providerNameSlug'];
            $classes[] = 'wp-block-embed-' . (string) $attrs['providerNameSlug'];
        }

        $figureAttrs = $attrs;
        $figureAttrs['className'] = $this->mergeClassNames(implode(' ', $classes), (string) ($attrs['className'] ?? ''));

        return '<figure' . $this->blockSupportAttrs($figureAttrs) . '><div class="wp-block-embed__wrapper">' . $url . '</div></figure>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function fileHtml(array $attrs): string
    {
        $href = (string) ($attrs['href'] ?? $attrs['url'] ?? '');
        $text = (string) ($attrs['text'] ?? ($href !== '' ? basename(parse_url($href, PHP_URL_PATH) ?: $href) : ''));
        $linkAttrs = array(
            'href' => $href,
        );

        $downloadButton = '';
        if ( ! empty($attrs['showDownloadButton']) ) {
            $downloadButton = '<a class="wp-block-file__button wp-element-button" href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" download>Download</a>';
        }

        return '<div' . $this->blockSupportAttrs($attrs, 'wp-block-file') . '><a' . $this->htmlAttrs($linkAttrs) . '>' . $this->preserveRichTextPunctuation($text) . '</a>' . $downloadButton . '</div>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function mediaHtml(string $tagName, array $attrs): string
    {
        $mediaAttrs = array(
            'src'      => (string) ($attrs['src'] ?? ''),
            'poster'   => (string) ($attrs['poster'] ?? ''),
            'preload'  => (string) ($attrs['preload'] ?? ''),
            'width'    => (string) ($attrs['width'] ?? ''),
            'height'   => (string) ($attrs['height'] ?? ''),
            'controls' => ! empty($attrs['controls']) ? 'controls' : '',
        );
        $caption = ! empty($attrs['caption']) ? '<figcaption class="wp-element-caption">' . $this->preserveRichTextPunctuation((string) $attrs['caption']) . '</figcaption>' : '';

        return '<figure' . $this->blockSupportAttrs($attrs, 'wp-block-' . $tagName) . '><' . $tagName . $this->htmlAttrs($mediaAttrs) . '></' . $tagName . '>' . $caption . '</figure>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function searchHtml(array $attrs): string
    {
        // core/search is dynamic in the supported runtime: save() returns null,
        // so stored blocks must carry no static form markup.
        return '';
    }

    /**
     * Translate a core/button block's native style support into the rendered
     * has-* support classes and the inline style string WordPress emits for
     * custom colors and borders. Accepts the canonical `style` object; falls back
     * to a legacy raw `style` string when present for backward compatibility.
     *
     * @param array<string, mixed> $attrs
     * @return array{classes: string, style: string}
     */
    private function buttonStyleSupport(array $attrs): array
    {
        $style = $attrs['style'] ?? null;
        if ( ! is_array($style) ) {
            return array(
                'classes' => '',
                'style'   => is_string($style) ? $style : '',
            );
        }

        $classes = array();
        $declarations = array();

        $background = (string) ($style['color']['background'] ?? '');
        $text = (string) ($style['color']['text'] ?? '');
        if ( '' !== $text ) {
            $classes[] = 'has-text-color';
        }
        if ( '' !== $background ) {
            $classes[] = 'has-background';
        }

        $border = is_array($style['border'] ?? null) ? $style['border'] : array();
        if ( isset($border['color']) && '' !== (string) $border['color'] ) {
            $classes[] = 'has-border-color';
            $declarations[] = 'border-color:' . (string) $border['color'];
        }
        if ( isset($border['style']) && '' !== (string) $border['style'] ) {
            $declarations[] = 'border-style:' . (string) $border['style'];
        }
        if ( isset($border['width']) && '' !== (string) $border['width'] ) {
            $declarations[] = 'border-width:' . (string) $border['width'];
        }
        if ( isset($border['radius']) && '' !== (string) $border['radius'] ) {
            $declarations[] = 'border-radius:' . (string) $border['radius'];
        }

        if ( '' !== $text ) {
            $declarations[] = 'color:' . $text;
        }
        if ( '' !== $background ) {
            $declarations[] = 'background-color:' . $background;
        }

        $shadow = trim((string) ($style['shadow'] ?? ''));
        if ( '' !== $shadow ) {
            $declarations[] = 'box-shadow:' . $shadow;
        }

        $padding = is_array($style['spacing']['padding'] ?? null) ? $style['spacing']['padding'] : array();
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
            if ( isset($padding[$side]) && '' !== (string) $padding[$side] ) {
                $declarations[] = 'padding-' . $side . ':' . (string) $padding[$side];
            }
        }

        $typography = is_array($style['typography'] ?? null) ? $style['typography'] : array();
        if ( isset($typography['fontSize']) && '' !== (string) $typography['fontSize'] ) {
            $classes[] = 'has-custom-font-size';
        }
        $typographyMap = array(
            'fontSize'      => 'font-size',
            'fontWeight'    => 'font-weight',
            'letterSpacing' => 'letter-spacing',
            'lineHeight'    => 'line-height',
            'textTransform' => 'text-transform',
        );
        foreach ( $typographyMap as $attrName => $cssName ) {
            if ( isset($typography[$attrName]) && '' !== (string) $typography[$attrName] ) {
                $declarations[] = $cssName . ':' . (string) $typography[$attrName];
            }
        }

        return array(
            'classes' => implode(' ', $classes),
            'style'   => implode(';', $declarations),
        );
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
     * @param array<string, mixed> $attrs
     */
    private function blockSupportAttrs(array $attrs, string $baseClass = ''): string
    {
        $support = $this->styleSupport($attrs['style'] ?? null);
        $presetClasses = $this->presetColorClasses($attrs);
        $layoutClasses = $this->layoutClasses($attrs['layout'] ?? null, $baseClass);
        $classes = $this->mergeClassNames($baseClass, $presetClasses, $support['classes'], $layoutClasses, (string) ($attrs['className'] ?? ''));
        $style = trim((string) $support['style'] . ';' . (string) ($attrs['inlineGeometryStyle'] ?? ''), ';');
        return $this->htmlAttrs(array(
            'id'    => (string) ($attrs['anchor'] ?? ''),
            'class' => $classes,
            'style' => $style,
        ));
    }

    /**
     * Serialize the canonical block `style` OBJECT into the has-* support classes
     * and inline CSS string WordPress emits in `save()`. A legacy raw `style`
     * string is passed through unchanged for backward compatibility.
     *
     * @param mixed $style
     * @return array{classes: string, style: string}
     */
    private function styleSupport(mixed $style): array
    {
        if ( is_array($style) ) {
            return $this->styleMapper()->serialize($style);
        }

        return array(
            'classes' => '',
            'style'   => is_string($style) ? $style : '',
        );
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function presetColorClasses(array $attrs): string
    {
        $classes = array();
        $textColor = $this->safeSlug((string) ($attrs['textColor'] ?? ''));
        if ( '' !== $textColor ) {
            $classes[] = 'has-' . $textColor . '-color';
            $classes[] = 'has-text-color';
        }

        $backgroundColor = $this->safeSlug((string) ($attrs['backgroundColor'] ?? ''));
        if ( '' !== $backgroundColor ) {
            $classes[] = 'has-' . $backgroundColor . '-background-color';
            $classes[] = 'has-background';
        }

        return implode(' ', $classes);
    }

    private function layoutClasses(mixed $layout, string $baseClass): string
    {
        if ( ! is_array($layout) ) {
            return '';
        }

        $type = $this->safeSlug((string) ($layout['type'] ?? ''));
        if ( ! in_array($type, array( 'constrained', 'flex', 'flow', 'grid' ), true) ) {
            return '';
        }

        return $this->mergeClassNames(
            'is-layout-' . $type,
            '' !== $baseClass ? $baseClass . '-is-layout-' . $type : ''
        );
    }

    private function safeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_-]+$/', $value) ? $value : '';
    }

    /**
     * Keep source-authored straight punctuation stable through wptexturize.
     */
    private function preserveRichTextPunctuation(string $html): string
    {
        $output = '';
        $inTag = false;
        $attributeQuote = '';
        $length = strlen($html);

        for ( $index = 0; $index < $length; ++$index ) {
            $character = $html[$index];
            if ( ! $inTag && '<' === $character ) {
                $inTag = true;
                $output .= $character;
                continue;
            }
            if ( $inTag ) {
                if ( '' !== $attributeQuote ) {
                    if ( $attributeQuote === $character ) {
                        $attributeQuote = '';
                    }
                } elseif ( '"' === $character || "'" === $character ) {
                    $attributeQuote = $character;
                } elseif ( '>' === $character ) {
                    $inTag = false;
                }
                $output .= $character;
                continue;
            }

            $output .= match ( $character ) {
                '"' => '&quot;',
                "'" => '&#039;',
                default => $character,
            };
        }

        return $output;
    }

    /**
     * @param array<string, string> $attrs
     * @param array<int, string> $includeEmpty
     */
    private function htmlAttrs(array $attrs, array $includeEmpty = array()): string
    {
        $html = '';
        foreach ( $attrs as $name => $value ) {
            if ( '' === $value && ! in_array($name, $includeEmpty, true) ) {
                continue;
            }
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $html;
    }
}
