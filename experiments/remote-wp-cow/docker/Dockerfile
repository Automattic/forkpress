FROM rust:1-bookworm

ENV DEBIAN_FRONTEND=noninteractive
ENV WPCOW_HOME=/root/.wp-cow

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        fuse3 \
        less \
        libfuse3-dev \
        mariadb-client \
        mariadb-server \
        openssh-client \
        php-cli \
        php-mysqli \
        pkg-config \
        rsync \
        tini \
        vim-tiny \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /workspace
COPY Cargo.toml Cargo.lock ./
COPY src ./src
RUN cargo build --release \
    && cp target/release/wp-cow /usr/local/bin/wp-cow \
    && cargo clean

COPY docker/wp-cow-lab-entrypoint /usr/local/bin/wp-cow-lab-entrypoint
COPY docker/wp-cow-lab-check /usr/local/bin/wp-cow-lab-check
COPY docker/wp-cow-lab-clone /usr/local/bin/wp-cow-lab-clone
COPY docker/wp-cow-lab-mount /usr/local/bin/wp-cow-lab-mount
COPY docker/wp-cow-lab-run /usr/local/bin/wp-cow-lab-run
COPY docker/wp-cow-lab-ssh-target /usr/local/bin/wp-cow-lab-ssh-target
COPY docker/wp-cow-lab-dns /usr/local/bin/wp-cow-lab-dns
RUN chmod +x /usr/local/bin/wp-cow-lab-*

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/wp-cow-lab-entrypoint"]
CMD ["sleep", "infinity"]
