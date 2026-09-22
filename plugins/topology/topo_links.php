<?php
/** Retired evidence page: existing bookmarks open the topology canvas. */
require(__DIR__.'/../../include/auth.php');
header('Location: '.$config['url_path'].'plugins/topology/topo_canvas.php');
exit;
