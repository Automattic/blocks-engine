<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$failures = 0;
foreach (array(
    'plain' => array('', ''),
    'body id important' => array('body#p7 ', ' !important'),
    'ancestor' => array('main ', ''),
    'body id' => array('body#p7 ', ''),
    'ancestor important' => array('main ', ' !important'),
) as $name => [$scope, $important]) {
    $html = '<!doctype html><html><head><style>.cta a{display:inline-block;padding:12px 24px;color:#fff;border:1px solid}'
        . $scope . '.brand-bg{background-color:#f8f8f8' . $important . '}</style></head><body id="p7"><main><div class="brand brand-bg"><p>Why list with us?</p></div><div class="cta"><a class="brand-bg" href="/contact/">Contact</a></div></main></body></html>';
    $result = (new ArtifactCompiler())->compile(array('files' => array(array('path' => 'index.html', 'kind' => 'html', 'content' => $html))))->toArray();
    $container = $button = false;
    foreach ($result['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? '')) continue;
        (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body) use ($scope, $important, &$container, &$button): void {
            if (!str_contains($body, '#f8f8f8') || str_contains($body, '!important') !== ('' !== $important)) return;
            foreach (CssStylesheetTransformer::splitSelectorList($selector) ?? array() as $arm) {
                $container = $container || trim($arm) === $scope . '.brand-bg:not(:where(.blocks-engine-role-control))';
                $button = $button || str_contains($arm, '.wp-block-button__link');
            }
        });
    }
    if (!$container || !$button || !preg_match('/<div class="wp-block-group[^" ]*[^\"]*\bbrand\b[^\"]*\bbrand-bg\b/', $result['serialized_blocks'])) {
        ++$failures;
        fwrite(STDERR, "FAIL: {$name} keeps the shared container class and both background branches\n");
    }
}
exit($failures ? 1 : 0);
