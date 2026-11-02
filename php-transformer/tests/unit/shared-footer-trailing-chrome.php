<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
$fixture = require dirname(__DIR__) . '/fixtures/shared-trailing-chrome.php';
$result = (new ArtifactCompiler())->compile($fixture)->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$parts = array_column($plan['template_parts'] ?? $plan['parts'] ?? array(), null, 'slug');
$footer = $parts['footer']['canonical_block_markup'] ?? '';
if (2 !== substr_count($footer, 'mailto:a@example.com') || 2 !== substr_count($footer, '<footer')) throw new RuntimeException('Footer must own one bar per device.');
if (2 !== preg_match_all('/<\/footer>.*?<div class="[^"]*\bbar\b[^"]*".*?<div class="[^"]*bar-spacer/s', $footer)) throw new RuntimeException('Each spacer and bar must follow its footer.');
foreach ($plan['pages'] as $page) {
    $content = $page['canonical_block_markup'];
    if (2 !== substr_count($content, 'Only ')) throw new RuntimeException('Varying trailing content must survive once per scope.');
    if (str_contains($content, 'bar-spacer') || str_contains($content, 'mailto:a@example.com')) throw new RuntimeException('Chrome must leave page content.');
}
file_put_contents(sys_get_temp_dir() . '/shared-footer-trailing-chrome.json', json_encode(array('footer' => $footer, 'content' => $plan['pages'][1]['canonical_block_markup'])));
echo "shared-footer-trailing-chrome: passed\n";
