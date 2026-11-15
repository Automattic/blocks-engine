<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\Tests\Support;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Diagnostics\SourceElementDescription;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformerAnalysisCache;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CommerceStructureRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\AuthorStyleAnalysis;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolutionContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/** A real session and style resolver for a source body with no author CSS. */
final class StyleResolverFixture
{
    public readonly HtmlTransformerSession $session;
    public readonly StyleResolver $styleResolver;

    public function __construct(DOMElement $sourceBody)
    {
        $this->session = new HtmlTransformerSession(new Runtime(), new SourceElementDescription(new SourceElementClassifier(), new CommerceStructureRecognizer()));
        $this->session->installAuthorStyleAnalysis(new AuthorStyleAnalysis('', '', array(), $sourceBody));
        $session = $this->session;
        $this->styleResolver = new StyleResolver($session, new StyleResolutionContext(
            static fn (string $selector): array => $session->sourceStyleResolutionState()->parsedSelector($selector),
            static fn (string $className): string => $className,
            static fn (string $url): string => $url
        ), new HtmlTransformerAnalysisCache());
    }
}
