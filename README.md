# Seclore File Protection for Nextcloud (`files_seclore`)

Protect files on demand with [Seclore](https://www.seclore.com) Enterprise Digital Rights Management, directly from Nextcloud. Protection is performed by your organisation's Seclore Policy Server; the protected file replaces the original in place, so shares and sync keep working while Seclore enforces usage rights wherever the file travels.

> 📄 The full design is in [docs/software-design-document.md](docs/software-design-document.md) (SDD). Code comments reference SDD sections (e.g. `SDD §7.2`) and open questions (`TODO(Q1)`).

## Status

Feature-complete for the core protect/unprotect flow — **pending reconciliation of the Seclore API contract (SDD §15 Q1) and integration testing against a real Nextcloud 31 + Policy Server**.

- [x] App skeleton (`appinfo/info.xml`, bootstrap, DI wiring)
- [x] `ISecloreClient` adapter interface + HTTP implementation against the *indicative* API contract (SDD §7.3 — must be reconciled with your Policy Server's API guide, SDD §15 Q1)
- [x] Token caching (`TokenStore`), policy caching (`PolicyService`), typed config (`ConfigService`)
- [x] Database schema (`oc_seclore_state`) + entity/mapper
- [x] `occ files_seclore:test` connection check
- [x] `ProtectionService` orchestration (SDD §4.1): ETag compare-and-swap, version purge, files-metadata projection, state machine
- [x] OCS API (SDD §4.3): protect/unprotect/retry/status/policies, admin config + test-connection, capability
- [x] Background jobs for large files (SDD §4.2) + stale-state watchdog (SDD §9 E14)
- [x] Files UI (SDD §5): protect/unprotect actions (single + batch), policy picker, inline protected badge, Seclore sidebar tab
- [x] Admin settings UI (SDD §4.6): connection + test, default policy, group gates, thresholds (Admin settings → Security)
- [ ] Activity / notifications (SDD §4.6)
- [ ] State-row lifecycle listener on file deletion (SDD §6.1)

## Building the frontend

```sh
npm ci
npm run build     # outputs js/files_seclore-main.mjs
```

## Requirements

- Nextcloud 31, PHP ≥ 8.1
- A Seclore Policy Server reachable over HTTPS, with this app registered as an enterprise application (app ID + secret)

## Development setup

The repository root is the app root. Link it into your Nextcloud dev instance under the app id:

```sh
ln -s /path/to/sclrit /path/to/nextcloud/apps/files_seclore
occ app:enable files_seclore
```

## Configuration

Configure in the web UI under **Administration settings → Security → Seclore File Protection** (connection, default policy, group gates, thresholds, connection test), or via `occ`:

```sh
occ config:app:set files_seclore base_url --value="https://policy.example.com/api"
occ config:app:set files_seclore app_id --value="<app id>"
occ config:app:set files_seclore app_secret --sensitive --value="<secret>"
```

Then verify connectivity and credentials:

```sh
occ files_seclore:test
```

All configuration keys and defaults are documented in SDD Appendix A.

## License

AGPL-3.0-or-later
