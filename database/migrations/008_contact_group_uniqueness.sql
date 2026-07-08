WITH ranked_groups AS (
    SELECT
        id,
        first_value(id) OVER (
            PARTITION BY mailbox_identity, lower(name)
            ORDER BY id DESC
        ) AS kept_id,
        row_number() OVER (
            PARTITION BY mailbox_identity, lower(name)
            ORDER BY id DESC
        ) AS duplicate_rank
    FROM contact_groups
)
INSERT INTO contact_group_memberships (contact_id, group_id)
SELECT contact_group_memberships.contact_id, ranked_groups.kept_id
FROM ranked_groups
INNER JOIN contact_group_memberships
    ON contact_group_memberships.group_id = ranked_groups.id
WHERE ranked_groups.duplicate_rank > 1
ON CONFLICT DO NOTHING;

WITH ranked_groups AS (
    SELECT
        id,
        row_number() OVER (
            PARTITION BY mailbox_identity, lower(name)
            ORDER BY id DESC
        ) AS duplicate_rank
    FROM contact_groups
)
DELETE FROM contact_groups
USING ranked_groups
WHERE contact_groups.id = ranked_groups.id
    AND ranked_groups.duplicate_rank > 1;

CREATE UNIQUE INDEX IF NOT EXISTS contact_groups_mailbox_name_lower_unique_idx
    ON contact_groups (mailbox_identity, lower(name));
