#!/usr/bin/env bash
# Forge recipe: GEX-017 - prepare isolated queue Redis
# Run as root on GexOptions-workers (178.156.205.230) ONLY.
# This prepares an unused service. It does not change application .env files,
# move/delete jobs, restart existing Redis, or switch producers/consumers.
set +x
set -Eeuo pipefail
export LC_ALL=C
umask 077

fail() { printf 'STOP: %s\n' "$1" >&2; exit 1; }
[[ $EUID -eq 0 ]] || fail 'Choose root in the Forge Runs as field.'

for command in ip ss systemctl redis-cli redis-server openssl ufw install awk; do
    command -v "$command" >/dev/null || fail "Required command is missing: $command"
done
id redis >/dev/null 2>&1 || fail 'The redis service user is missing.'
id forge >/dev/null 2>&1 || fail 'The forge application user is missing.'

ip -4 -o address show | awk '$4 ~ /^178\.156\.205\.230\// {found=1} END {exit !found}' \
    || fail 'Wrong server: select GexOptions-workers, not the web server.'
private_interface=$(ip -4 -o address show | awk '$4 ~ /^10\.10\.0\.3\// {print $2}')
[[ "$private_interface" =~ ^[a-zA-Z0-9_.-]+$ ]] || fail 'Expected private address 10.10.0.3 was not found.'
ufw status | awk '/^Status: active$/ {found=1} END {exit !found}' \
    || fail 'UFW is not active. Leave it unchanged and ask for a firewall review.'
listeners=$(ss -H -ltn 'sport = :6380') || fail 'Could not inspect port 6380.'
[[ -z "$listeners" ]] || fail 'Port 6380 is already in use.'
[[ $(systemctl show redis-server@queue.service -p FragmentPath --value) == */redis-server@.service ]] \
    || fail 'The expected installed Redis service template is missing or overridden.'
[[ -z $(systemctl show redis-server@queue.service -p DropInPaths --value) ]] \
    || fail 'The queue service has overrides that require review.'
if systemctl is-active --quiet redis-server@queue.service; then
    fail 'The queue service already exists. Do not rerun provisioning.'
fi

config=/etc/redis/redis-queue.conf
data=/var/lib/redis/gex-queue
log=/var/log/redis/redis-server-queue.log
secret_directory=/etc/gexoptions-queue
secret=$secret_directory/password
for path in "$config" "$data" "$log" "$secret_directory" /run/redis-queue; do
    [[ ! -e "$path" && ! -L "$path" ]] || fail "Path already exists; nothing will be overwritten: $path"
done
[[ $(df -Pk /var/lib/redis | awk 'END {print $4}') -ge 2097152 ]] \
    || fail 'Less than 2 GiB free disk space.'
[[ $(awk '/^MemAvailable:/ {print $2}' /proc/meminfo) -ge 2097152 ]] \
    || fail 'Less than 2 GiB available host memory.'

# Stop only the NEW service if setup fails. Preserve its files for diagnosis.
started=0
cleanup_failure() {
    local status=$?
    if [[ $status -ne 0 ]]; then
        if [[ $started -eq 1 ]]; then systemctl stop redis-server@queue.service || true; fi
        printf '%s\n' 'Setup did not finish. Existing Redis and application settings are unchanged.' >&2
        printf '%s\n' 'New setup files or a private firewall rule may remain. Do not rerun blindly.' >&2
        printf '%s\n' 'Send the error text only; do not send Redis config or password files.' >&2
    fi
}
trap cleanup_failure EXIT

# New 256-bit credential. Never print it or put it into a command argument.
password=$(openssl rand -hex 32)
[[ "$password" =~ ^[a-f0-9]{64}$ ]] || fail 'Password generation failed.'
install -d -o redis -g redis -m 0750 "$data"
install -o redis -g redis -m 0640 /dev/null "$log"
install -o root -g redis -m 0640 /dev/null "$config"
printf '%s\n' \
    'bind 127.0.0.1 10.10.0.3' \
    'protected-mode yes' \
    'port 6380' \
    'daemonize no' \
    'supervised systemd' \
    'pidfile /run/redis-queue/redis-server.pid' \
    'loglevel notice' \
    "logfile $log" \
    "dir $data" \
    'databases 16' \
    "requirepass $password" \
    'maxmemory 512mb' \
    'maxmemory-policy noeviction' \
    'appendonly yes' \
    'appendfsync everysec' \
    'appendfilename appendonly-queue.aof' \
    'appenddirname appendonlydir' \
    'no-appendfsync-on-rewrite no' \
    'aof-use-rdb-preamble yes' \
    'auto-aof-rewrite-percentage 100' \
    'auto-aof-rewrite-min-size 64mb' \
    'save 900 1' \
    'save 300 100' \
    'save 60 10000' \
    'dbfilename dump-queue.rdb' \
    'stop-writes-on-bgsave-error yes' \
    'timeout 0' \
    'tcp-keepalive 300' > "$config"
install -d -o root -g forge -m 0750 "$secret_directory"
printf '%s\n' "$password" > "$secret"
chown root:forge "$secret"
chmod 0640 "$secret"

# Only allow the existing web server over the private network. Do not enable,
# reset, or otherwise change the firewall; never open 6380 on a public address.
ufw allow in on "$private_interface" proto tcp from 10.10.0.2 to 10.10.0.3 port 6380 \
    comment 'GEX-017 private queue Redis'

redis_queue() {
    REDISCLI_AUTH="$password" redis-cli --no-auth-warning --raw -h 127.0.0.1 -p 6380 "$@"
}
wait_for_queue() {
    for attempt in {1..20}; do
        if [[ $(redis_queue PING 2>/dev/null) == PONG ]]; then return 0; fi
        sleep 0.5
    done
    fail 'New Redis service did not respond to authenticated PING.'
}
started=1
systemctl start redis-server@queue.service
wait_for_queue
[[ $(redis_queue CONFIG GET appendonly | tail -n 1) == yes ]] || fail 'AOF is not enabled.'
[[ $(redis_queue CONFIG GET appendfsync | tail -n 1) == everysec ]] || fail 'Unexpected fsync policy.'
[[ $(redis_queue CONFIG GET maxmemory-policy | tail -n 1) == noeviction ]] || fail 'Unexpected eviction policy.'
[[ $(redis_queue CONFIG GET maxmemory | tail -n 1) == 536870912 ]] || fail 'Unexpected memory cap.'
[[ $(redis_queue DBSIZE) == 0 ]] || fail 'The new queue database was not empty; review before proceeding.'

# Restart proof affects only the new, unused service, never the live cache.
marker="gex017:provision:$(openssl rand -hex 12)"
# WAITAOF must use the SAME connection as SET to cover that write.
proof=$(printf 'SET %s verified EX 600 NX\nWAITAOF 1 0 5000\n' "$marker" | redis_queue)
[[ "$proof" == $'OK\n1\n0' ]] || fail 'Test write/AOF fsync proof did not complete.'
systemctl restart redis-server@queue.service
wait_for_queue
[[ $(redis_queue GET "$marker") == verified ]] || fail 'The test key did not survive the new-service restart.'
[[ $(redis_queue DEL "$marker") == 1 ]] || fail 'Could not remove the owned test key.'
redis_queue INFO persistence | tr -d '\r' | awk '
    /^aof_enabled:1$/ {enabled=1}
    /^aof_last_write_status:ok$/ {healthy=1}
    END {exit !(enabled && healthy)}' || fail 'AOF health verification failed.'
listeners=$(ss -H -ltn 'sport = :6380') || fail 'Could not verify Redis listeners.'
printf '%s\n' "$listeners" | awk '
    $4 == "127.0.0.1:6380" {local_count++; next}
    $4 == "10.10.0.3:6380" {private_count++; next}
    {bad=1}
    END {exit !(local_count==1 && private_count==1 && !bad)}' \
    || fail 'Expected exactly the loopback and private Redis listeners.'
systemctl is-active --quiet redis-server@queue.service || fail 'The new queue service is not active.'
systemctl enable redis-server@queue.service
unset password
trap - EXIT
printf '%s\n' \
    'READY: isolated queue Redis is running on private port 6380.' \
    'AOF everysec, noeviction, 512 MiB cap, and new-service restart check passed.' \
    'Application .env files and existing Redis on 6379 were not changed.' \
    'No jobs were moved or deleted. The application has NOT switched queues yet.' \
    'Send this result to continue private connectivity checks and a lossless cutover.'
