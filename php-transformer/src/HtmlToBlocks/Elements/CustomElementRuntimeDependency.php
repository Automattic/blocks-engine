<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

/**
 * Proven runtime dependency for a textless custom element.
 *
 * A hyphenated element has no rendering of its own until a script upgrades it.
 * Preservation is allowed when captured script content — an inline body or a
 * materialized script named by script provenance — defines that exact tag. If
 * a capture omits remote script bytes, an adjacent safe external script tag is
 * retained as the element's declared runtime dependency.
 */
final class CustomElementRuntimeDependency
{
    public const UNPROVEN_CODE = 'html_custom_element_runtime_unproven';

    public const UNPROVEN_REASON = 'missing_functional_content';

    private const MAX_SCRIPT_BYTES = 1048576;

    /**
     * @param array<int, array<string, mixed>> $scriptMetadata
     * @param array<int, array<string, mixed>> $projectionAssets
     * @return array{disposition: string, scripts: array<int, array<string, mixed>>}
     */
    public function classify(DOMElement $element, array $scriptMetadata, array $projectionAssets): array
    {
        $declined = array('disposition' => 'decline', 'scripts' => array());
        $tag = strtolower($element->tagName);
        if ( ! $this->isTextlessCustomElement($element, $tag) || ! $this->isSafe($element) ) {
            return $declined;
        }

        $scripts = $this->provenScripts($element, $tag, $scriptMetadata, $projectionAssets);
        if ( array() !== $scripts ) {
            return array('disposition' => 'preserve', 'scripts' => $scripts);
        }

        if ( ! $this->hasConfigurationAttribute($element) ) {
            return $declined;
        }

        return array('disposition' => 'unproven', 'scripts' => array());
    }

    private function isTextlessCustomElement(DOMElement $element, string $tag): bool
    {
        return 1 === preg_match('/^[a-z][a-z0-9]*-[a-z0-9-]+$/D', $tag)
            && '' === trim($element->textContent ?? '');
    }

    /**
     * @param array<int, array<string, mixed>> $scriptMetadata
     * @param array<int, array<string, mixed>> $projectionAssets
     * @return array<int, array<string, mixed>>
     */
    private function provenScripts(DOMElement $element, string $tag, array $scriptMetadata, array $projectionAssets): array
    {
        $owner = $element->ownerDocument;
        if ( null === $owner ) {
            return array();
        }

        $contentByPath = array();
        foreach ( $projectionAssets as $asset ) {
            if ( ! is_array($asset) || ! is_string($asset['path'] ?? null) || ! is_string($asset['content'] ?? null) ) {
                continue;
            }
            $contentByPath[$asset['path']] = $asset['content'];
        }

        $scripts = array();
        $seen = array();
        foreach ( $owner->getElementsByTagName('script') as $script ) {
            if ( ! $script instanceof DOMElement || ! $this->isExecutableScript($script) ) {
                continue;
            }
            $proven = $this->provenScript($element, $script, $tag, $scriptMetadata, $contentByPath);
            if ( null === $proven ) {
                continue;
            }
            $identity = hash('sha256', json_encode($proven) ?: '');
            if ( isset($seen[$identity]) ) {
                continue;
            }
            $seen[$identity] = true;
            $scripts[] = $proven;
        }

        return $scripts;
    }

    /**
     * @param array<int, array<string, mixed>> $scriptMetadata
     * @param array<string, string>            $contentByPath
     * @return array<string, mixed>|null
     */
    private function provenScript(DOMElement $element, DOMElement $script, string $tag, array $scriptMetadata, array $contentByPath): ?array
    {
        $src = trim($script->getAttribute('src'));
        if ( '' === $src ) {
            $body = (string) $script->textContent;
            if ( ! $this->definesCustomElement($body, $tag) ) {
                return null;
            }

            return $this->scriptRow($script, 'inline', $this->bounded($body), '');
        }

        if ( $this->isAdjacentScriptDependency($element, $script) && $this->isSafeExternalSource($src) ) {
            foreach ( $scriptMetadata as $row ) {
                if ( ! is_array($row) ) {
                    continue;
                }
                $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : array();
                $rowSrc = trim((string) ($attributes['src'] ?? ''));
                $path = is_string($row['path'] ?? null) ? $row['path'] : '';
                $content = $contentByPath[$path] ?? '';
                if ( '' === $rowSrc || ! $this->sameScriptSource($src, $rowSrc) || '' === $content ) {
                    continue;
                }
                if ( ! $this->definesCustomElement($content, $tag) ) {
                    return null;
                }

                return $this->scriptRow($script, 'external', $this->bounded($content), $path);
            }

            return $this->scriptRow($script, 'external', '', '');
        }

        foreach ( $scriptMetadata as $row ) {
            if ( ! is_array($row) ) {
                continue;
            }
            $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : array();
            $rowSrc = trim((string) ($attributes['src'] ?? ''));
            if ( '' === $rowSrc || ! $this->sameScriptSource($src, $rowSrc) ) {
                continue;
            }
            $path = is_string($row['path'] ?? null) ? $row['path'] : '';
            $content = $contentByPath[$path] ?? '';
            if ( '' === $content || ! $this->definesCustomElement($content, $tag) ) {
                continue;
            }

            return $this->scriptRow($script, 'external', $this->bounded($content), $path);
        }

        return null;
    }

    private function sameScriptSource(string $elementSrc, string $metadataSrc): bool
    {
        return $elementSrc === $metadataSrc
            || rawurldecode($elementSrc) === rawurldecode($metadataSrc);
    }

    private function isAdjacentScriptDependency(DOMElement $element, DOMElement $script): bool
    {
        if ( $element->parentNode !== $script->parentNode ) {
            return false;
        }
        for ( $node = $element->previousSibling; null !== $node; $node = $node->previousSibling ) {
            if ( XML_TEXT_NODE === $node->nodeType && '' === trim($node->textContent ?? '') ) {
                continue;
            }
            if ( XML_COMMENT_NODE === $node->nodeType ) {
                continue;
            }

            return $node === $script;
        }

        return false;
    }

    private function isSafeExternalSource(string $src): bool
    {
        return 1 === preg_match('~^(?:https?:)?//[^\x00-\x20]+$~i', $src);
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptRow(DOMElement $script, string $sourceKind, string $body, string $path): array
    {
        $attributes = array();
        foreach ( array( 'async', 'defer', 'id', 'src', 'type' ) as $name ) {
            if ( ! $script->hasAttribute($name) ) {
                continue;
            }
            $value = $script->getAttribute($name);
            if ( preg_match('/javascript\s*:/i', $value) ) {
                continue;
            }
            $attributes[$name] = strlen($value) > 300 ? substr($value, 0, 300) . '...' : $value;
        }

        return array_filter(array(
            'selector'           => SourceDom::elementSelector($script),
            'attributes'         => $attributes,
            'script_role'        => 'runtime',
            'script_source_kind' => $sourceKind,
            'script_body'        => $body,
            'path'               => $path,
        ), static fn (mixed $value): bool => '' !== $value && array() !== $value);
    }

    private function definesCustomElement(string $source, string $tag): bool
    {
        if ( strlen($source) > self::MAX_SCRIPT_BYTES ) {
            $source = substr($source, 0, self::MAX_SCRIPT_BYTES);
        }

        return 1 === preg_match(
            '/(?:^|[^\w])customElements\s*\.\s*define\s*\(\s*(["\'])' . preg_quote($tag, '/') . '\1/i',
            $source
        );
    }

    private function bounded(string $source): string
    {
        return strlen($source) > self::MAX_SCRIPT_BYTES ? substr($source, 0, self::MAX_SCRIPT_BYTES) : $source;
    }

    private function isExecutableScript(DOMElement $script): bool
    {
        $type = strtolower(trim($script->getAttribute('type')));

        return '' === $type || in_array($type, array( 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript' ), true);
    }

    private function isSafe(DOMElement $element): bool
    {
        $nodes = array( $element );
        foreach ( $element->getElementsByTagName('*') as $child ) {
            if ( $child instanceof DOMElement ) {
                $nodes[] = $child;
            }
        }
        foreach ( $nodes as $node ) {
            if ( in_array(strtolower($node->tagName), array( 'script', 'style', 'iframe', 'object', 'embed', 'template' ), true) ) {
                return false;
            }
            foreach ( $node->attributes ?? array() as $attribute ) {
                $name = strtolower($attribute->name);
                $value = (string) $attribute->value;
                if ( 1 === preg_match('/^on[a-z]+$/', $name) || str_starts_with($name, 'data-wp-') || 1 === preg_match('/^\s*(?:javascript|vbscript|data)\s*:/i', $value) ) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasConfigurationAttribute(DOMElement $element): bool
    {
        foreach ( $element->attributes ?? array() as $attribute ) {
            $name = strtolower($attribute->name);
            if ( '' === trim((string) $attribute->value) ) {
                continue;
            }
            if ( in_array($name, array( 'id', 'class', 'style', 'role', 'title', 'hidden', 'slot', 'part', 'is', 'lang', 'dir', 'tabindex' ), true) ) {
                continue;
            }
            if ( str_starts_with($name, 'aria-') || str_starts_with($name, 'data-wp-') || 1 === preg_match('/^on[a-z]+$/', $name) ) {
                continue;
            }

            return true;
        }

        return false;
    }
}
