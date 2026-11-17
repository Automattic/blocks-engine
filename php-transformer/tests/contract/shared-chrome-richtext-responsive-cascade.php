<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade;
use Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticStyleParityRunner;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Issue #6337: the shared marker and page subject projections must have the
// same weight, so the later mobile rule wins just as it does in the source.
$document = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>Title hide</title><style>
body{margin:0;font-family:sans-serif}header{background:#222;color:#fff}
.row{display:table;min-width:100%;table-layout:fixed}.cell{display:table-cell;vertical-align:middle;width:420px}.navc{display:table-cell;text-align:right}
.logo{height:56px;vertical-align:middle}
.t{display:inline-block;vertical-align:middle}
.h.t{display:none}
.cell .t{display:inline-block;line-height:1;max-width:380px;overflow:hidden;padding:16px 12px;text-overflow:ellipsis}
@media only screen and (max-width:479px){.cell>.t{max-width:calc(100vw - 56px)}.h.t{display:none}}
</style></head><body><header><div class="row"><div class="cell"><a class="home" href="/" style="color:#fff"><img src="/media/logo.png" class="logo" alt="" aria-label="Site home"><span class="t h">Cycle Title, PLLC</span></a></div><div class="navc"><a href="/about/" style="color:#fff">About</a></div></div></header><main><h1>Hello</h1></main></body></html>
HTML;
$plan = (new ArtifactCompiler())->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'index.html',
    'files' => array(
        array('path' => 'index.html', 'content' => $document),
        array('path' => 'about/index.html', 'content' => str_replace('<h1>Hello</h1>', '<h1>About</h1>', $document)),
    ),
))->toWordPressSitePlanView()['wordpress_site_plan'];
$headers = array_values(array_filter($plan['template_parts'] ?? array(), static fn (array $part): bool => 'header' === ($part['area'] ?? null) && 'shared_shell' === ($part['placement']['kind'] ?? null)));
$assert(1 === count($headers), 'The two pages still extract a shared header.');
$sharedStyles = array_filter($plan['assets'] ?? array(), static fn (array $asset): bool => 'css' === ($asset['kind'] ?? null) && str_contains($asset['target_path'] ?? '', 'shared-chrome-'));
$assert(array() !== $sharedStyles, 'Shared-chrome stylesheet extraction remains enabled.');
$markup = $headers[0]['canonical_block_markup'];
$dom = new DOMDocument();
@$dom->loadHTML(StaticStyleParityRunner::candidateHtmlFromSerializedBlocks($markup));
$title = (new DOMXPath($dom))->query('//mark[contains(@style, "--blocks-engine-richtext-marker:")]')->item(0);
$assert($title instanceof DOMElement, 'The title is lowered to a rich-text mark.');
foreach (array('index.html', 'about/index.html') as $route) {
    $enqueued = array();
    foreach ($plan['assets'] ?? array() as $asset) {
        if ('css' !== ($asset['kind'] ?? null)) continue;
        foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
            if ($route === ($instance['source_path'] ?? null)) $enqueued[(int) $instance['order']] = $asset['content'];
        }
    }
    ksort($enqueued);
    foreach (array(390 => 'none', 479 => 'none', 480 => 'inline-block', 1000 => 'inline-block') as $width => $display) {
        $css = '';
        // StaticCssCascade uses a fixed desktop viewport. Evaluate media
        // conditions at the requested width before passing it the live rules.
        (new CssStylesheetTransformer())->visitStyleRules(implode("\n", $enqueued), static function (string $selector, string $body, array $conditions) use (&$css, $width): void {
            foreach ($conditions as $condition) {
                if (str_starts_with($condition, '@media ') && !CssCascade::mediaConditionApplies(substr($condition, 7), $width, 16)) return;
            }
            $css .= $selector . '{' . $body . '}';
        });
        $cascade = new StaticCssCascade($dom, $css);
        $resolved = $cascade->resolve($title, array('display'), array());
        $assert($display === ($resolved['display'] ?? null), $route . ' title at ' . $width . 'px: expected ' . $display . ', got ' . json_encode($resolved));
    }
}
echo "Shared chrome richtext responsive cascade passed.\n";
