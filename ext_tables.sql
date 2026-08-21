CREATE TABLE tx_viewmendsitetracker_settings (
    uid int unsigned NOT NULL auto_increment,
    integration_id varchar(255) NOT NULL DEFAULT '',
    site_identifier varchar(255) NOT NULL DEFAULT '',
    site_url varchar(2048) NOT NULL DEFAULT '',
    environment varchar(60) NOT NULL DEFAULT 'production',
    token_ciphertext text,
    enabled smallint unsigned NOT NULL DEFAULT 0,
    record_pages smallint unsigned NOT NULL DEFAULT 1,
    record_content smallint unsigned NOT NULL DEFAULT 1,
    record_publish smallint unsigned NOT NULL DEFAULT 1,
    record_update smallint unsigned NOT NULL DEFAULT 1,
    record_unpublish smallint unsigned NOT NULL DEFAULT 1,
    record_delete smallint unsigned NOT NULL DEFAULT 1,
    record_cache_flush smallint unsigned NOT NULL DEFAULT 0,
    retention_days int unsigned NOT NULL DEFAULT 30,
    last_worker_at bigint unsigned NOT NULL DEFAULT 0,
    last_worker_summary varchar(500) NOT NULL DEFAULT '',
    last_test_at bigint unsigned NOT NULL DEFAULT 0,
    last_test_event_id varchar(160) NOT NULL DEFAULT '',
    last_error varchar(1000) NOT NULL DEFAULT '',
    created_at bigint unsigned NOT NULL DEFAULT 0,
    updated_at bigint unsigned NOT NULL DEFAULT 0,

    PRIMARY KEY (uid)
);

CREATE TABLE tx_viewmendsitetracker_delivery (
    uid int unsigned NOT NULL auto_increment,
    event_id varchar(160) NOT NULL DEFAULT '',
    site_identifier varchar(255) NOT NULL DEFAULT '',
    event_type varchar(40) NOT NULL DEFAULT '',
    title varchar(140) NOT NULL DEFAULT '',
    payload text NOT NULL,
    status varchar(40) NOT NULL DEFAULT 'queued',
    attempt_count int unsigned NOT NULL DEFAULT 0,
    next_attempt_at bigint unsigned NOT NULL DEFAULT 0,
    lease_token varchar(64) NOT NULL DEFAULT '',
    locked_until bigint unsigned NOT NULL DEFAULT 0,
    api_delivery_id varchar(255) NOT NULL DEFAULT '',
    api_event_id varchar(255) NOT NULL DEFAULT '',
    queue_status varchar(120) NOT NULL DEFAULT '',
    response_summary varchar(1000) NOT NULL DEFAULT '',
    last_http_status int unsigned NOT NULL DEFAULT 0,
    last_error varchar(1000) NOT NULL DEFAULT '',
    created_at bigint unsigned NOT NULL DEFAULT 0,
    updated_at bigint unsigned NOT NULL DEFAULT 0,
    delivered_at bigint unsigned NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    UNIQUE KEY event_scope (site_identifier, event_id),
    KEY queue_lookup (status, next_attempt_at),
    KEY site_status (site_identifier, status)
);

CREATE TABLE tx_viewmendsitetracker_attempt (
    uid int unsigned NOT NULL auto_increment,
    delivery_uid int unsigned NOT NULL DEFAULT 0,
    attempt_number int unsigned NOT NULL DEFAULT 0,
    started_at bigint unsigned NOT NULL DEFAULT 0,
    finished_at bigint unsigned NOT NULL DEFAULT 0,
    outcome varchar(40) NOT NULL DEFAULT '',
    http_status int unsigned NOT NULL DEFAULT 0,
    message varchar(1000) NOT NULL DEFAULT '',

    PRIMARY KEY (uid),
    UNIQUE KEY delivery_attempt (delivery_uid, attempt_number)
);

CREATE TABLE tx_viewmendsitetracker_observation (
    uid int unsigned NOT NULL auto_increment,
    site_identifier varchar(255) NOT NULL DEFAULT '',
    record_table varchar(64) NOT NULL DEFAULT '',
    record_uid int unsigned NOT NULL DEFAULT 0,
    language_id int NOT NULL DEFAULT 0,
    site_url varchar(2048) NOT NULL DEFAULT '',
    page_url varchar(2048) NOT NULL DEFAULT '',
    public_state smallint unsigned NOT NULL DEFAULT 0,
    fingerprint varchar(64) NOT NULL DEFAULT '',
    revision int unsigned NOT NULL DEFAULT 0,
    next_transition_at bigint unsigned NOT NULL DEFAULT 0,
    last_seen_at bigint unsigned NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    UNIQUE KEY record_scope (site_identifier, record_table, record_uid, language_id),
    KEY scheduled (next_transition_at)
);
