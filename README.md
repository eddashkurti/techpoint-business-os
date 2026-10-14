# TechPoint Business OS

Business management system planned exclusively for TechPoint Kosova: one central warehouse, distinct sales channels, exact-decimal financial calculations, auditable accounting, and verified integrations.

## Current status

The repository was initialized on 14 October 2026 at the owner's request using Edda's linked Git/GitHub identity. It currently contains discovery documentation and repository safeguards. Application implementation, migrations, server provisioning, and production integration have not begun.

The proposed stack is Laravel 13, PHP 8.5, MySQL 8.4 LTS, Vue 3/TypeScript/Inertia, and a single-host Linux deployment. Phase 1 application work remains subject to the approval requested in the discovery proposal. No live WooCommerce, stock, fiscal, database, or DNS changes are authorized by repository initialization alone.

## Discovery documentation

- [Phase 0 proposal](docs/discovery/TechPoint-Phase-0-Proposal.md)
- [Data model and transaction policies](docs/discovery/phase0-data-model.md)
- [Fiscal research and verified provider candidates](docs/discovery/phase0-fiscal-research.md)
- [Stack, hosting, costs, and maintenance](docs/discovery/phase0-stack-hosting.md)
- [WooCommerce and hardware discovery](docs/discovery/phase0-integrations-hardware.md)

## Hosting and database direction

Development can run locally. Online business use requires an always-on host; the proposed Contabo VPS hosts both the application and a restricted MySQL instance. A separate AWS/Azure/Firebase account or managed database subscription is not required. The actual hosting plan, contract, and recurring total still need verification before provisioning.

SSMS is Microsoft's management client for SQL Server. Its availability does not establish a MySQL server or provide cloud hosting. The existing SQL Server/SSMS setup will not be modified as part of this repository initialization. The proposed development database will be separate.

Independent encrypted backups and tested recovery remain required. Git/GitHub preserve source history, not live database/customer/financial records. Never commit credentials, private customer data, uploads, fiscal keys, database files, or database dumps.

## Version control

- Use `main` for accepted milestones and feature branches for implementation increments.
- Review changes and run the relevant tests before merging.
- Commit migrations and application source, with dependency lockfiles when created.
- Keep runtime secrets and generated business data outside Git.
- Keep the GitHub repository private; a public release/license requires a separate owner decision.

## Validation

Repository initialization validates file contents, documentation links, ignore rules, the initial commit, clean working state, remote privacy, and pushed commit identity. No application tests or production-readiness claim exists yet.
