<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\HtmlTransformer;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$viewportCss = '.data-liberation-mobile-document{display:none!important}@media(max-width:768px){.data-liberation-desktop-document{display:none!important}.data-liberation-mobile-document{display:contents!important}}';
$fixture = file_get_contents(dirname(__DIR__) . '/fixtures/viewport-hidden-wix-mobile-document.html');
if (! is_string($fixture) || '' === trim($fixture)) {
    throw new RuntimeException('Missing viewport-hidden Wix mobile document fixture.');
}

$hiddenMobile = (new HtmlTransformer())->transform($fixture, array(
    'source' => 'website/index.html',
    'static_css' => $viewportCss,
));
$hiddenMarkup = $hiddenMobile->serializedBlocks;
$assert(
    str_contains($hiddenMarkup, 'data-liberation-mobile-document')
        && str_contains($hiddenMarkup, 'TINY_MENU')
        && str_contains($hiddenMarkup, '<!-- wp:navigation')
        && 1 === count($hiddenMobile->blocks),
    'A viewport-hidden captured mobile document still materializes its overlay navigation.'
);

fwrite(STDOUT, "viewport-hidden-document-variants contract passed\n");
