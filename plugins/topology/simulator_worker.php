<?php
/** CLI-only automatic import worker, run as Cacti's web user by systemd. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require(__DIR__.'/../../include/cli_check.php');
require_once(__DIR__.'/includes/simulator.php');
if(!api_plugin_is_enabled('topology'))exit(0);
tp_ready();
if((int)db_fetch_cell('SELECT GET_LOCK("topology_simulator_worker",0)')!==1)exit(0);
try{tp_sim_process_queue();}finally{db_fetch_cell('SELECT RELEASE_LOCK("topology_simulator_worker")');}
