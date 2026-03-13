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
$content = str_replace("\r\n", "\n", $content);
$setupDoneInEnv = (bool) preg_match("/^\\s*DOCKER_SETUP_DONE\\s*=\\s*[\"\\x27]?true[\"\\x27]?\\s*(?:#|$)/mi", $content);
$sharedAppUrl = trim((string) @file_get_contents(".docker/app.url"));
$setupDoneShared = is_file(".docker/setup.done") && $sharedAppUrl !== "" && preg_match("#^https?://#i", $sharedAppUrl);
$setupDone = $setupDoneInEnv || $setupDoneShared;

$existingAppUrl = null;
if (preg_match("/^\\s*APP_URL\\s*=\\s*(.+)\\s*$/mi", $content, $m)) {
    $existingAppUrl = trim((string) ($m[1] ?? ""), " \\t\\n\\r\\0\\x0B\\\"\\x27`");
}
$existingCronSecret = null;
if (preg_match("/^\\s*CRON_SECRET\\s*=\\s*(.*)\\s*$/mi", $content, $m)) {
    $existingCronSecret = trim((string) ($m[1] ?? ""), " \\t\\n\\r\\0\\x0B\\\"\\x27`");
}
$cronSecret = $existingCronSecret;
if (!is_string($cronSecret) || $cronSecret === "") {
    $cronSecret = rtrim(strtr(base64_encode(random_bytes(24)), "+/", "-_"), "=");
}
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
    "CRON_SECRET" => $cronSecret ?: null,
    "DB_CONNECTION" => getenv("DB_CONNECTION") ?: "mysql",
    "DB_HOST" => getenv("DB_HOST") ?: ((getenv("DB_CONNECTION") ?: "mysql") === "pgsql" ? "postgres" : "mysql"),
    "DB_PORT" => getenv("DB_PORT") ?: ((getenv("DB_CONNECTION") ?: "mysql") === "pgsql" ? "5432" : "3306"),
    "DB_DATABASE" => getenv("DB_DATABASE") ?: "getfy",
    "DB_USERNAME" => getenv("DB_USERNAME") ?: ((getenv("DB_CONNECTION") ?: "mysql") === "pgsql" ? "postgres" : "getfy"),
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
    $needsQuotes = (bool) preg_match("/\\s|#|\"|\\x27|`/", $value);
    if ($value === "null") {
        $line = $key . "=null";
    } else {
        $escaped = $needsQuotes ? ("\"" . str_replace("\"", "\\\"", $value) . "\"") : $value;
        $line = $key . "=" . $escaped;
    }
    $pattern = "/^\\s*" . preg_quote($key, "/") . "\\s*=.*$/m";
    if (preg_match($pattern, $content)) {
        $content = (string) preg_replace($pattern, $line, $content);
    } else {
        $content = rtrim($content, "\r\n") . "\n" . $line . "\n";
    }
}
file_put_contents($envFile, $content);
'

DB_CONNECTION="${DB_CONNECTION:-mysql}"

if [ "$DB_CONNECTION" = "pgsql" ]; then
  DB_HOST="${DB_HOST:-postgres}"
  DB_PORT="${DB_PORT:-5432}"
  DB_DATABASE="${DB_DATABASE:-getfy}"
  DB_USERNAME="${DB_USERNAME:-postgres}"
  DB_PASSWORD="${DB_PASSWORD:-getfy}"
  DB_DSN="pgsql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}"
  DB_LABEL="PostgreSQL"
else
  DB_HOST="${DB_HOST:-mysql}"
  DB_PORT="${DB_PORT:-3306}"
  DB_DATABASE="${DB_DATABASE:-getfy}"
  DB_USERNAME="${DB_USERNAME:-${MYSQL_USER:-getfy}}"
  DB_PASSWORD="${DB_PASSWORD:-${MYSQL_PASSWORD:-getfy}}"
  DB_DSN="mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_DATABASE}"
  DB_LABEL="MySQL"
fi

echo "Aguardando ${DB_LABEL} em ${DB_HOST}:${DB_PORT}..."
DB_OK=0
for i in $(seq 1 60); do
  if php -r "try { new PDO('${DB_DSN}', '${DB_USERNAME}', '${DB_PASSWORD}', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); } catch (Throwable \$e) { exit(1); }" >/dev/null 2>&1; then
    DB_OK=1
    break
  fi
  sleep 1
done

if [ "$DB_OK" -ne 1 ]; then
  echo "${DB_LABEL} indisponível. Verifique DB_HOST/DB_PORT e o serviço de banco no compose."
  exit 1
fi

if [ "${GETFY_RUN_SETUP:-true}" = "true" ]; then
  if [ ! -f vendor/autoload.php ]; then
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
  fi
  php artisan package:discover --ansi
  php artisan migrate --force
  if ! php -r '$c = (string) @file_get_contents(".env"); exit((preg_match("/^\\s*PWA_VAPID_PUBLIC\\s*=\\s*\\S+/m", $c) && preg_match("/^\\s*PWA_VAPID_PRIVATE\\s*=\\s*\\S+/m", $c)) ? 0 : 1);' >/dev/null 2>&1; then
    php artisan pwa:vapid || true
  fi
fi

exec "$@"
