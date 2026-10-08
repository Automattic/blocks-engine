<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$source = '<style>body{margin:0;font:14px/22px Arial}.frame{margin:0 20px}.social-region{text-align:center}.responsive-region{display:flex;align-items:center;justify-content:center;gap:20px;padding:10px 0}.responsive-region h2{font:24px/32px Arial;margin:0}.rule{border:0;border-top:1px solid #ddd;margin:10px 0}.icon-row{display:inline-flex;gap:8px;margin:0;padding:0;vertical-align:middle}.icon-row a{display:inline-block;width:32px;height:32px;font-size:32px;line-height:32px;padding:0;color:#123456}.icon-row a svg,.icon-row a span{display:inline-block;width:1em;height:1em;vertical-align:top}.icon-row a span:before{content:"●"}.following{height:40px}@media(max-width:700px){.rule{display:none}.responsive-region{flex-direction:column;gap:6px;padding:20px 0}.icon-row a{width:28px;height:28px;font-size:28px;line-height:28px}}</style>'
    . '<section class="social-region" role="region" aria-label="Social channels"><p>Community channels</p><div class="frame"><hr class="rule"><div class="responsive-region"><h2>Connect with the community</h2><div class="icon-row">'
    . '<a href="https://facebook.com/example" aria-label="Facebook"><span></span></a>'
    . '<a href="https://instagram.com/example" aria-label="Instagram"><svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg></a>'
    . '<a href="https://example.test/getSocialPlatform?platform=community" aria-label="Community"><span></span></a>'
    . '</div></div><hr class="rule"></div></section><section class="following"><h3>Following content</h3></section>';
if (in_array('--font-paint', $argv, true)) {
    $source = str_replace('display:inline-flex;gap:8px;', 'display:inline-block;', $source);
    $source = str_replace('</a><a ', '</a>&nbsp; <a ', $source);
    $source = str_replace('</style>', '.icon-row a span{display:inline;width:auto;height:auto;font-size:40px;line-height:34px;font-family:Arial}.icon-row a span:before{display:inline-block}.vector-profile{vertical-align:top}@media(min-width:701px){.icon-row a span{font-size:32px;line-height:28px}}</style>', $source);
    $source = str_replace('<a href="https://example.test/getSocialPlatform?platform=community" aria-label="Community"><span></span>', '<a class="vector-profile" href="https://example.test/getSocialPlatform?platform=community" aria-label="Community"><svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>', $source);
}
if (in_array('--interactive', $argv, true)) {
    $source = str_replace('<a href="https://facebook.com/example"', '<a id="profile-first" class="profile" data-tone="cool" data-layout="profile-box" href="https://facebook.com/example"', $source);
    $source = str_replace('</style>', '.icon-row{padding:12px;background:#eeeeee}.profile{background:red;border-radius:4px}[class="profile"]{border:2px solid red}.profile:hover{background:blue}.profile:focus-visible{background:green}.profile:active{background:orange}.icon-row:hover{background:#dddddd}@layer foundation{.profile:hover{background:yellow}}@media(min-width:701px){.profile:hover{background:#0066cc}}@supports(display:grid){#profile-first[data-tone="cool"]:focus-visible{background:#006600}}.icon-row:hover>#profile-first.profile[data-tone="cool"]:focus-visible{background:purple}</style>', $source);
}
$result = (new HtmlTransformer())->transform($source)->toArray();
$compiled = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array('index.html' => $source)))->toArray();
$bootstrap = '';
foreach ($compiled['source_reports']['wordpress_site_plan']['writes'] as $write) if ('functions.php' === $write['target_path']) $bootstrap = $write['payload']['data'];
if (in_array('--fixture', $argv, true)) {
    echo json_encode(array('source' => $source, 'result' => $result, 'compiled' => $compiled['source_reports']['compiled_site'], 'bootstrap' => $bootstrap), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}
if (0 !== $result['metrics']['fallback_count'] || 'pass' !== $result['source_reports']['wp_block_validity']['status']) {
    throw new RuntimeException('Mixed native social context must remain valid without fallback.');
}
if (!str_contains($result['serialized_blocks'], '"service":"chain"')) {
    throw new RuntimeException('An unknown profile endpoint keeps the existing generic service rather than fabricating a brand.');
}
echo "Social-links source context passed\n";
