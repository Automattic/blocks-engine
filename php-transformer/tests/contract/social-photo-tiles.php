<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

// Opaque, multicolour artwork cannot be represented by a core service glyph.
$photo = imagecreatetruecolor(32, 32);
imagefilledrectangle($photo, 0, 0, 15, 31, imagecolorallocate($photo, 180, 40, 20));
imagefilledrectangle($photo, 16, 0, 31, 31, imagecolorallocate($photo, 20, 80, 190));
ob_start();
imagejpeg($photo);
$photoBytes = (string) ob_get_clean();
$glyph = imagecreatetruecolor(24, 24);
imagealphablending($glyph, false);
imagesavealpha($glyph, true);
imagefill($glyph, 0, 0, imagecolorallocatealpha($glyph, 0, 0, 0, 127));
imagefilledrectangle($glyph, 6, 6, 17, 17, imagecolorallocatealpha($glyph, 255, 255, 255, 0));
ob_start();
imagepng($glyph);
$glyphBytes = (string) ob_get_clean();

$profiles = array(
    'facebook' => 'https://www.facebook.com/x',
    'instagram' => 'https://www.instagram.com/x/',
    'linkedin' => 'https://www.linkedin.com/in/x/',
    'youtube' => 'https://www.youtube.com/@x',
);
$tiles = $icons = '';
$assets = array();
foreach ($profiles as $service => $url) {
    $tiles .= '<a href="' . $url . '" target="_blank"><img src="' . $service . '-tile.jpg" alt=""><div class="name">' . ucfirst($service) . '</div></a>';
    $icons .= '<a href="' . $url . '" aria-label="' . ucfirst($service) . '"><img src="' . $service . '-glyph.png" alt="' . ucfirst($service) . '"></a>';
    $assets[] = array('path' => $service . '-tile.jpg', 'kind' => 'image', 'content' => $photoBytes);
    $assets[] = array('path' => $service . '-glyph.png', 'kind' => 'image', 'content' => $glyphBytes);
}
$tileSection = '<main><section class="socials"><h2>Follow Us</h2><div class="tiles icons">' . $tiles . '</div></section></main>';
$footer = '<footer><div class="social">' . $icons . '</div></footer>';
foreach (array('no footer' => $tileSection, 'footer after' => $tileSection . $footer, 'footer before' => $footer . $tileSection) as $case => $body) {
    $result = (new ArtifactCompiler())->compile(array('files' => array_merge(array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => '<!doctype html><html><head><link rel="stylesheet" href="a.css"></head><body>' . $body . '</body></html>'),
        array('path' => 'a.css', 'kind' => 'css', 'content' => '.tiles{display:grid;grid-template-columns:repeat(2,140px);gap:15px}.tiles a{position:relative;display:block;width:140px;height:140px}.tiles img{width:100%;height:100%;object-fit:cover}.tiles .name{position:absolute;bottom:40px;width:100%;text-align:center;color:#fff}.social img{width:24px;height:24px}'),
    ), $assets)))->toArray();
    $markup = $result['serialized_blocks'];
    $dom = new DOMDocument();
    @$dom->loadHTML($markup);
    foreach ($profiles as $service => $url) {
        $kept = false;
        foreach ($dom->getElementsByTagName('img') as $image) {
            if (!str_ends_with($image->getAttribute('src'), $service . '-tile.jpg')) continue;
            $anchor = $image->parentNode;
            while ($anchor instanceof DOMElement && 'a' !== $anchor->tagName) $anchor = $anchor->parentNode;
            $kept = $anchor instanceof DOMElement && $url === $anchor->getAttribute('href');
        }
        $assert($kept, $case . ': ' . $service . ' photo keeps its artwork and profile link.');
    }
    $assert(('no footer' === $case ? 0 : 4) === substr_count($markup, '<!-- wp:social-link '), $case . ': only genuine footer glyphs become core social links.');
    $assert(!str_contains($markup, '<!-- wp:html'), $case . ': photo tiles stay editable without Custom HTML.');
}
