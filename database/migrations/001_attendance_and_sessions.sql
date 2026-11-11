CREATE TABLE IF NOT EXISTS attendance_logs (
    log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    membership_id INT NOT NULL,
    recorded_by INT NULL,
    scanner_location VARCHAR(100) NULL,
    check_in_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY attendance_user_time_idx (user_id, check_in_time),
    KEY attendance_membership_idx (membership_id),
    KEY attendance_recorder_idx (recorded_by),
    CONSTRAINT attendance_user_fk FOREIGN KEY (user_id) REFERENCES users (user_id),
    CONSTRAINT attendance_membership_fk FOREIGN KEY (membership_id) REFERENCES memberships (membership_id),
    CONSTRAINT attendance_recorder_fk FOREIGN KEY (recorded_by) REFERENCES users (user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trainer_sessions (
    session_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT NOT NULL,
    schedule_id INT NOT NULL,
    trainer_id INT NOT NULL,
    member_id INT NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('booked', 'completed', 'cancelled') NOT NULL DEFAULT 'booked',
    notes VARCHAR(255) NULL,
    booked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id),
    KEY trainer_sessions_trainer_time_idx (trainer_id, session_date, status),
    KEY trainer_sessions_member_time_idx (member_id, session_date, status),
    KEY trainer_sessions_request_idx (request_id),
    KEY trainer_sessions_schedule_idx (schedule_id),
    CONSTRAINT trainer_sessions_request_fk FOREIGN KEY (request_id) REFERENCES trainer_requests (request_id),
    CONSTRAINT trainer_sessions_schedule_fk FOREIGN KEY (schedule_id) REFERENCES trainer_schedules (schedule_id),
    CONSTRAINT trainer_sessions_trainer_fk FOREIGN KEY (trainer_id) REFERENCES trainers (trainer_id),
    CONSTRAINT trainer_sessions_member_fk FOREIGN KEY (member_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
