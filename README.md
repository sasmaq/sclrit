# Seclore File Protection for Nextcloud (`files_seclore`)

Protect files on demand with [Seclore](https://www.seclore.com) Enterprise Digital Rights Management, directly from Nextcloud. Protection is performed by your organisation's Seclore Policy Server; the protected file replaces the original in place, so shares and sync keep working while Seclore enforces usage rights wherever the file travels.

> 📄 The full design is in [docs/software-design-document.md](docs/software-design-document.md) (SDD). Code comments reference SDD sections (e.g. `SDD §7.2`) and open questions (`TODO(Q1)`).

## Status

Backend foundation scaffold — **not yet functional end to end**.

- [x] App skeleton (`appinfo/info.xml`, bootstrap, DI wiring)
- [x] `ISecloreClient` adapter interface + HTTP implementation against the *indicative* API contract (SDD §7.3 — must be reconciled with your Policy Server's API guide, SDD §15 Q1)
- [x] Token caching (`TokenStore`), policy caching (`PolicyService`), typed config (`ConfigService`)
- [x] Database schema (`oc_seclore_state`) + entity/mapper
- [x] `occ files_seclore:test` connection check
- [ ] `ProtectionService` orchestration (SDD §4.1) + OCS API (SDD §4.3)
- [ ] Background jobs for large files (SDD §4.2)
- [ ] Files UI: action, policy picker, status badge/sidebar (SDD §5)
- [ ] Admin settings UI (SDD §4.6)
- [ ] Activity / notifications (SDD §4.6)

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

Until the settings UI lands, configure via `occ`:

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
