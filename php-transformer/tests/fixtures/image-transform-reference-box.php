<?php
declare(strict_types=1);
$image = static function (int $width, int $height): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '"><rect width="100%" height="100%" fill="#112233"/><path d="M0 0H80V60H0Z" fill="#f24b31"/><path d="M80 60H160V140H80Z" fill="#2fe074"/><circle cx="40" cy="120" r="25" fill="#496bff"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
};
$base = 'body{margin:0}.crop{position:relative;overflow:hidden;width:80%;height:0;padding-bottom:45%;margin:20px}.crop img{position:absolute;left:0;top:0;width:100%;height:auto;transform-origin:left top}.badge{width:60px;height:40px;background:#ffdc00}';
return array(
    array('name' => 'landscape class transform', 'css' => $base . '.focal{transform:translate3d(-12%,-23%,0) scale3d(1.3,1.3,1)}', 'html' => '<div class="crop"><img class="focal" src="' . $image(200, 150) . '" alt="Landscape"></div>'),
    array('name' => 'portrait responsive transform', 'css' => $base . '.focal{transform:translate(-8%,-11%) scale(1.15)}@media(min-width:700px){.focal{transform:translate(-17%,-9%) scale(1.4)}}', 'html' => '<div class="crop"><img class="focal" src="' . $image(150, 200) . '" alt="Portrait"></div>'),
    array('name' => 'mixed class ownership', 'css' => $base . '.focal{transform:translate(-12%,-23%) scale(1.3)}', 'html' => '<div class="crop"><img class="focal" src="' . $image(200, 150) . '" alt="Shared class"></div><div class="badge focal"></div>'),
    array('name' => 'individual transforms', 'css' => $base . '.focal{translate:-12% -23%;scale:1.3;transform-origin:0 0}', 'html' => '<div class="crop"><img class="focal" src="' . $image(200, 150) . '" alt="Individual transforms"></div>'),
    array('name' => 'authored figure owns its transform', 'css' => $base . '.authored{margin:20px;width:200px;transform:translate(8%,12%) scale(1.1)}.authored img{width:100%;height:auto}', 'html' => '<figure class="authored"><img src="' . $image(200, 150) . '" alt="Authored figure"></figure>', 'crop' => '.authored'),
    array('name' => 'runtime inline image transform', 'css' => $base, 'html' => '<div class="crop"><img class="focal" style="transform:translate(-12%,-23%) scale(1.3);transform-origin:left top" src="' . $image(200, 150) . '" alt="Runtime styled"></div>'),
    array('name' => 'descendant origin specificity', 'css' => $base . '.focal{transform:translate(-12%,-23%) scale(1.3);transform-origin:center center}', 'html' => '<div class="crop"><img class="focal" src="' . $image(200, 150) . '" alt="Specificity"></div>'),
    array('name' => 'linked image placement', 'css' => $base . '.focal{--pan:-12%;transform:translate(var(--pan),-23%) scale(1.3)}', 'html' => '<div class="crop"><a href="https://example.com/art"><img class="focal" src="' . $image(200, 150) . '" alt="Linked image"></a></div>'),
    array('name' => 'fractional responsive reference box', 'css' => $base . '.crop{width:calc(70vw + .5px);padding-bottom:calc(52vw + .3px)}.focal{transform:translate(-13.75%,-21.125%) scale(1.275)}', 'html' => '<div class="crop"><img class="focal" src="' . $image(200, 150) . '" alt="Fractional crop"></div>'),
);
