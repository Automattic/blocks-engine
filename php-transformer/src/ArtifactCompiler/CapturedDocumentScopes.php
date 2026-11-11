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
}
