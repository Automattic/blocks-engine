<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

// Issue #5946: the first case is the issue's complete acceptance fixture.
$cases = array(
    'issue fixture' => '<form><p class="guidance">Opens WhatsApp.</p><a class="fallback" hidden target="_blank" rel="noopener">Open WhatsApp manually</a></form>',
    'explicit hidden value' => '<form><p>Guidance</p><a hidden="hidden" target="_blank" rel="noopener">Fallback</a></form>',
    'hidden flex item' => '<form style="display:flex"><p>Guidance</p><a hidden target="_blank" rel="noopener">Fallback</a></form>',
    'visible control' => '<form style="display:flex"><p>Guidance</p><a target="_blank" rel="noopener">Fallback</a></form>',
);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach ($cases as $name => $html) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $markup = (string) ($result['serialized_blocks'] ?? '');
    $css = implode("\n", array_column(array_filter($result['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? '')), 'content'));
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>' . $markup);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $anchor = $document->getElementsByTagName('a')->item(0);
    $assert($anchor instanceof DOMElement, $name . ': rendered link survives');
    $hidden = str_contains($html, ' hidden');
    $assert($anchor->hasAttribute('hidden') === $hidden, $name . ': hidden state survives compilation');
    $assert('_blank' === $anchor->getAttribute('target') && str_contains($anchor->getAttribute('rel'), 'noopener'), $name . ': link attributes survive');
    $assert($anchor->parentNode instanceof DOMElement && str_contains($anchor->parentNode->getAttribute('class'), 'blocks-engine-synthetic-paragraph'), $name . ': link uses the synthetic paragraph carrier');

    $assert(str_contains($css, ':where(p.blocks-engine-synthetic-paragraph.blocks-engine-synthetic-anchor-block-display)>a:not([hidden]){display:block}'), $name . ': generated support CSS excludes hidden links');
    $blockified = str_contains($anchor->parentNode->getAttribute('class'), 'blocks-engine-synthetic-anchor-block-display');
    $hasBlockDisplay = static function () use ($anchor, $css): bool {
        $matchesDisplay = false;
        (new CssStylesheetTransformer())->transformStyleRules($css, static function (string $prelude, string $body) use ($anchor, &$matchesDisplay): string {
            if (preg_match('/(?:^|;)\s*display\s*:\s*block\s*(?:!important)?\s*(?:;|$)/', $body)) {
                foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                    $match = CssSelectorMatcher::matches($anchor, CssSelectorMatcher::parse(trim($selector)));
                    if ($match['supported'] && $match['matches']) {
                        $matchesDisplay = true;
                    }
                }
            }
            return $prelude . '{' . $body . '}';
        });
        return $matchesDisplay;
    };
    $assert($hasBlockDisplay() === ($blockified && ! $hidden), $name . ': generated block display must not override the UA hidden state');
    // Script-driven reveal must work without recompilation or a new stylesheet.
    $anchor->removeAttribute('hidden');
    $assert($hasBlockDisplay() === $blockified, $name . ': removing hidden restores the generated block display when needed');
    $anchor->setAttribute('hidden', '');
    $assert(! $hasBlockDisplay(), $name . ': hiding the link again disables generated block display');
}

echo "synthetic anchor hidden display contract passed.\n";
