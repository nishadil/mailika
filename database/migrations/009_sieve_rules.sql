CREATE TABLE IF NOT EXISTS sieve_rules (
    id BIGSERIAL PRIMARY KEY,
    mailbox_identity VARCHAR(320) NOT NULL,
    name VARCHAR(128) NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    match_field VARCHAR(32) NOT NULL,
    match_operator VARCHAR(32) NOT NULL,
    match_value TEXT NOT NULL,
    action VARCHAR(32) NOT NULL,
    action_target VARCHAR(512),
    stop_processing BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS sieve_rules_mailbox_identity_idx ON sieve_rules (mailbox_identity);

CREATE UNIQUE INDEX IF NOT EXISTS sieve_rules_mailbox_name_lower_unique_idx
    ON sieve_rules (mailbox_identity, lower(name));
