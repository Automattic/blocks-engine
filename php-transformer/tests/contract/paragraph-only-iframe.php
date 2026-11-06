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
echo "paragraph-only-iframe contract passed\n";
