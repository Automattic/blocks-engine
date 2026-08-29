<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

/**
 * Neutralize JS-gated closed-state presentation after interactive behavior is
 * stripped.
 *
 * Source widgets commonly hide a panel with `display:none`, `visibility:hidden`,
 * `opacity:0`, or a `height:0;overflow:hidden` collapse, then reveal it from
 * script or `:hover`. Import removes that behavior. Leaving the closed CSS in
 * place freezes content as unreachable dead controls. Content-bearing nodes
 * lose those declarations so the fallback is visible/static; decorative nodes
 * keep them.
 */
final class ClosedStateNormalizer
{
    /**
     * @param array<string, string> $declarations
     * @return array{declarations: array<string, string>, stripped: list<string>}
     */
    public function strip(array $declarations): array
    {
        $stripped = array();
        if ( isset($declarations['display']) && 'none' === $this->normalizedValue($declarations['display']) ) {
            unset($declarations['display']);
            $stripped[] = 'display:none';
        }
        if ( isset($declarations['visibility']) && 'hidden' === $this->normalizedValue($declarations['visibility']) ) {
            unset($declarations['visibility']);
            $stripped[] = 'visibility:hidden';
        }
        $opacity = isset($declarations['opacity']) ? $this->normalizedValue($declarations['opacity']) : '';
        if ( '' !== $opacity && is_numeric($opacity) && 0.0 === (float) $opacity ) {
            unset($declarations['opacity']);
            $stripped[] = 'opacity:0';
        }

        $height = strtolower(trim((string) ($declarations['height'] ?? '')));
        $maxHeight = strtolower(trim((string) ($declarations['max-height'] ?? '')));
        $overflow = strtolower(trim((string) ($declarations['overflow'] ?? '')));
        if ( $this->isZeroLength($height) ) {
            unset($declarations['height']);
            $stripped[] = 'height:0';
            if ( $this->isHiddenOverflow($overflow) ) {
                unset($declarations['overflow']);
                $stripped[] = 'overflow:hidden';
            }
        }
        if ( $this->isZeroLength($maxHeight) ) {
            unset($declarations['max-height']);
            $stripped[] = 'max-height:0';
            if ( $this->isHiddenOverflow($overflow) ) {
                unset($declarations['overflow']);
                $stripped[] = 'overflow:hidden';
            }
        }

        return array(
            'declarations' => $declarations,
            'stripped' => array_values(array_unique($stripped)),
        );
    }

    /**
     * @param list<string> $stripped
     * @return array<string, string>
     */
    public function repairs(array $stripped): array
    {
        $repairs = array();
        foreach ( $stripped as $declaration ) {
            foreach ( $this->repairFor($declaration) as $property => $value ) {
                $repairs[$property] = $value;
            }
        }

        return $repairs;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return list<string>
     */
    public function repairRules(array $findings): array
    {
        $repairs = array();
        foreach ( $findings as $finding ) {
            $selector = trim((string) ($finding['editor_selector'] ?? ''));
            if ( '' === $selector ) {
                continue;
            }
            foreach ( $this->repairs((array) ($finding['declarations'] ?? array())) as $property => $value ) {
                $repairs[$selector][$property] = $value;
            }
        }

        ksort($repairs, SORT_STRING);
        $rules = array();
        foreach ( $repairs as $selector => $declarations ) {
            ksort($declarations, SORT_STRING);
            $body = '';
            foreach ( $declarations as $property => $value ) {
                $body .= $property . ':' . $value . ';';
            }
            $rules[] = ':root ' . $selector . '{' . rtrim($body, ';') . '}';
        }

        return $rules;
    }

    public function isDecorativeHiddenElement(DOMElement $element, callable $attr): bool
    {
        if ( in_array(strtolower(trim((string) $attr($element, 'role'))), array( 'presentation', 'none' ), true) ) {
            return true;
        }
        if ( in_array(strtolower($element->tagName), array( 'svg', 'canvas' ), true) ) {
            return true;
        }

        if ( '' !== trim($element->textContent ?? '') ) {
            return false;
        }

        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && in_array(strtolower($descendant->tagName), array( 'a', 'button', 'input', 'select', 'textarea', 'img', 'picture', 'video', 'audio', 'iframe', 'nav', 'form' ), true) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, true>
     */
    public function hiddenStateProperties(): array
    {
        return array(
            'display' => true,
            'opacity' => true,
            'visibility' => true,
            'height' => true,
            'max-height' => true,
            'overflow' => true,
        );
    }

    /**
     * @return array<string, string>
     */
    private function repairFor(string $declaration): array
    {
        return match ( $declaration ) {
            'display:none' => array( 'display' => 'revert!important' ),
            'visibility:hidden' => array( 'visibility' => 'visible!important' ),
            'opacity:0' => array( 'opacity' => '1!important', 'transform' => 'none!important' ),
            'height:0' => array( 'height' => 'auto!important' ),
            'max-height:0' => array( 'max-height' => 'none!important' ),
            'overflow:hidden' => array( 'overflow' => 'visible!important' ),
            default => array(),
        };
    }

    private function isZeroLength(string $value): bool
    {
        return 1 === preg_match('/^0(?:px|em|rem|%|vh|vw)?$/', $this->normalizedValue($value));
    }

    private function isHiddenOverflow(string $value): bool
    {
        return in_array($this->normalizedValue($value), array( 'hidden', 'clip' ), true);
    }

    private function normalizedValue(string $value): string
    {
        return strtolower(trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value));
    }
}
