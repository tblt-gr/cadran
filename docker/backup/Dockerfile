# Same base and major version as docker/postgres/Dockerfile so pg_dump matches the server.
FROM postgres:18.6-alpine3.23@sha256:885cf05d376c7cf27afef02073e6bdac3841252537f16e244fd1c1e6a7c99fb1

RUN apk upgrade --no-cache libcrypto3 libssl3 libuuid libcurl \
    && apk add --no-cache age \
    && rm /usr/local/bin/gosu

COPY cadran-backup.sh /usr/local/bin/cadran-backup

USER 70:70

ENTRYPOINT ["/usr/local/bin/cadran-backup"]
CMD []
