CREATE TABLE IF NOT EXISTS inbound_emails (
    inbound_email_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resend_email_id VARCHAR(64) NOT NULL,
    message_id VARCHAR(998) NULL,
    sender_email VARCHAR(254) NOT NULL,
    sender_name VARCHAR(255) NULL,
    recipient_addresses TEXT NOT NULL,
    subject VARCHAR(998) NOT NULL DEFAULT '',
    body_text MEDIUMTEXT NULL,
    received_at DATETIME NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (inbound_email_id),
    UNIQUE KEY inbound_emails_resend_id_uq (resend_email_id),
    KEY inbound_emails_received_idx (received_at),
    KEY inbound_emails_read_idx (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inbound_email_replies (
    reply_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inbound_email_id BIGINT UNSIGNED NOT NULL,
    admin_user_id INT NOT NULL,
    body_text TEXT NOT NULL,
    resend_email_id VARCHAR(64) NOT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reply_id),
    KEY inbound_email_replies_thread_idx (inbound_email_id, sent_at),
    CONSTRAINT inbound_email_replies_email_fk
        FOREIGN KEY (inbound_email_id) REFERENCES inbound_emails (inbound_email_id),
    CONSTRAINT inbound_email_replies_admin_fk
        FOREIGN KEY (admin_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
