<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ResponsiveDocumentVariants;
use Automattic\BlocksEngine\PhpTransformer\Css\MediaCondition;

$failures = 0;
$cases = array(
    array('', '(min-width:768px)', '(min-width:768px)'),
    array('(min-width:768px)', 'all', '(min-width:768px)'),
    array('ALL', 'screen', 'screen'),
    array('(min-width:768px)', 'screen', 'screen and (min-width:768px)'),
    array('screen', '(min-width:768px)', 'screen and (min-width:768px)'),
    array('screen', 'screen and (color)', 'screen and (color)'),
    array('screen', 'print', 'not all'),
    array('screen, print', '(color), (monochrome)', 'screen and (color), screen and (monochrome), print and (color), print and (monochrome)'),
    array('all, print', '(color)', '(color), print and (color)'),
    array('only screen', '(color)', 'only screen and (color)'),
    array('(color)', 'only screen and (hover)', 'only screen and (color) and (hover)'),
    array('not screen', '(color)', null),
    array('(color)', 'not screen and (hover)', null),
    array('not (color)', '(hover)', '(not (color)) and (hover)'),
    array('(color) or (hover)', 'screen', 'screen and ((color) or (hover))'),
);
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL: $message\n");
    }
};
$method = new ReflectionMethod(ArtifactCompiler::class, 'compatibilityStylesheetContent');
foreach ($cases as [$left, $right, $expected]) {
    $label = "$left intersect $right";
    $assert(MediaCondition::combine($left, $right) === $expected, $label);
    // Exercise the import projection, including inheritance into a second import.
    $imports = array();
    $files = array(
        array('path' => 'child.css', 'kind' => 'css', 'content' => '@import "leaf.css";p{color:red}'),
        array('path' => 'leaf.css', 'kind' => 'css', 'content' => 'p{color:blue}'),
    );
    $args = array('@import "child.css" ' . $right . ';', 'parent.css', $files, array(), false, &$imports, $left);
    $method->invokeArgs(new ArtifactCompiler(), $args);
    foreach ($imports as $import) {
        $assert($import['media'] === ($expected ?? $right), "import media: $label");
        if (null === $expected) $assert(str_contains($import['content'], '@media ' . $left . '{'), "nested import: $label");
    }

    // A real document_variants artifact must carry stylesheet media into the
    // composed document, rather than emitting `(min-width:768px) and screen`.
    if ('' === $left) continue;
    $artifact = array('files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => '<html><head></head><body><p>Default</p></body></html>'),
        array('path' => 'variant.html', 'kind' => 'html', 'content' => '<html><head><style media="' . $right . '">p{color:red}</style></head><body><p>Variant</p></body></html>'),
    ), 'document_variants' => array(array('source_path' => 'index.html', 'variants' => array(array('id' => 'mobile', 'source_path' => 'variant.html', 'media' => $left)))));
    $composed = (new ResponsiveDocumentVariants())->compose($artifact);
    $html = $composed['files'][0]['content'];
    $assert(str_contains($html, '<style media="' . ($expected ?? $left) . '">'), "variant stylesheet media: $label");
    if (null === $expected) $assert(str_contains($html, '@media ' . $right . '{'), "nested variant: $label");
}
exit($failures ? 1 : 0);
