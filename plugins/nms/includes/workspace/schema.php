<?php
/** Additive workspace storage, called only by the existing plugin upgrade lifecycle. */
function nms_workspace_schema()
{
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_consolidation_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,keep_id MEDIUMINT UNSIGNED NOT NULL,
        other_id MEDIUMINT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,poller_id INT UNSIGNED NOT NULL,
        revision CHAR(64) NOT NULL,plan_json MEDIUMTEXT NOT NULL,status VARCHAR(24) NOT NULL,
        progress_json MEDIUMTEXT NOT NULL,result_json MEDIUMTEXT NOT NULL,error VARCHAR(255) NOT NULL DEFAULT '',
        requested_at DATETIME NOT NULL,started_at DATETIME NULL,finished_at DATETIME NULL,
        KEY collector_queue(poller_id,status,id),KEY pair_history(keep_id,other_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_consolidation_reviews (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,keep_id MEDIUMINT UNSIGNED NOT NULL,
        other_id MEDIUMINT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,revision CHAR(64) NOT NULL,
        plan_json MEDIUMTEXT NOT NULL,note VARCHAR(1024) NOT NULL,created_at DATETIME NOT NULL,
        KEY pair_history(keep_id,other_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_service_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,host_id MEDIUMINT UNSIGNED NOT NULL,
        poller_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,config_hash CHAR(64) NOT NULL,
        spec_json TEXT NOT NULL,status VARCHAR(16) NOT NULL,result_json MEDIUMTEXT NOT NULL,
        requested_at DATETIME NOT NULL,started_at DATETIME NULL,finished_at DATETIME NULL,
        KEY collector_queue(poller_id,status,id),KEY host_history(host_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_management_changes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,host_id MEDIUMINT UNSIGNED NOT NULL,
        proposal_id CHAR(64) NOT NULL,user_id INT UNSIGNED NOT NULL,poller_id INT UNSIGNED NOT NULL,
        old_address VARCHAR(255) NOT NULL,target VARCHAR(45) NOT NULL,config_hash CHAR(64) NOT NULL,
        status VARCHAR(24) NOT NULL,result_json MEDIUMTEXT NOT NULL,error VARCHAR(255) NOT NULL DEFAULT '',
        requested_at DATETIME NOT NULL,started_at DATETIME NULL,verified_at DATETIME NULL,finished_at DATETIME NULL,
        KEY collector_queue(poller_id,status,id),KEY host_history(host_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_onboarding_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,check_id BIGINT UNSIGNED NOT NULL,
        reporter_id MEDIUMINT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,poller_id INT UNSIGNED NOT NULL,
        site_id INT UNSIGNED NOT NULL,target VARCHAR(45) NOT NULL,template_id MEDIUMINT UNSIGNED NOT NULL,
        description VARCHAR(150) NOT NULL,review_hash CHAR(64) NOT NULL,status VARCHAR(16) NOT NULL,
        host_id MEDIUMINT UNSIGNED NULL,error VARCHAR(255) NOT NULL DEFAULT '',
        requested_at DATETIME NOT NULL,started_at DATETIME NULL,finished_at DATETIME NULL,
        KEY collector_queue(poller_id,status,id),KEY reporter_history(reporter_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_candidate_checks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,reporter_id MEDIUMINT UNSIGNED NOT NULL,
        candidate_id CHAR(64) NOT NULL,poller_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,
        target VARCHAR(45) NOT NULL,config_hash CHAR(64) NOT NULL,status VARCHAR(16) NOT NULL,
        cancel_requested TINYINT NOT NULL DEFAULT 0,result_json MEDIUMTEXT NOT NULL,error VARCHAR(255) NOT NULL DEFAULT '',
        requested_at DATETIME NOT NULL,started_at DATETIME NULL,finished_at DATETIME NULL,
        KEY collector_queue(poller_id,status,id),KEY reporter_history(reporter_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_identity_reviews (
        host_a MEDIUMINT UNSIGNED NOT NULL,host_b MEDIUMINT UNSIGNED NOT NULL,
        decision VARCHAR(16) NOT NULL,note VARCHAR(512) NOT NULL DEFAULT '',
        evidence_hash CHAR(64) NOT NULL,revision INT UNSIGNED NOT NULL DEFAULT 1,
        user_id INT UNSIGNED NOT NULL,updated_at DATETIME NOT NULL,
        PRIMARY KEY(host_a,host_b)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_workspace_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED NOT NULL,
        host_id MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,network_id INT UNSIGNED NOT NULL DEFAULT 0,
        action VARCHAR(48) NOT NULL,detail_json TEXT NOT NULL,created_at DATETIME NOT NULL,
        KEY host_history(host_id,id),KEY network_history(network_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_scan_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,network_id INT UNSIGNED NOT NULL,
        poller_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,kind VARCHAR(16) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'queued',config_hash CHAR(64) NOT NULL,
        options_json MEDIUMTEXT NOT NULL,target_count INT UNSIGNED NOT NULL DEFAULT 0,
        task_count INT UNSIGNED NOT NULL DEFAULT 0,progress_cursor INT UNSIGNED NOT NULL DEFAULT 0,
        requested_at DATETIME NOT NULL,started_at DATETIME NULL,heartbeat_at DATETIME NULL,finished_at DATETIME NULL,
        cancel_requested TINYINT NOT NULL DEFAULT 0,error VARCHAR(255) NOT NULL DEFAULT '',summary_json TEXT NOT NULL,
        KEY collector_queue(poller_id,status,id),KEY network_runs(network_id,id),KEY user_runs(user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    nms_category_execute("CREATE TABLE IF NOT EXISTS plugin_nms_scan_results (
        run_id BIGINT UNSIGNED NOT NULL,ordinal INT UNSIGNED NOT NULL,address VARCHAR(45) NOT NULL,
        method VARCHAR(16) NOT NULL,port INT UNSIGNED NULL,reachable TINYINT NOT NULL,
        detail VARCHAR(1024) NOT NULL,checked_at DATETIME NOT NULL,
        PRIMARY KEY(run_id,ordinal),KEY run_address(run_id,address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
