# Build environment only — no source is baked in.
# The repo is bind-mounted at /app at runtime, so editing files on the host
# takes effect immediately; rebuilds are only needed when ext/branchfs.c
# (the C extension) changes, and that's a 2-second `make`.
FROM debian:bookworm-slim

ARG TARGETARCH

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    PATH="/usr/local/bin:${PATH}" \
    PHP_CONFIG=php-config8.2 \
    WP_SRC=/opt/wordpress-src

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        ca-certificates \
        curl \
        git \
        libsqlite3-dev \
        php8.2 \
        php8.2-cli \
        php8.2-dev \
        php8.2-mbstring \
        php8.2-mysql \
        php8.2-sqlite3 \
        pkg-config \
        procps \
        tar \
    && rm -rf /var/lib/apt/lists/*

# Install Dolt for the target architecture
RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
        amd64) DOLT_ARCH=amd64 ;; \
        arm64) DOLT_ARCH=arm64 ;; \
        *) echo "unsupported arch: ${TARGETARCH}"; exit 1 ;; \
    esac; \
    curl -sL "https://github.com/dolthub/dolt/releases/latest/download/dolt-linux-${DOLT_ARCH}.tar.gz" -o /tmp/dolt.tgz; \
    tar -C /tmp -xzf /tmp/dolt.tgz; \
    install -m 0755 /tmp/dolt-linux-${DOLT_ARCH}/bin/dolt /usr/local/bin/dolt; \
    rm -rf /tmp/dolt.tgz /tmp/dolt-linux-${DOLT_ARCH}

# Dolt refuses to run without identity
RUN dolt config --global --add user.name "branched-wp" \
 && dolt config --global --add user.email "branched-wp@example.com"

# Pre-fetch a pinned WordPress into a neutral path so it's not on the
# bind-mount (avoids slow Docker-Desktop-on-Mac I/O for ~2400 WP files).
RUN curl -sL https://wordpress.org/wordpress-6.5.tar.gz -o /tmp/wp.tgz \
 && tar -C /opt -xzf /tmp/wp.tgz \
 && mv /opt/wordpress /opt/wordpress-src \
 && rm /tmp/wp.tgz

WORKDIR /app

# Entry script: builds the .so if missing or stale, then execs the command
# (defaults to dev.sh — live server — but overridable via `docker compose run`).
COPY entrypoint.sh /usr/local/bin/branched-wp-entrypoint
RUN chmod +x /usr/local/bin/branched-wp-entrypoint

ENTRYPOINT ["/usr/local/bin/branched-wp-entrypoint"]
CMD ["bash", "e2e/dev.sh"]
