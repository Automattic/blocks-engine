<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
};
$html = <<<'HTML'
<!doctype html><html><head><style>
.col{display:flex;flex-direction:column;justify-content:center;width:672px;padding:48px;box-sizing:border-box}
.btn{display:inline-block;padding:1.25rem 2rem;border:1px solid #333;min-width:160px;border-radius:3rem}
.fit{width:fit-content}.auto{width:auto}
</style></head><body><main><div class="col"><h2>Community</h2><p>Copy text.</p>
<a class="btn fit" href="/fit/">Read more about our community</a>
<a class="btn auto" href="/auto/">Read more</a>
</div></main></body></html>
HTML;
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
$document = new DOMDocument();
@$document->loadHTML((string) $result['serialized_blocks']);
$xpath = new DOMXPath($document);
$styles = '';
array_walk_recursive($result, static function ($value) use (&$styles): void {
    if (is_string($value)) $styles .= $value . "\n";
});
foreach (array('fit', 'auto') as $kind) {
    $link = $xpath->query('//a[@href="/' . $kind . '/"]')->item(0);
    $assert($link instanceof DOMElement, 'The ' . $kind . ' link survives.');
    $assert(1 === preg_match('/blocks-engine-control-[0-9a-f]+-\d+/', $link->parentNode->getAttribute('class'), $marker), 'The link has a generated button wrapper.');
    preg_match_all('/([^{}]*)\{([^{}]*)\}/', $styles, $rules, PREG_SET_ORDER);
    $stretched = array('button' => false, 'link' => false);
    foreach ($rules as $rule) {
        if (! str_contains($rule[1], $marker[0]) || ! str_contains($rule[1], 'wp-block-button') || ! str_contains($rule[2], 'width:100%!important')) continue;
        $stretched[str_contains($rule[1], 'wp-block-button__link') ? 'link' : 'button'] = true;
    }
    $assert(('auto' === $kind) === $stretched['button'], 'Only the auto-width button wrapper stretches.');
    $assert(('auto' === $kind) === $stretched['link'], 'Only the auto-width link stretches.');
    if ('fit' === $kind) {
        $assert(str_contains($link->getAttribute('style'), 'width:fit-content') || (bool) preg_match('/[^{}]*' . preg_quote($marker[0], '/') . '[^{}]*wp-block-button__link[^{}]*\{[^{}]*width:fit-content/', $styles), 'The fit-content width reaches the link.');
    }
}
echo "Column-flex button authored width contract passed.\n";
