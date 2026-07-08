DELETE FROM saved_searches current_search
USING saved_searches newer_search
WHERE current_search.mailbox_identity = newer_search.mailbox_identity
    AND lower(current_search.name) = lower(newer_search.name)
    AND current_search.id < newer_search.id;

CREATE UNIQUE INDEX IF NOT EXISTS saved_searches_mailbox_name_lower_unique_idx
    ON saved_searches (mailbox_identity, lower(name));
