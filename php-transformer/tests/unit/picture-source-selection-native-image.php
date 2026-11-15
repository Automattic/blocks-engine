<?php
declare(strict_types=1);

// Reuse the small WordPress HTML API stand-in and render-filter installation.
require __DIR__ . '/multi-image-srcset-box.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\ReferenceAnalyzer;

$picture = '<picture class="c1-4 c1-81" style="width:100%">';
$sources = array();
foreach (array('(max-width: 450px)', '(min-width: 451px) and (max-width: 767px)', '(min-width: 768px) and (max-width: 1023px)', '(min-width: 1024px) and (max-width: 1279px)', '(min-width: 1280px) and (max-width: 1535px)', '(min-width: 1536px)') as $i => $media) {
    $srcset = 0 === $i ? '/media/62215-8.webp, /media/62215.jpg 2x, /media/62215.jpg 3x' : '/media/62215-' . ($i + 8) . '.webp, /media/62215-' . ($i + 8) . '.webp 3x';
    $sources[] = array('media' => $media, 'srcset' => $srcset);
    $picture .= '<source media="' . $media . '" srcset="' . $srcset . '">';
}
$picture .= '<img data-ux="Image" src="/media/62215-7.jpg" class="photo" style="aspect-ratio: 3 / 4"></picture>';
foreach (array($picture, '<a class="photo-link" href="/photo">' . $picture . '</a>', '<div class="frame">' . $picture . '</div>', '<figure class="outer"><a href="/photo">' . $picture . '</a></figure>') as $source) {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => '<main>' . $source . '</main>')))->toArray();
    $markup = $result['serialized_blocks'];
    $assert(!str_contains($markup, '/responsive-media'), 'issue picture stays native');
    $assert(1 === preg_match('~<!-- wp:image (\{.*?\}) -->(.*?)<!-- /wp:image -->~s', $markup, $match), 'issue picture compiles to core/image');
    $attrs = json_decode($match[1], true);
    $selection = $attrs['metadata']['blocksEngineImageSelection'];
    $assert($sources === $selection['sources'], 'ordered sources retain media and exact descriptors');
    $assert('c1-4 c1-81' === $selection['picture']['class'], 'picture box retains classes');
    $rendered = ($GLOBALS['imageSelectionRenderer'])($match[2], array('attrs' => $attrs));
    $dom = new DOMDocument();
    @$dom->loadHTML($rendered);
    $box = $dom->getElementsByTagName('picture')->item(0);
    $assert($box instanceof DOMElement && 'c1-4 c1-81' === $box->getAttribute('class'), 'renderer restores picture box');
    $assert(6 === $box->getElementsByTagName('source')->length, 'renderer restores all source children');
    foreach ($sources as $i => $attributes) foreach ($attributes as $name => $value) {
        $assert($value === $box->getElementsByTagName('source')->item($i)->getAttribute($name), 'renderer retains source selection');
    }
    $assert('photo' === $box->getElementsByTagName('img')->item(0)->getAttribute('class'), 'image class stays on image');
    if (str_contains($source, '<a')) $assert('a' === $box->parentNode->nodeName, 'picture stays inside link');
    $replacement = str_replace('/media/62215-7.jpg', '/replacement.jpg', $match[2]);
    $assert($replacement === ($GLOBALS['imageSelectionRenderer'])($replacement, array('attrs' => $attrs)), 'replacement retires old picture family');

    // The existing recursive JSON srcset contract must bind every picture URL.
    $tokens = array();
    foreach (array_unique(array_merge(array('/media/62215-7.jpg'), ...array_map(static fn(array $row): array => Automattic\BlocksEngine\PhpTransformer\AssetAnalysis\SrcsetParser::urls($row['srcset']), $sources))) as $i => $url) {
        $tokens[] = array('source_path' => ltrim($url, '/'), 'token' => 'image-' . $i);
    }
    $bound = (new AssetReferenceCanonicalizer($tokens))->content($markup, 'index.html');
    $assert(!str_contains($bound, '/media/62215'), 'every selection candidate binds to an asset token');
    $references = (new ReferenceAnalyzer())->htmlReferenceCandidates($source, 'index.html');
    $assert(14 === count($references) - (str_contains($source, '<a') ? 1 : 0), 'asset analysis sees all source candidates and fallback');
}
echo "PASS: native picture selection replay and replacement\n";
