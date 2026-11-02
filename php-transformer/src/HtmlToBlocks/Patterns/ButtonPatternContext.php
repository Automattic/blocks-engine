<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements\RuntimeIslandAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AccessibleLinkBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/** Button recognition evidence, backed by real collaborators. */
final class ButtonPatternContext
{
    public function __construct(
        private readonly ?StyleResolver $styleResolver = null,
        private readonly ?RichTextMaterialization $richTextMaterializer = null,
        private readonly ?SourceBlockCreator $createBlock = null,
        private readonly ?HtmlTransformerSession $session = null,
        private readonly ?RuntimeIslandAnalyzer $runtimeIslands = null
    ) {
    }

    /** @return array<string, mixed>|null */
    public function fileBlockFromAnchor(DOMElement $anchor): ?array
    {
        if ( ! $this->styleResolver instanceof StyleResolver || ! $this->createBlock instanceof SourceBlockCreator ) {
            return null;
        }

        $href = $this->safeFileUrl(SourceDom::attr($anchor, 'href'));
        if ( '' === $href ) {
            return null;
        }

        if ( $this->sitsInMixedLinkCluster($anchor) ) {
            return null;
        }

        $attrs = array_filter(array_merge($this->styleResolver->presentationAttributes($anchor), array(
            'href'               => $href,
            'fileName'           => $this->richText($anchor),
            'textLinkHref'       => $href,
            'showDownloadButton' => $anchor->hasAttribute('download'),
        )), static fn (mixed $value): bool => is_bool($value) ? true : '' !== $value);

        return $this->createBlock->createBlock('core/file', $attrs, array(), $anchor);
    }

    /**
     * Whether the anchor is one link among links rather than a document listing.
     *
     * core/file renders two affordances: the file link, plus a download button
     * whenever the source carried a `download` attribute. One authored anchor
     * therefore materialises as two, the author's own link is emptied of its
     * classes and loses `download` to the generated button, and both take the
     * button's smaller type. That is the right shape for a published document,
     * and the wrong one for a link that merely points at a file.
     *
     * A run of sibling anchors that all address files is a document listing, so
     * each one is a file. A cluster mixing a file link with ordinary
     * destinations — a footer reading `Email · Download CV · Work` — is a set of
     * links, and the file link is a link like the ones beside it.
     */
    private function sitsInMixedLinkCluster(DOMElement $anchor): bool
    {
        $parent = $anchor->parentNode;
        if ( ! $parent instanceof DOMElement ) {
            return false;
        }

        foreach ( $parent->childNodes as $sibling ) {
            if ( ! $sibling instanceof DOMElement || $sibling === $anchor || 'a' !== strtolower($sibling->tagName) ) {
                continue;
            }
            if ( '' === $this->safeFileUrl(SourceDom::attr($sibling, 'href')) ) {
                return true;
            }
        }

        return false;
    }

    public function resolvedStyle(DOMElement $element): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '';
        }

        return $this->styleResolver->resolveCssVariablesInValue(
            $this->styleResolver->specificityResolvedPresentationStyle($element),
            $element
        );
    }

    /** @return array<string, string> */
    public function resolvedDeclarations(DOMElement $element): array
    {
        return $this->styleResolver?->cssDeclarations($this->resolvedStyle($element)) ?? array();
    }

    public function controlSurfaceStyle(DOMElement $element): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '';
        }

        return $this->styleResolver->resolveCssVariablesInValue(
            $this->styleResolver->controlSurfaceResolvedStyle($element),
            $element
        );
    }

    /** Native supports must leave conditional families on their projected author selectors. */
    public function nativePresentationStyle(DOMElement $element): string
    {
        if (! $this->styleResolver instanceof StyleResolver) {
            return '';
        }

        return $this->styleResolver->cssDeclarationString($this->styleResolver->resolvedPresentationDeclarations(
            $element,
            $this->styleResolver->classOwnedResponsiveDeclarations(
                $element,
                $this->styleResolver->cssDeclarations($this->styleResolver->specificityResolvedPresentationStyle($element))
            ),
            true
        ));
    }

    /** Inner markup as button RichText {@see StyleResolver::richTextSerializationSource()}. */
    public function richTextInnerHtml(DOMElement $element): string
    {
        return SourceDom::innerHtml($this->styleResolver?->richTextSerializationSource($element) ?? $element);
    }

    public function richText(DOMElement $element): string
    {
        return $this->richTextMaterializer?->content($element) ?? SourceDom::innerHtml($element);
    }

    /** @param (\Closure(DOMElement, string): string)|null $imageMarkup */
    public function materializeSvgImages(DOMElement $element, string $content, ?\Closure $imageMarkup = null): ?string
    {
        return $this->richTextMaterializer?->contentWithMaterializedSvgImages($element, $content, $imageMarkup);
    }

    public function attribute(DOMElement $element, string $name): string
    {
        return SourceDom::attr($element, $name);
    }

    public function isGridItem(DOMElement $element): bool
    {
        return $element->parentNode instanceof DOMElement
            && in_array($this->authoredDisplay($element->parentNode), array( 'grid', 'inline-grid' ), true);
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return $this->runtimeIslands?->isRuntimeDomTarget($element) ?? false;
    }

    /**
     * The control renders the anchor's descendants with their source identity
     * (classes, nesting), so author rules keep addressing them as written.
     * Rules that reach them through the anchor itself are projected onto the
     * emitted control element by the author selector projection. A surface
     * unwrapped into that control is projected, not retained label markup.
     * Logo labels can limit this override to predicted block/layout topology:
     * their materialized RichText carriers already agree with the saved label.
     * When supplied, $labelHtml identifies the RichText markers actually saved
     * in the label; those carriers keep their role and subject projection.
     */
    public function retainSourceDescendants(DOMElement $anchor, ?DOMElement $projectedSurface = null, bool $onlyProjectedTopology = false, string $labelHtml = ''): void
    {
        $projections = $this->session?->authorSelectorProjectionState();
        foreach ( $anchor->getElementsByTagName('*') as $descendant ) {
            if ( $descendant === $projectedSurface ) {
                continue;
            }
            $path = $descendant->getNodePath() ?? '';
            // Materialized RichText markers own their selector identity even
            // inside retained label topology. Treating them as raw source
            // suppresses subject classes while the richtext role excludes
            // them from the original author selector branch.
            $richTextMarker = $projections?->richTextMarker($path) ?? '';
            if ( '' !== $richTextMarker && str_contains($labelHtml, $richTextMarker) ) {
                continue;
            }
            // Logo labels are materialized through RichText before being saved.
            // Their existing carriers still own author styles (including universal
            // resets); only predicted block/carrier topology needs overriding.
            if ( $onlyProjectedTopology && null !== $projections
                && ! $projections->isInlineLayoutCarrierPath($path)
                && '' === $projections->semanticMarker($path)
                && '' === $projections->imageWrapperMarker($path) ) {
                continue;
            }
            $projections?->markRetainedSourcePath($path);
        }
    }

    /**
     * `$wrapper` is the anchor's single wrapper child whose label `$content`
     * holds; the block's RichText element renders as that wrapper.
     */
    public function accessibleNameCompanion(DOMElement $anchor, string $content, ?DOMElement $wrapper = null): PatternRecognitionResult
    {
        // The link keeps the anchor and the descendants its content renders
        // with their source identity, so author rules keep addressing them
        // as written instead of through a paragraph carrier it never emits.
        $this->session?->authorSelectorProjectionState()->markRetainedSourcePath($anchor->getNodePath() ?? '');
        $this->retainSourceDescendants($anchor);
        $contentMode = 0 < $anchor->getElementsByTagName('button')->length ? 'raw-source' : 'rich-text';
        if ( 'raw-source' === $contentMode ) {
            $wrapper = null;
        }
        $registry = $this->session?->generatedBlockRegistry()
            ?? throw new \LogicException('Generated block registry has not been prepared for this transform.');
        $generator = new AccessibleLinkBlockGenerator();
        $namespace = $registry->namespace();
        $registry->register(AccessibleLinkBlockGenerator::class, $generator->definition($namespace));
        $attrs = array_filter(array(
            'href' => SourceDom::attr($anchor, 'href'),
            'accessibleLabel' => SourceDom::attr($anchor, 'aria-label'),
            'content' => $content,
            'contentMode' => $contentMode,
            'contentTagName' => $wrapper instanceof DOMElement && 'span' !== strtolower($wrapper->tagName) ? AccessibleLinkBlockGenerator::contentTagName($wrapper->tagName) : '',
            'contentAttributes' => $wrapper instanceof DOMElement ? $this->accessibleLinkContentAttributes($wrapper) : array(),
            'className' => SourceDom::attr($anchor, 'class'),
            'style' => SourceDom::attr($anchor, 'style'),
            'id' => SourceDom::attr($anchor, 'id'),
            'linkTarget' => SourceDom::attr($anchor, 'target'),
            'rel' => SourceDom::attr($anchor, 'rel'),
            'sourceAttributes' => $this->accessibleLinkSourceAttributes($anchor),
        ), static fn (mixed $value, string $key): bool => '' !== $value && ( ! is_array($value) || array() !== $value || 'sourceAttributes' === $key ), ARRAY_FILTER_USE_BOTH);
        $markup = $generator->markup($attrs);

        return new PatternRecognitionResult(array(
            'blockName' => $namespace . '/' . AccessibleLinkBlockGenerator::LOCAL_NAME,
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $markup,
            'innerContent' => array( $markup ),
        ));
    }

    /** @return array<string, string> */
    private function accessibleLinkContentAttributes(DOMElement $wrapper): array
    {
        $attributes = array();
        foreach ( $wrapper->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->name);
            if ( AccessibleLinkBlockGenerator::isContentAttributeName($name) ) {
                $attributes[$name] = $attribute->value;
            }
        }
        ksort($attributes);

        return $attributes;
    }

    /** @return array<string, string> */
    private function accessibleLinkSourceAttributes(DOMElement $anchor): array
    {
        $attributes = array();
        foreach ( $anchor->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->name);
            if ( 'role' === $name || str_starts_with($name, 'data-') || ( str_starts_with($name, 'aria-') && 'aria-label' !== $name ) ) {
                $attributes[$name] = $attribute->value;
            }
        }
        ksort($attributes);

        return $attributes;
    }

    private function authoredDisplay(DOMElement $element): string
    {
        if ( ! $this->styleResolver instanceof StyleResolver ) {
            return '';
        }

        $display = '';
        foreach ( $this->styleResolver->styleRuleCandidates($element, 'static') as $rule ) {
            if ( isset($rule['declarations']['display']) && $this->styleResolver->matchesCssSelector($element, $rule['selector']) ) {
                $display = (string) $rule['declarations']['display'];
            }
        }

        $inline = $this->styleResolver->cssDeclarations(SourceDom::attr($element, 'style'));

        return CssValueInspector::comparable((string) ($inline['display'] ?? $display));
    }

    private function safeFileUrl(string $url): string
    {
        $url = trim($url);
        if ( '' === $url || preg_match('/[\x00-\x1f\x7f]|javascript\s*:/i', $url) ) {
            return '';
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, array( 'doc', 'docx', 'odp', 'ods', 'odt', 'pdf', 'ppt', 'pptx', 'rtf', 'txt', 'xls', 'xlsx', 'zip' ), true) ? $url : '';
    }
}
