<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

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

        $attrs = array_filter(array_merge($this->styleResolver->presentationAttributes($anchor), array(
            'href'               => $href,
            'fileName'           => $this->richText($anchor),
            'textLinkHref'       => $href,
            'showDownloadButton' => $anchor->hasAttribute('download'),
        )), static fn (mixed $value): bool => is_bool($value) ? true : '' !== $value);

        return $this->createBlock->createBlock('core/file', $attrs, array(), $anchor);
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

    public function richText(DOMElement $element): string
    {
        return $this->richTextMaterializer?->content($element) ?? SourceDom::innerHtml($element);
    }

    public function materializeSvgImages(DOMElement $element, string $content): ?string
    {
        return $this->richTextMaterializer?->contentWithMaterializedSvgImages($element, $content);
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

    public function accessibleNameCompanion(DOMElement $anchor, string $content): PatternRecognitionResult
    {
        $registry = $this->session?->generatedBlockRegistry()
            ?? throw new \LogicException('Generated block registry has not been prepared for this transform.');
        $generator = new AccessibleLinkBlockGenerator();
        $namespace = $registry->namespace();
        $registry->register(AccessibleLinkBlockGenerator::class, $generator->definition($namespace));
        $attrs = array_filter(array(
            'href' => SourceDom::attr($anchor, 'href'),
            'accessibleLabel' => SourceDom::attr($anchor, 'aria-label'),
            'content' => $content,
            'contentMode' => 0 < $anchor->getElementsByTagName('button')->length ? 'raw-source' : 'rich-text',
            'className' => SourceDom::attr($anchor, 'class'),
            'style' => SourceDom::attr($anchor, 'style'),
            'id' => SourceDom::attr($anchor, 'id'),
            'linkTarget' => SourceDom::attr($anchor, 'target'),
            'rel' => SourceDom::attr($anchor, 'rel'),
            'sourceAttributes' => $this->accessibleLinkSourceAttributes($anchor),
        ), static fn (mixed $value): bool => '' !== $value);
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

        return strtolower(trim(preg_replace('/\s*!important\s*$/i', '', (string) ($inline['display'] ?? $display)) ?? ''));
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
