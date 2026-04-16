#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .docker .docker/plugins-installed
chmod -R 777 storage bootstrap/cache .docker 2>/dev/null || true

if [ ! -f .docker/app.key ]; then
  php -r 'echo "base64:".base64_encode(random_bytes(32));' > .docker/app.key
fi

if [ ! -f .env ]; then
  cp .env.example .env
fi

rm -f public/hot 2>/dev/null || true

# VAPID compartilhado (.docker/pwa_vapid.env): só no worker (GETFY_RUN_SETUP!=true).
# No container "app" (setup), injetar sempre no boot sobrescrevia um .env válido com chaves antigas/truncadas
# do volume e impedia php artisan pwa:vapid de rodar (linhas já preenchidas, porém inválidas).
if [ "${GETFY_RUN_SETUP:-true}" != "true" ]; then
  if [ -f .docker/pwa_vapid.env ]; then
    set -a
    # shellcheck disable=SC1091
    . .docker/pwa_vapid.env || true
    set +a
  fi

  # Injeta no .env local: o worker não roda pwa:vapid; precisa das mesmas chaves que o app gravou no volume.
  php -r '
$sharedFile = ".docker/pwa_vapid.env";
$envFile = ".env";
if (!is_file($sharedFile) || !is_file($envFile)) { exit(0); }
$shared = (string) file_get_contents($sharedFile);
$env = (string) file_get_contents($envFile);
$env = str_replace("\r\n", "\n", $env);
$shared = str_replace("\r\n", "\n", $shared);
foreach (["PWA_VAPID_PUBLIC","PWA_VAPID_PRIVATE"] as $k) {
  if (!preg_match("/^\\s*".$k."\\s*=\\s*(.+)\\s*$/mi", $shared, $m)) { continue; }
  $v = trim((string) ($m[1] ?? ""));
  $v = trim($v, " \\t\\n\\r\\0\\x0B\\\"\\x27`");
  if ($v === "") { continue; }
  $needsQuotes = (bool) preg_match("/\\s|#|\"|\\x27|`/", $v);
  $escaped = $needsQuotes ? ("\"" . str_replace("\"", "\\\"", $v) . "\"") : $v;
  $line = $k . "=" . $escaped;
  $pattern = "/^\\s*" . preg_quote($k, "/") . "\\s*=.*$/m";
  if (preg_match($pattern, $env)) {
    $env = (string) preg_replace($pattern, $line, $env);
  } else {
    $env = rtrim($env, "\r\n") . "\n" . $line . "\n";
  }
}
file_put_contents($envFile, $env);
';
fi

# Se houver cache de config, pode "prender" env antigo. Limpa de forma segura (sem falhar o boot).
rm -f bootstrap/cache/config.php 2>/dev/null || true

php -r '
$envFile = ".env";
$content = file_exists($envFile) ? (string) file_get_contents($envFile) : "";
$content = str_replace("\r\n", "\n", $content);
$setupDoneInEnv = (bool) preg_match("/^\\s*DOCKER_SETUP_DONE\\s*=\\s*[\"\\x27]?true[\"\\x27]?\\s*(?:#|$)/mi", $content);
$sharedAppUrl = trim((string) @file_get_contents(".docker/app.url"));
$sharedAppUrl = trim($sharedAppUrl, " \t\n\r\0\x0B\"`");
$sharedAppUrl = str_replace(["\r", "\n", "\t"], "", $sharedAppUrl);
$sharedAppUrl = str_replace(["`", "\"", "\x27"], "", $sharedAppUrl);
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
$parts = parse_url((string) $appUrl);
$scheme = strtolower((string) ($parts["scheme"] ?? ""));
$host = strtolower((string) ($parts["host"] ?? ""));
if ($scheme === "http" && $host !== "" && $host !== "localhost" && $host !== "127.0.0.1" && $host !== "::1" && filter_var($host, FILTER_VALIDATE_IP) === false) {
    $appUrl = "https://" . $host;
}
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
  if ! php -r '
require "vendor/autoload.php";
$c = (string) @file_get_contents(".env");
$c = str_replace("\r\n", "\n", $c);
$val = static function (string $c, string $k): ?string {
  if (!preg_match("/^\\s*" . preg_quote($k, "/") . "\\s*=\\s*(.+)\\s*$/mi", $c, $m)) { return null; }
  $v = trim((string) ($m[1] ?? ""), " \\t\\n\\r\\0\\x0B\\\"\\x27`");
  return $v === "" ? null : $v;
};
$pub = $val($c, "PWA_VAPID_PUBLIC");
$priv = $val($c, "PWA_VAPID_PRIVATE");
exit(\App\Support\VapidEnvKeys::normalizedPairLooksValid($pub, $priv) ? 0 : 1);
' >/dev/null 2>&1; then
    php artisan pwa:vapid || true
  fi
fi

# Persiste VAPID em arquivo compartilhado no volume .docker para que "queue" e "app" usem as mesmas chaves.
php -r '
$envFile = ".env";
$sharedFile = ".docker/pwa_vapid.env";
if (!is_file($envFile)) { exit(0); }
$env = (string) file_get_contents($envFile);
$env = str_replace("\r\n", "\n", $env);
$out = "";
foreach (["PWA_VAPID_PUBLIC","PWA_VAPID_PRIVATE"] as $k) {
  if (!preg_match("/^\\s*".$k."\\s*=\\s*(.+)\\s*$/mi", $env, $m)) { continue; }
  $v = trim((string) ($m[1] ?? ""));
  $v = trim($v, " \\t\\n\\r\\0\\x0B\\\"\\x27`");
  if ($v === "") { continue; }
  $out .= $k . "=\"" . str_replace("\"", "\\\"", $v) . "\"\n";
}
if ($out !== "") {
  @mkdir(dirname($sharedFile), 0777, true);
  file_put_contents($sharedFile, $out);
}
';

exec "$@"
