<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\PublicationDate;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlan;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
// No language attribute, route hints, structured dates, or language-specific templates.
$article = static fn(string $date): string => '<html><body><main><article><h1>Field notes</h1><div class="date-indicator"><div>' . $date . '</div></div><p>Observations from a quiet afternoon outdoors.</p></article></main></body></html>';
$result = (new ArtifactCompiler())->compile(array('entrypoint' => 'index.html', 'files' => array(
    'index.html' => '<main>Home</main>',
    'collection/index.html' => '<main><h1>Notes</h1><article><h2><a href="/collection/one/">One</a></h2><p>First observation.</p><a href="/collection/one/">Read more</a></article><article><h2><a href="/collection/two/">Two</a></h2><p>Second observation.</p><a href="/collection/two/">Read more</a></article><article><h2><a href="/collection/three/">Three</a></h2><p>Third observation.</p><a href="/collection/three/">Read more</a></article></main>',
    // A dated sibling establishes the existing listing inference independently of the language under test.
    'collection/reference.html' => $article('Jan 1, 2023'),
    'collection/one/index.html' => $article('Sep 11, 2023'),
    'collection/two/index.html' => $article('11 septembre 2023'),
    // Blogger: a dotted day-first date in the post's date heading.
    'collection/three/index.html' => '<html><body><main><article><h2 class="date-header"><span>11.09.2023</span></h2><h1>Field notes</h1><p>Observations from a quiet afternoon outdoors.</p></article></main></body></html>',
)))->toArray();
$plan = (new WordPressSitePlan())->fromCompilerResult($result);
$pages = array_column($plan['pages'], null, 'source_path');
foreach (array('collection/one/index.html', 'collection/two/index.html', 'collection/three/index.html') as $path) {
    $page = $pages[$path];
    // The site-plan publication timestamp supplies the materializer's post_date.
    $postDate = isset($page['publication_timestamp']) ? (new DateTimeImmutable($page['publication_timestamp']))->format('Y-m-d H:i:s') : null;
    fwrite(STDOUT, $path . ': ' . json_encode(array('post_type' => $page['post_type'], 'post_date' => $postDate)) . "\n");
    $assert('post' === $page['post_type'] && '2023-09-11 00:00:00' === $postDate, "{$path}: every visible date form becomes a dated post");
}
WordPressSitePlan::assertValid($plan);

$cases = array(
    'Sep 11, 2023' => '2023-09-11', '11 septembre 2023' => '2023-09-11',
    '11 sept. 2023' => '2023-09-11', '12 de julio de 2024' => '2024-07-12',
    '11. September 2023' => '2023-09-11', '11.September 2023' => '2023-09-11',
    '12 DE JUL. DE 2024' => '2024-07-12', 'Sept. 11, 2023' => '2023-09-11',
    "11 fe\u{0301}vrier 2023" => '2023-02-11',
    'not a date' => null, '11 unknown 2023' => null, '11 ma 2023' => null,
    '31 février 2023' => null, '11 septembre-ish 2023' => null,
    '11 de unknown de 2023' => null, '11 septembre 2023 extra' => null,
    '08.02.2023' => '2023-02-08', '8.2.2023' => '2023-02-08', '31.02.2023' => null, '08.13.2023' => null,
);
// Each row contains full and abbreviated forms for fr, es, de, it, pt, nl.
$months = array(
    'janvier janv enero ene Januar Jan gennaio gen janeiro jan januari jan',
    'février févr febrero feb Februar Feb febbraio feb fevereiro fev februari feb',
    'mars mars marzo mar März Mär Mrz Maerz marzo mar março mar maart mrt',
    'avril avr abril abr April Apr aprile apr abril abr april apr',
    'mai mai mayo may Mai Mai maggio mag maio mai mei mei',
    'juin juin junio jun Juni Jun giugno giu junho jun juni jun',
    'juillet juil juill julio jul Juli Jul luglio lug julho jul juli jul',
    'août août agosto ago August Aug agosto ago agosto ago augustus aug',
    'septembre sept septiembre sept September Sep settembre set setembro set september sep',
    'octobre oct octubre oct Oktober Okt ottobre ott outubro out oktober okt',
    'novembre nov noviembre nov November Nov novembre nov novembro nov november nov',
    'décembre déc diciembre dic Dezember Dez dicembre dic dezembro dez december dec',
);
foreach ($months as $index => $forms) {
    foreach (explode(' ', $forms) as $form) {
        foreach (array($form, mb_strtoupper($form) . '.', strtr($form, array('é' => 'e', 'ä' => 'a', 'ç' => 'c', 'û' => 'u'))) as $variant) {
            $cases['11 ' . $variant . ' 2023'] = sprintf('2023-%02d-11', $index + 1);
        }
    }
}
foreach ($cases as $input => $expected) {
    $actual = PublicationDate::fromVisibleText($input);
    $assert((null === $expected ? null : $expected . 'T00:00:00Z') === $actual, "Visible month: {$input}");
}
// A recognized date reprints in its source form; English forms keep their existing formats.
$formats = array(
    'Sep 11, 2023' => 'M j, Y', 'September 11, 2023' => 'F j, Y', '11 Sep 2023' => 'j M Y', '11 September 2023' => 'j F Y',
    'Monday, March 4, 2024' => 'l, F j, Y', '9/11/2023' => 'n/j/Y', '13-9-2023' => 'j-n-Y',
    '09/11/2023' => 'm/d/Y', '11.09.2023' => 'd.m.Y', '8.2.2023' => 'j.n.Y', '2023-09-11' => 'Y-m-d',
    '11 septembre 2023' => 'j F Y', '11 sept. 2023' => 'j M Y', '11. September 2023' => 'j. F Y',
    '12 de julio de 2024' => 'j \\d\\e F \\d\\e Y', 'Sept. 11, 2023' => 'M j, Y', 'not a date' => null,
);
foreach ($formats as $input => $expected) {
    $assert($expected === PublicationDate::visibleFormat($input), "Visible format: {$input} => " . var_export(PublicationDate::visibleFormat($input), true));
}
fwrite(STDOUT, "wordpress site plan visible month tests: passed\n");
