CREATE TABLE IF NOT EXISTS contacts (
    id BIGSERIAL PRIMARY KEY,
    mailbox_identity VARCHAR(320) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(320) NOT NULL,
    notes TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS contacts_mailbox_identity_idx ON contacts (mailbox_identity);
CREATE INDEX IF NOT EXISTS contacts_email_idx ON contacts (email);

CREATE TABLE IF NOT EXISTS preferences (
    mailbox_identity VARCHAR(320) PRIMARY KEY,
    locale VARCHAR(32) NOT NULL DEFAULT 'en',
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    remote_images BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS identities (
    id BIGSERIAL PRIMARY KEY,
    mailbox_identity VARCHAR(320) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    email VARCHAR(320) NOT NULL,
    reply_to VARCHAR(320),
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS identities_mailbox_identity_idx ON identities (mailbox_identity);

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGSERIAL PRIMARY KEY,
    event_type VARCHAR(128) NOT NULL,
    mailbox_identity VARCHAR(320),
    ip_address VARCHAR(64),
    user_agent VARCHAR(512),
    metadata TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS audit_events_type_idx ON audit_events (event_type);
CREATE INDEX IF NOT EXISTS audit_events_created_at_idx ON audit_events (created_at);
