CREATE TABLE IF NOT EXISTS philsms_notification_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(50) NOT NULL,
    event_entity_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED DEFAULT NULL,
    family_id INT UNSIGNED DEFAULT NULL,
    recipient_name VARCHAR(255) DEFAULT NULL,
    recipient_phone VARCHAR(30) DEFAULT NULL,
    message TEXT DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_philsms_event (event_type, event_entity_id),
    KEY idx_philsms_status_created (status, created_at),
    KEY idx_philsms_person (person_id),
    KEY idx_philsms_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
