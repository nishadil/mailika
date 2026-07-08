WITH duplicate_sets AS (
    SELECT
        mailbox_identity,
        lower(email) AS normalized_email,
        max(id) AS kept_id,
        bool_or(is_default) AS has_default
    FROM identities
    GROUP BY mailbox_identity, lower(email)
    HAVING count(*) > 1
)
UPDATE identities
SET
    is_default = duplicate_sets.has_default,
    updated_at = CURRENT_TIMESTAMP
FROM duplicate_sets
WHERE identities.id = duplicate_sets.kept_id
    AND duplicate_sets.has_default = TRUE;

WITH ranked_identities AS (
    SELECT
        id,
        row_number() OVER (
            PARTITION BY mailbox_identity, lower(email)
            ORDER BY id DESC
        ) AS duplicate_rank
    FROM identities
)
DELETE FROM identities
USING ranked_identities
WHERE identities.id = ranked_identities.id
    AND ranked_identities.duplicate_rank > 1;

WITH ranked_defaults AS (
    SELECT
        id,
        row_number() OVER (
            PARTITION BY mailbox_identity
            ORDER BY updated_at DESC, id DESC
        ) AS default_rank
    FROM identities
    WHERE is_default = TRUE
)
UPDATE identities
SET
    is_default = FALSE,
    updated_at = CURRENT_TIMESTAMP
FROM ranked_defaults
WHERE identities.id = ranked_defaults.id
    AND ranked_defaults.default_rank > 1;

WITH missing_defaults AS (
    SELECT DISTINCT mailbox_identity
    FROM identities current_identity
    WHERE NOT EXISTS (
        SELECT 1
        FROM identities default_identity
        WHERE default_identity.mailbox_identity = current_identity.mailbox_identity
            AND default_identity.is_default = TRUE
    )
),
first_identities AS (
    SELECT DISTINCT ON (identities.mailbox_identity)
        identities.id
    FROM identities
    INNER JOIN missing_defaults
        ON missing_defaults.mailbox_identity = identities.mailbox_identity
    ORDER BY identities.mailbox_identity, identities.display_name, identities.email, identities.id
)
UPDATE identities
SET
    is_default = TRUE,
    updated_at = CURRENT_TIMESTAMP
FROM first_identities
WHERE identities.id = first_identities.id;

CREATE UNIQUE INDEX IF NOT EXISTS identities_mailbox_email_lower_unique_idx
    ON identities (mailbox_identity, lower(email));

CREATE UNIQUE INDEX IF NOT EXISTS identities_one_default_per_mailbox_idx
    ON identities (mailbox_identity)
    WHERE is_default = TRUE;
