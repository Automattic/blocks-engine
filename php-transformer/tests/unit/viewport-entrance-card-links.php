<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\Css\CssSelectorMatcher;
use Automattic\BlocksEngine\PhpTransformer\Contract\CaptureEvidence;

$fixture = json_decode(file_get_contents(__DIR__ . '/../fixtures/parity/viewport-entrance-card-links.json'), true, 512, JSON_THROW_ON_ERROR);
$result = (new ArtifactCompiler())->compile($fixture['input']['artifact'])->toArray();
$css = '';
foreach ($result['assets'] as $asset) {
    if (str_ends_with($asset['path'], '.css')) $css .= $asset['content'];
}
$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$dom->loadHTML('<html class="motion-ready"><body>' . $result['serialized_blocks'] . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
$links = $dom->getElementsByTagName('a');
if (3 !== $links->length) throw new RuntimeException('All three card destinations must survive.');
foreach ($links as $link) {
    $state = json_decode($link->getAttribute(CaptureEvidence::VIEWPORT_ENTRANCE), true);
    if (!is_array($state) || !str_contains($link->getAttribute('class'), 'reveal')) {
        throw new RuntimeException('Entrance evidence and swapped tokens must remain on each rendered link.');
    }
    foreach (array('before' => 'opacity:0;transform:translateY(15px);transition:opacity .45s ease,transform .45s ease', 'after' => 'opacity:1;transform:translateY(0)') as $pose => $declarations) {
        $link->setAttribute('class', $state['attributes']['class'][$pose]);
        $matched = false;
        preg_match_all('/([^{}]+)\{([^{}]+)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            if ($rule[2] === $declarations && CssSelectorMatcher::matches($link, CssSelectorMatcher::parse(trim($rule[1])))['matches']) {
                $matched = true;
            }
        }
        if (!$matched) throw new RuntimeException('Author entrance rules must reach the link in its ' . $pose . ' pose.');
    }
}
echo "Viewport entrance card links passed.\n";
