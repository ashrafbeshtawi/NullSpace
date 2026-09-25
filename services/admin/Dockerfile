FROM php:8.3-apache

# sudo: run.php runs the ./bin scripts as root (see sudoers).
# restic: needed by backup-offsite.sh / restore-offsite.sh.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl gnupg git sudo restic \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && chmod a+r /etc/apt/keyrings/docker.asc \
    && . /etc/os-release \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $VERSION_CODENAME stable" \
        > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends docker-ce-cli docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/* \
    && git config --system --add safe.directory /opt/NullSpace

COPY index.php run.php /var/www/html/
COPY --chmod=0440 sudoers /etc/sudoers.d/admin-panel
RUN visudo -cf /etc/sudoers.d/admin-panel
