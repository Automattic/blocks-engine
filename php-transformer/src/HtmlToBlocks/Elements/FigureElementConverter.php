<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CodeWindowPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\FigureQuotePattern;
use DOMElement;

/** Converts figures and their captions through the ordered figure strategies. */
final class FigureElementConverter implements ElementConverter
{
    public function __construct(private readonly FigureElementContext $context)
    {
    }

    public function handles(string $tagName): bool
    {
        return 'figure' === $tagName || 'figcaption' === $tagName;
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        if ( 'figcaption' === $tagName ) {
            $content = SourceDom::innerHtml($element);
            if ( ! $this->context->hasVisibleText($content) ) {
                return ConversionOutcome::handled(null);
            }

            return ConversionOutcome::handled($this->context->createBlock(
                'core/paragraph',
                array_merge($this->context->presentationAttributes($element), array( 'content' => $content )),
                array(),
                $element
            ));
        }

        self::restoreSavedTableClasses($element);

        $gallery = $this->context->mediaGalleryBlock($element, $fallbacks);
        if ( null !== $gallery ) {
            return ConversionOutcome::handled($gallery);
        }

        $codeWindow = $this->context->recognizePatterns($element, $fallbacks, array( CodeWindowPattern::class ));
        if ( null !== $codeWindow ) {
            return ConversionOutcome::handled($codeWindow);
        }

        $linkedMedia = $this->context->linkedMediaAnchor($element);
        if ( $linkedMedia instanceof DOMElement ) {
            $linkedPicture = SourceDom::firstChildElement($linkedMedia, 'picture');
            if ( $linkedPicture instanceof DOMElement ) {
                return ConversionOutcome::handled($this->context->convertPicture($linkedPicture, $element, $linkedMedia));
            }

            $linkedImage = SourceDom::firstChildElement($linkedMedia, 'img');
            if ( $linkedImage instanceof DOMElement ) {
                return ConversionOutcome::handled($this->context->convertImage($linkedImage, $element, null, $linkedMedia));
            }
        }

        $image = $this->context->mediaElement($element, 'img');
        if ( $image instanceof DOMElement ) {
            return ConversionOutcome::handled($this->context->convertImage($image, $element));
        }

        $picture = $this->context->mediaElement($element, 'picture');
        if ( $picture instanceof DOMElement ) {
            return ConversionOutcome::handled($this->context->convertPicture($picture, $element));
        }

        $blockquote = SourceDom::firstChildElement($element, 'blockquote');
        if ( $blockquote instanceof DOMElement ) {
            return ConversionOutcome::handled($this->context->recognizePatterns($element, $fallbacks, array( FigureQuotePattern::class )));
        }

        return ConversionOutcome::handled($this->context->convertGeneric($element, $fallbacks));
    }

    /**
     * `core/table` saves its block classes on the figure it wraps the table in.
     * Reading that markup back means returning them to the table they came
     * from; left on the figure they become a wrapper group, so converting the
     * engine's own output would add a level on every pass.
     */
    private static function restoreSavedTableClasses(DOMElement $figure): void
    {
        $classes = preg_split('/\s+/', trim($figure->getAttribute('class'))) ?: array();
        if ( ! in_array('wp-block-table', $classes, true) ) {
            return;
        }
        $table = SourceDom::firstChildElement($figure, 'table');
        if ( ! $table instanceof DOMElement ) {
            return;
        }
        $kept = array();
        $moved = array();
        foreach ( $classes as $class ) {
            if ( '' === $class ) {
                continue;
            }
            if ( 'wp-block-table' === $class || str_starts_with($class, 'blocks-engine-') ) {
                $kept[] = $class;
            } else {
                $moved[] = $class;
            }
        }
        if ( array() === $moved ) {
            return;
        }
        $tableClasses = preg_split('/\s+/', trim($table->getAttribute('class'))) ?: array();
        $table->setAttribute('class', implode(' ', array_values(array_unique(array_filter(array_merge($moved, $tableClasses), static fn (string $class): bool => '' !== $class)))));
        $figure->setAttribute('class', implode(' ', $kept));
    }
}
