#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .docker
chmod -R 777 storage bootstrap/cache .docker 2>/dev/null || true

if [ ! -f .docker/app.key ]; then
  php -r 'echo "base64:".base64_encode(random_bytes(32));' > .docker/app.key
fi

if [ ! -f .env ]; then
  cp .env.example .env
fi

rm -f public/hot 2>/dev/null || true

php -r '
$envFile = ".env";
$content = file_exists($envFile) ? (string) file_get_contents($envFile) : "";
$setupDoneInEnv = (bool) preg_match("/^\\s*DOCKER_SETUP_DONE\\s*=\\s*[\"\\x27]?true[\"\\x27]?\\s*(?:#|$)/mi", $content);
$setupDoneShared = is_file(".docker/setup.done");
$setupDone = $setupDoneInEnv || $setupDoneShared;

$existingAppUrl = null;
if (preg_match("/^\\s*APP_URL\\s*=\\s*(.+)\\s*$/mi", $content, $m)) {
    $existingAppUrl = trim((string) ($m[1] ?? ""), " \\t\\n\\r\\0\\x0B\\\"\\x27");
}
$sharedAppUrl = trim((string) @file_get_contents(".docker/app.url"));
$appUrl = $setupDone ? ($sharedAppUrl !== "" ? $sharedAppUrl : $existingAppUrl) : ((getenv("GETFY_APP_URL") ?: getenv("APP_URL")) ?: "http://localhost");
$vars = [
    "APP_NAME" => getenv("APP_NAME") ?: "Getfy",
    "APP_ENV" => getenv("APP_ENV") ?: "local",
    "APP_DEBUG" => getenv("APP_DEBUG") ?: "false",
    "APP_URL" => $appUrl ?: null,
    "APP_KEY" => getenv("APP_KEY") ?: (trim((string) @file_get_contents(".docker/app.key")) ?: ""),
    "APP_INSTALLED" => getenv("APP_INSTALLED") ?: "true",
    "DOCKER_SETUP_DONE" => $setupDone ? "true" : null,
    "APP_AUTO_MIGRATE" => getenv("APP_AUTO_MIGRATE") ?: "false",
    "DB_CONNECTION" => getenv("DB_CONNECTION") ?: "mysql",
    "DB_HOST" => getenv("DB_HOST") ?: "mysql",
    "DB_PORT" => getenv("DB_PORT") ?: "3306",
    "DB_DATABASE" => getenv("DB_DATABASE") ?: "getfy",
    "DB_USERNAME" => getenv("DB_USERNAME") ?: "getfy",
    "DB_PASSWORD" => getenv("DB_PASSWORD") ?: "getfy",
    "CACHE_STORE" => getenv("CACHE_STORE") ?: "redis",
    "QUEUE_CONNECTION" => getenv("QUEUE_CONNECTION") ?: "redis",
    "SESSION_DRIVER" => getenv("SESSION_DRIVER") ?: "file",
    "REDIS_CLIENT" => getenv("REDIS_CLIENT") ?: "predis",
    "REDIS_HOST" => getenv("REDIS_HOST") ?: "redis",
    "REDIS_PORT" => getenv("REDIS_PORT") ?: "6379",
    "REDIS_PASSWORD" => getenv("REDIS_PASSWORD") ?: "null",
];
foreach ($vars as $key => $value) {
    if ($value === null) {
        continue;
    }
    $value = (string) $value;
    $needsQuotes = (bool) preg_match("/\\s|#|\"|\\x27/", $value);
    if ($value === "null") {
        $line = $key . "=null";
    } else {
        $escaped = $needsQuotes ? ("\"" . str_replace("\"", "\\\"", $value) . "\"") : $value;
        $line = $key . "=" . $escaped;
    }
    $pattern = "/^" . preg_quote($key, "/") . "=.*/m";
    if (preg_match($pattern, $content)) {
        $content = (string) preg_replace($pattern, $line, $content);
    } else {
        $content = rtrim($content, "\r\n") . "\n" . $line . "\n";
    }
}
file_put_contents($envFile, $content);
'

DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-getfy}"
DB_USERNAME="${DB_USERNAME:-${MYSQL_USER:-getfy}}"
DB_PASSWORD="${DB_PASSWORD:-${MYSQL_PASSWORD:-getfy}}"

DB_OK=0
for i in $(seq 1 60); do
  if php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); } catch (Throwable \$e) { exit(1); }" >/dev/null 2>&1; then
    DB_OK=1
    break
  fi
  sleep 1
done

if [ "$DB_OK" -ne 1 ]; then
  echo "MySQL indisponível. Verifique DB_HOST/DB_PORT e o serviço mysql no compose."
  exit 1
fi

if [ "${GETFY_RUN_SETUP:-true}" = "true" ]; then
  if [ ! -f vendor/autoload.php ]; then
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
  fi
  php artisan package:discover --ansi
  php artisan migrate --force
fi

exec "$@"
