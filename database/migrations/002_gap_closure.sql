CREATE TABLE IF NOT EXISTS contact_groups (
    id BIGSERIAL PRIMARY KEY,
    mailbox_identity VARCHAR(320) NOT NULL,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS contact_groups_mailbox_identity_idx ON contact_groups (mailbox_identity);

CREATE TABLE IF NOT EXISTS contact_group_memberships (
    contact_id BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    group_id BIGINT NOT NULL REFERENCES contact_groups(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (contact_id, group_id)
);

CREATE TABLE IF NOT EXISTS saved_searches (
    id BIGSERIAL PRIMARY KEY,
    mailbox_identity VARCHAR(320) NOT NULL,
    name VARCHAR(255) NOT NULL,
    folder VARCHAR(512) NOT NULL DEFAULT 'INBOX',
    query TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS saved_searches_mailbox_identity_idx ON saved_searches (mailbox_identity);

CREATE TABLE IF NOT EXISTS message_metadata_cache (
    mailbox_identity VARCHAR(320) NOT NULL,
    folder VARCHAR(512) NOT NULL,
    message_uid VARCHAR(128) NOT NULL,
    subject TEXT,
    sender TEXT,
    sent_at TIMESTAMP,
    flags TEXT,
    has_attachments BOOLEAN NOT NULL DEFAULT FALSE,
    thread_id VARCHAR(255),
    cached_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (mailbox_identity, folder, message_uid)
);

CREATE INDEX IF NOT EXISTS message_metadata_cache_folder_idx
    ON message_metadata_cache (mailbox_identity, folder, cached_at);

CREATE TABLE IF NOT EXISTS drafts (
    id BIGSERIAL PRIMARY KEY,
    mailbox_identity VARCHAR(320) NOT NULL,
    identity_id BIGINT REFERENCES identities(id) ON DELETE SET NULL,
    recipients_to TEXT NOT NULL,
    recipients_cc TEXT,
    recipients_bcc TEXT,
    subject TEXT,
    body_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS drafts_mailbox_identity_idx ON drafts (mailbox_identity);
