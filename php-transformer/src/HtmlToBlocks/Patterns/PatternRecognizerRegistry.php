<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class PatternRecognizerRegistry
{
    /** Canonical production composition; array order defines recognizer precedence. */
    public static function createDefault(): self
    {
        $buttons = new ButtonsPattern();
        $quote = new QuotePattern();

        return new self(array(
            new MediaTextPattern(),
            new CoverPattern(),
            new ColumnsPattern(),
            new MathPattern(),
            new ParameterTablePattern(),
            new SpacerPattern(),
            new CodeWindowPattern(),
            new LogoPattern(),
            new PlaceholderMediaPattern(),
            $quote,
            new FigureQuotePattern($quote),
            new DetailsPattern(),
            new GalleryPattern(),
            new ButtonsContainerPattern($buttons),
            new ButtonAnchorPattern($buttons),
            new ButtonPattern($buttons),
            new AccordionPattern(),
            new SocialLinksPattern(),
            new NavigationPattern(),
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
