FROM postgres:18.6-alpine3.24

RUN apk upgrade --no-cache libcrypto3 libssl3 \
    && rm /usr/local/bin/gosu \
    && mkdir -p /var/lib/postgresql/18/docker \
    && chown -R postgres:postgres /var/lib/postgresql

USER postgres
