WITH ranked_contacts AS (
    SELECT
        id,
        first_value(id) OVER (
            PARTITION BY mailbox_identity, lower(email)
            ORDER BY id DESC
        ) AS kept_id,
        row_number() OVER (
            PARTITION BY mailbox_identity, lower(email)
            ORDER BY id DESC
        ) AS duplicate_rank
    FROM contacts
)
INSERT INTO contact_group_memberships (contact_id, group_id)
SELECT ranked_contacts.kept_id, contact_group_memberships.group_id
FROM ranked_contacts
INNER JOIN contact_group_memberships
    ON contact_group_memberships.contact_id = ranked_contacts.id
WHERE ranked_contacts.duplicate_rank > 1
ON CONFLICT DO NOTHING;

WITH ranked_contacts AS (
    SELECT
        id,
        row_number() OVER (
            PARTITION BY mailbox_identity, lower(email)
            ORDER BY id DESC
        ) AS duplicate_rank
    FROM contacts
)
DELETE FROM contacts
USING ranked_contacts
WHERE contacts.id = ranked_contacts.id
    AND ranked_contacts.duplicate_rank > 1;

CREATE UNIQUE INDEX IF NOT EXISTS contacts_mailbox_email_lower_unique_idx
    ON contacts (mailbox_identity, lower(email));
