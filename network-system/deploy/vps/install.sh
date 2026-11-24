#!/usr/bin/env bash
if grep -q $'\r' "$0" 2>/dev/null; then sed -i 's/\r$//' "$0" && exec bash "$0" "$@"; fi #
#
# MADIX NEXUS — server installer (Ubuntu 22.04 / 24.04, Debian 12 / 13)
#
#   bash madix-nexus-vps.sh                 install, or update an existing installation
#   bash madix-nexus-vps.sh --help          all options
#
set -Eeuo pipefail
umask 022

MADIX_VERSION="__MADIX_VERSION__"
PAYLOAD_SHA256="__MADIX_SHA256__"

APP_DIR=/var/www/madix
ETC_DIR=/etc/madix
STATE_DIR=/var/lib/madix
STATE_FILE=$ETC_DIR/madix.env
BACKUP_DIR=/var/backups/madix
LOG_FILE=/var/log/madix-install.log
CRED_FILE=/root/madix-nexus.txt
WEB_USER=www-data

MODE=""            # platform | single
DOMAIN=""
EMAIL=""
ADMIN_NAME=""
ADMIN_USER=""
ADMIN_PASS="${MADIX_ADMIN_PASS:-}"
NETWORK_NAME=""
TIMEZONE="Asia/Gaza"
SOURCE=""
ASSUME_YES=0
DO_SSL=1
DO_FIREWALL=1
ACTION=""          # install | update
PASS_GENERATED=0
STAGE=""
STEP=0

# ---------------------------------------------------------------- output
if [ -t 1 ]; then
    C_OK=$'\e[32m'; C_WARN=$'\e[33m'; C_ERR=$'\e[31m'; C_HEAD=$'\e[36;1m'; C_DIM=$'\e[2m'; C_OFF=$'\e[0m'
else
    C_OK=""; C_WARN=""; C_ERR=""; C_HEAD=""; C_DIM=""; C_OFF=""
fi

log()   { printf '%s\n' "$*" >>"$LOG_FILE" 2>/dev/null || true; }
say()   { printf '%s\n' "$*"; log "$*"; }
step()  { STEP=$((STEP + 1)); printf '\n%s[%d] %s%s\n' "$C_HEAD" "$STEP" "$*" "$C_OFF"; log "[$STEP] $*"; }
ok()    { printf '    %s✔%s %s\n' "$C_OK" "$C_OFF" "$*"; log "    ok: $*"; }
warn()  { printf '    %s!%s %s\n' "$C_WARN" "$C_OFF" "$*"; log "    warn: $*"; }
die()   { printf '\n%s✘ %s%s\n' "$C_ERR" "$*" "$C_OFF" >&2; log "error: $*"; exit 1; }

# run a command, keeping its output in the log only
quiet() {
    log "    \$ $*"
    if ! "$@" </dev/null >>"$LOG_FILE" 2>&1; then
        return 1
    fi
}

on_error() {
    local code=$? line=$1 cmd=$2
    trap - ERR
    if [ "${BASH_SUBSHELL:-0}" -gt 0 ]; then
        exit "$code"
    fi
    rm -f "$APP_DIR/storage/maintenance" 2>/dev/null || true
    printf '\n%s✘ Installation stopped (line %s): %s%s\n' "$C_ERR" "$line" "$cmd" "$C_OFF" >&2
    printf '  Details: %s\n' "$LOG_FILE" >&2
    printf '  The installer is safe to run again after fixing the problem.\n' >&2
    log "failed (exit $code) at line $line: $cmd"
    exit "$code"
}

cleanup() {
    if [ -n "$STAGE" ] && [ -d "$STAGE" ]; then
        rm -rf "$STAGE"
    fi
}

usage() {
    cat <<'EOF'
MADIX NEXUS server installer

Usage: bash madix-nexus-vps.sh [options]

  --domain=NAME        domain pointing to this server (example: nexus.example.com)
  --email=ADDRESS      email for the free SSL certificate (Let's Encrypt)
  --mode=platform      the platform: landing page, admin panel, network accounts (default)
  --mode=single        one network only, without the platform
  --admin-name=NAME    administrator full name
  --admin-user=USER    administrator username
                       password: typed when asked, or MADIX_ADMIN_PASS=... in the environment
  --network-name=NAME  network name (single mode)
  --timezone=ZONE      default Asia/Gaza
  --update             update an existing installation (keeps all data)
  --no-ssl             skip the SSL certificate
  --no-firewall        do not change the firewall
  --source=PATH        install from a system folder, .zip or .tar.gz instead of the built-in copy
  --yes                accept defaults without asking
EOF
}

# ---------------------------------------------------------------- helpers
has_tty() { [ -r /dev/tty ] && [ -w /dev/tty ] && { : </dev/tty; } 2>/dev/null; }

ask() { # ask VAR "Question" "default"
    local __var=$1 __q=$2 __def=${3:-} __ans=""
    if [ "$ASSUME_YES" = 1 ] || ! has_tty; then
        printf -v "$__var" '%s' "$__def"
        return
    fi
    if [ -n "$__def" ]; then
        read -r -p "    $__q [$__def]: " __ans </dev/tty || true
    else
        read -r -p "    $__q: " __ans </dev/tty || true
    fi
    __ans=$(printf '%s' "$__ans" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    printf -v "$__var" '%s' "${__ans:-$__def}"
}

confirm() { # confirm "Question" Y|N
    local q=$1 def=${2:-Y} ans=""
    if [ "$ASSUME_YES" = 1 ] || ! has_tty; then
        [ "$def" = Y ]
        return
    fi
    if [ "$def" = Y ]; then
        read -r -p "    $q [Y/n]: " ans </dev/tty || true
        [[ ! "$ans" =~ ^[Nn] ]]
    else
        read -r -p "    $q [y/N]: " ans </dev/tty || true
        [[ "$ans" =~ ^[Yy] ]]
    fi
}

ask_password() {
    local p1="" p2=""
    if [ -n "$ADMIN_PASS" ]; then
        [ "${#ADMIN_PASS}" -ge 8 ] || die "The administrator password must be at least 8 characters."
        return
    fi
    if [ "$ASSUME_YES" = 1 ] || ! has_tty; then
        ADMIN_PASS=$(random_password)
        PASS_GENERATED=1
        return
    fi
    while :; do
        read -r -s -p "    Administrator password (8+ characters, Enter = generate one): " p1 </dev/tty || true
        printf '\n'
        if [ -z "$p1" ]; then
            ADMIN_PASS=$(random_password)
            PASS_GENERATED=1
            return
        fi
        if [ "${#p1}" -lt 8 ]; then
            warn "Too short, use at least 8 characters."
            continue
        fi
        read -r -s -p "    Repeat the password: " p2 </dev/tty || true
        printf '\n'
        if [ "$p1" != "$p2" ]; then
            warn "The passwords do not match, try again."
            continue
        fi
        ADMIN_PASS=$p1
        return
    done
}

random_password() { # 16 characters, letters and digits only
    local p=""
    while [ "${#p}" -lt 16 ]; do
        p+=$(head -c 64 /dev/urandom | tr -dc 'A-Za-z0-9' || true)
    done
    printf '%s' "${p:0:16}"
}

random_hex() { head -c "${1:-24}" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

has_systemd() { [ -d /run/systemd/system ]; }

in_container() {
    [ -f /.dockerenv ] && return 0
    grep -qa 'container=' /proc/1/environ 2>/dev/null && return 0
    if command -v systemd-detect-virt >/dev/null 2>&1 && systemd-detect-virt --container --quiet 2>/dev/null; then
        return 0
    fi
    return 1
}

svc() { # svc start|restart|reload|enable NAME
    local action=$1 name=$2
    if has_systemd; then
        if [ "$action" = enable ]; then
            quiet systemctl enable "$name" || true
            return 0
        fi
        quiet systemctl "$action" "$name" && return 0
        [ "$action" = restart ] || return 1
        sleep 3
        quiet systemctl start "$name"
    else
        [ "$action" = enable ] && return 0
        quiet service "$name" "$action" && return 0
        [ "$action" = restart ] || return 1
        sleep 3
        quiet service "$name" start
    fi
}

svc_active() {
    if has_systemd; then
        systemctl is-active --quiet "$1"
    else
        service "$1" status >/dev/null 2>&1
    fi
}

wait_for_apt() {
    local n=0
    while pgrep -f '/usr/bin/unattended-upgrade( |$)|apt\.systemd\.daily' >/dev/null 2>&1 || pgrep -x 'apt-get|apt|dpkg' >/dev/null 2>&1; do
        [ "$n" = 0 ] && say "    Waiting for automatic system updates to finish..."
        n=$((n + 1))
        [ "$n" -ge 120 ] && break
        sleep 5
    done
}

apt_get() {
    local i
    wait_for_apt
    for i in 1 2 3; do
        if quiet env DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1 \
            apt-get -y -q -o DPkg::Lock::Timeout=600 \
            -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$@"; then
            return 0
        fi
        warn "apt-get $1 failed, retrying ($i/3)..."
        sleep $((i * 5))
        quiet env DEBIAN_FRONTEND=noninteractive dpkg --configure -a || true
    done
    return 1
}

normalize_domain() {
    local d
    d=$(printf '%s' "$1" | tr 'A-Z' 'a-z' | sed -E 's#^[a-z]+://##; s#[/?#].*$##; s#:[0-9]+$##; s#\.$##')
    printf '%s' "$d"
}

valid_domain() {
    [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$ ]]
}

public_ip() {
    local ip=""
    for u in https://api.ipify.org https://ifconfig.me/ip https://icanhazip.com; do
        ip=$(curl -4 -fsS --max-time 8 "$u" 2>/dev/null | tr -d '[:space:]' || true)
        if [[ "$ip" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
            printf '%s' "$ip"
            return
        fi
    done
    hostname -I 2>/dev/null | awk '{print $1}' || true
}

resolve_ipv4() { getent ahostsv4 "$1" 2>/dev/null | awk '{print $1; exit}' || true; }

mysql_root() { mysql --protocol=socket -uroot "$@"; }

as_web() { # run a command as the web server user
    if command -v runuser >/dev/null 2>&1; then
        runuser -u "$WEB_USER" -- "$@"
    else
        su -s /bin/sh "$WEB_USER" -c "$(printf '%q ' "$@")"
    fi
}

# ---------------------------------------------------------------- payload
extract_payload() { # extract_payload DIR
    local dir=$1 line sum
    if [ -n "$SOURCE" ]; then
        if [ -d "$SOURCE" ]; then
            cp -a "$SOURCE/." "$dir/"
        elif [[ "$SOURCE" == *.zip ]]; then
            command -v unzip >/dev/null 2>&1 || apt_get install unzip || die "unzip is not available."
            quiet unzip -q -o "$SOURCE" -d "$dir" || die "Cannot read $SOURCE"
        else
            tar -xzmf "$SOURCE" -C "$dir" || die "Cannot read $SOURCE"
        fi
        # a zip may hold the system inside one folder
        if [ ! -f "$dir/app/bootstrap.php" ]; then
            local inner
            inner=$(find "$dir" -mindepth 2 -maxdepth 3 -type f -path '*/app/bootstrap.php' | head -n1)
            if [ -n "$inner" ]; then
                inner=$(dirname "$(dirname "$inner")")
                local tmp
                tmp=$(mktemp -d "$dir/.inner.XXXXXX")
                mv "$inner" "$tmp/sys"
                find "$dir" -mindepth 1 -maxdepth 1 ! -path "$tmp" -exec rm -rf {} +
                cp -a "$tmp/sys/." "$dir/"
                rm -rf "$tmp"
            fi
        fi
    else
        [ -f "$0" ] || die "Save this installer as a file, then run: bash madix-nexus-vps.sh"
        line=$(grep -an '^__MADIX_PAYLOAD_BELOW__$' "$0" | tail -n1 | cut -d: -f1 || true)
        [ -n "$line" ] || die "This installer has no built-in system copy. Use --source=PATH."
        tail -n +"$((line + 1))" "$0" | base64 -d >"$dir/.payload.tgz" 2>/dev/null \
            || die "The installer file is damaged or incomplete. Download it again."
        sum=$(sha256sum "$dir/.payload.tgz" | awk '{print $1}')
        [ "$sum" = "$PAYLOAD_SHA256" ] || die "The installer file is damaged or incomplete. Download it again."
        # -m: files take the time of this install, so browsers and OPcache see every update as new
        tar -xzmf "$dir/.payload.tgz" -C "$dir" || die "The installer file is damaged. Download it again."
        rm -f "$dir/.payload.tgz"
    fi
    [ -f "$dir/app/bootstrap.php" ] && [ -f "$dir/public/index.php" ] && [ -f "$dir/database/schema.sql" ] \
        || die "The system files are incomplete."
    rm -f "$dir"/config/config*.php
}

# ---------------------------------------------------------------- steps
preflight() {
    [ "$(id -u)" -eq 0 ] || die "Run as root:  sudo bash $0"
    mkdir -p "$(dirname "$LOG_FILE")"
    touch "$LOG_FILE" && chmod 600 "$LOG_FILE"
    log "==== $(date -u '+%F %T') MADIX NEXUS $MADIX_VERSION ($*)"

    [ -r /etc/os-release ] || die "Unsupported system. Use Ubuntu 22.04/24.04 or Debian 12."
    # shellcheck disable=SC1091
    . /etc/os-release
    case "${ID:-}:${VERSION_ID:-}" in
        ubuntu:22.04|ubuntu:24.04|ubuntu:24.10|ubuntu:25.04|ubuntu:25.10|ubuntu:26.04|debian:12|debian:13) ;;
        ubuntu:*|debian:*) warn "${PRETTY_NAME:-$ID} is not tested; Ubuntu 24.04 is recommended." ;;
        *) die "Unsupported system (${PRETTY_NAME:-unknown}). Use Ubuntu 24.04, 22.04 or Debian 12." ;;
    esac
    command -v apt-get >/dev/null 2>&1 || die "apt-get is required."

    local free_mb
    free_mb=$(df -Pm / | awk 'NR==2{print $4}')
    [ "${free_mb:-0}" -ge 1500 ] || die "Not enough disk space (${free_mb} MB free, 1500 MB needed)."

    if ! command -v curl >/dev/null 2>&1; then
        apt_get update && apt_get install curl ca-certificates || die "Cannot install curl. Check the server's internet connection."
    fi
}

detect_action() {
    if [ -f "$APP_DIR/config/config.php" ]; then
        if [ "$ACTION" != update ]; then
            say ""
            say "    MADIX NEXUS is already installed in $APP_DIR."
            confirm "Update it to version $MADIX_VERSION? Your data is kept" Y || { say "    Nothing changed."; exit 0; }
        fi
        ACTION=update
        if [ -r "$STATE_FILE" ]; then
            # the saved state holds the installed version; this installer's version is the one to record
            local this_version=$MADIX_VERSION
            # shellcheck disable=SC1090
            . "$STATE_FILE"
            MADIX_VERSION=$this_version
        fi
        [ -n "$MODE" ] || MODE=$(grep -q "'platform'" "$APP_DIR/config/config.php" && echo platform || echo single)
    else
        [ "$ACTION" = update ] && die "No installation found in $APP_DIR."
        ACTION=install
    fi
}

collect_inputs() {
    step "Settings"
    local ip
    ip=$(public_ip)
    say "    Server IP: ${ip:-unknown}"

    # the platform is the default: landing page, admin panel and an account with its own database for every network
    [ -n "$MODE" ] || MODE=platform
    [ "$MODE" = platform ] || [ "$MODE" = single ] || die "--mode must be platform or single."
    if [ "$MODE" = platform ]; then
        ok "Platform: landing page, admin panel, network accounts"
    else
        ok "Single network"
    fi

    local given=$DOMAIN
    while :; do
        [ -n "$given" ] || ask DOMAIN "Domain pointing to this server (Enter = use the IP address)" ""
        DOMAIN=$(normalize_domain "$DOMAIN")
        if [ -z "$DOMAIN" ] || [[ "$DOMAIN" =~ ^[0-9.]+$ ]]; then
            DOMAIN=""
            break
        fi
        valid_domain "$DOMAIN" && break
        [ -n "$given" ] || [ "$ASSUME_YES" = 1 ] || ! has_tty && die "Invalid domain: $DOMAIN"
        warn "Invalid domain: $DOMAIN (example: nexus.example.com)"
        DOMAIN=""
    done

    if [ -n "$DOMAIN" ]; then
        local dns
        dns=$(resolve_ipv4 "$DOMAIN")
        if [ -z "$dns" ]; then
            warn "$DOMAIN does not resolve yet. Add an A record pointing to ${ip:-this server}."
        elif [ -n "$ip" ] && [ "$dns" != "$ip" ]; then
            warn "$DOMAIN points to $dns, but this server is $ip."
        fi
        if [ -z "$dns" ] || { [ -n "$ip" ] && [ "$dns" != "$ip" ]; }; then
            if [ "$ASSUME_YES" != 1 ] && ! confirm "Continue anyway? (SSL is skipped until the domain points here)" N; then
                say "    Fix the DNS record, wait a few minutes, then run the installer again."
                exit 0
            fi
            DNS_OK=0
        else
            ok "Domain $DOMAIN points to this server"
            DNS_OK=1
        fi
        if [ "$DO_SSL" = 1 ] && [ -z "$EMAIL" ]; then
            ask EMAIL "Email for the SSL certificate (optional)" ""
        fi
        if [ -n "$EMAIL" ] && [[ ! "$EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; then
            warn "Invalid email, the certificate is requested without one."
            EMAIL=""
        fi
    fi
    SERVER_IP=$ip

    if [ "$MODE" = single ] && [ -z "$NETWORK_NAME" ]; then
        ask NETWORK_NAME "Network name" "MADIX NEXUS"
    fi
    [ -n "$ADMIN_NAME" ] || ask ADMIN_NAME "Administrator full name" "Administrator"
    while :; do
        [ -n "$ADMIN_USER" ] || ask ADMIN_USER "Administrator username (letters, digits . _ -)" "admin"
        if [[ "$ADMIN_USER" =~ ^[A-Za-z0-9._-]{3,60}$ ]]; then
            break
        fi
        warn "Use 3-60 English letters, digits, dot, dash or underscore."
        [ "$ASSUME_YES" = 1 ] && die "Invalid --admin-user."
        ADMIN_USER=""
    done
    ask_password
    ok "Administrator: $ADMIN_USER"
}

setup_swap() {
    in_container && return 0
    if [ -n "$(swapon --noheadings 2>/dev/null)" ]; then
        return 0
    fi
    local mem_mb
    mem_mb=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)
    [ "$mem_mb" -lt 3500 ] || return 0
    if [ ! -f /swapfile ]; then
        quiet fallocate -l 2G /swapfile || quiet dd if=/dev/zero of=/swapfile bs=1M count=2048 || { warn "Swap not created."; return 0; }
        chmod 600 /swapfile
        quiet mkswap /swapfile || { rm -f /swapfile; warn "Swap not created."; return 0; }
    fi
    if quiet swapon /swapfile; then
        grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
        printf 'vm.swappiness=10\n' >/etc/sysctl.d/90-madix.conf
        quiet sysctl -p /etc/sysctl.d/90-madix.conf || true
        ok "Swap 2 GB enabled"
    else
        warn "Swap not enabled."
    fi
}

install_packages() {
    step "Installing server packages (a few minutes)"
    setup_swap
    apt_get update || die "Cannot reach the package servers. Check the server's internet connection."
    local pkgs=(apache2 libapache2-mod-php php-fpm php-cli php-mysql php-mbstring php-xml php-zip php-curl
        php-intl php-gd php-bcmath curl ca-certificates unzip cron openssl tar gzip
        util-linux procps iproute2 wireguard-tools unattended-upgrades)
    if dpkg -s mysql-server >/dev/null 2>&1; then
        ok "Using the installed MySQL server"
    else
        pkgs+=(mariadb-server mariadb-client)
    fi
    [ "$DO_SSL" = 1 ] && pkgs+=(certbot python3-certbot-apache)
    if [ "$DO_FIREWALL" = 1 ] && ! in_container; then
        pkgs+=(ufw fail2ban)
    fi
    apt_get install "${pkgs[@]}" || die "Package installation failed. Details: $LOG_FILE"

    PHPV=$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')
    php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || die "PHP 8.1 or newer is required (found $PHPV)."
    local missing
    missing=$(php -r 'foreach (["pdo_mysql","mbstring","openssl","zip","curl","sodium"] as $e) if (!extension_loaded($e)) echo $e, " ";')
    [ -z "$missing" ] || die "Missing PHP extensions: $missing"
    ok "PHP $PHPV, Apache, $(mysql --version 2>/dev/null | grep -o 'MariaDB\|MySQL' | head -n1 || echo database)"
}

tune_services() {
    local mem_mb workers pool
    mem_mb=$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)

    # PHP (mod_php and PHP-FPM take the same settings)
    local ini
    for ini in /etc/php/"$PHPV"/apache2/conf.d /etc/php/"$PHPV"/fpm/conf.d /etc/php/"$PHPV"/cli/conf.d; do
        [ -d "$ini" ] || continue
        if [[ "$ini" != */cli/* ]]; then
            cat >"$ini/99-madix.ini" <<EOF
memory_limit = 256M
upload_max_filesize = 32M
post_max_size = 40M
max_execution_time = 120
max_input_time = 120
max_input_vars = 5000
expose_php = Off
date.timezone = $TIMEZONE
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
opcache.interned_strings_buffer = 16
realpath_cache_size = 4096K
realpath_cache_ttl = 600
EOF
        else
            cat >"$ini/99-madix.ini" <<EOF
memory_limit = 512M
date.timezone = $TIMEZONE
EOF
        fi
    done

    # Database memory first: PHP gets what is left
    pool=$((mem_mb / 4))
    [ "$pool" -lt 128 ] && pool=128
    [ "$pool" -gt 4096 ] && pool=4096

    # PHP-FPM: a fixed number of PHP workers sized to the memory, so the server never runs out of memory and swaps
    # (about 40 MB each, after the database, the system and Apache)
    local children
    children=$(( (mem_mb - pool - 300) / 40 ))
    [ "$children" -lt 5 ] && children=5
    [ "$children" -gt 60 ] && children=60
    if [ -d /etc/php/"$PHPV"/fpm/pool.d ]; then
        cat >/etc/php/"$PHPV"/fpm/pool.d/zz-madix.conf <<EOF
; MADIX NEXUS: PHP workers sized to this server's memory ($mem_mb MB)
[www]
pm = dynamic
pm.max_children = $children
pm.start_servers = $(( children > 8 ? 4 : 2 ))
pm.min_spare_servers = 1
pm.max_spare_servers = $(( children > 8 ? children / 2 : 3 ))
pm.max_requests = 500
listen.backlog = 511
EOF
    fi

    # Apache
    workers=$((mem_mb / 64))
    [ "$workers" -lt 20 ] && workers=20
    [ "$workers" -gt 150 ] && workers=150
    cat >/etc/apache2/conf-available/madix.conf <<EOF
ServerTokens Prod
ServerSignature Off
TraceEnable Off
# HTTP/2 and long keep-alive: one fast connection per visitor instead of a new one for every click
Protocols h2 http/1.1
KeepAlive On
KeepAliveTimeout 15
MaxKeepAliveRequests 500
<IfModule http2_module>
    # HTTP/2 only through HTTPS (ALPN); no Upgrade header on plain requests (old Safari fails on it)
    H2Upgrade off
</IfModule>
<IfModule mpm_prefork_module>
    StartServers 4
    MinSpareServers 4
    MaxSpareServers 12
    MaxRequestWorkers $workers
    MaxConnectionsPerChild 2000
</IfModule>
<IfModule mpm_event_module>
    StartServers 2
    ThreadsPerChild 25
    MaxRequestWorkers 150
    MaxConnectionsPerChild 0
</IfModule>
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript application/javascript application/json application/manifest+json image/svg+xml
</IfModule>
# page timing for "madix speed": server build time (first byte) and total, for every page but not /assets files
<IfModule logio_module>
    LogIOTrackTTFB On
    <IfModule setenvif_module>
        SetEnvIf Request_URI "^/assets/" madix_static
        SetEnvIf Request_URI "^/(favicon\\.ico|manifest\\.webmanifest)\$" madix_static
    </IfModule>
    GlobalLog \${APACHE_LOG_DIR}/madix-timing.log "%{%s}t %>s %D %^FB %m %U%q" env=!madix_static
</IfModule>
<IfModule mod_headers.c>
    # files with a version in the link (?v=…) never change: the browser keeps them without asking again
    <If "%{REQUEST_URI} =~ m#^/assets/# && %{QUERY_STRING} =~ /(^|&)v=[0-9a-f]+/">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </If>
</IfModule>
EOF
    quiet a2enconf madix
    quiet a2enmod rewrite headers expires deflate
    if [ -f /etc/apache2/mods-available/php"$PHPV".load ]; then
        quiet a2enmod php"$PHPV" || true
    fi

    # Database (fewer connections on small servers: PHP never opens more than its workers)
    local conns=200
    [ "$mem_mb" -lt 2048 ] && conns=100
    local cnf_dir=/etc/mysql/mariadb.conf.d
    [ -d "$cnf_dir" ] || cnf_dir=/etc/mysql/mysql.conf.d
    if [ -d "$cnf_dir" ]; then
        local cnf
        cnf=$(printf '[mysqld]\nbind-address = 127.0.0.1\ninnodb_buffer_pool_size = %sM\nmax_connections = %s\ncharacter-set-server = utf8mb4\ncollation-server = utf8mb4_unicode_ci\n' "$pool" "$conns")
        if [ "$(cat "$cnf_dir/99-madix.cnf" 2>/dev/null)" != "$cnf" ]; then
            printf '%s\n' "$cnf" >"$cnf_dir/99-madix.cnf"
            DB_CNF_CHANGED=1
        fi
    fi
}

start_database() {
    svc enable mariadb 2>/dev/null || svc enable mysql 2>/dev/null || true
    if [ "${DB_CNF_CHANGED:-0}" = 1 ] || ! mysqladmin --protocol=socket -uroot ping >/dev/null 2>&1; then
        if ! svc restart mariadb 2>/dev/null; then
            svc restart mysql || die "The database server does not start. Details: $LOG_FILE"
        fi
        DB_CNF_CHANGED=0
    fi
    local i
    for i in $(seq 1 40); do
        mysqladmin --protocol=socket -uroot ping >/dev/null 2>&1 && return 0
        sleep 1
    done
    die "The database server is not responding."
}

setup_database() {
    step "Database"
    start_database
    mysql_root -e 'SELECT 1' >/dev/null 2>&1 \
        || die "Cannot sign in to the database as root. A database with a root password already exists on this server."

    DB_NAME=$([ "$MODE" = platform ] && echo madix_platform || echo madix_nexus)
    DB_USER=madix
    DB_PASS=$(random_hex 24)
    TENANT_PREFIX=mnx_

    if [ "$MODE" = single ]; then
        local rows
        rows=$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='employees'" 2>/dev/null || echo 0)
        if [ "${rows:-0}" != 0 ] && [ "$(mysql_root -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.employees" 2>/dev/null || echo 0)" != 0 ]; then
            local old
            old="${DB_NAME}_old_$(date +%Y%m%d%H%M%S)"
            warn "Database $DB_NAME holds an earlier unfinished installation; it is renamed to $old."
            mysql_root -e "CREATE DATABASE \`$old\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            local t
            for t in $(mysql_root -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB_NAME'"); do
                mysql_root -e "RENAME TABLE \`$DB_NAME\`.\`$t\` TO \`$old\`.\`$t\`"
            done
        fi
    fi

    mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`mnx\\_%\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    ok "Database $DB_NAME and user $DB_USER"
}

fix_permissions() {
    chown -R root:root "$APP_DIR"
    find "$APP_DIR" -type d -exec chmod 755 {} +
    find "$APP_DIR" -type f -exec chmod 644 {} +
    mkdir -p "$APP_DIR"/storage/{logs,tmp,backups,locks,cache,sessions,imports,tenants,server/requests} "$APP_DIR/public/uploads"
    chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/storage" "$APP_DIR/public/uploads"
    chmod -R u+rwX,g+rwX,o-rwx "$APP_DIR/storage"
    chmod -R u+rwX,g+rX,o+rX "$APP_DIR/public/uploads"
    chown root:"$WEB_USER" "$APP_DIR/config"
    chmod 750 "$APP_DIR/config"
    if [ -f "$APP_DIR/config/config.php" ]; then
        chown root:"$WEB_USER" "$APP_DIR/config/config.php"
        chmod 640 "$APP_DIR/config/config.php"
    fi
}

deploy_files() {
    step "System files"
    if [ "$ACTION" = install ]; then
        if [ -d "$APP_DIR" ] && [ -n "$(ls -A "$APP_DIR" 2>/dev/null)" ]; then
            local old
            old="$APP_DIR.old-$(date +%Y%m%d%H%M%S)"
            mv "$APP_DIR" "$old"
            warn "Previous files moved to $old"
        fi
        mkdir -p "$APP_DIR"
        cp -a "$STAGE/." "$APP_DIR/"
    else
        local item name
        for item in "$STAGE"/* "$STAGE"/.htaccess; do
            [ -e "$item" ] || continue
            name=$(basename "$item")
            case "$name" in
                config|storage)
                    mkdir -p "$APP_DIR/$name"
                    find "$item" -maxdepth 1 -type f ! -name 'config*.php' -exec cp -f {} "$APP_DIR/$name/" \;
                    ;;
                public)
                    find "$APP_DIR/public" -mindepth 1 -maxdepth 1 ! -name uploads -exec rm -rf {} +
                    find "$item" -mindepth 1 -maxdepth 1 ! -name uploads -exec cp -a {} "$APP_DIR/public/" \;
                    mkdir -p "$APP_DIR/public/uploads"
                    find "$item/uploads" -maxdepth 1 -type f -exec cp -f {} "$APP_DIR/public/uploads/" \;
                    ;;
                *)
                    rm -rf "${APP_DIR:?}/$name"
                    cp -a "$item" "$APP_DIR/"
                    ;;
            esac
        done
    fi
    fix_permissions
    ok "Installed in $APP_DIR"
}

app_url() {
    local scheme=$1 host
    host=${DOMAIN:-$SERVER_IP}
    [ -n "$host" ] || host=$(hostname -I | awk '{print $1}')
    printf '%s://%s' "$scheme" "$host"
}

write_platform_config() {
    local url=$1 file=$APP_DIR/config/config.php
    MX_URL="$url" MX_TZ="$TIMEZONE" MX_DB="$DB_NAME" MX_USER="$DB_USER" MX_PASS="$DB_PASS" MX_PREFIX="$TENANT_PREFIX" \
    php -r '
        $c = [
            "app" => [
                "url" => getenv("MX_URL"),
                "key" => "base64:" . base64_encode(random_bytes(32)),
                "debug" => false,
                "timezone" => getenv("MX_TZ"),
                "cron_key" => bin2hex(random_bytes(20)),
            ],
            "db" => ["host" => "localhost", "port" => 3306, "name" => getenv("MX_DB"), "user" => getenv("MX_USER"), "pass" => getenv("MX_PASS"), "charset" => "utf8mb4"],
            "platform" => [
                "enabled" => true,
                "tenant_db" => ["host" => "localhost", "port" => 3306, "user" => getenv("MX_USER"), "pass" => getenv("MX_PASS"), "prefix" => getenv("MX_PREFIX")],
            ],
        ];
        $out = "<?php\n// MADIX NEXUS configuration. Keep it private.\nreturn " . var_export($c, true) . ";\n";
        exit(file_put_contents($argv[1], $out) === false ? 1 : 0);
    ' "$file.tmp"
    mv -f "$file.tmp" "$file"
    chown root:"$WEB_USER" "$file"
    chmod 640 "$file"
}

configure_system() {
    step "System setup"
    local url
    url=$(app_url http)
    if [ "$MODE" = platform ]; then
        write_platform_config "$url"
        as_web php "$APP_DIR/cli/platform.php" setup --name="$ADMIN_NAME" --username="$ADMIN_USER" --password="$ADMIN_PASS" >>"$LOG_FILE" 2>&1 \
            || die "Platform setup failed. Details: $LOG_FILE"
        ok "Platform ready, administrator $ADMIN_USER"
    else
        MX_URL="$url" MX_TZ="$TIMEZONE" MX_DB="$DB_NAME" MX_USER="$DB_USER" MX_PASS="$DB_PASS" \
        MX_NET="$NETWORK_NAME" MX_ANAME="$ADMIN_NAME" MX_AUSER="$ADMIN_USER" MX_APASS="$ADMIN_PASS" \
        php -r '
            require $argv[1] . "/app/bootstrap.php";
            $r = App\Core\Installer::install([
                "db_host" => "localhost", "db_port" => 3306, "db_name" => getenv("MX_DB"),
                "db_user" => getenv("MX_USER"), "db_pass" => getenv("MX_PASS"),
                "network_name" => getenv("MX_NET"), "app_url" => getenv("MX_URL"), "timezone" => getenv("MX_TZ"),
                "admin_name" => getenv("MX_ANAME"), "admin_username" => getenv("MX_AUSER"), "admin_password" => getenv("MX_APASS"),
            ]);
            if (!$r["written"]) {
                file_put_contents($argv[1] . "/config/config.php", App\Core\Installer::configContent($r["config"]));
            }
        ' "$APP_DIR" >>"$LOG_FILE" 2>&1 || die "System setup failed. Details: $LOG_FILE"
        ok "Network \"$NETWORK_NAME\" ready, administrator $ADMIN_USER"
    fi
    fix_permissions
}

write_vhost() {
    local name=${DOMAIN:-${SERVER_IP:-_default_}} alias=""
    if [ -n "$DOMAIN" ] && [ "$(printf '%s' "$DOMAIN" | tr -cd '.' | wc -c)" -eq 1 ]; then
        alias="    ServerAlias www.$DOMAIN"
    fi
    if [ "$ACTION" = update ] && [ -f /etc/apache2/sites-available/madix.conf ]; then
        return 0 # keep the existing site and its certificate settings
    fi
    {
        printf '<VirtualHost *:80>\n    ServerName %s\n' "$name"
        [ -n "$alias" ] && printf '%s\n' "$alias"
        cat <<EOF
    DocumentRoot $APP_DIR/public

    <Directory $APP_DIR>
        Options -Indexes
        AllowOverride None
        Require all denied
    </Directory>
    <Directory $APP_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/madix-error.log
    CustomLog \${APACHE_LOG_DIR}/madix-access.log combined
</VirtualHost>
EOF
    } >/etc/apache2/sites-available/madix.conf
}

# PHP through PHP-FPM with the event MPM and HTTP/2 (light on memory, many visitors at once) → 0 when ready
php_fpm_on() {
    [ -f /etc/apache2/conf-available/php"$PHPV"-fpm.conf ] && [ -f /etc/apache2/mods-available/mpm_event.load ] || return 1
    svc enable php"$PHPV"-fpm
    svc restart php"$PHPV"-fpm || return 1
    quiet a2dismod php"$PHPV" || true
    quiet a2dismod mpm_prefork || true
    quiet a2enmod mpm_event proxy_fcgi setenvif || return 1
    quiet a2enmod http2 || true
    quiet a2enconf php"$PHPV"-fpm || return 1
    quiet apache2ctl configtest
}

# the previous setup: PHP inside Apache (mod_php, prefork)
php_fpm_off() {
    quiet a2disconf php"$PHPV"-fpm || true
    quiet a2dismod http2 || true
    quiet a2dismod mpm_event || true
    quiet a2enmod mpm_prefork || true
    quiet a2enmod php"$PHPV" || true
}

php_fpm_active() { apache2ctl -M 2>/dev/null | grep -q proxy_fcgi_module && apache2ctl -M 2>/dev/null | grep -q mpm_event_module; }

configure_web() {
    step "Web server"
    write_vhost
    quiet a2dissite 000-default || true
    quiet a2ensite madix
    local was_fpm=0
    php_fpm_active && was_fpm=1
    if php_fpm_on; then
        PHP_MODE=fpm
    else
        php_fpm_off
        PHP_MODE=mod_php
        warn "PHP-FPM is not available; PHP stays inside Apache"
    fi
    quiet apache2ctl configtest || die "Apache configuration error. Details: $LOG_FILE"
    svc enable apache2
    if [ "$ACTION" = update ] && svc_active apache2 && { [ "$PHP_MODE" = fpm ] && [ "$was_fpm" = 1 ]; }; then
        svc reload apache2 || svc restart apache2 || die "Apache does not start. Details: $LOG_FILE"
    else
        # changing how PHP runs needs a full restart
        svc restart apache2 || die "Apache does not start. Details: $LOG_FILE"
    fi
    if [ "$PHP_MODE" = fpm ]; then
        ok "Apache serves $APP_DIR/public (PHP-FPM, HTTP/2)"
    else
        ok "Apache serves $APP_DIR/public"
    fi
}

# a page answers through PHP; when it does not with PHP-FPM, go back to PHP inside Apache
page_code() { # page_code PATH → HTTP code
    local host=${DOMAIN:-localhost} url
    url=$(php -r '$c = require $argv[1]; echo $c["app"]["url"] ?? "";' "$APP_DIR/config/config.php" 2>/dev/null)
    if [[ "$url" == https://* ]] && [ -n "$DOMAIN" ]; then
        curl -s -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN$1" || true
    else
        curl -s -o /dev/null -w '%{http_code}' --max-time 20 -H "Host: $host" "http://127.0.0.1$1" || true
    fi
}

verify_php() {
    [ "${PHP_MODE:-}" = fpm ] || return 0
    local code tries
    for tries in 1 2 3; do
        code=$(page_code /login)
        case "$code" in 200|301|302|303) return 0 ;; esac
        sleep 2
    done
    warn "The site answered $code with PHP-FPM; switching back to PHP inside Apache"
    php_fpm_off
    PHP_MODE=mod_php
    svc restart apache2 || die "Apache does not start. Details: $LOG_FILE"
}

configure_cron() {
    cat >/etc/cron.d/madix <<EOF
# MADIX NEXUS scheduled tasks
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * $WEB_USER flock -n $APP_DIR/storage/locks/cron.flock sh -c 'php $APP_DIR/cli/cron.php >/dev/null 2>&1 && touch $APP_DIR/storage/server/cron.beat'
* * * * * root php /usr/local/lib/madix/agent.php tick >/dev/null 2>&1
17 3 * * * root /usr/local/bin/madix backup --quiet --offsite >/dev/null 2>&1
EOF
    chmod 644 /etc/cron.d/madix
    svc enable cron
    svc restart cron || svc start cron || warn "cron did not start."
    ok "Scheduled tasks every minute, backup every night at 03:17"
}

setup_agent() {
    step "Monitoring and router tunnel"
    mkdir -p "$STATE_DIR" "$BACKUP_DIR" /usr/local/lib/madix
    chown root:"$WEB_USER" "$STATE_DIR"
    chmod 750 "$STATE_DIR"
    chmod 700 "$BACKUP_DIR"

    # security updates of the operating system install themselves
    if dpkg -s unattended-upgrades >/dev/null 2>&1 && [ -d /etc/apt/apt.conf.d ]; then
        printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' >/etc/apt/apt.conf.d/20auto-upgrades
    fi

    if [ -d /etc/update-motd.d ]; then
        cat >/etc/update-motd.d/60-madix <<'EOF'
#!/bin/sh
[ -x /usr/local/bin/madix ] || exit 0
echo
/usr/local/bin/madix status 2>/dev/null
echo "Commands: madix help"
EOF
        chmod 755 /etc/update-motd.d/60-madix
    fi

    # requests from the system (backup now, tunnel changes) are applied at once
    if has_systemd; then
        cat >/etc/systemd/system/madix-queue.service <<EOF
[Unit]
Description=MADIX NEXUS requests

[Service]
Type=oneshot
ExecStart=/usr/bin/php /usr/local/lib/madix/agent.php queue
EOF
        cat >/etc/systemd/system/madix-queue.path <<EOF
[Unit]
Description=MADIX NEXUS requests

[Path]
PathChanged=$APP_DIR/storage/server/tunnel.peers
PathChanged=$APP_DIR/storage/server/queue.flag
Unit=madix-queue.service

[Install]
WantedBy=multi-user.target
EOF
        quiet systemctl daemon-reload || true
        quiet systemctl enable madix-queue.path || true
        quiet systemctl restart madix-queue.path || true
    fi

    if command -v wg >/dev/null 2>&1; then
        if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q 'Status: active'; then
            quiet ufw allow 51820/udp || true
        fi
        quiet php /usr/local/lib/madix/agent.php tunnel || true
        if wg show madix >/dev/null 2>&1; then
            ok "Router tunnel ready (UDP port 51820)"
        else
            warn "The router tunnel is not available on this server."
        fi
    fi
    quiet php /usr/local/lib/madix/agent.php tick || true
    ok "Monitoring, self-repair and alerts are active"
}

save_state() {
    mkdir -p "$ETC_DIR"
    chmod 700 "$ETC_DIR"
    cat >"$STATE_FILE" <<EOF
MODE=$MODE
DOMAIN=$DOMAIN
EMAIL=$EMAIL
APP_DIR=$APP_DIR
DB_NAME=${DB_NAME:-}
TENANT_PREFIX=${TENANT_PREFIX:-mnx_}
TIMEZONE=$TIMEZONE
MADIX_VERSION=$MADIX_VERSION
EOF
    chmod 600 "$STATE_FILE"
}

set_app_url() { # set_app_url URL  — config, single network settings and platform accounts
    local url=$1
    MX_URL="$url" php -r '
        $f = $argv[1];
        $c = require $f;
        $c["app"]["url"] = getenv("MX_URL");
        $out = "<?php\n// MADIX NEXUS configuration. Keep it private.\nreturn " . var_export($c, true) . ";\n";
        file_put_contents($f . ".tmp", $out);
        rename($f . ".tmp", $f);
    ' "$APP_DIR/config/config.php"
    chown root:"$WEB_USER" "$APP_DIR/config/config.php"
    chmod 640 "$APP_DIR/config/config.php"
    local db=${DB_NAME:-}
    [ -n "$db" ] || return 0
    if [ "$MODE" = platform ]; then
        mysql_root -N -e "SELECT slug, db_name FROM \`$db\`.tenants" 2>/dev/null | while read -r slug tdb; do
            [[ "$tdb" =~ ^[A-Za-z0-9_]+$ ]] || continue
            mysql_root -e "UPDATE \`$tdb\`.settings SET \`value\` = '$url/$slug' WHERE \`key\` = 'general.panel_url'" 2>/dev/null || true
        done
    else
        mysql_root -e "UPDATE \`$db\`.settings SET \`value\` = '$url' WHERE \`key\` = 'general.panel_url'" 2>/dev/null || true
    fi
}

request_ssl() { # request_ssl → 0 when https is active
    [ -n "$DOMAIN" ] || return 1
    command -v certbot >/dev/null 2>&1 || apt_get install certbot python3-certbot-apache || return 1
    local args=(--apache --non-interactive --agree-tos --redirect -d "$DOMAIN")
    if [ -n "$EMAIL" ]; then args+=(-m "$EMAIL"); else args+=(--register-unsafely-without-email); fi
    if [ "$(printf '%s' "$DOMAIN" | tr -cd '.' | wc -c)" -eq 1 ] && [ "$(resolve_ipv4 "www.$DOMAIN")" = "$(resolve_ipv4 "$DOMAIN")" ]; then
        args+=(-d "www.$DOMAIN")
    fi
    if quiet certbot "${args[@]}"; then
        set_app_url "https://$DOMAIN"
        svc reload apache2 || true
        return 0
    fi
    return 1
}

configure_ssl() {
    [ -n "$DOMAIN" ] || return 0
    if [ "$ACTION" = update ]; then
        return 0
    fi
    step "SSL certificate"
    if [ "$DO_SSL" != 1 ]; then
        warn "Skipped (--no-ssl). Later: madix ssl"
        return 0
    fi
    if [ "${DNS_OK:-0}" != 1 ]; then
        warn "Skipped: the domain does not point to this server yet. When it does: madix ssl"
        return 0
    fi
    if request_ssl; then
        ok "https://$DOMAIN (renews automatically)"
    else
        warn "The certificate was not issued (port 80 closed by the provider, or DNS not ready)."
        warn "The system works on http://$DOMAIN. Later run: madix ssl"
    fi
}

configure_firewall() {
    [ "$DO_FIREWALL" = 1 ] || return 0
    in_container && return 0
    step "Firewall"
    if [ -f /etc/iptables/rules.v4 ] && command -v netfilter-persistent >/dev/null 2>&1 \
        && grep -q -- '-j REJECT' /etc/iptables/rules.v4; then
        # images with their own iptables rules (Oracle Cloud): open 80/443 there instead of enabling ufw
        local port
        for port in 80 443; do
            iptables -C INPUT -p tcp --dport "$port" -m state --state NEW -j ACCEPT 2>/dev/null \
                || iptables -I INPUT 1 -p tcp --dport "$port" -m state --state NEW -j ACCEPT
        done
        iptables -C INPUT -p udp --dport 51820 -j ACCEPT 2>/dev/null || iptables -I INPUT 1 -p udp --dport 51820 -j ACCEPT
        quiet netfilter-persistent save || true
        ok "Ports 80, 443 and 51820/udp opened in the existing firewall"
        warn "Also open ports 80, 443 and UDP 51820 in your provider's network settings (security list)."
        return 0
    fi
    if ! command -v ufw >/dev/null 2>&1; then
        warn "ufw is not installed, skipped."
        return 0
    fi
    local p ports
    ports=$( {
        sshd -T 2>/dev/null | awk '$1=="port"{print $2}'
        ss -tlnpH 2>/dev/null | awk '/sshd/{n=split($4,a,":"); print a[n]}'
        [ -n "${SSH_CONNECTION:-}" ] && printf '%s\n' "${SSH_CONNECTION##* }"
        echo 22
    } | grep -E '^[0-9]+$' | sort -un)
    for p in $ports; do
        quiet ufw allow "$p/tcp" || true
    done
    quiet ufw allow 80/tcp || true
    quiet ufw allow 443/tcp || true
    if command -v wg >/dev/null 2>&1; then
        quiet ufw allow 51820/udp || true
    fi
    if quiet ufw --force enable; then
        ok "Open ports: SSH ($(echo $ports | tr ' ' ',')), 80, 443, 51820/udp"
    else
        warn "The firewall could not be enabled."
    fi

    if command -v fail2ban-client >/dev/null 2>&1; then
        mkdir -p /etc/fail2ban/jail.d
        if [ ! -f /var/log/auth.log ]; then
            apt_get install python3-systemd >/dev/null 2>&1 || true
            printf '[sshd]\nenabled = true\nbackend = systemd\n' >/etc/fail2ban/jail.d/madix.local
        else
            printf '[sshd]\nenabled = true\n' >/etc/fail2ban/jail.d/madix.local
        fi
        svc enable fail2ban
        if svc restart fail2ban; then
            ok "Protection against password guessing (fail2ban)"
        else
            warn "fail2ban did not start."
        fi
    fi
}

set_timezone() {
    if has_systemd && command -v timedatectl >/dev/null 2>&1; then
        quiet timedatectl set-timezone "$TIMEZONE" || true
    elif [ -f "/usr/share/zoneinfo/$TIMEZONE" ]; then
        ln -sf "/usr/share/zoneinfo/$TIMEZONE" /etc/localtime
        echo "$TIMEZONE" >/etc/timezone
    fi
}

backup_before_update() {
    step "Backup before update"
    if [ -x /usr/local/bin/madix ] && /usr/local/bin/madix backup --quiet >>"$LOG_FILE" 2>&1; then
        ok "Saved in $BACKUP_DIR"
    else
        mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
        local f
        f="$BACKUP_DIR/files-before-update-$(date +%Y%m%d-%H%M%S).tar.gz"
        tar -czf "$f" -C "$(dirname "$APP_DIR")" "$(basename "$APP_DIR")" --exclude="$(basename "$APP_DIR")/storage/logs" 2>>"$LOG_FILE" \
            && ok "Files saved in $f" || warn "Backup failed, continuing."
    fi
}

finish_update() {
    step "Database upgrade"
    as_web php "$APP_DIR/cli/cron.php" 25 >>"$LOG_FILE" 2>&1 || warn "The first scheduled run reported a problem; see $LOG_FILE"
    ok "Up to date"
}

health_check() {
    step "Check"
    local host=${DOMAIN:-localhost} code path url
    url=$(php -r '$c = require $argv[1]; echo $c["app"]["url"] ?? "";' "$APP_DIR/config/config.php")
    rm -f "$APP_DIR/storage/maintenance"
    for path in /health / /login /admin/login; do
        if [ "$MODE" = single ] && [ "$path" = /admin/login ]; then
            continue
        fi
        if [[ "$url" == https://* ]] && [ -n "$DOMAIN" ]; then
            code=$(curl -s -o /dev/null -w '%{http_code}' --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN$path" || true)
        else
            code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $host" "http://127.0.0.1$path" || true)
        fi
        case "$code" in
            200|301|302|303) ;;
            *) die "The page $path answered $code. Details: $APP_DIR/storage/logs and /var/log/apache2/madix-error.log" ;;
        esac
    done
    if as_web php "$APP_DIR/cli/cron.php" 15 >>"$LOG_FILE" 2>&1; then
        ok "Pages and scheduled tasks work"
    else
        warn "The scheduled task reported a problem; see $LOG_FILE"
    fi
}

install_tool() {
    [ -f "$STAGE/deploy/vps/madix.sh" ] && [ -f "$STAGE/deploy/vps/agent.php" ] || die "The system files are incomplete."
    mkdir -p /usr/local/lib/madix
    install -m 755 "$STAGE/deploy/vps/madix.sh" /usr/local/bin/madix
    install -m 644 "$STAGE/deploy/vps/agent.php" /usr/local/lib/madix/agent.php
    [ -f "$STAGE/deploy/vps/speed.php" ] && install -m 644 "$STAGE/deploy/vps/speed.php" /usr/local/lib/madix/speed.php
}

maintenance() { # maintenance on|off
    if [ "$1" = on ]; then
        mkdir -p "$APP_DIR/storage" && : >"$APP_DIR/storage/maintenance"
    else
        rm -f "$APP_DIR/storage/maintenance"
    fi
}

summary() {
    local url
    url=$(php -r '$c = require $argv[1]; echo $c["app"]["url"] ?? "";' "$APP_DIR/config/config.php")
    {
        printf '\n%s==============================================================%s\n' "$C_OK" "$C_OFF"
        if [ "$ACTION" = update ]; then
            printf '  MADIX NEXUS updated to %s\n' "$MADIX_VERSION"
        else
            printf '  MADIX NEXUS is ready\n'
        fi
        printf '%s--------------------------------------------------------------%s\n' "$C_OK" "$C_OFF"
        if [ "$MODE" = platform ]; then
            printf '  Landing page : %s/\n' "$url"
            printf '  Admin panel  : %s/admin\n' "$url"
        else
            printf '  System       : %s/\n' "$url"
        fi
        if [ "$ACTION" = install ]; then
            printf '  Username     : %s\n' "$ADMIN_USER"
            if [ "$PASS_GENERATED" = 1 ]; then
                printf '  Password     : %s\n' "$ADMIN_PASS"
            else
                printf '  Password     : the one you entered\n'
            fi
        fi
        printf '  Android app  : server address %s\n' "$url"
        printf '  Tasks        : every minute on this server (no external cron needed)\n'
        printf '  Backups      : every night, %s\n' "$BACKUP_DIR"
        printf '  Commands     : madix doctor | status | backup | restore | ssl | help\n'
        printf '%s==============================================================%s\n' "$C_OK" "$C_OFF"
    } >"${SUMMARY_TTY:-/dev/stdout}"
    if [ "$ACTION" = install ]; then
        {
            echo "MADIX NEXUS — $(date '+%F %T')"
            echo "Address: $url"
            [ "$MODE" = platform ] && echo "Admin panel: $url/admin"
            echo "Username: $ADMIN_USER"
            [ "$PASS_GENERATED" = 1 ] && echo "Password: $ADMIN_PASS"
        } >"$CRED_FILE"
        chmod 600 "$CRED_FILE"
        [ "$PASS_GENERATED" = 1 ] && printf '  (saved in %s)\n' "$CRED_FILE"
    fi
    log "done: $ACTION $MADIX_VERSION"
}

# ---------------------------------------------------------------- main
main() {
    while [ $# -gt 0 ]; do
        case "$1" in
            --domain=*) DOMAIN=${1#*=} ;;
            --email=*) EMAIL=${1#*=} ;;
            --mode=*) MODE=${1#*=} ;;
            --admin-name=*) ADMIN_NAME=${1#*=} ;;
            --admin-user=*) ADMIN_USER=${1#*=} ;;
            --network-name=*) NETWORK_NAME=${1#*=} ;;
            --timezone=*) TIMEZONE=${1#*=} ;;
            --source=*) SOURCE=${1#*=} ;;
            --update) ACTION=update ;;
            --no-ssl) DO_SSL=0 ;;
            --no-firewall) DO_FIREWALL=0 ;;
            --yes|-y) ASSUME_YES=1 ;;
            -h|--help) usage; exit 0 ;;
            *) usage; die "Unknown option: $1" ;;
        esac
        shift
    done
    [ -f "/usr/share/zoneinfo/$TIMEZONE" ] || [ ! -d /usr/share/zoneinfo ] || die "Unknown timezone: $TIMEZONE"
    if [ -n "$SOURCE" ]; then
        SOURCE=$(readlink -f "$SOURCE")
        [ -e "$SOURCE" ] || die "Not found: $SOURCE"
    fi

    preflight "$@"
    trap 'on_error $LINENO "$BASH_COMMAND"' ERR
    trap cleanup EXIT

    printf '%s\n  MADIX NEXUS server installer  %s%s\n' "$C_HEAD" "$MADIX_VERSION" "$C_OFF"
    if has_tty && [ -z "${SUMMARY_TTY:-}" ]; then SUMMARY_TTY=/dev/tty; fi

    STAGE=$(mktemp -d /tmp/madix-stage.XXXXXX)
    if [ -z "$SOURCE" ] || [[ "$SOURCE" != *.zip ]] || command -v unzip >/dev/null 2>&1; then
        extract_payload "$STAGE"
    fi

    detect_action
    if [ "$ACTION" = install ]; then
        collect_inputs
        install_packages
        tune_services
        [ -f "$STAGE/app/bootstrap.php" ] || extract_payload "$STAGE"
        set_timezone
        setup_database
        deploy_files
        save_state
        configure_system
        configure_web
        install_tool
        configure_cron
        configure_ssl
        configure_firewall
        verify_php
        setup_agent
        health_check
    else
        step "Update to $MADIX_VERSION ($MODE)"
        DOMAIN=${DOMAIN:-}
        install_packages
        tune_services
        [ -f "$STAGE/app/bootstrap.php" ] || extract_payload "$STAGE"
        start_database
        backup_before_update
        install_tool
        maintenance on
        deploy_files
        save_state
        configure_web
        configure_cron
        maintenance off
        verify_php
        finish_update
        setup_agent
        health_check
    fi
    summary
}

main "$@"
exit 0
