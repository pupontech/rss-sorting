param(
    [ValidateSet('Preflight', 'Worker', 'Assertions')][string]$Mode = 'Preflight',
    [string]$FreshRssRoot = (Join-Path $PSScriptRoot '../../.upstream/FreshRSS'),
    [string]$User = 'demo',
    [int]$Limit = 50
)
$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$root = (Resolve-Path $FreshRssRoot -ErrorAction Stop).Path
$safeRoot = [IO.Path]::GetFullPath((Join-Path $repoRoot '.upstream/FreshRSS')).TrimEnd([char[]]@('\','/'))
$pathComparison = if ([IO.Path]::DirectorySeparatorChar -eq '\') { [StringComparison]::OrdinalIgnoreCase } else { [StringComparison]::Ordinal }
if (-not $root.StartsWith($safeRoot + [IO.Path]::DirectorySeparatorChar, $pathComparison) -and
    -not $root.Equals($safeRoot, $pathComparison)) {
    throw "Refusing to run against '$root'. This harness is restricted to the disposable .upstream/FreshRSS checkout."
}
$constants = Join-Path $root 'constants.php'
if (-not (Test-Path $constants)) { throw "Not a FreshRSS checkout: $root" }
$version = Select-String -Path $constants -Pattern "FRESHRSS_VERSION\s*=\s*'([^']+)'" | ForEach-Object { $_.Matches[0].Groups[1].Value } | Select-Object -First 1
if ($version -ne '1.30.4') { throw "Expected the disposable FreshRSS 1.30.4 install; found '$version'." }
$portablePhp = Join-Path $repoRoot '.tools/php/php.exe'
if (Test-Path $portablePhp) { $php = $portablePhp }
else {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $phpCommand) { throw 'PHP was not found. Install PHP or provide .tools/php/php.exe.' }
    $php = $phpCommand.Source
}
$extensionSource = Join-Path $repoRoot 'xExtension-ArticleTopics'
foreach ($required in @('cli/classify.php', 'lib/ArticleTopicsWorker.php')) {
    if (-not (Test-Path (Join-Path $extensionSource $required))) { throw "Extension is not ready: missing $required" }
}
$targetExtension = Join-Path $root 'extensions/xExtension-ArticleTopics'
Write-Host "Preflight passed: FreshRSS $version, isolated root $root, PHP executable $php."
if ($Mode -eq 'Preflight') { return }

# This mode deliberately changes only the ignored, disposable .upstream checkout.
# It assumes the demo user, API password demo-api, and local mock RSS feed were
# prepared there. Parent runs the assertions after all core files have settled.
if ($Mode -eq 'Worker' -and -not (Test-Path (Join-Path $root "data/users/$User"))) {
    throw "Demo user data is absent for '$User'; prepare the disposable fixture before Worker mode."
}
if (-not (Test-Path (Join-Path $root 'data/config.php'))) { throw 'FreshRSS does not appear installed (data/config.php missing).' }
if (-not (Test-Path $targetExtension)) { New-Item -ItemType Directory -Path $targetExtension | Out-Null }
Copy-Item -Path (Join-Path $extensionSource '*') -Destination $targetExtension -Recurse -Force
$oldHost = $env:HOST
$env:HOST = '127.0.0.1'
$pythonCommand = Get-Command python -ErrorAction SilentlyContinue
if ($null -eq $pythonCommand) { $pythonCommand = Get-Command python3 -ErrorAction SilentlyContinue }
if ($null -eq $pythonCommand) { throw 'Python 3 was not found.' }
$mock = Start-Process -FilePath $pythonCommand.Source -ArgumentList @((Join-Path $repoRoot 'tests/mock/server.py')) -PassThru -WindowStyle Hidden
$env:HOST = $oldHost
$pushedLocation = $false
try {
    Push-Location $root
    $pushedLocation = $true
    $healthy = $false
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        try { Invoke-RestMethod 'http://127.0.0.1:8081/health' | Out-Null; $healthy = $true; break }
        catch { Start-Sleep -Milliseconds 250 }
    }
    if (-not $healthy) { throw 'Local deterministic mock did not become healthy at 127.0.0.1:8081.' }
    $fixtures = Invoke-RestMethod 'http://127.0.0.1:8081/__test__/fixtures'
    if ($fixtures.Count -ne 12) { throw "Mock fixture self-check failed: expected 12 fixtures, got $($fixtures.Count)." }
    $zelda = $fixtures | Where-Object { $_.title -eq 'Nintendo announces new Zelda game' }
    if ($null -eq $zelda -or @($zelda.topics).Count -ne 1 -or $zelda.topics[0] -ne 'Video Games') {
        throw 'Mock fixture self-check failed for the Nintendo/Zelda article mapping.'
    }
    $switches = $fixtures | Where-Object { $_.title -eq 'Best 10GbE network switches for NAS builds' }
    if ($null -eq $switches -or (@($switches.topics) -join ',') -ne 'Hardware,Self-Hosting') {
        throw 'Mock fixture self-check failed for the network-switch ambiguity mapping.'
    }
    $farmers = $fixtures | Where-Object { $_.title -eq 'Apple farmers face drought' }
    if ($null -eq $farmers -or @($farmers.topics).Count -ne 0) {
        throw 'Mock fixture self-check failed for the Apple farmers negative mapping.'
    }
    Write-Host 'Mock available. Running the isolated Refresh and worker; this does not contact production.'
    if ($Mode -eq 'Assertions') {
        $testUser = 'm2test' + (Get-Date -Format 'yyyyMMddHHmmss')
        & $php (Join-Path $root 'cli/create-user.php') "--user=$testUser" '--password=demo' '--api-password=demo-api' '--no-default-feeds'
        if ($LASTEXITCODE -ne 0) { throw 'Could not create disposable assertion user.' }
        $env:M2_TEST_USER = $testUser
        & $php (Join-Path $repoRoot 'tests/integration/assert_m2.php')
        if ($LASTEXITCODE -ne 0) { throw 'Milestone 2 assertions failed.' }
        foreach ($required in @('lib/ArticleTopicsBackfill.php', 'cli/backfill.php')) {
            if (-not (Test-Path (Join-Path $extensionSource $required))) { throw "Backfill implementation is not ready: missing $required" }
        }
        Copy-Item -Path (Join-Path $extensionSource '*') -Destination $targetExtension -Recurse -Force
        & $php (Join-Path $repoRoot 'tests/integration/assert_backfill.php')
        $backfillFailed = $LASTEXITCODE -ne 0
        $callsBefore = (Invoke-RestMethod 'http://127.0.0.1:8081/__test__/state').calls
        Invoke-RestMethod -Method Post -ContentType application/json -Body '{"responses":[503]}' 'http://127.0.0.1:8081/__test__/responses' | Out-Null
        & $php (Join-Path $root 'cli/actualize-user.php') "--user=$testUser" '--feed-id=1'
        if ($LASTEXITCODE -ne 0 -or (Invoke-RestMethod 'http://127.0.0.1:8081/__test__/state').calls -ne $callsBefore) { throw 'Refresh failed or contacted the provider.' }
        Write-Host 'PASS: refresh succeeds without LLM calls during provider outage'
        $apiServer = Start-Process -FilePath $php -ArgumentList @('-S','127.0.0.1:8088','-t',(Join-Path $root 'p')) -PassThru -WindowStyle Hidden
        for ($attempt=0; $attempt -lt 20; $attempt++) {
            try { Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8088/i/' | Out-Null; break } catch { Start-Sleep -Milliseconds 100 }
        }
        $env:FRESHRSS_API_BASE='http://127.0.0.1:8088/api/greader.php'
        $env:FRESHRSS_USER=$testUser
        $env:FRESHRSS_PASSWORD='demo-api'
        $env:FRESHRSS_ORIGINAL_FEED_ID='1'
        $env:FRESHRSS_EXPECTED_TITLE='Steam Deck performance benchmarks'
        & $php (Join-Path $repoRoot 'tests/api/verify_greader.php')
        if ($LASTEXITCODE -ne 0) { throw 'Google Reader API verification failed.' }
        if ($backfillFailed) { throw 'Milestone 3/4 backfill assertions failed; API verification completed separately.' }
        return
    }
    & $php (Join-Path $root 'cli/actualize-user.php') "--user=$User" '--feed-id=1'
    if ($LASTEXITCODE -ne 0) { throw 'FreshRSS fixture refresh failed.' }
    & $php (Join-Path $targetExtension 'cli/classify.php') "--user=$User" "--limit=$Limit"
    if ($LASTEXITCODE -ne 0) { throw 'ArticleTopics worker failed.' }
    Write-Host 'Worker invocation completed. The parent integration pass must assert state changes through the installed FreshRSS database and API.'
}
finally {
    if ($apiServer -and -not $apiServer.HasExited) { Stop-Process -Id $apiServer.Id -Force }
    if ($pushedLocation) { Pop-Location }
    if ($mock -and -not $mock.HasExited) { Stop-Process -Id $mock.Id -Force }
}
