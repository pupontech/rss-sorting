#!/usr/bin/env pwsh
$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
Set-Location $repoRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI is unavailable. Install Docker Desktop with WSL 2 integration, then rerun tests/docker/run.ps1.'
}
if (-not (Test-Path 'xExtension-ArticleTopics/cli/classify.php')) {
    throw 'The core extension worker is not present yet at xExtension-ArticleTopics/cli/classify.php.'
}

docker compose -f docker-compose.test.yml down --volumes --remove-orphans
if ($LASTEXITCODE -ne 0) { throw 'Could not reset the isolated Docker test environment.' }
docker compose -f docker-compose.test.yml up -d --build --wait
if ($LASTEXITCODE -ne 0) { throw 'Docker Compose failed to start the test services.' }

docker compose -f docker-compose.test.yml cp tests/docker/seed.php freshrss:/tmp/seed-article-topics.php
if ($LASTEXITCODE -ne 0) { throw 'Could not copy the deterministic seed script into FreshRSS.' }
$seedJson = docker compose -f docker-compose.test.yml exec -T freshrss php /tmp/seed-article-topics.php
if ($LASTEXITCODE -ne 0) { throw 'FreshRSS test fixture setup failed.' }
$seed = $seedJson | ConvertFrom-Json
if (-not $seed.feed_id) { throw 'FreshRSS setup did not return the original feed ID.' }
docker compose -f docker-compose.test.yml exec -T freshrss php cli/actualize-user.php --user=demo --feed-id=$($seed.feed_id)
if ($LASTEXITCODE -ne 0) { throw 'FreshRSS could not fetch the local RSS fixture.' }
docker compose -f docker-compose.test.yml exec -T freshrss php extensions/xExtension-ArticleTopics/cli/classify.php --user=demo --limit=50
if ($LASTEXITCODE -ne 0) { throw 'ArticleTopics classification worker failed.' }

$apiVerifier = 'tests/api/verify_greader.php'
if (-not (Test-Path $apiVerifier)) {
    throw "The Google Reader API verifier has not been added yet: $apiVerifier"
}
docker compose -f docker-compose.test.yml exec -T `
    -e FRESHRSS_API_BASE=http://127.0.0.1/api/greader.php `
    -e FRESHRSS_USER=demo `
    -e FRESHRSS_PASSWORD=demo-api `
    -e FRESHRSS_ORIGINAL_FEED_ID=$($seed.feed_id) `
    -e FRESHRSS_ORIGINAL_FEED_TITLE='General Tech Daily' `
    freshrss php /tests/api/verify_greader.php
if ($LASTEXITCODE -ne 0) { throw 'Google Reader API verification failed.' }
Write-Host 'Milestone 1 Docker fixture and API verification passed.'
