<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$html = <<<'HTML'
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Acme</title><style>.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.col{display:flex;flex-direction:column;gap:16px}.pay{width:38px;height:24px}</style></head><body>
<div class="wrap"><div class="wrap"><div class="wrap"><div class="wrap"><div class="wrap"><div class="wrap"><main><h1>Acme</h1><p>Welcome to Acme.</p></main><footer class="site-footer"><div class="wrap"><div class="wrap"><div class="wrap"><div class="wrap"><div class="wrap"><div class="wrap"><div class="container col"><div class="cell row"><a href="mailto:hello@example.com">Email</a></div><div class="cell row"><form action="/subscribe" method="post"><input type="email" name="email" placeholder="Email"> <button type="submit">Sign Up</button></form><img class="pay" src="media/visa.svg" alt="Visa"> <img class="pay" src="media/mastercard.svg" alt="Mastercard"></div></div></div></div></div></div></div></div></footer></div></div></div></div></div></div></body></html>
HTML;
$result = (new ArtifactCompiler())->compileFragment($html, 'index.html')->toArray();
$markup = $result['serialized_blocks'];
$assert(! preg_match('~/(?:gallery|collection)-[0-9a-f]{64}~', $markup), 'Footer layout must not become a generated gallery/collection.');
$assert(2 === preg_match_all('/<!-- wp:image\b/', $markup), 'Payment icons lower to two native image blocks.');
$assert(str_contains($markup, 'Email') && str_contains($markup, 'Sign Up'), 'Link and form content remain represented.');
$assert(str_contains(json_encode($result), 'form_requires_runtime'), 'Form remains visible to its runtime/provider lowering path.');
$assert(! str_contains($markup, 'media/visa.svg') || str_contains($markup, 'wp:image'), 'Image source is not trapped in generated raw HTML.');
echo "footer form with images contract passed\n";
