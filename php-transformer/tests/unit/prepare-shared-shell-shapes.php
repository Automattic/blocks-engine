<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$prepare = static function (int $routes, bool $observations): array {
    $files = array();
    for ($route = 0; $route < $routes; ++$route) {
        $headers = '';
        foreach (array('desktop', 'mobile') as $variant) {
            $items = '';
            for ($item = 0; $item < $routes; ++$item) {
                $current = $route === $item ? ' class="active" aria-current="page"' : '';
                $items .= '<a href="/page-' . $item . '.html"' . $current . '>Page ' . $item . '</a>';
            }
            $evidence = $observations ? ' data-blocks-engine-capture-width-class-' . $route . '="' . (100 + $route) . '"' : '';
            $headers .= '<header id="' . $variant . '"' . $evidence . '><nav>' . $items . '</nav></header>';
        }
        $files[] = array('path' => 'page-' . $route . '.html', 'content' => '<html><head><style>nav{display:flex}</style></head><body>' . $headers . '<main><h1>Route ' . $route . '</h1></main></body></html>');
    }
    $compiler = new ArtifactCompiler();
    $start = hrtime(true);
    $plan = $compiler->prepareShared(array('entrypoint' => 'page-0.html', 'files' => $files));
    $elapsed = (hrtime(true) - $start) / 1e6;
    $count = (new ReflectionProperty($compiler, 'sharedShellCompilationCount'))->getValue($compiler);
    if (2 !== $count) throw new RuntimeException('Expected one shell compile per variant, got ' . $count);
    $shells = $plan['shared_reduction']['inline_shell_compilation']['artifacts'];
    if (2 !== count($shells)) throw new RuntimeException('Expected two shared header variants.');
    foreach ($shells as $shell) {
        if ($routes !== count($shell['source_paths'])) throw new RuntimeException('Every route must share each variant.');
    }
    return array($elapsed, $count);
};
foreach (array(4, 12) as $routes) {
    [$plain] = $prepare($routes, false);
    [$captured, $compiles] = $prepare($routes, true);
    printf("%d routes: plain %.2f ms; route observations %.2f ms; %d shell compiles\n", $routes, $plain, $captured, $compiles);
}
