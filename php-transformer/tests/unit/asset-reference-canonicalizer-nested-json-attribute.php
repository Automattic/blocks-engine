<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\Support\BlockAttributes;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\AssetReferenceCanonicalizer;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

// A block keeps a JSON config, holding markup, as a string attribute, and its
// save() mirrors that config into an HTML data attribute. The editor validates
// the block only when both copies localize the same image identically.
$config = BlockAttributes::encode(array('choices' => array(array('html' => '<button type="button"><img src="/media/view-1.webp" alt=""></button>'))));
$block = '<!-- wp:example/choice-group ' . BlockAttributes::encode(array('config' => $config)) . ' -->'
    . '<div class="choices" data-config="' . htmlspecialchars($config, ENT_QUOTES) . '"><img src="/media/view-1.webp" alt=""></div>'
    . '<!-- /wp:example/choice-group -->';
$canonical = (new AssetReferenceCanonicalizer(array(array('source_path' => 'media/view-1.webp', 'token' => 'view')), ''))->content($block, 'index.html');

preg_match('/^<!-- wp:example\/choice-group (\{.*?\}) -->/', $canonical, $opener);
preg_match('/data-config="([^"]*)"/', $canonical, $mirror);
$attribute = json_decode($opener[1] ?? '', true)['config'] ?? null;
$token = WordPressSitePlan::TOKEN_PREFIX . 'view}}';
if (!is_string($attribute) || !str_contains($attribute, $token) || str_contains($attribute, '/media/view-1.webp')) {
    throw new RuntimeException('The config attribute must localize the image it serializes, got: ' . var_export($attribute, true));
}
if ($attribute !== html_entity_decode($mirror[1] ?? '', ENT_QUOTES)) {
    throw new RuntimeException('The config attribute and its saved HTML mirror must stay identical.');
}
fwrite(STDOUT, "Asset reference canonicalizer nested JSON attribute contract passed\n");
