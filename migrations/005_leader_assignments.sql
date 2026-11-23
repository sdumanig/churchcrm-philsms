CREATE TABLE IF NOT EXISTS philsms_leader_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    approval_queue_id BIGINT UNSIGNED NOT NULL,
    leader_id INT UNSIGNED NOT NULL,
    contact_person_id INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    assigned_by INT UNSIGNED NOT NULL,
    due_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_philsms_assignment_approval (approval_queue_id),
    KEY idx_philsms_assignment_leader_status (leader_id, status),
    KEY idx_philsms_assignment_contact (contact_person_id),
    KEY idx_philsms_assignment_due (status, due_at),
    CONSTRAINT chk_philsms_assignment_status
        CHECK (status IN ('pending', 'in_progress', 'completed', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
