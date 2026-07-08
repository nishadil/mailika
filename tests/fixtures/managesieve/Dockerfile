FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        dovecot-core \
        dovecot-imapd \
        dovecot-managesieved \
        dovecot-sieve \
    && rm -rf /var/lib/apt/lists/*

COPY tests/fixtures/managesieve/dovecot.conf /etc/dovecot/dovecot.conf
COPY tests/fixtures/managesieve/entrypoint.sh /usr/local/bin/mailika-managesieve-entrypoint

RUN chmod 0755 /usr/local/bin/mailika-managesieve-entrypoint

EXPOSE 143 4190

ENTRYPOINT ["/usr/local/bin/mailika-managesieve-entrypoint"]
