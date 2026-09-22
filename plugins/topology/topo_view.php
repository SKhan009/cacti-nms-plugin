<?php
/** Compatibility redirect: device inventory is managed by native Cacti. */
require(__DIR__.'/../../include/auth.php');
header('Location: '.$config['url_path'].'host.php');
exit;
