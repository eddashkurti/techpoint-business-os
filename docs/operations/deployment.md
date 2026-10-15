# Deployment, monitoring, backups and recovery

No production host exists for this project as of 14 October 2026. These are prepared procedures, not a claim that a server has been deployed. Local application and MySQL run in isolated Docker/WSL resources; the existing SQL Server installation is untouched.

## Host and costs

Use one Linux VPS for PHP-FPM, Nginx, MySQL, one queue worker and scheduler. No Kubernetes or managed database is needed. Container memory limits total about 5.5 GB; retain OS/cache headroom and verify the actual purchased plan. The advertised Contabo configuration/contract is documented in discovery. Confirm the monthly term, VAT/setup fees, renewal price and remote backup storage total before buying. No subscription or infrastructure purchase was made.

## Production configuration

1. Obtain a reviewed source commit and clone the private repository into a dedicated Linux directory, e.g. `/opt/techpoint-business-os`. Use a read-only deploy key. Keep the recovery record of the image/source revision.
2. Install supported Docker/Compose, firewall tooling, restic, jq, Python and TLS tooling. Enable SSH key authentication, security updates and a firewall permitting only restricted SSH and ports 80/443. Docker-published ports can bypass some host firewall rules: enforce the provider firewall/DOCKER-USER chain as well. MySQL has no host port in the supplied Compose file.
3. Copy `infra/config/production.env.example` to a root-owned file outside Git. Set actual ERP hostname/HTTPS URL and a commit tag. Generate distinct secrets with `infra/scripts/create-secrets.sh /etc/techpoint/secrets`; protect the directory and preserve the application key securely. The script refuses to replace existing secrets.
4. Obtain a valid certificate for the ERP subdomain and verify automatic renewal. The Nginx template expects the real certificate paths. Configure only ERP DNS; do not move or overwrite the live WooCommerce application. Provision mail/recovery credentials through protected configuration.
5. Validate with `docker compose --env-file /etc/techpoint/production.env -f infra/compose.yaml config -q`. Build `app` and `web` images with `APP_IMAGE_TAG` set to the reviewed 40-character Git commit. Inspect labels and lock/preserve the selected base image digests for that release. The unavailable explicit Nginx tag was replaced by the verified official `stable-alpine` image; refresh and pin the digest at deployment. Official release/security information: https://nginx.org/en/download.html and https://nginx.org/en/security_advisories.html .
6. Start `db`, then run the maintenance profile `migrate` service. Only this short-lived service receives the database root secret because creation of immutable triggers requires elevated DDL privilege. Run the seeder using that service with `php artisan db:seed --force`. Runtime app/queue/scheduler receive only the application DB password.
7. Create the owner with `php artisan techpoint:create-owner --help` and a protected password file using the application service. Set accountant-approved business policies and an initial accounting period. Never install test fixtures in production.
8. Start app/queue/scheduler/web. Validate HTTPS, secure cookies, login/2FA, owner/accountant/warehouse/sales boundaries and real browser layout. Test certificate renewal, documented fiscal/provider/hardware workflows and restore reconciliation before accepting production work.

## Release changes

Take a verified remote backup before schema changes. Build the new immutable images, record the source commit, stop/drain workers and take the app into maintenance for migration. Apply forward migrations through the migration service, start workers and run `techpoint:reconcile --json`. Retain previous images. A failed deployment can revert the app image only when its schema is compatible. Do not run `migrate:fresh` or blind down migrations on production financial data; restore an independently validated snapshot when necessary.

## Backups

The secret generator keeps its host directory at `0700` and the application key, application/root database passwords and restic password at `0600`. Only `db_backup_password` uses `0444`: MySQL reads that individual read-only mount while running initialization as its `mysql` user. The private host parent prevents other host users from reaching the file; only the database service receives the mount. For secrets generated before this fix, an administrator must retain the `0700` parent and apply `chmod 0444 /etc/techpoint/secrets/db_backup_password` before first database initialization. No password rotation is needed for this permission correction.

Run `bash infra/tests/test-secret-permissions.sh` to verify these permissions on native Linux storage, even when the source checkout is on Windows. The test checks the permitted read, denied reads of the other credentials, and protection through the host parent directory. CI runs this check. `validate-images.sh` also uses native Linux secret storage and verifies that the initialized backup account can read the seeded database.

Configure a separate machine/service with an encrypted restic repository. Supported configurations include SFTP, S3-compatible, B2 or HTTPS rest endpoints; the script rejects local-only destinations. Copy `infra/config/backup.env.example` to `/etc/techpoint/backup.env`, replace every placeholder and provision credentials/password files with restrictive permissions. Keep recovery secrets separately from the VPS and outside Git. Budget this storage explicitly.

Run `backup-init.sh` once for the verified destination. `backup.sh` drains writers and captures consistent database contents, private storage, the application key, row counts, checksums and source/image revision. It restores writer availability through a cleanup trap. Retention is configured in the script. Install the supplied systemd service/timer and check both exit status and actual newest snapshot; a scheduled timer alone is not proof of successful backups.

`restore-drill.sh SNAPSHOT_ID EXPECTED_40_CHARACTER_COMMIT` restores into a uniquely named isolated Compose project with no public ports/outbound integration actions. It validates paths, checksums, table counts and reconciliation against the recorded release. Preserve its recovery evidence. Restoring production requires external Woo/fiscal comparison first: outbound operations must stay paused until transactions since the backup are reconciled. An older ERP snapshot must never cause new fiscal issuance or duplicate external effects.

Local verification restored a nonempty MySQL 8.4 fixture into a separate database and compared journal/stock values, Albanian Unicode and encrypted-field decryption. Archive boundary tests check traversal, links and checksum tampering. This does not replace a real off-server restore of production storage and secrets.

## Monitoring and maintenance

Observe container health, queue failures/backlog, scheduler execution, disk space, database storage, certificate expiry, reconciliation exit status, fiscal unknown/failed documents and integration last success/errors. Run `php artisan techpoint:reconcile --json` daily; the scheduler already registers reconciliation and Woo read-only reconciliation. Alerts need an actual owner-controlled delivery destination; none exists yet.

Use `docker compose ps`, constrained logs and `php artisan queue:failed` for diagnosis. Logs should not contain customer payloads, credentials or fiscal keys. Dependency lockfiles are committed. Run Composer/npm security audits and the test/build suite before upgrades. Keep OS/Docker/PHP/MySQL/Nginx patches current and rehearse recovery periodically. GitHub preserves source history only, never live financial/customer records.
