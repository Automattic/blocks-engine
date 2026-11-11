<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler;

use DOMDocument;
use DOMElement;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;

trait CapturedDocumentScopes
{
    /** @return array<int, DOMElement> */
    private function documentScopes(DOMDocument $document): array
    {
        $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        if (! $body instanceof DOMElement) {
            return array();
        }
        $scopes = array();
        foreach ($body->childNodes as $child) {
            if ($child instanceof DOMElement && SourceDom::isDocumentVariantRoot($child)) {
                $scopes[] = $child;
            }
        }
        return array() === $scopes ? array($body) : $scopes;
    }

    /**
     * Each device document contributes at most one element; more than one in
     * any document, or more documents than the caller can bound, is ambiguous.
     *
     * @param callable(DOMElement): array<int, DOMElement> $match
     * @return array{status:'matched'|'unmatched'|'ambiguous', elements:array<int, DOMElement>}
     */
    private function uniqueScopeMatches(DOMDocument $document, callable $match, int $maxScopes = PHP_INT_MAX): array
    {
        $scopes = $this->documentScopes($document);
        if (count($scopes) > $maxScopes) {
            return array('status' => 'ambiguous', 'elements' => array());
        }
        $elements = array();
        foreach ($scopes as $scope) {
            $matched = $match($scope);
            if (count($matched) > 1) {
                return array('status' => 'ambiguous', 'elements' => array());
            }
            if (1 === count($matched)) {
                $elements[] = $matched[0];
            }
        }
        return array('status' => array() === $elements ? 'unmatched' : 'matched', 'elements' => $elements);
    }
}
