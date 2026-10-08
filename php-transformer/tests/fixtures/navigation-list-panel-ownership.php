<?php
declare(strict_types=1);

$html = require __DIR__ . '/navigation-list-host-ownership.php';
$panel = '<div id="phone-menu" hidden data-dla-dialog-panel="phone-menu"><div class="panel-items">' . $items('--phone') . '</div></div>';
$html = str_replace('</style>', '.source-list-panel{padding:24px;list-style:none}</style>', $html);
return str_replace($panel, '<ul id="phone-menu" class="source-list-panel" hidden data-dla-dialog-panel="phone-menu">' . $items('--phone') . '</ul>', $html);
