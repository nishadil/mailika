CREATE TABLE IF NOT EXISTS mail_account_profiles (
    id BIGSERIAL PRIMARY KEY,
    mailbox_identity VARCHAR(320) NOT NULL,
    label VARCHAR(128) NOT NULL,
    email VARCHAR(320) NOT NULL,
    imap_host VARCHAR(253) NOT NULL,
    imap_port INTEGER NOT NULL,
    imap_tls BOOLEAN NOT NULL DEFAULT TRUE,
    smtp_host VARCHAR(253) NOT NULL,
    smtp_port INTEGER NOT NULL,
    smtp_tls VARCHAR(16) NOT NULL DEFAULT 'starttls',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS mail_account_profiles_mailbox_identity_idx
    ON mail_account_profiles (mailbox_identity);

CREATE UNIQUE INDEX IF NOT EXISTS mail_account_profiles_mailbox_email_lower_unique_idx
    ON mail_account_profiles (mailbox_identity, lower(email));
