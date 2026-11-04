<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\CapturedComponentStates;

// Exercise the same owning-subtree identity used to share captured evidence.
$fingerprint = new ReflectionMethod(CapturedComponentStates::class, 'fingerprint');
$identity = static function (string $html) use ($fingerprint): mixed {
    $document = new DOMDocument();
    $document->loadHTML('<html><body>' . $html . '</body></html>');
    return $fingerprint->invoke(null, $document->getElementsByTagName('button')->item(0));
};
$cases = array(
    'custom declaration order' => array('<div style="--a: 0px;--b: -0px"><button>Open</button></div>', '<div style="--b: -0px;--a: 0px"><button>Open</button></div>', true),
    'ordinary declaration order and empties' => array('<div style="color: red; ;opacity: 1;"><button>Open</button></div>', '<div style="opacity:1;color:red"><button>Open</button></div>', true),
    'empty descendant style' => array('<div><button><span style="">Open</span></button></div>', '<div><button><span>Open</span></button></div>', true),
    'different value' => array('<div style="--a: 0px"><button>Open</button></div>', '<div style="--a: 1px"><button>Open</button></div>', false),
    'different attribute' => array('<div class="a"><button>Open</button></div>', '<div class="b"><button>Open</button></div>', false),
    'different child' => array('<div><button>Open</button></div>', '<div><button>Search</button></div>', false),
    'custom property case' => array('<div style="--A: 0"><button>Open</button></div>', '<div style="--a: 0"><button>Open</button></div>', false),
    'quoted delimiters' => array('<div style="content: &quot;a;b:c&quot;;color:red"><button>Open</button></div>', '<div style="color:red;content: &quot;a;b:c&quot;"><button>Open</button></div>', true),
);
$failures = 0;
foreach ($cases as $label => [$left, $right, $equal]) {
    if (($identity($left) === $identity($right)) !== $equal) {
        ++$failures;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    }
}
exit($failures ? 1 : 0);
