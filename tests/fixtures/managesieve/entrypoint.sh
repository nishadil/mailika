#!/bin/sh
set -eu

: "${DOVECOT_TEST_EMAIL:=user@example.com}"
: "${DOVECOT_TEST_PASSWORD:=secret}"

if ! getent group vmail >/dev/null 2>&1; then
    groupadd --gid 5000 vmail
fi

if ! id -u vmail >/dev/null 2>&1; then
    useradd --uid 5000 --gid vmail --home-dir /var/mail/mailboxes --shell /usr/sbin/nologin vmail
fi

mkdir -p \
    "/var/mail/mailboxes/${DOVECOT_TEST_EMAIL}/Maildir/cur" \
    "/var/mail/mailboxes/${DOVECOT_TEST_EMAIL}/Maildir/new" \
    "/var/mail/mailboxes/${DOVECOT_TEST_EMAIL}/Maildir/tmp" \
    "/var/mail/mailboxes/${DOVECOT_TEST_EMAIL}/sieve"

chown -R vmail:vmail /var/mail/mailboxes

printf '%s:{PLAIN}%s\n' "${DOVECOT_TEST_EMAIL}" "${DOVECOT_TEST_PASSWORD}" > /etc/dovecot/users
chmod 0600 /etc/dovecot/users

exec dovecot -F -c /etc/dovecot/dovecot.conf
