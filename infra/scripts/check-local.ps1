$ErrorActionPreference = 'Stop'
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if (!(Test-Path -LiteralPath (Join-Path $repoRoot '.env'))) { throw 'Create the local .env first. See README.md.' }
& wsl.exe -d Ubuntu -u root --cd $repoRoot --exec test -f infra/compose.dev.yaml
if ($LASTEXITCODE -ne 0) { throw 'Could not access the repository in Ubuntu WSL. Check that Ubuntu is installed and this folder is available to WSL.' }
& wsl.exe -d Ubuntu -u root --cd $repoRoot --exec docker compose --env-file .env -f infra/compose.dev.yaml run --rm app bash infra/scripts/test-dev.sh
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& wsl.exe -d Ubuntu -u root --cd $repoRoot --exec bash infra/scripts/run-local-restore-drill.sh
exit $LASTEXITCODE
