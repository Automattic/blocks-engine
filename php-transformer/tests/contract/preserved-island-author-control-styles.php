<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;
use Automattic\BlocksEngine\PhpTransformer\WordPress\SourceClassIdentity;

// Issue #5944, verbatim; the second case shares its selector with a lowered control.
$fixture = <<<'HTML'
<style>body.home .quick-submit{display:grid;place-items:center;width:58px;height:58px;border:0;border-radius:50%;background:#416400;color:#fff}</style>
<body class="home"><form class="quick" id="q"><input name="n" required><button class="quick-submit" type="submit" aria-label="Send"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 2h20v20H2z"/></svg></button></form>
<script>document.getElementById('q').addEventListener('submit',e=>{e.preventDefault();})</script></body>
HTML;
foreach (array('issue' => $fixture, 'mixed' => str_replace('</body>', '<a class="quick-submit button" aria-label="Next" href="/next">Next</a></body>', $fixture)) as $name => $html) {
    $assert = static function (bool $condition, string $message) use ($name): void {
        if (!$condition) throw new RuntimeException($name . ': ' . $message);
    };
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    $markup = $result['serialized_blocks'];
    $assert(str_contains($markup, '<form') && str_contains($markup, '<button type="submit" aria-label="Send" class="quick-submit"') && str_contains($markup, '<svg'), 'The form, raw button and icon remain source markup.');
    if ('mixed' === $name) $assert(str_contains($markup, '<!-- wp:button'), 'The sibling still lowers to a native button.');
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<html><body>' . $markup . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    foreach (array('html' => 'root_attributes', 'body' => 'body_attributes') as $tag => $key) {
        foreach (SourceClassIdentity::projectRoot($plan['pages'][0]['document_metadata'][$key] ?? array()) as $attribute => $value) $dom->getElementsByTagName($tag)->item(0)->setAttribute($attribute, $value);
    }
    $button = (new DOMXPath($dom))->query('//form//button')->item(0);
    $assert($button instanceof DOMElement, 'A raw submit button renders inside the form.');
    $matched = '';
    foreach ($plan['assets'] as $asset) {
        if ('css' !== $asset['kind'] || 'editor' === ($asset['stylesheet_target'] ?? 'both')) continue;
        (new CssStylesheetTransformer())->transformStyleRules($asset['content'], static function (string $prelude, string $body) use ($button, &$matched): string {
            foreach (CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector) {
                $match = CssSelectorMatcher::matches($button, CssSelectorMatcher::parse(trim($selector)));
                if ($match['supported'] && $match['matches']) { $matched .= ';' . $body; break; }
            }
            return $prelude . '{' . $body . '}';
        });
    }
    foreach (array('display' => 'grid', 'width' => '58px', 'height' => '58px', 'border-radius' => '50%', 'background' => '#416400') as $property => $value) {
        $assert(1 === preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*' . '(' . preg_quote($value, '/') . '|var\([^;{}]*,\s*' . preg_quote($value, '/') . '\s*\))\s*(?:;|$)/', $matched), 'Generated frontend CSS reaching the retained button must declare ' . $property . ':' . $value . '. Got: ' . $matched);
    }
}
echo "Preserved island author control styles contract passed.\n";
