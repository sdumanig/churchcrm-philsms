CREATE TABLE IF NOT EXISTS philsms_assignment_activity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id BIGINT UNSIGNED NOT NULL,
    actor_person_id INT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    previous_status VARCHAR(20) DEFAULT NULL,
    new_status VARCHAR(20) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_philsms_activity_assignment (assignment_id, id),
    KEY idx_philsms_activity_actor (actor_person_id, created_at),
    CONSTRAINT fk_philsms_activity_assignment
        FOREIGN KEY (assignment_id) REFERENCES philsms_leader_assignments (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
