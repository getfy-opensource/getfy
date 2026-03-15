$ErrorActionPreference = "Stop"

Set-Location (Split-Path $PSScriptRoot -Parent)

New-Item -ItemType Directory -Force -Path ".docker" | Out-Null

$envFile = ".docker\\stack.env"

function New-RandomDbUser {
    $userSuffix = -join (((48..57) + (97..122) | Get-Random -Count 8) | ForEach-Object { [char]$_ })
    "getfy_$userSuffix"
}

function New-RandomSecret([int]$len = 32) {
    -join (((48..57) + (65..90) + (97..122) | Get-Random -Count $len) | ForEach-Object { [char]$_ })
}

function Write-EnvFile([string]$path, [hashtable]$vars) {
    $existing = @{}
    if (Test-Path $path) {
        Get-Content $path | ForEach-Object {
            if ($_ -match '^\s*([^#=\s]+)\s*=\s*(.*)\s*$') {
                $existing[$matches[1]] = $matches[2]
            }
        }
    }
    foreach ($k in $vars.Keys) { $existing[$k] = $vars[$k] }
    $content = ($existing.GetEnumerator() | Sort-Object Name | ForEach-Object { "$($_.Name)=$($_.Value)" }) -join "`n"
    Set-Content -NoNewline -Path $path -Value $content
}

if (!(Test-Path $envFile)) {
    $dbUser = New-RandomDbUser
    $dbPass = New-RandomSecret 32
    $rootPass = New-RandomSecret 32
    $httpPort = if ($env:GETFY_HTTP_PORT) { $env:GETFY_HTTP_PORT } else { "80" }
    $appUrl = if ($env:GETFY_APP_URL) { $env:GETFY_APP_URL } else { "http://localhost" }
    Write-EnvFile $envFile @{
        GETFY_DB_DATABASE = "getfy"
        GETFY_DB_USERNAME = $dbUser
        GETFY_DB_PASSWORD = $dbPass
        GETFY_APP_URL = $appUrl
        GETFY_HTTP_PORT = $httpPort
        GETFY_MYSQL_DATABASE = "getfy"
        GETFY_MYSQL_USER = $dbUser
        GETFY_MYSQL_PASSWORD = $dbPass
        GETFY_MYSQL_ROOT_PASSWORD = $rootPass
        GETFY_QUEUE_CONNECTION = if ($env:GETFY_QUEUE_CONNECTION) { $env:GETFY_QUEUE_CONNECTION } else { "redis" }
        GETFY_CACHE_STORE = if ($env:GETFY_CACHE_STORE) { $env:GETFY_CACHE_STORE } else { "redis" }
        GETFY_SESSION_DRIVER = if ($env:GETFY_SESSION_DRIVER) { $env:GETFY_SESSION_DRIVER } else { "file" }
        GETFY_MYSQL_INNODB_BUFFER_POOL_SIZE = if ($env:GETFY_MYSQL_INNODB_BUFFER_POOL_SIZE) { $env:GETFY_MYSQL_INNODB_BUFFER_POOL_SIZE } else { "256M" }
        GETFY_MYSQL_INNODB_LOG_FILE_SIZE = if ($env:GETFY_MYSQL_INNODB_LOG_FILE_SIZE) { $env:GETFY_MYSQL_INNODB_LOG_FILE_SIZE } else { "64M" }
        GETFY_MYSQL_INNODB_BUFFER_POOL_INSTANCES = if ($env:GETFY_MYSQL_INNODB_BUFFER_POOL_INSTANCES) { $env:GETFY_MYSQL_INNODB_BUFFER_POOL_INSTANCES } else { "1" }
        GETFY_MYSQL_MAX_CONNECTIONS = if ($env:GETFY_MYSQL_MAX_CONNECTIONS) { $env:GETFY_MYSQL_MAX_CONNECTIONS } else { "50" }
        GETFY_MYSQL_TABLE_OPEN_CACHE = if ($env:GETFY_MYSQL_TABLE_OPEN_CACHE) { $env:GETFY_MYSQL_TABLE_OPEN_CACHE } else { "200" }
        GETFY_MYSQL_THREAD_CACHE_SIZE = if ($env:GETFY_MYSQL_THREAD_CACHE_SIZE) { $env:GETFY_MYSQL_THREAD_CACHE_SIZE } else { "16" }
        GETFY_MYSQL_SKIP_TZINFO = if ($env:GETFY_MYSQL_SKIP_TZINFO) { $env:GETFY_MYSQL_SKIP_TZINFO } else { "1" }
        GETFY_REDIS_MAXMEMORY = if ($env:GETFY_REDIS_MAXMEMORY) { $env:GETFY_REDIS_MAXMEMORY } else { "128mb" }
        GETFY_REDIS_MAXMEMORY_POLICY = if ($env:GETFY_REDIS_MAXMEMORY_POLICY) { $env:GETFY_REDIS_MAXMEMORY_POLICY } else { "allkeys-lru" }
        GETFY_QUEUE_WORKER_MEMORY = if ($env:GETFY_QUEUE_WORKER_MEMORY) { $env:GETFY_QUEUE_WORKER_MEMORY } else { "128" }
        GETFY_QUEUE_WORKER_MAX_TIME = if ($env:GETFY_QUEUE_WORKER_MAX_TIME) { $env:GETFY_QUEUE_WORKER_MAX_TIME } else { "3600" }
        GETFY_QUEUE_WORKER_MAX_JOBS = if ($env:GETFY_QUEUE_WORKER_MAX_JOBS) { $env:GETFY_QUEUE_WORKER_MAX_JOBS } else { "1000" }
        GETFY_CADDY_HOST = if ($env:GETFY_CADDY_HOST) { $env:GETFY_CADDY_HOST } else { ":80" }
    }
} else {
    $content = Get-Content $envFile -Raw
    $needsRotate = $content -match '^\s*GETFY_DB_USERNAME\s*=\s*(getfy)?\s*$' -or $content -match '^\s*GETFY_DB_PASSWORD\s*=\s*(getfy)?\s*$'
    if ($needsRotate) {
        $dbUser = New-RandomDbUser
        $dbPass = New-RandomSecret 32
        $rootPass = New-RandomSecret 32
        Write-EnvFile $envFile @{
            GETFY_DB_USERNAME = $dbUser
            GETFY_DB_PASSWORD = $dbPass
            GETFY_MYSQL_USER = $dbUser
            GETFY_MYSQL_PASSWORD = $dbPass
            GETFY_MYSQL_ROOT_PASSWORD = $rootPass
        }
    }
}

$composeFilesRaw = if ($env:GETFY_COMPOSE_FILES) { $env:GETFY_COMPOSE_FILES } else { "docker-compose.yml" }
$composeFiles = $composeFilesRaw -split ';' | Where-Object { $_ -and $_.Trim() -ne "" } | ForEach-Object { $_.Trim() }
$composeArgs = @()
foreach ($f in $composeFiles) {
    $composeArgs += @("-f", $f)
}

docker compose @composeArgs --env-file $envFile up --build -d
