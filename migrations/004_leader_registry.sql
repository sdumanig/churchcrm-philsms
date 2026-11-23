CREATE TABLE IF NOT EXISTS philsms_leaders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    person_id INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_philsms_leader_person (person_id),
    KEY idx_philsms_leader_active (is_active, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
