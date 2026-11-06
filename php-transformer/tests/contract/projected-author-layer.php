<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;

$pages = array(
    'icon' => array(
        'css' => '@layer base{img,svg{display:block}}@layer utilities{.row{display:flex;flex-wrap:wrap;gap:12px;margin-top:32px}.inline-flex{display:inline-flex}.items-center{align-items:center}.gap-1{gap:4px}.btn{border:2px solid #1f2a44;padding:12px 24px;font-weight:700;color:#1f2a44;text-decoration:none}}',
        'body' => '<h1>Icon link</h1><div class="row"><a href="https://example.com/a" class="btn">Inquire</a><a href="https://example.com/b" class="btn inline-flex items-center gap-1">View the original page <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg></a></div>',
        'target' => 'wp-block-button__link',
        'property' => 'display',
        'value' => 'inline-flex',
    ),
    'logo' => array(
        'css' => '@layer theme{:root{--spacing:.25rem}}@layer base{img{display:block;max-width:100%;height:auto}}@layer utilities{.flex{display:flex}.justify-between{justify-content:space-between}.h-8{height:calc(var(--spacing) * 8)}.w-auto{width:auto}.card{width:400px;border:1px solid #888;padding:24px}}',
        'body' => '<div class="card"><div class="flex justify-between"><img class="h-8 w-auto" width="27" height="32" style="color:transparent;aspect-ratio:26.9531 / 32" src="mark.svg" alt=""><span>Available</span></div><h2>Usually</h2></div>',
        'target' => '>img',
        'property' => 'height',
        'value' => 'calc(var(--spacing) * 8)',
    ),
);
foreach ($pages as $name => $page) {
    foreach (array(true, false) as $layered) {
        $css = $page['css'];
        if (!$layered) {
            $css = preg_replace('/@layer \w+\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/', '$1', $css);
        }
        $html = '<!doctype html><html><head><title>' . $name . '</title><style>' . $css . '</style></head><body><main>' . $page['body'] . '</main></body></html>';
        $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $html)))->toArray();
        $found = false;
        $projectedValue = false;
        foreach ($result['assets'] ?? array() as $asset) {
            (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body) use (&$projectedValue, $page): void {
                if (str_contains(str_replace(' ', '', $selector), $page['target'])
                    && str_contains(str_replace(' ', '', $body), $page['property'] . ':' . str_replace(' ', '', $page['value']))) $projectedValue = true;
            });
            if ('after-author' !== ($asset['stylesheet_placement'] ?? '')) continue;
            (new CssStylesheetTransformer())->visitStyleRules((string) ($asset['content'] ?? ''), static function (string $selector, string $body, array $ancestors) use (&$found, $page, $layered): void {
                if (str_contains(str_replace(' ', '', $selector), $page['target'])
                    && str_contains($body, $page['property'] . ':revert-layer')
                    && !preg_grep('/^@layer\b/', $ancestors)) $found = true;
            });
        }
        if (!$projectedValue) throw new RuntimeException($name . ': source winning value must reach the lowered element');
        if ($found !== $layered) throw new RuntimeException($name . ': projected precedence bridge must exist only for layered authors');
    }
}
echo "ok\n";
