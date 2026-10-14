$ErrorActionPreference = 'Stop'
$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$linuxRoot = (& wsl -d Ubuntu -- wslpath -a $repoRoot).Trim()
if ($LASTEXITCODE -ne 0 -or !$linuxRoot.StartsWith('/mnt/') -or $linuxRoot.Contains("'")) { throw 'Cannot resolve the repository in Ubuntu WSL.' }
& wsl -d Ubuntu -u root -- bash -lc "cd '$linuxRoot' && docker compose --env-file .env -f infra/compose.dev.yaml run --rm app bash infra/scripts/test-dev.sh"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& wsl -d Ubuntu -u root -- bash -lc "cd '$linuxRoot' && bash infra/scripts/run-local-restore-drill.sh"
exit $LASTEXITCODE
