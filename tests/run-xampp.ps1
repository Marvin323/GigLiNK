param(
    [string]$Xampp = 'C:/xampp',
    [int]$DbPort = 33317,
    [int]$HttpPort = 33719,
    [switch]$SkipBrowser
)

$ErrorActionPreference = 'Stop'
$OutputEncoding = [Text.UTF8Encoding]::new($false)
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$mysql = Join-Path $Xampp 'mysql/bin/mysql.exe'
$php = Join-Path $Xampp 'php/php.exe'
$suffix = [guid]::NewGuid().ToString('N').Substring(0, 10)
$testDb = 'giglink_qa_' + $suffix
$testDir = Join-Path ([System.IO.Path]::GetTempPath()) ('giglink-qa-' + $suffix)
$local = @('-h', '127.0.0.1', '-P', "$DbPort", '-u', 'root')

foreach ($port in @($DbPort, $HttpPort)) {
    if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) {
        throw "Port $port is in use. Choose an unused test port."
    }
}

function Import-QaSql($file) {
    (Get-Content -LiteralPath $file -Raw -Encoding utf8) -creplace
        '(?m)^(CREATE DATABASE|USE) giglink\b', ('$1 ' + $testDb) |
        & $mysql @local --default-character-set=utf8mb4
    if ($LASTEXITCODE) { throw "SQL import failed for $file" }
}

$server = $null
$web = $null
Push-Location $root
try {
    & (Join-Path $Xampp 'mysql/bin/mysql_install_db.exe') "--datadir=$testDir" "--port=$DbPort"
    if ($LASTEXITCODE) { throw 'Could not initialize the QA data directory.' }
    $serverArgs = @('--no-defaults', ('--datadir="' + $testDir + '"'),
        '--bind-address=127.0.0.1', "--port=$DbPort", '--console')
    $server = Start-Process (Join-Path $Xampp 'mysql/bin/mysqld.exe') -ArgumentList $serverArgs -WindowStyle Hidden -PassThru
    for ($i = 0; $i -lt 20; $i++) {
        & $mysql @local --connect-timeout=1 -e 'SELECT VERSION()' 2>$null
        if ($LASTEXITCODE -eq 0) { break }
        Start-Sleep -Milliseconds 250
    }
    if ($LASTEXITCODE) { throw 'QA MariaDB did not start.' }

    foreach ($file in @('schema', '02_lifecycle', '03_usernames', '04_platform')) {
        Import-QaSql (Join-Path $root "sql/$file.sql")
    }
    Import-QaSql (Join-Path $root 'tests/legacy-fixture.sql')
    Import-QaSql (Join-Path $root 'sql/05_reliability.sql')
    Import-QaSql (Join-Path $root 'sql/05_reliability.sql')

    Import-QaSql (Join-Path $root 'tests/legacy-disputes.sql')
    Import-QaSql (Join-Path $root 'sql/06_trust_reconciliation.sql')
    Import-QaSql (Join-Path $root 'sql/06_trust_reconciliation.sql')

    Import-QaSql (Join-Path $root 'tests/legacy-reconciliation.sql')
    Import-QaSql (Join-Path $root 'sql/07_financial_governance.sql')
    Import-QaSql (Join-Path $root 'sql/07_financial_governance.sql')
    Import-QaSql (Join-Path $root 'sql/08_payment_queue.sql')
    Import-QaSql (Join-Path $root 'sql/08_payment_queue.sql')
    Import-QaSql (Join-Path $root 'sql/09_mobile_money_sandbox.sql')
    Import-QaSql (Join-Path $root 'sql/09_mobile_money_sandbox.sql')
    $env:GIGLINK_TEST_DB = $testDb
    $env:GIGLINK_TEST_PORT = "$DbPort"
    & $php tests/workflow.php
    $dbStatus = $LASTEXITCODE
    & $php -d extension=gd tests/trust.php
    $trustStatus = $LASTEXITCODE
    & $php tests/governance.php
    $governanceStatus = $LASTEXITCODE
    & $php tests/payment-queue.php
    $queueStatus = $LASTEXITCODE
    & $php tests/mobile-money.php
    $mobileMoneyStatus = $LASTEXITCODE

    & node --test tests/service-worker.test.cjs
    $swStatus = $LASTEXITCODE

    $browserStatus = 0
    if (!$SkipBrowser) {
        $env:GIGLINK_ENV = 'dev'
        $env:GIGLINK_ENABLE_MOCK_PAYMENTS = '1'
        $env:GIGLINK_MOCK_WEBHOOK_SECRET = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
        $env:GIGLINK_DB_NAME = $testDb
        $env:GIGLINK_DB_PORT = "$DbPort"
        $env:GIGLINK_DB_USER = 'root'
        $env:GIGLINK_DB_PASS = ''
        $env:GIGLINK_TEST_URL = "http://127.0.0.1:$HttpPort/giglink"
        $env:GIGLINK_BASE_URL = $env:GIGLINK_TEST_URL
        $docRoot = Split-Path -Parent $root
        $web = Start-Process $php -ArgumentList @('-d', 'extension=gd', '-S', "127.0.0.1:$HttpPort", '-t', ('"' + $docRoot + '"')) -WindowStyle Hidden -PassThru
        $env:GIGLINK_TEST_PHP = $php
        $env:GIGLINK_TEST_WEB_PID = [string]$web.Id
        & node tests/browser.cjs
        $browserStatus = $LASTEXITCODE
    }
    if ($dbStatus -or $trustStatus -or $governanceStatus -or $queueStatus -or $mobileMoneyStatus -or $swStatus -or $browserStatus) { exit 1 }
} finally {
    if ($web -and !$web.HasExited) { $web.Kill() }
    if ($server -and !$server.HasExited) {
        & (Join-Path $Xampp 'mysql/bin/mysqladmin.exe') @local shutdown
    }
    Write-Host "QA files: $testDir"
    Pop-Location
}

