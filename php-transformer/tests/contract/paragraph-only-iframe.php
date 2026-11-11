<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// Classic editors wrap embed codes in a paragraph (#4666). A text-less
// paragraph around one iframe must lower exactly like the bare iframe.
$plan = static function (string $body): array {
    $result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => "<!doctype html><html><body><main>{$body}</main></body></html>")))->toArray();
    return $result['source_reports']['wordpress_site_plan'] ?? array();
};
$iframe = '<iframe style="border: 0" src="https://www.google.com/maps/embed?pb=!1m18" width="600" height="450" allowfullscreen loading="lazy"></iframe>';
$bare = (string) ($plan($iframe)['pages'][0]['canonical_block_markup'] ?? '');
$wrapped = $plan("<p>{$iframe}</p>");
$markup = (string) ($wrapped['pages'][0]['canonical_block_markup'] ?? '');

if ( ! str_contains($bare, '"src":"https://www.google.com/maps/embed?pb=!1m18"') || $markup !== $bare ) {
    fwrite(STDERR, "FAIL: an iframe-only paragraph does not lower like a bare iframe.\nbare: {$bare}\nwrapped: {$markup}\n");
    exit(1);
}
WordPressSitePlan::assertValid($wrapped);
$secondIframe = '<iframe src="https://example.com/widget?item=2" width="120" height="240"></iframe>';
$secondBare = (string) ($plan($secondIframe)['pages'][0]['canonical_block_markup'] ?? '');
$cases = array(
    'trailing' => array("<p>Text.{$iframe}</p>", array('Text.', $bare)),
    'leading' => array("<p>{$iframe}Text.</p>", array($bare, 'Text.')),
    'adjacent' => array("<p>Text.{$iframe}{$secondIframe}</p>", array('Text.', $bare, $secondBare)),
    'formatted runs' => array("<p class=\"closing\" style=\"text-align:right\"><strong>Text.</strong>{$iframe}<em>After.</em></p>", array('<strong>Text.</strong>', $bare, '<em>After.</em>')),
    'whitespace runs' => array("<p> \n{$iframe} \n{$secondIframe} </p>", array($bare, $secondBare)),
);
foreach ( $cases as $label => [$source, $ordered] ) {
    $splitPlan = $plan($source);
    WordPressSitePlan::assertValid($splitPlan);
    $split = (string) ($splitPlan['pages'][0]['canonical_block_markup'] ?? '');
    $offset = 0;
    $valid = ! str_contains($split, 'wp:html');
    foreach ( $ordered as $part ) {
        $position = strpos($split, $part, $offset);
        $valid = $valid && false !== $position;
        $offset = false === $position ? $offset : $position + strlen($part);
    }
    if ( str_contains($source, 'Text.') ) {
        $valid = $valid && 1 === preg_match('/<!-- wp:paragraph\b[^>]*-->\s*<p\b[^>]*>.*?Text\..*?<\/p>\s*<!-- \/wp:paragraph -->/s', $split);
    }
    if ( 'formatted runs' === $label ) {
        $valid = $valid && 2 === substr_count($split, 'class="has-text-align-right closing"');
    }
    if ( ! $valid ) {
        fwrite(STDERR, "FAIL: {$label} iframe paragraph did not split in source order.\n{$split}\n");
        exit(1);
    }
}
foreach ( array('a', 'strong') as $tag ) {
    $nested = (string) ($plan("<p>Text.<{$tag}>{$iframe}</{$tag}></p>")['pages'][0]['canonical_block_markup'] ?? '');
    if ( ! str_contains($nested, 'wp:html') ) {
        fwrite(STDERR, "FAIL: nested iframe unexpectedly split.\n{$nested}\n");
        exit(1);
    }
}
echo "paragraph-only-iframe contract passed\n";
