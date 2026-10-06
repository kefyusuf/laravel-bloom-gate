#!/usr/bin/env bash
set -euo pipefail

: "${SHARED_MEMORY_TASK:?Set a unique lowercase task label}"
export SHARED_MEMORY_SQL_ROOT_PASSWORD="$(openssl rand -hex 32)"
seed_password="$(openssl rand -hex 32)"
[[ "$SHARED_MEMORY_TASK" =~ ^[a-z0-9][a-z0-9-]+$ ]] || exit 2
project="lbg-shared-memory-$SHARED_MEMORY_TASK"
image="lbg-shared-memory-php:$SHARED_MEMORY_TASK"
fixture="tests/Experiments/Swoole"
compose=(docker compose -p "$project" -f "$fixture/compose.yaml")
if docker network inspect "${project}_default" >/dev/null 2>&1 || [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" ]]; then
    echo "Refusing to reuse an existing task stack: $project" >&2
    exit 2
fi
for resource in "${project}_mysql" "${project}_experiment"; do
    if docker volume inspect "$resource" >/dev/null 2>&1; then
        echo "Refusing to reuse an existing task volume: $resource" >&2
        exit 2
    fi
done
if docker image inspect "$image" >/dev/null 2>&1; then
    echo "Refusing to replace an existing task image: $image" >&2
    exit 2
fi
cleanup() {
    "${compose[@]}" down --volumes --remove-orphans
    docker image rm "$image"
}
trap cleanup EXIT
"${compose[@]}" build php
"${compose[@]}" up -d --wait
printf "ALTER USER 'seeder'@'%%' IDENTIFIED BY '%s';\n" "$seed_password" | "${compose[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot'
"${compose[@]}" exec -T php sh -c 'cp -r /package/tests/Experiments/Swoole/. /experiment/ && mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs public tests/Experiments/Swoole && cp SharedMemory*.php fixture.php tests/Experiments/Swoole/ && composer install --no-interaction --no-progress --prefer-dist'
"${compose[@]}" exec -T php php preflight.php
"${compose[@]}" exec -T -e "DEMO_SEED_PASSWORD=$seed_password" php sh -c 'php seed.php && rm seed.php'
unset seed_password
"${compose[@]}" cp "$fixture/seal.sql" mysql:/tmp/seal.sql
"${compose[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot < /tmp/seal.sql'
"${compose[@]}" exec -T -e SHARED_MEMORY_NATIVE_TESTS=1 php vendor/bin/pest tests/Experiments/Swoole/SharedMemorySafetyTest.php --group=swoole --fail-on-warning --fail-on-risky
"${compose[@]}" exec -T -e SHARED_MEMORY_NATIVE_TESTS=1 php vendor/bin/pest tests/Experiments/Swoole/SharedMemoryQueryTest.php --group=swoole --fail-on-warning --fail-on-risky
"${compose[@]}" exec -T -e SHARED_MEMORY_NATIVE_TESTS=1 php vendor/bin/pest tests/Experiments/Swoole/SharedMemoryHttpTest.php --group=swoole --fail-on-warning --fail-on-risky
