<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\FormControlClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\GeneratedBlockRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredNativeFormBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Closure;
use DOMDocument;
use DOMElement;

/**
 * Lowers static native forms without assigning them a provider contract: GET
 * workflows, and self-contained submissions that hand the browser to another
 * origin with fixed fields.
 */
final class NativeGetFormBlockBuilder
{
    private int $depth = 0;

    /** @param Closure(DOMElement, array<int, array<string, mixed>>&): array<int, array<string, mixed>> $convertChildren */
    /** @param Closure(DOMElement): array<string, mixed> $presentationAttributes */
    /** @param Closure(): GeneratedBlockRegistry $generatedBlocks */
    /** @param Closure(): list<string> $documentHosts Hosts the source document declares as its own. */
    public function __construct(private readonly Closure $convertChildren, private readonly Closure $presentationAttributes, private readonly SourceBlockCreator $createBlock, private readonly Closure $generatedBlocks, private readonly ?Closure $documentHosts = null)
    {
    }

    public function isInside(): bool
    {
        return 0 < $this->depth;
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function build(DOMElement $form, array &$fallbacks): ?array
    {
        $selfContained = $this->isSelfContainedExternalSubmission($form);
        if ( ! $selfContained && ! $this->isSafeNativeGetForm($form) ) {
            return null;
        }
        $children = array();
        ++$this->depth;
        try {
            $children = ($this->convertChildren)($form, $fallbacks);
        } finally {
            --$this->depth;
        }
        if ( array() === $children ) {
            return null;
        }
        $generator = new AuthoredNativeFormBlockGenerator();
        $registry = ($this->generatedBlocks)();
        $registry->register(AuthoredNativeFormBlockGenerator::class, $generator->definition($registry->namespace()));
        $attrs = array_filter(array_merge(($this->presentationAttributes)($form), array(
            'action' => SourceDom::attr($form, 'action'),
            'method' => $selfContained ? strtolower(trim(SourceDom::attr($form, 'method'))) : 'get',
            'methodDeclared' => $form->hasAttribute('method'),
            'id' => SourceDom::attr($form, 'id'),
            'name' => SourceDom::attr($form, 'name'),
            'ariaLabel' => SourceDom::attr($form, 'aria-label'),
            'target' => SourceDom::attr($form, 'target'),
            'autocomplete' => SourceDom::attr($form, 'autocomplete'),
            'noValidate' => $form->hasAttribute('novalidate'),
        )), static fn (mixed $value): bool => is_bool($value) || '' !== $value);
        $markup = $generator->markup($attrs);
        return array( 'blockName' => $registry->blockName(AuthoredNativeFormBlockGenerator::LOCAL_NAME), 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => $markup['opening'] . $markup['closing'], 'innerContent' => array_merge(array($markup['opening']), array_fill(0, count($children), null), array($markup['closing'])) );
    }

    private function isSafeNativeGetForm(DOMElement $form): bool
    {
        if ('' !== trim(SourceDom::attr($form, 'data-blocks-engine-runtime-form-owner'))) {
            return false;
        }
        $method = strtolower(trim(SourceDom::attr($form, 'method')));
        if ( '' !== $method && 'get' !== $method || 0 < $form->getElementsByTagName('script')->length ) {
            return false;
        }
        $action = trim(SourceDom::attr($form, 'action'));
        // The browser's default GET is not enough evidence that a captured
        // form is a search/filter workflow. Keep unspecified forms available
        // for provider materialization, where their submission handler lives.
        if ( '' === $method && '' === $action ) {
            // An unnamed choice-only fragment has local native state but no
            // successful controls or source submit. Preserve that exact form;
            // assigning a provider would invent submission chrome/semantics.
            $controls = FormControlClassifier::controlElements($form);
            if (array() === $controls) return false;
            foreach ($controls as $control) {
                if ('input' !== strtolower($control->tagName)
                    || !in_array(strtolower(SourceDom::attr($control, 'type')), array('checkbox', 'radio'), true)
                    || '' !== trim(SourceDom::attr($control, 'name'))
                    || '' !== trim(SourceDom::attr($control, 'form'))
                ) return false;
            }
        }
        if ( 1 === preg_match('/^\s*(?:javascript|data):/i', $action) ) {
            return false;
        }
        foreach ( FormControlClassifier::controlElements($form) as $control ) {
            if ( ! in_array(strtolower($control->tagName), array( 'input', 'select', 'button' ), true)
                || ! FormControlClassifier::isReadableControl($control)
                || $control->hasAttribute('formaction')
                || $control->hasAttribute('formmethod')
                || ( 'button' === strtolower($control->tagName) && $this->hasAnchorAncestor($control, $form) )
            ) {
                return false;
            }
            foreach ($control->attributes as $attribute) {
                if ( str_starts_with(strtolower($attribute->nodeName), 'on') ) {
                    return false;
                }
            }
        }
        foreach ($form->attributes as $attribute) {
            if ( str_starts_with(strtolower($attribute->nodeName), 'on') ) {
                return false;
            }
        }
        return true;
    }

    /**
     * A form whose only fields are fixed hidden values and whose one submit
     * control sends the browser to an absolute http(s) endpoint on another
     * origin needs no handler on the imported site: the native form already is
     * the whole workflow. Same-origin, relative, scripted, or user-editable
     * forms stay with a form provider, where their submission handler lives.
     */
    private function isSelfContainedExternalSubmission(DOMElement $form): bool
    {
        if ( 'form' !== strtolower($form->tagName)
            || '' !== trim(SourceDom::attr($form, 'data-blocks-engine-runtime-form-owner'))
            || 0 < $form->getElementsByTagName('script')->length
            || $this->hasEventHandlerAttribute($form)
        ) {
            return false;
        }
        $method = strtolower(trim(SourceDom::attr($form, 'method')));
        $enctype = strtolower(trim(SourceDom::attr($form, 'enctype')));
        if ( ! in_array($method, array( 'get', 'post' ), true) || ( '' !== $enctype && 'application/x-www-form-urlencoded' !== $enctype ) ) {
            return false;
        }
        $host = self::absoluteHttpHost(SourceDom::attr($form, 'action'));
        if ( null === $host || in_array($host, null !== $this->documentHosts ? ($this->documentHosts)() : array(), true) ) {
            return false;
        }

        $submits = 0;
        foreach ( $form->getElementsByTagName('*') as $control ) {
            if ( ! $control instanceof DOMElement || ! in_array(strtolower($control->tagName), array( 'input', 'select', 'textarea', 'button' ), true) ) {
                continue;
            }
            if ( $this->hasEventHandlerAttribute($control) ) {
                return false;
            }
            foreach ( $control->attributes as $attribute ) {
                if ( str_starts_with(strtolower($attribute->nodeName), 'form') ) {
                    return false;
                }
            }
            $tagName = strtolower($control->tagName);
            $type = FormControlClassifier::controlType($control);
            if ( 'input' === $tagName && 'hidden' === $type ) {
                continue;
            }
            if ( ( 'input' === $tagName && in_array($type, array( 'submit', 'image' ), true) )
                || ( 'button' === $tagName && 'submit' === $type && ! $this->hasAnchorAncestor($control, $form) )
            ) {
                ++$submits;
                continue;
            }

            return false;
        }

        return 1 === $submits;
    }

    private static function absoluteHttpHost(string $url): ?string
    {
        $url = trim($url);
        if ( 1 !== preg_match('#^https?://#i', $url) ) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ( ! is_string($host) || '' === $host ) {
            return null;
        }
        $host = rtrim(strtolower($host), '.');
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Hosts a source document declares as its own in its head (base URL,
     * canonical URL, Open Graph URL). An action on one of them is a same-site
     * handler, not a self-contained external submission.
     *
     * @return list<string>
     */
    public static function declaredDocumentHosts(string $html): array
    {
        if ( 1 !== preg_match('#<head\b[^>]*>(.*?)</head\s*>#is', $html, $matches) ) {
            return array();
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="utf-8" ?><head>' . $matches[1] . '</head>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ( ! $loaded ) {
            return array();
        }
        $urls = array();
        foreach ( $document->getElementsByTagName('base') as $base ) {
            $urls[] = SourceDom::attr($base, 'href');
        }
        foreach ( $document->getElementsByTagName('link') as $link ) {
            if ( in_array('canonical', preg_split('/\s+/', strtolower(SourceDom::attr($link, 'rel'))) ?: array(), true) ) {
                $urls[] = SourceDom::attr($link, 'href');
            }
        }
        foreach ( $document->getElementsByTagName('meta') as $meta ) {
            if ( 'og:url' === strtolower(trim(SourceDom::attr($meta, 'property'))) ) {
                $urls[] = SourceDom::attr($meta, 'content');
            }
        }

        return array_values(array_unique(array_filter(array_map(self::absoluteHttpHost(...), $urls))));
    }

    private function hasEventHandlerAttribute(DOMElement $element): bool
    {
        foreach ( $element->attributes as $attribute ) {
            if ( str_starts_with(strtolower($attribute->nodeName), 'on') ) {
                return true;
            }
        }

        return false;
    }

    private function hasAnchorAncestor(DOMElement $element, DOMElement $boundary): bool
    {
        for ( $ancestor = $element->parentNode; $ancestor instanceof DOMElement && $ancestor !== $boundary; $ancestor = $ancestor->parentNode ) {
            if ( 'a' === strtolower($ancestor->tagName) ) {
                return true;
            }
        }

        return false;
    }
}
