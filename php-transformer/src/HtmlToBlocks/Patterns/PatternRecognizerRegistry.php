<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class PatternRecognizerRegistry
{
    /** Canonical production composition; array order defines recognizer precedence. */
    public static function createDefault(
        \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        \Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime $runtime,
        \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier $sourceElementClassifier,
        \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization $richTextMaterializer
    ): self
    {
        $buttons = new ButtonsPattern($createBlock);
        $quote = new QuotePattern($runtime, $createBlock, $sourceElementClassifier);

        return new self(array(
            new MediaTextPattern($createBlock),
            new CoverPattern($createBlock),
            new ColumnsPattern($createBlock),
            new MathPattern($runtime, $createBlock),
            new ParameterTablePattern($createBlock),
            new SpacerPattern($createBlock),
            new CodeWindowPattern($createBlock),
            new LogoPattern($richTextMaterializer, $createBlock),
            new PlaceholderMediaPattern($createBlock, $runtime),
            $quote,
            new FigureQuotePattern($quote),
            new DetailsPattern($createBlock),
            new GalleryPattern($createBlock),
            new ButtonsContainerPattern($buttons),
            new ButtonAnchorPattern($createBlock, $buttons),
            new ButtonPattern($buttons),
            new AccordionPattern($createBlock),
            new TabsPattern($createBlock),
            new SocialLinksPattern($createBlock),
            new NavigationPattern($createBlock),
        ));
    }

    /**
     * @param array<int, PatternRecognizerInterface> $recognizers
     */
    public function __construct(private readonly array $recognizers)
    {
    }

    /** @param list<class-string<PatternRecognizerInterface>> $allowed */
    public function firstMatch(DOMElement $element, PatternContext $context, array $allowed = array()): ?PatternRecognitionResult
    {
        foreach ( $this->recognizers as $recognizer ) {
            if ( array() !== $allowed && ! in_array($recognizer::class, $allowed, true) ) {
                continue;
            }
            $result = $recognizer->recognize($element, $context);
            if ( null !== $result ) {
                return $result;
            }
        }

        return null;
    }
}
