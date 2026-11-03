<?php
require dirname(__DIR__, 3) . '/../../include/auth.php';
require_once dirname(__DIR__, 3) . '/shared/services/bootstrap.php';
icct_nms_redirect('dashboard/topology/controllers/topology_configuration.php?tab=discovered');
