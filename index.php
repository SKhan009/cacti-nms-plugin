<?php
/** Redirect directory requests to the normal Cacti-authenticated NMS controller. */
header('Location: nms.php', true, 302);
exit;
