ALTER TABLE membership_payments
    ADD COLUMN payer_reference VARCHAR(100) NULL AFTER reference_number,
    ADD COLUMN proof_image_path VARCHAR(255) NULL AFTER payer_reference,
    ADD COLUMN proof_submitted_at DATETIME NULL AFTER proof_image_path;
