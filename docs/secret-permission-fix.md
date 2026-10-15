# Native Linux secret permissions — 15 October 2026

The pending diagnostic exposed a production initialization failure hidden by the Windows filesystem's permission behavior. The generator had set every credential file to owner-only `0600`. MySQL's initialization script runs as the container's `mysql` user and could not read its individually mounted backup password file on native Linux.

The generator now gives only that backup password file read-only `0444` permissions. Its host parent remains private (`0700`); application/root database credentials, the application key and the restic password remain `0600`. The database service is the only production service receiving the backup password mount.

The regression check creates temporary secrets on a native Linux Docker volume. It verifies the exact modes, the allowed backup-password read, denied reads of the other credential files, and denied access through the private host parent. Temporary resources are removed automatically. CI includes the check.

The isolated production validation now uses native Linux secrets instead of relying on NTFS permission behavior. It checks database/PHP-FPM startup, privileged migrations, application database privileges, reconciliation, actual backup-account reads and Nginx TLS configuration. It does not deploy a business server or connect external fiscal/WooCommerce services.
