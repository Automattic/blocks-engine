<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$desktop = '<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope=""><main><p>Body text</p></main></div>';
$mobile = '<div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-document-scope=""><main><p>Mobile body text</p></main></div>';
$css = 'html{font-family:sans-serif}body>div{font-family:Optima,Arial,sans-serif}';
foreach (array('reduction' => $desktop, 'paired' => $desktop . $mobile) as $name => $body) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
        'index.html' => '<html><head><link rel="stylesheet" href="/style.css"></head><body>' . $body . '</body></html>',
        'style.css' => $css,
    )))->toArray();
    $plan = (new WordPressSitePlan())->fromCompilerResult($result);
    $markup = $plan['pages'][0]['canonical_block_markup'];
    $style = implode("\n", array_column($plan['assets'], 'content'));
    preg_match_all('/blocks-engine-root-child-[a-zA-Z0-9_-]+/', $style, $matches);
    $carriers = array_unique($matches[0]);
    $assert(count($carriers) === ('paired' === $name ? 2 : 1), $name . ': projected root-child carriers exist.');
    $assert(str_contains($style, 'Optima,Arial,sans-serif'), $name . ': source font stack remains projected.');
    // The SSI runtime renders saved HTML, not the block comment attributes.
    $html = preg_replace('/<!--.*?-->/s', '', $markup);
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    foreach ($carriers as $class) {
        $assert($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]')->length > 0,
            $name . ': projected carrier missing from rendered HTML: ' . $class);
    }
    preg_match_all('/<!-- wp:custom\/layout-shell (.*?) -->\s*<([a-z0-9-]+)\b([^>]*)>/s', $markup, $shells, PREG_SET_ORDER);
    $assert(count($shells) === ('paired' === $name ? 2 : 1), $name . ': captured roots remain layout shells.');
    foreach ($shells as $shell) {
        $attrs = json_decode($shell[1], true, 512, JSON_THROW_ON_ERROR);
        $outer = $attrs['wrappers'][0];
        $assert($outer['tagName'] === $shell[2], 'Outermost tag agrees with save.');
        preg_match('/\bclass="([^"]*)"/', $shell[3], $savedClass);
        $assert($outer['attributes']['class'] === html_entity_decode($savedClass[1], ENT_QUOTES | ENT_HTML5), 'Wrapper classes agree with the save contract.');
        foreach (preg_split('/\s+/', trim($attrs['className'] ?? '')) as $class) {
            $assert(in_array($class, explode(' ', $outer['attributes']['class']), true), 'Every className token is saved on the outer wrapper.');
        }
    }
}
fwrite(STDOUT, "layout-shell-root-child-classes contract passed\n");
