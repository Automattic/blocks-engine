<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactNormalizer;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$files = array();
foreach (array('one', 'two', 'print') as $page) {
    $files[] = array('path' => $page . '.html', 'content' => '<html><head><link rel="stylesheet" href="before.css"><style' . ('print' === $page ? ' media="print"' : '') . '>.sample{color:red}</style><link rel="stylesheet" href="after.css"><script>window.shared = true;</script></head><body><main><p class="sample">' . $page . '</p></main></body></html>');
}
$files[] = array('path' => 'before.css', 'content' => '.sample{color:blue}');
$files[] = array('path' => 'after.css', 'content' => '.sample{font-weight:bold}');
$normalizer = new ArtifactNormalizer();
$normalized = $normalizer->normalize(array('files' => $files));
foreach (array('inline-style', 'inline-script') as $source) {
    $assets = array_values(array_filter($normalized['files'], static fn(array $file): bool => $source === $file['source']));
    $assert(1 === count($assets), 'Each shared inline body is one file: ' . $source);
    $assert(3 === count($assets[0]['inline_references']), 'Every page retains an occurrence.');
    $assert(1 === preg_match('#^inline/[a-f0-9]{64}\.(css|js)$#', $assets[0]['path']), 'Paths are content addressed.');
}
$again = $normalizer->normalize(array('files' => $normalized['files']));
$assert($normalized['files'] === $again['files'], 'Normalization is idempotent.');
$compiler = new ArtifactCompiler();
$result = $compiler->compile(array('entrypoint' => 'one.html', 'files' => $files))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
foreach (array('inline-style', 'inline-script') as $source) {
    $shared = array_values(array_filter($plan['assets'], static fn(array $asset): bool => $source === ($asset['source'] ?? '')));
    $assert(1 === count($shared), 'The rendered plan writes one shared asset: ' . $source);
    $assert(3 === count($shared[0]['inline_references']), 'Rendered references retain every page.');
    if ('inline-style' === $source) {
        $instances = array_column($shared[0]['stylesheet_instances'], null, 'source_path');
        foreach (array('one.html', 'two.html', 'print.html') as $page) {
            $assert(1 === $instances[$page]['order'], 'Rendered cascade places shared CSS between linked resources.');
            $assert(('print.html' === $page ? 'print' : '') === $instances[$page]['media'], 'Rendered cascade uses the occurrence media.');
            $document = array_column($plan['pages'], null, 'source_path')[$page];
            foreach (array('screen', 'print') as $medium) {
                $sheets = array();
                foreach ($plan['assets'] as $asset) foreach ($asset['stylesheet_instances'] ?? array() as $instance) {
                    if ($page === $instance['source_path'] && ('' === $instance['media'] || $medium === $instance['media'])) $sheets[$instance['order']] = $asset['content'];
                }
                ksort($sheets);
                $dom = new DOMDocument();
                @$dom->loadHTML('<html><body>' . $document['canonical_block_markup'] . '</body></html>');
                $element = $dom->getElementsByTagName('p')->item(0);
                $style = (new \Automattic\BlocksEngine\PhpTransformer\VisualParity\StaticCssCascade($dom, implode("\n", $sheets)))->resolve($element, array('color'), array('color'));
                $assert(('print.html' === $page && 'screen' === $medium ? 'blue' : 'red') === $style['color'], 'Rendered blocks preserve the shared stylesheet cascade for ' . $page . ' / ' . $medium);
            }
        }
    } else {
        foreach ($plan['pages'] as $page) {
            $script = $page['document_metadata']['scripts'][0];
            $assert(str_contains($script['asset_reference'], $shared[0]['token']), 'Every rendered script refers to the same published asset.');
        }
    }
}
// Shared chrome is lifted per media condition. An inline body loaded under two
// conditions that styles no chrome is still one asset with one target.
$header = '<header class="site-header"><nav><a href="one.html">One</a> <a href="two.html">Two</a> <a href="print.html">Print</a></nav></header>';
$chromeFiles = array(array('path' => 'site.css', 'content' => '.site-header{background:navy}.site-header a{color:white}'));
foreach (array('one', 'two', 'print') as $page) {
    $chromeFiles[] = array('path' => $page . '.html', 'content' => '<html><head><link rel="stylesheet" href="site.css"><style' . ('print' === $page ? ' media="print"' : '') . '>.sample{color:red}</style></head><body>' . $header . '<main><p class="sample">' . $page . '</p></main></body></html>');
}
$chromePlan = $compiler->compile(array('entrypoint' => 'one.html', 'files' => $chromeFiles))->toArray()['source_reports']['wordpress_site_plan'] ?? null;
$assert(is_array($chromePlan), 'A site with shared chrome and a multi-media inline body plans.');
$inline = array_values(array_filter($chromePlan['assets'], static fn(array $asset): bool => 'inline-style' === ($asset['source'] ?? '')));
$assert(1 === count($inline) && 3 === count($inline[0]['inline_references']), 'The multi-media inline body is one asset with every occurrence.');
echo "Content-addressed inline assets passed.\n";
