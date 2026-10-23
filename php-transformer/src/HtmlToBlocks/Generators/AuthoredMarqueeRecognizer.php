<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators;

use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterializer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Recognizes authored text marquees and lowers them to the authored marquee
 * companion block.
 *
 * Two source shapes qualify: a CSS-animated track whose children repeat as two
 * identical halves under an infinite linear translate keyframe, and a track
 * that declares its motion through `data-marquee-animation`.
 * {@see AuthoredMarqueeBlockGenerator} owns the block definition and markup.
 */
final class AuthoredMarqueeRecognizer
{
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly StyleResolver $styleResolver,
        private readonly RichTextMaterializer $richTextMaterializer,
        private readonly Runtime $runtime,
        private readonly HtmlTransformerSession $session
    ) {
    }

    /** @return array<string, mixed>|null */
    public function cssAuthoredMarqueeBlock(DOMElement $element): ?array
    {
        if ( ! $this->sourceElementClassifier->hasMotionStructureToken($element) ) {
            return null;
        }

        $children = $this->authoredMarqueeElementChildren($element);
        $count = count($children);
        if ( 0 === $count || 0 !== $count % 2 ) {
            return null;
        }

        $half = intdiv($count, 2);
        $signature = static function (DOMElement $item) use (&$signature): string {
            $parts = array(
                strtolower($item->tagName),
                trim($item->getAttribute('class')),
                trim($item->textContent ?? ''),
            );
            foreach ( $item->childNodes as $child ) {
                if ( $child instanceof DOMElement ) {
                    $parts[] = $signature($child);
                }
            }

            return implode("\0", $parts);
        };
        for ( $index = 0; $index < $half; ++$index ) {
            if ( $signature($children[$index]) !== $signature($children[$index + $half]) ) {
                return null;
            }
        }

        $itemSources = $this->authoredMarqueeItemSources($children, $half);
        if ( null === $itemSources ) {
            return null;
        }

        $motion = $this->authoredMarqueeMotion($element);
        if ( null === $motion ) {
            return null;
        }

        $items = array();
        foreach ( $itemSources as $item ) {
            $content = $this->authoredMarqueeItemContent($item);
            if ( null === $content ) {
                return null;
            }
            $items[] = array(
                'content' => $content,
                'className' => trim($this->attr($item, 'class')),
                'marker' => trim($this->attr($item, 'data-blocks-engine-richtext-marker')),
            );
        }

        $generator = new AuthoredMarqueeBlockGenerator();
        $this->generatedBlocks()->register(AuthoredMarqueeBlockGenerator::class, $generator->definition($this->generatedBlocks()->namespace()));
        $attributes = array(
            'items' => $items,
            'direction' => $motion['direction'],
            'duration' => $motion['duration'],
            'decorative' => SourceDom::hasAriaHiddenAncestor($element),
        );
        $markup = $generator->markup($attributes);

        return array(
            'blockName' => $this->generatedBlocks()->blockName(AuthoredMarqueeBlockGenerator::LOCAL_NAME),
            'attrs' => $attributes,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /**
     * @return list<DOMElement>
     */
    private function authoredMarqueeElementChildren(DOMElement $element): array
    {
        return array_values(array_filter(
            iterator_to_array($element->childNodes),
            static fn (mixed $child): bool => $child instanceof DOMElement
        ));
    }

    /**
     * @param list<DOMElement> $children
     * @return list<DOMElement>|null
     */
    private function authoredMarqueeItemSources(array $children, int $half): ?array
    {
        $leaves = true;
        foreach ( $children as $child ) {
            if ( 0 !== $child->childElementCount ) {
                $leaves = false;
                break;
            }
        }
        if ( $leaves && 2 <= $half ) {
            return array_slice($children, 0, $half);
        }
        if ( 2 === count($children) && 0 < $children[0]->childElementCount ) {
            $items = $this->authoredMarqueeElementChildren($children[0]);
            return array() === $items ? null : $items;
        }

        return null;
    }

    private function authoredMarqueeItemContent(DOMElement $item): ?string
    {
        if ( 0 === $item->childElementCount ) {
            $text = trim($item->textContent ?? '');
            return '' === $text ? null : $this->runtime->escapeHtml($text);
        }

        $content = $this->richTextMaterializer->content($item);
        if ( '' === trim($this->runtime->stripAllTags($content))
            || $this->richTextMaterializer->hasStructuralHtml($content)
            || $this->richTextMaterializer->requiresHtmlFallback($content)
        ) {
            return null;
        }

        return $content;
    }

    /** @return array{direction: string, duration: float}|null */
    private function authoredMarqueeMotion(DOMElement $element): ?array
    {
        $declarations = $this->cascadedAnimationDeclarations($element);
        $name = trim((string) ($declarations['animation-name'] ?? ''));
        $duration = trim((string) ($declarations['animation-duration'] ?? ''));
        $direction = strtolower(trim((string) ($declarations['animation-direction'] ?? 'normal')));
        $iteration = strtolower(trim((string) ($declarations['animation-iteration-count'] ?? '1')));
        $timing = strtolower(trim((string) ($declarations['animation-timing-function'] ?? 'ease')));

        if ( '' === $name || 'infinite' !== $iteration || 'linear' !== $timing || ! in_array($direction, array( 'normal', 'reverse' ), true) ) {
            return null;
        }
        $keyframeDirection = null;
        ( new CssStylesheetTransformer() )->visitKeyframeRules(
            $this->authorStyles()->combinedCss(),
            function (string $keyframeName, string $body) use ($name, &$keyframeDirection): void {
                if ( 0 === strcasecmp(trim($keyframeName), trim($name)) ) {
                    $keyframeDirection = $this->marqueeKeyframeDirection($body);
                }
            }
        );
        if ( null === $keyframeDirection || ! preg_match('/^([0-9]+(?:\.[0-9]+)?)(ms|s)$/', strtolower($duration), $durationMatch) ) {
            return null;
        }
        $seconds = (float) $durationMatch[1] * ('ms' === $durationMatch[2] ? 0.001 : 1);
        $resolvedDirection = 'reverse' === $direction
            ? ('left' === $keyframeDirection ? 'right' : 'left')
            : $keyframeDirection;

        return array(
            'direction' => $resolvedDirection,
            'duration' => min(600, max(1, $seconds)),
        );
    }

    /** @param array<string, array<string, mixed>> $cascade @param array<string, string> $declarations */
    private function applyAuthoredAnimationCascade(array &$cascade, array $declarations, int $specificity, int &$sequence): void
    {
        $properties = array( 'animation-name', 'animation-duration', 'animation-timing-function', 'animation-iteration-count', 'animation-direction' );
        foreach ( $declarations as $property => $rawValue ) {
            if ( 'animation' !== $property && ! in_array($property, $properties, true) ) {
                continue;
            }
            $important = (bool) preg_match('/!\s*important\s*$/i', $rawValue);
            $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $rawValue));
            $values = 'animation' === $property ? $this->marqueeAnimationShorthand($value) : array( $property => $value );
            if ( null === $values ) {
                $values = array( 'animation-name' => 'none' );
            }
            foreach ( $values as $name => $resolved ) {
                CssCascade::apply($cascade, $name, array(
                    'value' => $resolved,
                    'important' => $important,
                    'specificity' => $specificity,
                    'order' => $sequence,
                ));
            }
            ++$sequence;
        }
    }

    /** @return array<string, string>|null */
    private function marqueeAnimationShorthand(string $animation): ?array
    {
        $layers = CssValueSplitter::splitTopLevel($animation, array( ',' ));
        if ( 1 !== count($layers) ) {
            return null;
        }
        $resolved = array(
            'animation-name' => 'none',
            'animation-duration' => '0s',
            'animation-timing-function' => 'ease',
            'animation-iteration-count' => '1',
            'animation-direction' => 'normal',
        );
        $times = 0;
        foreach ( CssValueSplitter::splitTopLevelWhitespace($layers[0]) as $token ) {
            $lower = strtolower($token);
            if ( preg_match('/^[0-9]+(?:\.[0-9]+)?m?s$/', $lower) ) {
                if ( 0 === $times++ ) {
                    $resolved['animation-duration'] = $lower;
                }
            } elseif ( 'infinite' === $lower || is_numeric($lower) ) {
                $resolved['animation-iteration-count'] = $lower;
            } elseif ( in_array($lower, array( 'normal', 'reverse', 'alternate', 'alternate-reverse' ), true) ) {
                $resolved['animation-direction'] = $lower;
            } elseif ( 'linear' === $lower || str_starts_with($lower, 'cubic-bezier(') || str_starts_with($lower, 'steps(') || str_starts_with($lower, 'ease') ) {
                $resolved['animation-timing-function'] = $lower;
            } elseif ( ! in_array($lower, array( 'none', 'forwards', 'backwards', 'both', 'running', 'paused' ), true) ) {
                $resolved['animation-name'] = $token;
            }
        }
        return $resolved;
    }

    private function marqueeKeyframeDirection(string $body): ?string
    {
        $frames = array( 'start' => null, 'end' => null );
        ( new CssStylesheetTransformer() )->visitStyleRules($body, function (string $prelude, string $declarations) use (&$frames): void {
            $transform = $this->styleResolver->cssDeclarations($declarations)['transform'] ?? '';
            $offsets = CssStylesheetTransformer::splitSelectorList($prelude) ?? array();
            foreach ( $offsets as $offset ) {
                $offset = strtolower(trim($offset));
                if ( 'from' === $offset || '0%' === $offset ) {
                    $frames['start'] = $this->marqueeTranslationPercentage($transform);
                } elseif ( 'to' === $offset || '100%' === $offset ) {
                    $frames['end'] = $this->marqueeTranslationPercentage($transform);
                }
            }
        });
        if ( 0.0 === $frames['start'] && -50.0 === $frames['end'] ) {
            return 'left';
        }
        return -50.0 === $frames['start'] && 0.0 === $frames['end'] ? 'right' : null;
    }

    private function marqueeTranslationPercentage(string $transform): ?float
    {
        if ( ! preg_match('/translate(?:X)?\s*\(\s*(-?[0-9]+(?:\.[0-9]+)?)(%?)\s*\)/i', $transform, $match) ) {
            return null;
        }
        $value = (float) $match[1];
        return '%' === $match[2] || 0.0 === $value ? $value : null;
    }

    /** @return array<string, mixed>|null */
    public function authoredMarqueeBlock(DOMElement $element): ?array
    {
        $track = null;
        foreach ( $element->getElementsByTagName('*') as $candidate ) {
            if ( $candidate instanceof DOMElement && in_array($this->attr($candidate, 'data-marquee-animation'), array( 'left', 'right' ), true) ) {
                $track = $candidate;
                break;
            }
        }
        if ( ! $track instanceof DOMElement ) {
            return null;
        }
        foreach ( SourceDom::descendantElements($element) as $node ) {
            if ( FormControlClassifier::isControlElement($node) ) {
                return null;
            }
        }

        $content = '';
        foreach ( $track->getElementsByTagName('*') as $candidate ) {
            if ( ! $candidate instanceof DOMElement || 'true' === $this->attr($candidate, 'aria-hidden') || 0 !== $candidate->childElementCount ) {
                continue;
            }
            $text = trim($candidate->textContent ?? '');
            if ( '' !== $text ) {
                $content = $this->runtime->escapeHtml($text);
                break;
            }
        }
        if ( '' === $content ) {
            return null;
        }

        $this->generatedBlocks()->register(AuthoredMarqueeBlockGenerator::class, ( new AuthoredMarqueeBlockGenerator() )->definition($this->generatedBlocks()->namespace()));

        $duration = 40.0;
        $durationCandidates = array( $this->styleResolver->cssDeclarations($this->attr($track, 'style'))['--marquee-duration'] ?? '' );
        for ( $carrier = $element; $carrier instanceof DOMElement && 'body' !== strtolower($carrier->tagName); $carrier = $carrier->parentNode instanceof DOMElement ? $carrier->parentNode : null ) {
            $durationCandidates[] = $this->styleResolver->cssDeclarations($this->attr($carrier, 'style'))['--marquee-duration'] ?? '';
        }
        foreach ( $durationCandidates as $value ) {
            if ( preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', trim((string) $value), $matches) ) {
                $duration = (float) $matches[1];
                break;
            }
        }

        $attributes = array(
            'content' => $content,
            'direction' => $this->attr($track, 'data-marquee-animation'),
            'duration' => min(600, max(1, $duration)),
        );
        $markup = ( new AuthoredMarqueeBlockGenerator() )->markup($attributes);

        return array(
            'blockName' => $this->generatedBlocks()->blockName(AuthoredMarqueeBlockGenerator::LOCAL_NAME),
            'attrs' => $attributes,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        );
    }

    /** @return array<string, string> */
    private function cascadedAnimationDeclarations(DOMElement $element): array
    {
        $cascade = array();
        $sequence = 0;
        foreach ( $this->authorStyles()->styleRules() as $rule ) {
            foreach ( $rule['selectors'] ?? array() as $selector ) {
                $value = (string) ($selector['selector'] ?? '');
                if ( '' !== $value && $this->styleResolver->matchesCssSelector($element, $value) ) {
                    $this->applyAuthoredAnimationCascade(
                        $cascade,
                        $rule['declarations'] ?? array(),
                        CssSelectorMatcher::specificity($selector['parsed'] ?? array()),
                        $sequence
                    );
                    break;
                }
            }
        }
        $this->applyAuthoredAnimationCascade(
            $cascade,
            $this->styleResolver->cssDeclarations($this->attr($element, 'style')),
            PHP_INT_MAX,
            $sequence
        );

        return array_map(static fn (array $fact): string => (string) $fact['value'], $cascade);
    }

    private function attr(DOMElement $element, string $name): string
    {
        return SourceDom::attr($element, $name);
    }

    private function authorStyles(): AuthorStyleAnalysis
    {
        return $this->session->authorStyleAnalysis();
    }

    private function generatedBlocks(): GeneratedBlockRegistry
    {
        return $this->session->generatedBlockRegistry()
            ?? throw new \LogicException('Generated block registry has not been prepared for this transform.');
    }
}
