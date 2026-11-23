CREATE TABLE IF NOT EXISTS philsms_message_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_key VARCHAR(60) NOT NULL,
    template_name VARCHAR(120) NOT NULL,
    message_body TEXT NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_philsms_template_key (template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
