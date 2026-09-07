#!/usr/bin/env bash
# Prepare an UNUSED cache service on the selected Redis host. Run as root.
# Required arguments (replace every placeholder; never pass a password):
#   --bind-ip PRIVATE_REDIS_IP --web-ip PRIVATE_WEB_IP
#   --maxmemory-mib MEMORY_MIB --secret-file /etc/gex-cache/password
# The secret directory must be new and directly below /etc. The generated
# password is readable by root and the existing forge group, never printed.
# Does not change application env, existing Redis services, or existing data.
# RDB/noeviction is conservative because the default cache also has nonpayload
# state. This is NOT a crash-durability or arbitrary-eviction guarantee.
set +x
set -Eeuo pipefail
set -o noclobber
export LC_ALL=C
umask 077

fail() { printf 'STOP: %s\n' "$1" >&2; exit 1; }
[[ $EUID -eq 0 ]] || fail 'Run this preparation script as root.'
bind_ip=''
web_ip=''
memory_mib=''
secret=''
while (( $# )); do
    (( $# >= 2 )) || fail 'Each option requires a value.'
    case "$1" in
        --bind-ip) [[ -z "$bind_ip" ]] || fail 'Duplicate bind option.'; bind_ip=$2 ;;
        --web-ip) [[ -z "$web_ip" ]] || fail 'Duplicate web option.'; web_ip=$2 ;;
        --maxmemory-mib) [[ -z "$memory_mib" ]] || fail 'Duplicate memory option.'; memory_mib=$2 ;;
        --secret-file) [[ -z "$secret" ]] || fail 'Duplicate secret option.'; secret=$2 ;;
        *) fail 'Unknown option. Only the four documented options are accepted.' ;;
    esac
    shift 2
done

private_ipv4() {
    local a b c d part
    [[ "$1" =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]] || return 1
    IFS=. read -r a b c d <<< "$1"
    for part in "$a" "$b" "$c" "$d"; do
        [[ "$part" == 0 || "$part" != 0* ]] || return 1
        (( 10#$part <= 255 )) || return 1
    done
    (( 10#$a == 10 || (10#$a == 172 && 10#$b >= 16 && 10#$b <= 31) || (10#$a == 192 && 10#$b == 168) ))
}
private_ipv4 "$bind_ip" || fail 'The bind address must be an explicit RFC1918 IPv4 address.'
private_ipv4 "$web_ip" || fail 'The allowed web address must be an explicit RFC1918 IPv4 address.'
[[ "$bind_ip" != "$web_ip" ]] || fail 'Web and Redis private addresses must differ.'
[[ "$memory_mib" =~ ^[1-9][0-9]{1,4}$ ]] || fail 'Provide a numeric memory cap in MiB.'
(( memory_mib >= 64 && memory_mib <= 32768 )) || fail 'Memory cap must be between 64 and 32768 MiB.'
[[ "$secret" =~ ^/etc/[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}/password$ ]] \
    || fail 'Use a new, dedicated /etc/DIRECTORY/password secret path.'

for command in ip ss systemctl redis-cli redis-server openssl ufw awk stat df dirname mkdir chown chmod tail tr id getent sleep; do
    command -v "$command" >/dev/null || fail "Required command is missing: $command"
done
id redis >/dev/null 2>&1 || fail 'The redis service user is missing.'
getent group forge >/dev/null || fail 'The forge application group is missing.'
[[ ! -L /etc && $(stat -c %U /etc) == root ]] || fail 'The /etc directory requires review.'
mode=$(stat -c %a /etc)
(( (8#$mode & 0022) == 0 )) || fail 'The /etc directory is writable by a non-root account.'
private_interface=$(ip -4 -o address show | awk -v wanted="$bind_ip" '
    {split($4,address,"/"); if (address[1] == wanted) print $2}')
[[ "$private_interface" =~ ^[a-zA-Z0-9_.-]+$ ]] || fail 'Exactly one local interface must own the private bind address.'
ufw status | awk '/^Status: active$/ {found=1} END {exit !found}' \
    || fail 'UFW must already be active; this script will not enable or reset it.'
ufw status numbered | awk '/(^|[^0-9])6381([^0-9]|$)/ {found=1} END {exit found}' \
    || fail 'Cache-port firewall rules already exist and require review.'

service=redis-server@cache.service
config=/etc/redis/redis-cache.conf
data=/var/lib/redis/gex-cache
log=/var/log/redis/redis-server-cache.log
secret_directory=$(dirname "$secret")
listeners=$(ss -H -ltn 'sport = :6381') || fail 'Could not inspect the cache port.'
[[ -z "$listeners" ]] || fail 'Port 6381 is already in use; nothing will be replaced.'
fragment=$(systemctl show "$service" -p FragmentPath --value)
case "$fragment" in
    /lib/systemd/system/redis-server@.service|/usr/lib/systemd/system/redis-server@.service) ;;
    *) fail 'The installed distribution Redis service template is missing or overridden.' ;;
esac
[[ $(stat -c %U "$fragment") == root ]] || fail 'The Redis template is not root-owned.'
mode=$(stat -c %a "$fragment")
(( (8#$mode & 0022) == 0 )) || fail 'The Redis template is writable by a non-root account.'
[[ -z $(systemctl show "$service" -p DropInPaths --value) ]] || fail 'The cache service has overrides that require review.'
[[ $(systemctl show "$service" -p ActiveState --value) == inactive ]] || fail 'The cache service is not unused/inactive.'
if systemctl is-enabled --quiet "$service"; then fail 'The cache service is already enabled.'; fi
[[ $(systemctl show "$service" -p User --value) == redis ]] || fail 'Unexpected Redis service user.'
[[ $(systemctl show "$service" -p Group --value) == redis ]] || fail 'Unexpected Redis service group.'
[[ $(systemctl show "$service" -p RuntimeDirectory --value) == redis-cache ]] \
    || fail 'The template does not provide the expected isolated runtime directory.'
exec_start=$(systemctl show "$service" -p ExecStart --value)
[[ "$exec_start" == *"path=/usr/bin/redis-server ; argv[]=/usr/bin/redis-server $config "* ]] \
    || fail 'The template does not start the expected binary and cache-only config.'

for path in "$config" "$data" "$log" "$secret_directory" /run/redis-cache; do
    [[ ! -e "$path" && ! -L "$path" ]] || fail "Path already exists; nothing will be overwritten: $path"
done
for path in /etc/redis /var/lib/redis /var/log/redis; do
    [[ -d "$path" && ! -L "$path" ]] || fail 'A Redis parent directory is missing or a symlink.'
done
required_kib=$(( (memory_mib * 2 + 256) * 1024 ))
[[ $(df -Pk /var/lib/redis | awk 'END {print $4}') -ge $required_kib ]] \
    || fail 'Insufficient free disk for cache snapshots and rewrite headroom.'
[[ $(awk '/^MemAvailable:/ {print $2}' /proc/meminfo) -ge $required_kib ]] \
    || fail 'Insufficient available host memory for the requested cap and headroom.'

# Stop only the new instance after any failure. Preserve files for diagnosis;
# never remove data or alter existing Redis, queue workers, or app env.
started=0
cleanup_failure() {
    local status=$?
    if [[ $status -ne 0 ]]; then
        if [[ $started -eq 1 ]]; then systemctl stop "$service" || true; fi
        printf '%s\n' 'Preparation failed. Existing Redis services and application settings were not changed.' >&2
        printf '%s\n' 'New files or cache-port firewall rules may remain. Do not rerun blindly.' >&2
        printf '%s\n' 'Share error text only, never Redis configuration or credential files.' >&2
    fi
}
trap cleanup_failure EXIT

password=$(openssl rand -hex 32)
[[ "$password" =~ ^[a-f0-9]{64}$ ]] || fail 'Password generation failed.'
# mkdir fails if the path appeared after preflight. noclobber also protects
# every newly created file. No privileged write targets a user-owned home.
mkdir -m 0750 -- "$secret_directory"
chown root:forge "$secret_directory"
printf '%s\n' "$password" > "$secret"
chown root:forge "$secret"
chmod 0640 "$secret"
mkdir -m 0750 -- "$data"
chown redis:redis "$data"
: > "$log"
chown redis:redis "$log"
chmod 0640 "$log"
printf '%s\n' \
    "bind 127.0.0.1 $bind_ip" \
    'protected-mode yes' \
    'port 6381' \
    'daemonize no' \
    'supervised systemd' \
    'pidfile /run/redis-cache/redis-server.pid' \
    'loglevel notice' \
    "logfile $log" \
    "dir $data" \
    'databases 16' \
    "requirepass $password" \
    "maxmemory $memory_mib"'mb' \
    'maxmemory-policy noeviction' \
    'appendonly no' \
    'save 900 1' \
    'save 300 100' \
    'save 60 10000' \
    'dbfilename dump-cache.rdb' \
    'stop-writes-on-bgsave-error yes' \
    'timeout 0' \
    'tcp-keepalive 300' > "$config"
chown root:redis "$config"
chmod 0640 "$config"

# Add only cache-port rules. Deny is inserted first so even existing broad
# private allow rules cannot open this port to other hosts. Allow then becomes
# rule 1, above the new deny. Neither operation changes SSH or other ports.
ufw insert 1 deny in on "$private_interface" proto tcp from any to "$bind_ip" port 6381 \
    comment 'GEX-026 private cache deny other hosts'
ufw insert 1 allow in on "$private_interface" proto tcp from "$web_ip" to "$bind_ip" port 6381 \
    comment 'GEX-026 private cache web access'

redis_cache() {
    REDISCLI_AUTH="$password" redis-cli --no-auth-warning --raw -h 127.0.0.1 -p 6381 "$@"
}
wait_for_cache() {
    for attempt in {1..20}; do
        if [[ $(redis_cache PING 2>/dev/null) == PONG ]]; then return 0; fi
        sleep 0.5
    done
    fail 'New cache Redis did not respond to authenticated PING.'
}
started=1
systemctl start "$service"
wait_for_cache
[[ $(redis_cache CONFIG GET maxmemory-policy | tail -n 1) == noeviction ]] || fail 'Unexpected cache eviction policy.'
[[ $(redis_cache CONFIG GET maxmemory | tail -n 1) == $(( memory_mib * 1024 * 1024 )) ]] || fail 'Unexpected cache memory cap.'
[[ $(redis_cache CONFIG GET appendonly | tail -n 1) == no ]] || fail 'Unexpected cache persistence configuration.'
redis_cache INFO keyspace | tr -d '\r' | awk '/^db[0-9]+:/ {bad=1} END {exit bad}' \
    || fail 'The new cache was not empty; review before proceeding.'

# A graceful restart check of ONLY the unused cache instance. No existing
# Redis key, queue, or lock is accessed. This does not prove crash durability.
before_id=$(redis_cache INFO server | tr -d '\r' | awk -F: '$1=="run_id" {print $2}')
[[ "$before_id" =~ ^[a-f0-9]{40}$ ]] || fail 'Could not identify the new cache process.'
marker="gex026:provision:$(openssl rand -hex 12)"
[[ $(redis_cache SET "$marker" verified EX 600 NX) == OK ]] || fail 'Could not create the owned cache test key.'
systemctl restart "$service"
wait_for_cache
after_id=$(redis_cache INFO server | tr -d '\r' | awk -F: '$1=="run_id" {print $2}')
[[ "$after_id" =~ ^[a-f0-9]{40}$ && "$after_id" != "$before_id" ]] || fail 'The new cache process did not restart.'
[[ $(redis_cache GET "$marker") == verified ]] || fail 'The owned key did not survive the graceful cache restart.'
[[ $(redis_cache DEL "$marker") == 1 ]] || fail 'Could not remove the owned test key.'
redis_cache INFO persistence | tr -d '\r' | awk '
    /^loading:0$/ {loaded=1}
    /^rdb_last_bgsave_status:ok$/ {healthy=1}
    END {exit !(loaded && healthy)}' || fail 'Cache RDB health verification failed.'
listeners=$(ss -H -ltn 'sport = :6381') || fail 'Could not verify cache listeners.'
printf '%s\n' "$listeners" | awk -v private="$bind_ip:6381" '
    $4 == "127.0.0.1:6381" {local_count++; next}
    $4 == private {private_count++; next}
    {bad=1}
    END {exit !(local_count==1 && private_count==1 && !bad)}' \
    || fail 'Expected exactly the loopback and private cache listeners.'
systemctl is-active --quiet "$service" || fail 'The new cache service is not active.'
systemctl enable "$service"
unset password
trap - EXIT
printf '%s\n' \
    'READY: the unused isolated cache Redis is running on private port 6381.' \
    'Noeviction, explicit memory cap, RDB health and graceful new-service restart checks passed.' \
    'Existing Redis services, jobs, application env and cache readers were not changed.' \
    'Application cache has NOT switched. Verify private connectivity and both-node topology before cutover.' \
    'Durable publication rollout, cache-state review and independent rollback checks remain required.'
