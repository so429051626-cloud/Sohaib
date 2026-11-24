#!/usr/bin/env bash
# MADIX NEXUS server tool
set -Euo pipefail

STATE_FILE=/etc/madix/madix.env
STATE_DIR=/var/lib/madix
BACKUP_DIR=/var/backups/madix
AGENT=/usr/local/lib/madix/agent.php
KEEP=14
WEB_USER=www-data

[ "$(id -u)" -eq 0 ] || { echo "Run as root: sudo madix $*"; exit 1; }
[ -r "$STATE_FILE" ] || { echo "MADIX NEXUS is not installed."; exit 1; }
# shellcheck disable=SC1090
. "$STATE_FILE"
APP_DIR=${APP_DIR:-/var/www/madix}
MODE=${MODE:-platform}
DB_NAME=${DB_NAME:-}
DOMAIN=${DOMAIN:-}
EMAIL=${EMAIL:-}
TENANT_PREFIX=${TENANT_PREFIX:-mnx_}
MAINT=$APP_DIR/storage/maintenance

if [ -t 1 ]; then G=$'\e[32m'; Y=$'\e[33m'; R=$'\e[31m'; B=$'\e[1m'; N=$'\e[0m'; else G=""; Y=""; R=""; B=""; N=""; fi

as_web() { runuser -u "$WEB_USER" -- "$@"; }
mysql_root() { mysql --protocol=socket -uroot "$@"; }
has_systemd() { [ -d /run/systemd/system ]; }
svc() { if has_systemd; then systemctl "$1" "$2"; else service "$2" "$1"; fi; }
svc_active() { if has_systemd; then systemctl is-active --quiet "$1"; else service "$1" status >/dev/null 2>&1; fi; }
# PHP-FPM service when Apache runs PHP through it (empty when PHP runs inside Apache)
php_service() { local f; for f in /etc/apache2/conf-enabled/php*-fpm.conf; do [ -e "$f" ] && { basename "$f" .conf; return; }; done; }
db_service() { if [ -f /etc/init.d/mariadb ] || [ -f /lib/systemd/system/mariadb.service ] || [ -f /usr/lib/systemd/system/mariadb.service ]; then echo mariadb; else echo mysql; fi; }
app_url() { php -r '$c = require $argv[1]; echo $c["app"]["url"] ?? "";' "$APP_DIR/config/config.php"; }
agent() { [ -f "$AGENT" ] && php "$AGENT" "$@" >/dev/null 2>&1 || true; }
public_ip() { curl -4 -fsS --max-time 8 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}'; }

databases() {
    [ -n "$DB_NAME" ] && echo "$DB_NAME"
    if [ "$MODE" = platform ]; then
        mysql_root -N -e "SHOW DATABASES LIKE '${TENANT_PREFIX//_/\\_}%'" 2>/dev/null | grep -E '^[A-Za-z0-9_]+$' || true
    fi
}

fix_permissions() {
    mkdir -p "$APP_DIR"/storage/{logs,tmp,backups,locks,cache,sessions,imports,tenants,server/requests} "$APP_DIR/public/uploads"
    chown -R "$WEB_USER:$WEB_USER" "$APP_DIR/storage" "$APP_DIR/public/uploads"
    chmod -R u+rwX,g+rwX,o-rwx "$APP_DIR/storage"
    chmod -R u+rwX,g+rX,o+rX "$APP_DIR/public/uploads"
    chown root:"$WEB_USER" "$APP_DIR/config" "$APP_DIR/config/config.php"
    chmod 750 "$APP_DIR/config"
    chmod 640 "$APP_DIR/config/config.php"
}

# the address saved in every account must follow the address of the server
sync_urls() {
    local url slug tdb
    url=$(app_url)
    [ -n "$url" ] && [ -n "$DB_NAME" ] || return 0
    if [ "$MODE" = platform ]; then
        mysql_root -N -e "SELECT slug, db_name FROM \`$DB_NAME\`.tenants" 2>/dev/null | while read -r slug tdb; do
            [[ "$tdb" =~ ^[A-Za-z0-9_]+$ ]] && [[ "$slug" =~ ^[a-z0-9-]+$ ]] || continue
            mysql_root -e "UPDATE \`$tdb\`.settings SET \`value\` = '$url/$slug' WHERE \`key\` = 'general.panel_url'" 2>/dev/null || true
        done
    else
        mysql_root -e "UPDATE \`$DB_NAME\`.settings SET \`value\` = '$url' WHERE \`key\` = 'general.panel_url'" 2>/dev/null || true
    fi
}

set_config() { # set_config KEY.PATH VALUE   (two levels, e.g. app.url)
    MX_KEY="$1" MX_VAL="$2" php -r '
        $f = $argv[1]; $c = require $f; [$a, $b] = explode(".", getenv("MX_KEY"));
        $c[$a][$b] = getenv("MX_VAL");
        file_put_contents($f . ".tmp", "<?php\n// MADIX NEXUS configuration. Keep it private.\nreturn " . var_export($c, true) . ";\n");
        rename($f . ".tmp", $f);' "$APP_DIR/config/config.php"
    chown root:"$WEB_USER" "$APP_DIR/config/config.php"
    chmod 640 "$APP_DIR/config/config.php"
}

# ------------------------------------------------------------------ backup

cmd_backup() {
    local quiet=0 offsite=0 a
    for a in "$@"; do
        case "$a" in --quiet) quiet=1 ;; --offsite) offsite=1 ;; esac
    done
    exec 8>/run/madix-backup.lock
    flock -n 8 || { [ "$quiet" = 1 ] || echo "A backup is already running."; return 0; }

    local ts dir db
    mkdir -p "$BACKUP_DIR"
    ts=$(date +%Y%m%d-%H%M%S)
    dir=$BACKUP_DIR/$ts
    # never write into an existing backup (two backups in the same second)
    local try=0
    while ! mkdir "$dir" 2>/dev/null; do
        try=$((try + 1))
        [ -d "$dir" ] && [ "$try" -le 5 ] || { echo "Cannot write to $BACKUP_DIR."; return 1; }
        sleep 1
        ts=$(date +%Y%m%d-%H%M%S)
        dir=$BACKUP_DIR/$ts
    done
    chmod 700 "$BACKUP_DIR" "$dir"
    for db in $(databases); do
        if ! mysqldump --protocol=socket -uroot --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 "$db" | gzip >"$dir/$db.sql.gz"; then
            rm -rf "$dir"
            echo "Backup of $db failed."
            return 1
        fi
    done
    tar -czf "$dir/files.tar.gz" -C "$APP_DIR" \
        --exclude=storage/logs --exclude=storage/sessions --exclude=storage/cache --exclude=storage/backups \
        --exclude=storage/tmp --exclude=storage/locks --exclude=storage/maintenance --exclude=storage/server/requests \
        --exclude=storage/server/cron.beat --exclude=storage/server/queue.flag \
        config/config.php public/uploads storage 2>/dev/null || true
    local extra=(etc/madix/madix.env)
    [ -f /etc/wireguard/madix.conf ] && extra+=(etc/wireguard/madix.conf)
    tar -czf "$dir/server.tar.gz" -C / "${extra[@]}" 2>/dev/null || true
    {
        echo "version=${MADIX_VERSION:-}"
        echo "mode=$MODE"
        echo "domain=$DOMAIN"
        echo "date=$(date '+%F %T')"
        echo "databases=$(databases | tr '\n' ' ')"
    } >"$dir/manifest"
    chmod 600 "$dir"/*

    # old backups go, except the one being restored right now
    local old
    while IFS= read -r old; do
        [ -n "${RESTORE_SRC:-}" ] && [ "$(readlink -f "$old")" = "$RESTORE_SRC" ] && continue
        rm -rf "$old"
    done < <(ls -1dt "$BACKUP_DIR"/20*/ 2>/dev/null | tail -n +$((KEEP + 1)))
    find "$APP_DIR/storage/logs" -type f -name '*.log' -mtime +30 -delete 2>/dev/null || true
    find "$APP_DIR/storage/tmp" -type f -mtime +2 -delete 2>/dev/null || true

    if [ "$offsite" = 1 ] && as_web php "$APP_DIR/cli/server.php" offsite-enabled >/dev/null 2>&1; then
        local out
        mkdir -p "$STATE_DIR/outbox"
        chown root:"$WEB_USER" "$STATE_DIR/outbox"
        chmod 750 "$STATE_DIR/outbox"
        rm -f "$STATE_DIR"/outbox/*.tar
        out="$STATE_DIR/outbox/madix-$ts.tar"
        if tar -cf "$out" -C "$dir" . && chown root:"$WEB_USER" "$out" && chmod 640 "$out"; then
            if as_web php "$APP_DIR/cli/server.php" offsite "$out" >/dev/null 2>&1; then
                [ "$quiet" = 1 ] || echo "Off-site copy sent."
            else
                [ "$quiet" = 1 ] || echo "${Y}Off-site copy was not sent (see the Server page).${N}"
            fi
        fi
        rm -f "$out"
    fi
    agent collect
    [ "$quiet" = 1 ] || { echo "Backup saved: $dir"; du -sh "$dir" | awk '{print "Size: " $1}'; }
}

# ------------------------------------------------------------------ restore

cmd_restore() {
    local arg=${1:-} src="" tmp="" pass="" f db n
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"
    if [ -z "$arg" ]; then
        local list=()
        while IFS= read -r f; do list+=("$(basename "$f")"); done < <(ls -1dt "$BACKUP_DIR"/20*/ 2>/dev/null)
        [ "${#list[@]}" -gt 0 ] || { echo "No backups in $BACKUP_DIR."; return 1; }
        echo "Backups on this server:"
        n=1
        for f in "${list[@]}"; do
            printf '  %2d) %s  %s\n' "$n" "$f" "$(du -sh "$BACKUP_DIR/$f" | awk '{print $1}')"
            n=$((n + 1))
        done
        read -r -p "Number to restore (Enter = cancel): " n
        [[ "$n" =~ ^[0-9]+$ ]] && [ "$n" -ge 1 ] && [ "$n" -le "${#list[@]}" ] || { echo "Cancelled."; return 0; }
        arg=${list[$((n - 1))]}
    fi

    if [ -d "$BACKUP_DIR/$arg" ] && [[ "$arg" =~ ^[0-9-]+$ ]]; then
        src=$BACKUP_DIR/$arg
    elif [ -d "$arg" ]; then
        src=$(readlink -f "$arg")
    elif [ -f "$arg" ]; then
        tmp=$(mktemp -d "$BACKUP_DIR/.restore.XXXXXX")
        chmod 700 "$tmp"
        local base=$arg
        [[ "$arg" =~ \.[0-9]{3}$ ]] && base=${arg%.*}
        local parts=("$arg")
        if ls "$base".[0-9][0-9][0-9] >/dev/null 2>&1; then
            parts=()
            while IFS= read -r f; do parts+=("$f"); done < <(ls -1 "$base".[0-9][0-9][0-9] | sort)
        fi
        if [[ "$base" == *.enc ]]; then
            read -r -s -p "Backup password: " pass
            echo
            if ! cat "${parts[@]}" | MX_PASS="$pass" openssl enc -d -aes-256-cbc -pbkdf2 -md sha256 -pass env:MX_PASS 2>/dev/null | tar -xf - -C "$tmp" 2>/dev/null; then
                rm -rf "$tmp"
                echo "${R}Wrong password or damaged file.${N}"
                return 1
            fi
        else
            cat "${parts[@]}" | tar -xf - -C "$tmp" 2>/dev/null || { rm -rf "$tmp"; echo "${R}Cannot read this file.${N}"; return 1; }
        fi
        src=$tmp
    else
        echo "Not found: $arg"
        return 1
    fi
    [ -f "$src/manifest" ] && ls "$src"/*.sql.gz >/dev/null 2>&1 || { [ -n "$tmp" ] && rm -rf "$tmp"; echo "${R}This is not a MADIX NEXUS server backup.${N}"; return 1; }

    local b_mode b_date b_domain
    b_mode=$(sed -n 's/^mode=//p' "$src/manifest")
    b_date=$(sed -n 's/^date=//p' "$src/manifest")
    b_domain=$(sed -n 's/^domain=//p' "$src/manifest")
    echo
    echo "  Backup date : $b_date"
    echo "  Mode        : $b_mode"
    echo "  Domain      : ${b_domain:-none}"
    echo "  Databases   : $(ls "$src"/*.sql.gz | wc -l)"
    if [ "$b_mode" != "$MODE" ]; then
        [ -n "$tmp" ] && rm -rf "$tmp"
        echo "${R}This backup is from a '$b_mode' installation, this server is '$MODE'.${N}"
        return 1
    fi
    echo
    echo "${Y}All current data on this server will be replaced by this backup.${N}"
    read -r -p "Type yes to continue: " n
    [ "$n" = yes ] || { [ -n "$tmp" ] && rm -rf "$tmp"; echo "Cancelled."; return 0; }

    echo "==> Saving the current state first"
    RESTORE_SRC=$(readlink -f "$src")
    cmd_backup --quiet || echo "${Y}Could not save the current state.${N}"
    RESTORE_SRC=""
    ls "$src"/*.sql.gz >/dev/null 2>&1 || { [ -n "$tmp" ] && rm -rf "$tmp"; echo "${R}The backup is no longer readable. Nothing was changed.${N}"; return 1; }

    : >"$MAINT"
    echo "==> Databases"
    for f in "$src"/*.sql.gz; do
        db=$(basename "$f" .sql.gz)
        [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || continue
        if [ "$db" != "$DB_NAME" ] && [[ "$db" != "$TENANT_PREFIX"* ]]; then
            echo "    skipped $db"
            continue
        fi
        mysql_root -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        if gunzip -c "$f" | mysql_root --default-character-set=utf8mb4 "$db"; then
            echo "    $db"
        else
            rm -f "$MAINT"
            [ -n "$tmp" ] && rm -rf "$tmp"
            echo "${R}Restoring $db failed. The state saved a moment ago is in $BACKUP_DIR.${N}"
            return 1
        fi
    done

    echo "==> Files"
    if [ -f "$src/files.tar.gz" ]; then
        local stage
        stage=$(mktemp -d "$BACKUP_DIR/.files.XXXXXX")
        tar -xzf "$src/files.tar.gz" -C "$stage"
        if [ -f "$stage/config/config.php" ]; then
            # keep this server's database login and address; take the encryption keys of the backup
            # (the file from the backup is read as text, never executed)
            php -r '
                $cur = require $argv[1]; $old = (string) file_get_contents($argv[2]);
                if (preg_match("/\x27key\x27\s*=>\s*\x27(base64:[A-Za-z0-9+\/=]{40,64})\x27/", $old, $m)) { $cur["app"]["key"] = $m[1]; }
                if (preg_match("/\x27cron_key\x27\s*=>\s*\x27([A-Za-z0-9_-]{8,80})\x27/", $old, $m)) { $cur["app"]["cron_key"] = $m[1]; }
                file_put_contents($argv[1] . ".tmp", "<?php\n// MADIX NEXUS configuration. Keep it private.\nreturn " . var_export($cur, true) . ";\n");
                rename($argv[1] . ".tmp", $argv[1]);' "$APP_DIR/config/config.php" "$stage/config/config.php"
        fi
        [ -d "$stage/public/uploads" ] && cp -a "$stage/public/uploads/." "$APP_DIR/public/uploads/"
        rm -f "$stage/storage/server/cron.beat" "$stage/storage/server/queue.flag"
        [ -d "$stage/storage" ] && cp -a "$stage/storage/." "$APP_DIR/storage/"
        rm -rf "$stage"
    fi
    if [ -f "$src/server.tar.gz" ] && tar -tzf "$src/server.tar.gz" 2>/dev/null | grep -qx 'etc/wireguard/madix.conf'; then
        mkdir -p /etc/wireguard
        tar -xzf "$src/server.tar.gz" -C / etc/wireguard/madix.conf
        chmod 600 /etc/wireguard/madix.conf
    fi
    [ -n "$tmp" ] && rm -rf "$tmp"
    : >"$MAINT"
    fix_permissions
    sync_urls
    as_web php "$APP_DIR/cli/server.php" tunnel-export >/dev/null 2>&1 || true
    rm -f "$MAINT"
    svc reload apache2 >/dev/null 2>&1 || true
    as_web php "$APP_DIR/cli/cron.php" 20 >/dev/null 2>&1 || true
    agent tunnel
    echo "${G}Restored.${N} Address: $(app_url)"
}

# ------------------------------------------------------------------ status

cmd_status() {
    local s url last
    url=$(app_url)
    echo "${B}MADIX NEXUS${N} ${MADIX_VERSION:-}  ($MODE)"
    echo "Address : $url"
    [ "$MODE" = platform ] && echo "Admin   : $url/admin"
    for s in apache2 $(php_service) "$(db_service)" cron; do
        if svc_active "$s"; then printf '%-8s: %srunning%s\n' "$s" "$G" "$N"; else printf '%-8s: %sstopped%s\n' "$s" "$R" "$N"; fi
    done
    if [ -n "$DOMAIN" ] && [ -f "/etc/letsencrypt/live/$DOMAIN/cert.pem" ]; then
        echo "SSL     : until $(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/$DOMAIN/cert.pem" | cut -d= -f2)"
    fi
    echo "Disk    : $(df -h / | awk 'NR==2{print $4 " free of " $2}')"
    echo "Memory  : $(free -m | awk '/Mem:/{print $7 " MB free of " $2 " MB"}')"
    last=$(ls -1dt "$BACKUP_DIR"/20*/ 2>/dev/null | head -n1)
    echo "Backup  : ${last:-none yet}"
}

cmd_doctor() {
    local fails=0 warns=0 code url host age v
    ok()   { printf '  %s[ OK ]%s %s\n' "$G" "$N" "$1"; }
    bad()  { printf '  %s[FAIL]%s %s\n' "$R" "$N" "$1"; [ -n "${2:-}" ] && printf '         %s\n' "$2"; fails=$((fails + 1)); }
    note() { printf '  %s[WARN]%s %s\n' "$Y" "$N" "$1"; [ -n "${2:-}" ] && printf '         %s\n' "$2"; warns=$((warns + 1)); }

    echo "${B}MADIX NEXUS check${N}  ${MADIX_VERSION:-}"
    if svc_active apache2; then ok "Web server"; else bad "Web server is stopped" "madix restart"; fi
    v=$(php_service)
    if [ -n "$v" ]; then
        if svc_active "$v"; then ok "PHP ($v, HTTP/2)"; else bad "PHP ($v) is stopped" "madix restart"; fi
    fi
    if svc_active "$(db_service)"; then ok "Database server"; else bad "Database server is stopped" "madix restart"; fi
    if svc_active cron; then ok "Scheduler"; else bad "Scheduler (cron) is stopped" "systemctl start cron"; fi

    if as_web php -r 'require $argv[1] . "/app/bootstrap.php"; App\Core\DB::value("SELECT 1");' "$APP_DIR" >/dev/null 2>&1; then
        ok "System connects to its database"
    else
        bad "System cannot connect to its database" "madix logs"
    fi
    if as_web test -w "$APP_DIR/storage" && as_web test -w "$APP_DIR/public/uploads"; then ok "Storage is writable"; else bad "Storage is not writable" "madix repair"; fi

    url=$(app_url)
    host=${DOMAIN:-localhost}
    if [[ "$url" == https://* ]] && [ -n "$DOMAIN" ]; then
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/health" || true)
    else
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -H "Host: $host" http://127.0.0.1/health || true)
    fi
    if [ "$code" = 200 ]; then ok "Site answers ($url)"; else bad "Site does not answer (code $code)" "madix logs"; fi

    if [ -f "$APP_DIR/storage/server/cron.beat" ]; then
        age=$(( $(date +%s) - $(stat -c %Y "$APP_DIR/storage/server/cron.beat") ))
        if [ "$age" -lt 300 ]; then ok "Scheduled tasks ran ${age}s ago"; else bad "Scheduled tasks last ran $((age / 60)) min ago" "madix logs"; fi
    else
        note "Scheduled tasks have not run yet"
    fi

    if [ -n "$DOMAIN" ]; then
        local ip dns days
        ip=$(public_ip)
        dns=$(getent ahostsv4 "$DOMAIN" | awk '{print $1; exit}')
        if [ -n "$dns" ] && [ "$dns" = "$ip" ]; then ok "Domain $DOMAIN points to this server"; else bad "Domain $DOMAIN points to ${dns:-nothing} (server: $ip)" "Fix the A record at your DNS provider"; fi
        if [ -f "/etc/letsencrypt/live/$DOMAIN/cert.pem" ]; then
            days=$(( ( $(date -d "$(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/$DOMAIN/cert.pem" | cut -d= -f2)" +%s) - $(date +%s) ) / 86400 ))
            if [ "$days" -gt 14 ]; then ok "SSL certificate valid for $days days"; else note "SSL certificate expires in $days days" "certbot renew"; fi
        else
            note "No SSL certificate (site is on http)" "madix ssl"
        fi
    else
        note "No domain set (site is on the IP address)" "madix ssl your-domain"
    fi

    if command -v ufw >/dev/null 2>&1; then
        if ufw status 2>/dev/null | grep -q 'Status: active'; then ok "Firewall"; else note "Firewall is off" "ufw enable"; fi
    fi
    v=$(df -P / | awk 'NR==2{gsub("%","",$5); print $5}')
    if [ "$v" -lt 85 ]; then ok "Disk ${v}% used"; elif [ "$v" -lt 95 ]; then note "Disk ${v}% used"; else bad "Disk ${v}% used" "Free some space"; fi
    v=$(free -m | awk '/Mem:/{print $7}')
    if [ "$v" -gt 150 ]; then ok "Memory ${v} MB available"; else note "Memory low: ${v} MB available"; fi
    v=$(ls -1dt "$BACKUP_DIR"/20*/ 2>/dev/null | head -n1)
    if [ -n "$v" ] && [ $(( $(date +%s) - $(stat -c %Y "$v") )) -lt 129600 ]; then ok "Backup: $(basename "$v")"; else note "No backup in the last 36 hours" "madix backup"; fi
    if command -v wg >/dev/null 2>&1; then
        if wg show madix >/dev/null 2>&1; then ok "Router tunnel ($(wg show madix peers | wc -l) routers)"; else note "Router tunnel is not running" "systemctl restart wg-quick@madix"; fi
    fi
    if [ -f /var/run/reboot-required ]; then note "The server needs a restart to finish system updates" "reboot"; fi
    echo
    if [ "$fails" -eq 0 ]; then echo "${G}Everything works.${N} ($warns notes)"; else echo "${R}$fails problem(s) found.${N}"; fi
    return "$fails"
}

# ------------------------------------------------------------------ ssl, password, tunnel

cmd_ssl() {
    local d=${1:-$DOMAIN} e=${2:-$EMAIL} ip dns
    if [ -z "$d" ]; then read -r -p "Domain: " d; fi
    d=$(printf '%s' "$d" | tr 'A-Z' 'a-z' | sed -E 's#^[a-z]+://##; s#[/?#].*$##')
    [[ "$d" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$ ]] || { echo "Invalid domain."; return 1; }
    ip=$(public_ip)
    dns=$(getent ahostsv4 "$d" | awk '{print $1; exit}')
    if [ -z "$dns" ] || { [ -n "$ip" ] && [ "$dns" != "$ip" ]; }; then
        echo "$d points to ${dns:-nothing}, this server is ${ip:-unknown}. Fix the A record first."
        return 1
    fi
    command -v certbot >/dev/null 2>&1 || DEBIAN_FRONTEND=noninteractive apt-get install -y -q certbot python3-certbot-apache >/dev/null 2>&1
    sed -i -E "s#^([[:space:]]*ServerName[[:space:]]).*#\1$d#" /etc/apache2/sites-available/madix.conf
    apache2ctl configtest >/dev/null 2>&1 && svc reload apache2 >/dev/null 2>&1 || true
    local args=(--apache --non-interactive --agree-tos --redirect -d "$d")
    if [ -n "$e" ]; then args+=(-m "$e"); else args+=(--register-unsafely-without-email); fi
    certbot "${args[@]}" || { echo "${R}The certificate was not issued.${N}"; return 1; }
    set_config app.url "https://$d"
    sed -i -E "s#^DOMAIN=.*#DOMAIN=$d#" "$STATE_FILE"
    if [ -n "$e" ]; then
        if grep -q '^EMAIL=' "$STATE_FILE"; then sed -i -E "s#^EMAIL=.*#EMAIL=$e#" "$STATE_FILE"; else echo "EMAIL=$e" >>"$STATE_FILE"; fi
    fi
    DOMAIN=$d
    sync_urls
    svc reload apache2 >/dev/null 2>&1 || true
    agent tunnel
    echo "${G}Ready:${N} https://$d"
}

cmd_password() {
    local u=${1:-} p1 p2
    [ -n "$u" ] || read -r -p "Username: " u
    read -r -s -p "New password (8+ characters): " p1; echo
    read -r -s -p "Repeat: " p2; echo
    [ "$p1" = "$p2" ] || { echo "The passwords do not match."; return 1; }
    [ "${#p1}" -ge 8 ] || { echo "Use at least 8 characters."; return 1; }
    if [ "$MODE" = platform ]; then
        as_web php "$APP_DIR/cli/platform.php" admin --username="$u" --password="$p1"
    else
        MX_U="$u" MX_P="$p1" as_web env MX_U="$u" MX_P="$p1" php -r '
            require $argv[1] . "/app/bootstrap.php";
            $n = App\Core\DB::query("UPDATE employees SET password_hash = ?, session_version = session_version + 1 WHERE username = ?",
                [password_hash(getenv("MX_P"), PASSWORD_DEFAULT), getenv("MX_U")])->rowCount();
            echo $n ? "Password changed.\n" : "User not found.\n";
            exit($n ? 0 : 1);' "$APP_DIR"
    fi
}

cmd_import() {
    local slug=${1:-} file=${2:-} config=${3:-} tmp rc
    [ "$MODE" = platform ] || { echo "Accounts exist in platform mode only."; return 1; }
    if [ -z "$slug" ] || [ ! -f "$file" ]; then
        echo "Usage: madix import ACCOUNT-NAME BACKUP.sql.gz [OLD-config.php]"
        return 1
    fi
    tmp=$(mktemp -d /tmp/madix-import.XXXXXX)
    cp "$file" "$tmp/backup.sql.gz"
    local args=(--slug="$slug" --file="$tmp/backup.sql.gz")
    if [ -n "$config" ]; then
        [ -f "$config" ] || { rm -rf "$tmp"; echo "Not found: $config"; return 1; }
        cp "$config" "$tmp/config.php"
        args+=(--config="$tmp/config.php")
    fi
    chown -R "$WEB_USER" "$tmp"
    chmod 700 "$tmp"
    rc=0
    as_web php "$APP_DIR/cli/platform.php" import "${args[@]}" || rc=$?
    rm -rf "$tmp"
    return "$rc"
}

cmd_tunnel() {
    command -v wg >/dev/null 2>&1 || { echo "The router tunnel is not installed on this server."; return 1; }
    if ! wg show madix >/dev/null 2>&1; then
        agent tunnel
        wg show madix >/dev/null 2>&1 || { echo "${R}The router tunnel does not start on this server.${N}"; return 1; }
    fi
    local now k hs
    now=$(date +%s)
    echo "Router tunnel: running, UDP port 51820, $(wg show madix peers | wc -l) routers"
    wg show madix latest-handshakes | while read -r k hs; do
        if [ "$hs" = 0 ]; then printf '  %s  never connected\n' "${k:0:12}"; else printf '  %s  seen %ss ago\n' "${k:0:12}" "$((now - hs))"; fi
    done
    echo "Routers are added from the system: Routers > Secure tunnels."
}

cmd_logs() {
    local f
    f=$(ls -1t "$APP_DIR"/storage/logs/*.log 2>/dev/null | head -n1)
    echo "== /var/log/apache2/madix-error.log"
    tail -n 30 /var/log/apache2/madix-error.log 2>/dev/null || true
    [ -n "$f" ] && { echo; echo "== $f"; tail -n 40 "$f"; }
}

cmd_update() {
    local src=${1:-} file
    [ -n "$src" ] || { echo "Usage: madix update FILE-or-LINK"; return 1; }
    if [[ "$src" =~ ^https?:// ]]; then
        file=/root/madix-nexus-vps.sh
        echo "Downloading..."
        curl -fL --max-time 600 -o "$file.new" "$src" || { rm -f "$file.new"; echo "${R}Download failed.${N}"; return 1; }
        mv -f "$file.new" "$file"
    else
        file=$src
        [ -f "$file" ] || { echo "Not found: $file"; return 1; }
    fi
    exec bash "$file" --update
}

case "${1:-help}" in
    status)   cmd_status ;;
    doctor|check) cmd_doctor ;;
    backup)   shift; cmd_backup "$@" ;;
    restore)  cmd_restore "${2:-}" ;;
    import)   cmd_import "${2:-}" "${3:-}" "${4:-}" ;;
    ssl)      cmd_ssl "${2:-}" "${3:-}" ;;
    password) cmd_password "${2:-}" ;;
    tunnel|vpn) cmd_tunnel ;;
    logs)     cmd_logs ;;
    repair)   fix_permissions; rm -f "$MAINT"; svc reload apache2 >/dev/null 2>&1 || true; agent tick; echo "Done." ;;
    restart)  svc restart "$(db_service)"; v=$(php_service); [ -n "$v" ] && svc restart "$v"; svc restart apache2; echo "Restarted." ;;
    update)   cmd_update "${2:-}" ;;
    speed)    php /usr/local/lib/madix/speed.php "${2:-24}" "$APP_DIR" ;;
    *)
        cat <<'EOF'
madix status                 address, services, disk, last backup
madix doctor                 full check with a fix for every problem
madix backup                 back up everything now (automatic every night)
madix restore [BACKUP]       restore a backup from this server or from a backup file
madix import NAME FILE [CFG] create an account from a backup of a previous system
madix ssl [domain] [email]   free SSL certificate (https)
madix password [username]    change an administrator password
madix tunnel                 state of the router tunnel
madix logs                   recent errors
madix repair                 fix file permissions and stuck states
madix restart                restart the web and database servers
madix update FILE-or-LINK    update with a new installer file
madix speed [HOURS]          how long the server takes to build each page (default: last 24 hours)
EOF
        ;;
esac
