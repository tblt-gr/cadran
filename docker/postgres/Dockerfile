# Alpine 3.24 already lists util-linux 2.42.3-r0 as the HIGH CVE fix, but that
# package is not in the v3.24 repository yet. 3.23 published the 2.41.6-r0 backport.
FROM postgres:18.6-alpine3.23

RUN apk upgrade --no-cache libcrypto3 libssl3 libuuid libcurl \
    && rm /usr/local/bin/gosu \
    && mkdir -p /var/lib/postgresql/18/docker \
    && chown -R postgres:postgres /var/lib/postgresql

USER postgres
