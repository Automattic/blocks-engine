<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = '<style>body{margin:0}header{display:flex;align-items:center;height:118px;padding:0 25px 0 40px}.toggle{padding:0;border:0;background:transparent;font:16px/41px Arial}@media(max-width:600px){.toggle{padding:2px 15px 0 0}}.toggle .close{display:none}.logo{margin-right:auto}.search{border:none;border-radius:4px;width:52px;height:52px}nav{display:none}</style>'
    . '<header><button class="toggle" aria-label="Action"><span><svg width="32" height="41" viewBox="0 0 32 41"><path d="M0 0h32v41H0z"/></svg></span><span class="close"><svg width="32" height="41" viewBox="0 0 32 41"><path d="M0 0h32v41H0z"/></svg></span></button><a class="logo" href="/">Logo</a><button class="search" aria-label="Search" aria-controls="search-menu" aria-expanded="false"><svg width="24" height="24" viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg></button><nav id="search-menu"><a href="/one">One</a><a href="/two">Two</a><a href="/three">Three</a></nav></header>';
$result = (new HtmlTransformer())->transform($source, array())->toArray();
$css = '';
foreach ($result['assets'] ?? array() as $asset) {
    if ('css' === ($asset['kind'] ?? '') || str_ends_with((string) ($asset['path'] ?? ''), '.css')) $css .= ($asset['content'] ?? '') . "\n";
}
echo json_encode(array('source' => $source, 'markup' => $result['serialized_blocks'], 'css' => $css), JSON_THROW_ON_ERROR);
