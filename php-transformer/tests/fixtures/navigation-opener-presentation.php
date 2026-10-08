<?php
declare(strict_types=1);

$html = require __DIR__ . '/nested-header-menu.php';
$html = str_replace('role="button" tabindex="0"', 'class="source-opener" role="button" tabindex="0"', $html);
return str_replace('</style>', '.source-opener{display:flex;align-items:center;background:transparent;color:rgb(28,45,62);margin-left:16px;transform:scale(1.2)}@media(min-width:600px){.source-opener{margin-left:22px;transform:scale(1.4)}.source-opener>svg{width:22px;height:22px}}</style>', $html);
