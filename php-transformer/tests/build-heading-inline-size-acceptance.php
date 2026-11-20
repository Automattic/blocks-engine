<?php
putenv('RESTYLE_DISPOSABLE=1');
putenv('RESTYLE_PHASE=install');
putenv('RESTYLE_FIXTURE=' . __DIR__ . '/fixtures/design-system/heading-inline-size.php');
putenv('RESTYLE_ROUTES=' . sys_get_temp_dir() . '/heading-inline-size-routes.json');
ob_start();
require __DIR__ . '/integration/design-system-restyle-install.php';
ob_end_clean();
echo wp_json_encode(array('post_id' => (int) get_option('page_on_front')));
