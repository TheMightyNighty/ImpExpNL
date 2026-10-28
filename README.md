🇩🇪 [Deutsche Version](README.de.md)

# ImpExpNL — TYPO3 v14 (2.x)

ImpExpNL is a TYPO3 extension for the structured export and import of page trees between TYPO3 instances. This version is built for **TYPO3 v14 LTS** (Doctrine DBAL 4). For **TYPO3 v13.4 LTS** (and GSB 11) see the `13.x` branch / the `1.x` releases.

On export, a complete page tree — including all content elements, FAL references, system categories, redirects, container layouts and IRRE relations — is saved as a JSON file. On import, all internal links (UIDs, page links, language overlays, container hierarchies, category assignments) are automatically rewritten to match the target structure.

Additional tables can be registered purely declaratively via YAML configuration. No PHP code is required for this.

---

## Purpose

The extension addresses the need to transfer content between TYPO3 instances in a controlled way, for example from a development environment via a staging system to production. A direct database transfer is not possible between different instances because the UIDs of all records differ. ImpExpNL handles the complete UID remapping, resolves file references via file paths instead of UIDs, and rewrites internal links.

---

## Comparison with EXT:impexp

ImpExpNL is designed as a CLI-first, automation- and cluster-capable alternative to the TYPO3 core module **EXT:impexp**. The key differences:

| Aspect | EXT:impexp (core) | **ImpExpNL** |
|---|---|---|
| **Transfer format** | T3D (serialized) / XML – opaque, not diffable | **JSON / JSONL / CSV** – readable, versionable, diff- and pipeline-capable |
| **Operation** | primarily backend module (GUI) | **CLI-first**, headless, `--json` output + exit codes for CI/CD & GitOps |
| **Repeated import** | creates duplicates | **Delta/idempotency mode** – detects already-imported records via the origin mapping (`tx_impexpnl_uid_map`), skips identical ones |
| **Conflicts** | no detection | **Conflict detection + strategies** (overwrite / skip / ask) incl. field diff |
| **Pre-check** | – | **Dry-run diff analysis** before every write |
| **Roll back** | no targeted undo | **Logged rollback/undo** of every import + **automatic rollback on abort** (no half-written tree) |
| **Large trees** | loads the entire structure into memory | **Batch/chunk processing** via DataHandler (tested with 10,000 pages / 20,000 content elements, ~337 MB peak memory) |
| **Additional tables** | via TCA flags / PHP code | **Declarative YAML registry** – redirects, categories, news etc. without PHP |
| **Files (FAL)** | binary data embedded in the file | **FAL via file paths** – lean transfer file, assets transferred via `rsync` |
| **Integrity** | – | **Checksum/signature** (optional `IMPEXPNL_SIGNING_KEY`) against tampering |
| **Concurrency** | – | **Cluster-wide import lock** (DB-based) |
| **Other** | – | Workspace target import, multi-site slug regeneration, PSR-14 events, category path mapping |

EXT:impexp can certainly handle UID remapping, relations and FAL — the table highlights the points that ImpExpNL solves additionally or differently: automation, idempotency, rollback and declarative extensibility.

---

## Scope & non-goals

To make clear what ImpExpNL is **not** intended for:

- **Not a DB-dump replacement.** Pages, content and registered tables are transferred –
  not the entire database state (sessions, caches, backend users, system configuration).
  For complete clones, a DB dump is the right tool.
- **No generic import of arbitrary tables.** Tables beyond `pages`/`tt_content` must be
  registered via a YAML profile (table registry). Without a profile they are not touched.
- **No file transfer.** An asset list is exported, not the binary files. The physical
  transfer of `fileadmin` remains a job for `rsync`/`rclone`/S3 or similar.
- **Not a merge tool.** On conflicts, a decision is made per record (overwrite/skip/ask/abort),
  not merged field by field. There are no three-way merges.
- **Rollback ≠ snapshot restore.** The rollback undoes *one logged import* (the records
  created/updated by it); it does not restore an earlier overall state of the instance.
- **Direct MM imports deliberately bypass the DataHandler.** MM relations (e.g. categories)
  are written directly via path mapping – this is intentional (performance/idempotency),
  but it means DataHandler hooks do not fire for these MM tables.
- **Workspaces are project-specific.** The workspace target import uses the regular
  TYPO3 mechanisms; staging/release workflows (e.g. in GSB 11) remain the project's responsibility.

---

## System requirements

- PHP 8.2 or higher
- TYPO3 14 LTS (14.3.7 or later — includes the core security fixes TYPO3-CORE-SA-2026-021/-022)
- Composer-based TYPO3 installation

> **Quick try-out:** A ready-to-run DDEV demo project (vanilla TYPO3 v14, extension via path repository) is available under [`Build/demo/`](Build/demo/README.md) — run `ddev start` and go.

---

## Versions & compatibility

ImpExpNL is maintained in two parallel lines. When installing via Composer, the correct
line is selected **automatically** through the version constraints — manual pinning is
generally not necessary.

| Extension | TYPO3 | PHP | Branch | Maintenance |
|-----------|-------|-----|--------|--------|
| **2.x**   | v14 LTS    | 8.2 – 8.4 | [`main`](https://github.com/TheMightyNighty/ImpExpNL/tree/main) | active (features + fixes) |
| **1.x**   | v13.4 LTS (+ GSB 11) | 8.2 – 8.3 | [`13.x`](https://github.com/TheMightyNighty/ImpExpNL/tree/13.x) | maintenance (fixes) |

- **`main`** is the current v14 development line (`2.x` releases).
- **`13.x`** continues to maintain the v13.4 line (`1.x` releases) — e.g. for GSB 11.
- Fixes are developed in the oldest affected branch and forward-merged into `main`.

Example for explicit pinning (only if needed):

```bash
composer require themightynighty/impexpnl:"^2.0"   # TYPO3 v14
composer require themightynighty/impexpnl:"^1.0"   # TYPO3 v13.4
```

---

## Installation

ImpExpNL is a Composer extension. **Composer is the recommended route** – the correct
line (v14 = `^2.0`, v13.4 = `^1.0`) is chosen automatically via the version constraints.

```bash
composer require themightynighty/impexpnl

vendor/bin/typo3 extension:setup
vendor/bin/typo3 database:updateschema
vendor/bin/typo3 cache:flush
```

(In a DDEV project, prefix the commands with `ddev composer …` or
`ddev exec vendor/bin/typo3 …` respectively.)

`database:updateschema` creates the tables `tx_impexpnl_import_log`, `tx_impexpnl_lock` and `tx_impexpnl_uid_map`. `tx_impexpnl_uid_map` holds the origin mapping (source record → target record) to detect already-imported records on repeated imports – core tables (`pages`/`tt_content`) remain untouched by this. The table `tx_impexpnl_lock` implements the cluster-wide import lock. (Note: earlier versions used a field `tx_impexpnl_remote_uid` on `pages`/`tt_content`; `impexpnl:migrate-legacy-schema` migrates it into the new table.)

The installation is verified with:

```bash
vendor/bin/typo3 list impexpnl
```

The following commands are listed: `impexpnl:export`, `impexpnl:import`, `impexpnl:undo`, `impexpnl:status`, `impexpnl:list`, `impexpnl:check`, `impexpnl:validate-config`, `impexpnl:unlock` and `impexpnl:migrate-legacy-schema`.

### Other sources

- **Development / not yet on Packagist:** include it as a path or VCS repository.
  Add a `repositories` entry to the project's `composer.json` pointing to the local
  directory or the Git repo, then run `composer require themightynighty/impexpnl:@dev`.
- **TER** (TYPO3 Extension Repository): optional, for setups without a Composer workflow.
- **DDEV demo:** a ready-to-run example project (vanilla TYPO3 v14, extension via
  path repository) is available under [`Build/demo/`](Build/demo/README.md) – `ddev start` is enough.
  It is meant for trying things out, **not** as an installation route for production projects.

---

## Workflow

A content transfer follows a fixed sequence:

**1. Export on the source system.** The page tree is collected recursively starting from a start PID and saved as a JSON file. In parallel, a text file listing all referenced file paths is generated.

```bash
ddev exec vendor/bin/typo3 impexpnl:export 123 /var/www/html/var/export.json
```

**2. File transfer.** The physical images and documents are transferred separately to the target system. The file `impexpnl_assets.txt` generated during export serves as input for `rsync`:

```bash
rsync -avz --files-from=/var/www/html/var/impexpnl_assets.txt \
  /var/www/html/fileadmin/ user@targetserver:/var/www/html/fileadmin/
```

**3. Trial run.** Before the actual import, a diff analysis is performed. No data is written.

```bash
ddev exec vendor/bin/typo3 impexpnl:import /var/www/html/var/export.json 456 --dry-run
```

**4. Import.** The actual import is executed. Optionally, a workspace can be specified as the target.

```bash
ddev exec vendor/bin/typo3 impexpnl:import /var/www/html/var/export.json 456 --target-workspace=1
```

**5. Rollback.** If needed, the import can be rolled back completely.

```bash
ddev exec vendor/bin/typo3 impexpnl:undo
```

---

## Export

```bash
ddev exec vendor/bin/typo3 impexpnl:export <start-PID> <target-path> [options]
```

### Options

| Option | Description |
|---|---|
| `--depth=N` | Limits the recursion depth. At `0` (default) the entire tree is exported. At `--depth=1` only the start page and its direct child pages are captured. |
| `--include-hidden` | Includes hidden and disabled records. By default only active records are exported. |
| `--pages=1,2,3` | Exports individual pages without recursion. The given PIDs are exported directly, child pages are not included. |
| `--exclude-pages=99,100` | Excludes specific PIDs from the export. Those pages and their content are skipped. |
| `--since=2026-01-01` | Time-based filter. Only records whose `tstamp` is after the given date are exported. This significantly reduces the data volume for delta transfers. |
| `--content-types=text,textmedia` | Restricts the export to specific CTypes. All other content elements are not exported. |
| `--csv` | Generates additional CSV files (`impexpnl_pages.csv`, `impexpnl_tt_content.csv`). These files can be opened in spreadsheet programs to review the exported data in tabular form before import, or to reconcile it against other data sources. |
| `--bundle-baseline=<manifest>` | Incremental bundle (only with `--transport=bundle`): path to the **target's** asset manifest (`impexpnl_assets.json`, e.g. fetched in advance via the data lock). Files already listed there with identical content (sha256) are not packed — the bundle only contains new/changed assets. If a skipped file unexpectedly turns out to be missing on the target, the import reports this via the asset preflight (exit code `7`); no silent loss occurs. |
| `--jsonl` | JSONL format written line by line (one JSON object per line) — compact and memory-friendly during export (no single `json_encode` over the whole block). The target file should end in `.jsonl`; the import detects the format automatically. The default JSON format remains fully usable. **Recommendation: use JSONL for large exports (from a few thousand records)** — only this format is streamed record by record on import and dry-run diff (constant memory); `.json` is parsed entirely into memory. See the "Chunked processing" section. |

### Generated files

Every export produces two auxiliary files alongside the JSON file:

**`impexpnl_assets.txt`** contains a list of all referenced file paths (one path per line). The file is formatted for direct use with `rsync --files-from`. This way, only the files actually needed are transferred, not the entire `fileadmin` folder.

**`impexpnl_broken_links.txt`** lists all internal `t3://page?uid=` links whose target page is not included in the export. This information allows a check before import of which links would have no target on the target system.

### Integrity check

The JSON file contains a `_meta` block with export date, source system, TYPO3 version and a checksum over the entire data block. On import, the checksum is verified. If the file was corrupted after export, the import is aborted. With a configured `IMPEXPNL_SIGNING_KEY`, this becomes HMAC signature protection (see the "Security features" section).

### Multi-site

The site configuration of the source system (identifier, base URL, languages) is exported together with the JSON file's `_meta` block. This information is used on import for automatic slug regeneration.

---

## Import

```bash
ddev exec vendor/bin/typo3 impexpnl:import <file> <target-PID> [options]
```

### Options

| Option | Description |
|---|---|
| `--dry-run` / `-d` | Diff analysis without data changes. No lock is set and no database operation is performed. |
| `--delta` | Delta import. Every record is compared field-by-field with the existing record on the target system. Identical records are skipped, changed ones are updated via DataHandler, new ones are created. |
| `--conflict=X` | Conflict strategy for the delta import: `overwrite` (default), `skip`, `ask`, `abort` (see below). |
| `--verbose` / `-v` | Extended output for changes. For every changed record, the concrete field differences are shown (old value, new value). |
| `--target-workspace=N` | Import into a TYPO3 workspace. At `0` (default) the content is created directly in the live system. At `1` or higher it is imported into a draft workspace where it can be reviewed and published. |
| `--profile=name` | Loads an import profile. All other arguments and options are taken from the profile. |

### Diff analysis (dry run)

The dry run compares the import data with the existing records on the target system. For every page and content element, it reports whether the record is new, has changed, or is identical. When using `--verbose`, the concrete field differences are also listed.

```bash
ddev exec vendor/bin/typo3 impexpnl:import var/export.json 456 --dry-run --verbose
```

### Delta import

When re-importing the same or an updated JSON file, `--delta` processes only the actual changes. Identical records are detected via a field-by-field comparison and skipped. System fields (`uid`, `pid`, `tstamp`, `crdate`, `sorting`) are not considered in this comparison.

### Conflict detection and handling

A conflict occurs when a record on the target system has a newer timestamp than the corresponding record in the export file. This indicates that the record was edited locally after the export.

| Strategy | Behavior |
|---|---|
| `--conflict=overwrite` | The export overwrites the local change. This is the default behavior. |
| `--conflict=skip` | Records with conflicts are skipped. All other records are imported normally. |
| `--conflict=ask` | For every conflict, the console interactively asks whether to overwrite. |
| `--conflict=abort` | On the first conflict, the entire import is aborted (exit code `5`). Any records already written are — as with any abort — automatically rolled back. Intended for unattended CI/CD runs where a conflict should force a deliberate decision. |

### Exit codes (CI/CD)

Conflicts with `--conflict=abort` abort the import (exit code `5`); an unattended
run can thus insist on a deliberate decision. The complete list of differentiated
exit codes is in the [Exit codes](#exit-codes) section.

### Workspace import

Import into a workspace (e.g. `--target-workspace=1`) uses the regular TYPO3 workspace release process (in GSB 11, the editorial workflow). The imported content does not appear immediately on the live site, but is stored as workspace versions and released via the regular TYPO3 workspace functions. On publish, the versions are moved to live with correctly rewritten links. A workspace import that has not yet been published can be undone without residue using `impexpnl:undo` (see the "Rollback" section).

### Chunked processing

For large page trees (several thousand records), the data is automatically processed in batches of 500 records. Pages are processed before content elements so that the PID mapping is already resolved by the time content is imported. A progress bar reports on processing status.

Checksum computation is done incrementally per record. On **export**, the JSONL format (`--jsonl`) writes line by line, avoiding a single, very large `json_encode` over the entire data block.

On **import**, `import.max_file_size_mb` (default: 512, `0` = unlimited) limits the file size: oversized files are rejected **before** parsing with a clear error message, instead of running the process into an OOM (in a container: pod kill, a stuck lock until the stale timeout).

The memory demand of an import depends on the file format:

- **JSONL** (`.jsonl`): `pages` and `tt_content` are **streamed** record by record — both the import and the dry-run diff work batch-wise with constant memory, independent of the file size (measured: 180,000 records / 43 MB, peak 276 MB → 4 MB). **JSONL is therefore the recommended format for large exports.**
- **JSON** (`.json`): the file is parsed entirely into memory; the peak demand scales with the record count. Unproblematic for small to medium transfers — for large data sets, use JSONL instead of raising the PHP `memory_limit`.

**Note on registered record tables (auxiliary data):** even on the JSONL path, the *non-streamed* areas — registered record tables (e.g. `sys_redirect`, `tx_news_domain_model_news`), `sys_file_reference`, IRRE relations, and the asset manifest — are materialized once in full, because they are relationally interwoven and typically small. Memory here scales with the record count of those tables. If a registered table contains very many records (on the order of ~100,000+), two levers are available today: raise the PHP `memory_limit` of the CLI call (rough rule of thumb: ~1 KB per record plus base demand) or split the transfer — move the large table in a separate run with its own restricted export (e.g. partitioned by time via `--since`). A streaming path for registry tables is a possible future enhancement if needed.

### Slug regeneration

After import, the `slug` fields of all imported pages are automatically regenerated via the TYPO3 SlugHelper. This ensures the slugs match the site configuration of the target system, especially when transferring between different sites.

---

## Import profiles

Recurring import configurations can be stored as YAML files under `var/impexpnl_profiles/`.

```yaml
# var/impexpnl_profiles/dev_to_staging.yaml
source_file: /var/www/html/var/export_dev.json
target_pid: 456
workspace: 1
delta: true
conflict: skip
```

The call is made without further arguments:

```bash
ddev exec vendor/bin/typo3 impexpnl:import --profile=dev_to_staging
```

The profile provides the import configuration. Available fields: `source_file`, `target_pid`, `workspace`, `delta`, `conflict`. These profile values take precedence over the corresponding command-line arguments/options.

The runtime flags `--dry-run` and `--verbose` are still controlled via the command line and combined with the profile. This allows a profile to be safely tested with a dry run first:

```bash
ddev exec vendor/bin/typo3 impexpnl:import --profile=dev_to_staging --dry-run
```

---

## Transfer profiles (policy guardrails)

In addition to import profiles (argument pre-population), there are **transfer profiles** in the YAML config (`transferProfiles`) that enforce environment binding and policy guardrails. Selected on import via `--transfer-profile=<name>`.

| Field | Effect |
|---|---|
| `transport` | Reference to an `assetTransports` entry (`external`/`bundle`) |
| `conflictStrategy` | Enforced conflict strategy (`overwrite`/`skip`/`ask`/`abort`) |
| `allowedContext` | Allowed TYPO3 application contexts — prevents execution in the wrong environment (e.g. a prod package on dev) |
| `requireDryRun` | `true` = the profile only allows dry-run runs |
| `allowedTables` | **Scope allowlist** for the registry import: not set = all registered tables, set = only the named ones (empty list = none). `pages`/`tt_content`/FAL are unaffected by this. |

```yaml
transferProfiles:
  stage_to_prod_release:
    transport: bundle_full
    conflictStrategy: abort
    allowedContext: [Production]
    allowedTables: [sys_redirect, sys_category_record_mm]
```

**PID containment.** Regardless of the profile, the import writes registry records and IRRE relations only to pages that this import itself created or mapped. Records whose target page cannot be resolved are skipped with a warning — a (manipulated) export therefore cannot write records to arbitrary locations on the target system.

---

## Rollback

Every import is logged in the database table `tx_impexpnl_import_log`. The log contains the complete UID mapping between source and target UIDs.

```bash
# Roll back the last import (with preview and confirmation prompt)
ddev exec vendor/bin/typo3 impexpnl:undo

# Preview only, without deleting
ddev exec vendor/bin/typo3 impexpnl:undo --dry-run

# Roll back a specific import without confirmation
ddev exec vendor/bin/typo3 impexpnl:undo 20260329_142348_a1b2c3 --force
```

Before deleting, `undo` shows a preview (affected count, source file). If imported records were edited locally after the import (a newer `tstamp` than the import), the **rollback aborts** and lists the affected records – so that local changes are not lost unnoticed. `--force` deletes anyway (the auto-rollback after an aborted import uses this path internally for its own records). Without `--force`, an interactive confirmation is additionally required; `--dry-run` shows only the preview.

The rollback removes, in a fixed order:

1. FAL references (`sys_file_reference`) of the imported records
2. All data imported via the table registry (category assignments, redirects, etc.)
3. Content elements (`tt_content`)
4. Pages (`pages`), in reverse order (child pages before parent pages)

The import log is removed from the database after a successful rollback. The rollback is documented in the transaction log. The delete operations are — like the import — bundled in a database transaction, so the rollback stays in the same order of magnitude as the import (see [`Documentation/PERFORMANCE.md`](Documentation/PERFORMANCE.md)).

The rollback runs in the same workspace as the original import (the `workspace_id` is stored in the log). A workspace import is thus correctly rolled back – the versions created in the workspace are removed, the live system remains untouched.

---

## Status and history

```bash
# Current status: lock, open imports, last import
ddev exec vendor/bin/typo3 impexpnl:status
ddev exec vendor/bin/typo3 impexpnl:status --json

# Import history as a table
ddev exec vendor/bin/typo3 impexpnl:list
ddev exec vendor/bin/typo3 impexpnl:list --limit=50 --json

# Release a stuck import lock
ddev exec vendor/bin/typo3 impexpnl:unlock
```

The command `impexpnl:status` shows the DB lock (owner, age, stale status), the number of rollback-capable imports, and the details of the last import. `impexpnl:list` outputs the import history as a table (import ID, date, mode, workspace, record count, source file). `impexpnl:unlock` releases a stuck import lock (with confirmation, `--force` without confirmation). All three support `--json` for machine-readable output.

### Signed transfer report (audit artifact)

For audit trails across security zones, `impexpnl:report` produces a **signed transfer report** for a completed import: import ID, date, mode, workspace, per-table record counts, placed assets, and — if the source file is still available — its sha256. The report is signed with the same key that protects the import files (HMAC-SHA256 incl. key rotation; without a key it carries a plain checksum and the command notes this).

```bash
# Report for the most recent import (human-readable summary)
ddev exec vendor/bin/typo3 impexpnl:report

# Write the signed report as a JSON artifact and verify it later
ddev exec vendor/bin/typo3 impexpnl:report 20260706_180010_1901ac --output=var/report.json
ddev exec vendor/bin/typo3 impexpnl:report --verify=var/report.json   # exit code 4 on tampering
```

Verification follows the same rules as import files: with any configured key, only HMAC signatures are accepted (downgrade protection), and rotated keys are resolved via their key ID.

---

## Table registry

The table registry allows arbitrary database tables to be included in the export/import process. Configuration is done declaratively via YAML files.

### Configuration sources

ImpExpNL reads table configurations from two sources:

1. The extension's own `imp_exp_nl.yaml` in the extension folder
2. All loaded TYPO3 extensions that contain a file `Configuration/ImpExpNL.yaml`

The second source allows third-party extensions to register their own tables without changes to ImpExpNL.

### Table type: record

Record tables are regular TYPO3 tables with `uid` and `pid`. They are imported via the DataHandler. UIDs are mapped, links in configured fields are rewritten, and on rollback the records are deleted.

```yaml
impexpnl:
  tables:
    sys_redirect:
      type: record
      pid_field: pid          # field for page assignment
      uid_remap: true         # enable UID mapping (required for rollback)
      rewrite_links:          # fields with t3://page links
        - target
```

### Table type: mm

MM tables store many-to-many relations without their own UID. Import is performed via direct insert/delete on the table.

```yaml
impexpnl:
  tables:
    sys_category_record_mm:
      type: mm
      match_field: uid_foreign
      match_tablenames_field: tablenames
      match_tables: [pages, tt_content]
```

### Category path mapping

System categories have different UIDs on different TYPO3 instances. A UID-based mapping therefore leads to incorrect assignments.

With the option `category_match: path`, categories are identified on export via their full path (e.g. "Topics > Digitalization > E-Government"). On import, this path is resolved segment by segment on the target system. If a category does not exist on the target system, it is created automatically.

```yaml
impexpnl:
  tables:
    sys_category_record_mm:
      type: mm
      match_field: uid_foreign
      match_tablenames_field: tablenames
      match_tables: [pages, tt_content]
      category_match: path
```

### Example: registering extension-specific tables

The following configuration is placed in any extension under `Configuration/ImpExpNL.yaml`. It registers news articles, tags and their categories:

```yaml
# packages/my_sitepackage/Configuration/ImpExpNL.yaml
impexpnl:
  tables:
    tx_news_domain_model_news:
      type: record
      pid_field: pid
      uid_remap: true
      rewrite_links: [bodytext]

    tx_news_domain_model_tag:
      type: record
      pid_field: pid
      uid_remap: true

    sys_category_record_mm:
      type: mm
      match_field: uid_foreign
      match_tablenames_field: tablenames
      match_tables:
        - pages
        - tt_content
        - tx_news_domain_model_news
      category_match: path
```

### Bundled, contract-tested profiles

ImpExpNL already ships two profiles in `imp_exp_nl.yaml` — they are considered
*supported* because they pass a complete functional contract (export → import
→ rollback, plus profile-specific link rewriting resp. delta idempotency):

| Profile | Type | Contract test | Verified |
|--------|-----|---------------|---------|
| `sys_redirect` | record | `RedirectProfileContractTest` | Export, UID remapping, `t3://` rewrite in `target`, external target remains, rollback |
| `sys_category_record_mm` | mm | `CategoryProfileContractTest` | Export with paths, path mapping to new content, **delta without duplicates**, rollback |

These tests (`Tests/Functional/Profile/`) also serve as the **template for custom profiles**:
anyone wanting to "harden" another table copies one of these tests, swaps in the fixture and
table, and makes sure the contract stays green.

> Note: Registry tables of type `record` (with `uid_remap: true`) are
> **idempotent** in delta mode: already-imported records are resolved via the
> origin mapping (`tx_impexpnl_uid_map`, multi-source-capable via `source_id`)
> and skipped resp. updated instead of duplicated on match.
> MM tables with `category_match: path` are also idempotent. Without
> `uid_remap` there is no persisted mapping and thus no delta.

---

## Configuration

The file `imp_exp_nl.yaml` in the extension folder contains the default configuration:

```yaml
export:
  include:
    file_references: true       # export FAL references with file path

import:
  include:
    file_references: true       # resolve FAL on import via file path
  container_support: true       # remap b13/container layouts
  link_rewrite:
    fields:                     # fields with t3://page links
      - bodytext
      - pi_flexform
      # add custom/extension fields, e.g. in GSB 11: tx_gsb_accordion_text

impexpnl:
  tables:
    sys_category_record_mm:
      type: mm
      match_field: uid_foreign
      match_tablenames_field: tablenames
      match_tables: [pages, tt_content]
      category_match: path

    sys_redirect:
      type: record
      pid_field: pid
      uid_remap: true
      rewrite_links: [target]
```

---

## Security features

**Corruption protection (default).** Every JSON file contains a SHA256 checksum over the **entire data block** (all tables: `pages`, `tt_content`, `sys_file_reference`, IRRE relations, and all registry tables – not just pages and content). On import, the checksum is verified; on mismatch, the import is aborted. This detects **accidental corruption**. It is not protection against deliberate tampering, since a plain checksum can be recomputed by anyone.

**Tamper protection (optional).** If a secret key is configured, an **HMAC-SHA256** is formed instead of the plain SHA256 checksum. Without knowledge of the key, the signature cannot be regenerated after a change – importing a tampered file fails. The key is set identically on the source and target systems, either via the environment variable `IMPEXPNL_SIGNING_KEY` or in `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey'] = 'a-long-secret';
```

If a signed file is imported on a system without a (matching) key, the import is rejected. If a key is configured on the target system, **only HMAC signatures are accepted from then on** – a downgrade to a plain `sha256:` checksum (which anyone can recompute) is refused.

**Signature enforcement (`import.require_signature`).** For imports across a security boundary (e.g. from a lower-classified zone), the signature can be made mandatory:

```yaml
# imp_exp_nl.yaml
import:
  require_signature: true
```

This also rejects files **without** `_meta.checksum` (exit code `4`) – omitting the checksum is then no longer a bypass. `impexpnl:check` warns if a signing key is set but signature enforcement is missing.

**Bundle hardening.** When importing a `.tar.gz` bundle, the asset files are placed only **after** the export file's checksum/signature check has succeeded. The source is exclusively the asset manifest covered by the signature (the `assets` block of the export file); every file is verified against its signed SHA256 checksum before being taken over, identifiers are checked against path traversal, and bundles containing link entries (symlinks/hardlinks) are rejected **before extraction** (inspection of the tar table of contents; a second symlink check after extraction covers the PharData fallback).

**Cluster-wide concurrency lock.** During an import, a lock is set in the database table `tx_impexpnl_lock` (effective across all pods/nodes) plus a local file lock. A concurrent import attempt is rejected. Long-running imports keep the lock fresh via a heartbeat; on a crash or fatal error, the lock is released automatically via a shutdown handler or after a configurable timeout (default: 3600 s, adjustable via `import.lock_stale_seconds` in `imp_exp_nl.yaml`). The lock is bound to a random **owner token**: the heartbeat and release only act on the lock's own owner — if a stuck lock has meanwhile been cleared and re-acquired by another import, the old process can neither refresh nor delete it; it aborts instead (exit code 3). A dry run does not set a lock.

**Abort-safe rollback log.** If an import aborts after the first records have been created (error, timeout), an emergency log with the UID mapping created so far is written automatically. The partial data can thus be fully removed via `impexpnl:undo <import-id>` – no non-rollbackable ghost data remains.

**Path restriction.** Both the export target path and the import source file must lie within the project directory. Profile names are secured against path traversal.

**CSV injection protection.** On CSV export, values with leading formula triggers (`= + - @`) are neutralized so that they are not executed as formulas in spreadsheet applications.

**Conflict detection.** In delta mode, records with a locally newer timestamp are detected as conflicts. Handling is configurable via the `--conflict` option.

**DataHandler error logging.** Errors from the TYPO3 DataHandler are logged individually via the PSR-3 logger.

### Key rotation (key ID)

With an optional **key ID**, the signature is written as `hmac-sha256:<key-id>:<hash>` — the key can thus be rotated without invalidating packages from the transition period:

```php
// Active key + identifier (alternatively env IMPEXPNL_SIGNING_KEY / IMPEXPNL_SIGNING_KEY_ID)
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey'] = 'new-key';
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeyId'] = 'k2026';

// Old key for verification during the transition period
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys'] = [
    'k2025' => 'old-key',
];
```

On import, the key is resolved via the key ID; signatures without a key ID are checked against the active key and all stored old keys. After the transition period ends, remove the old key from `signingKeys`. The downgrade protection applies unchanged: as soon as any key is configured, only HMAC signatures are accepted.

> **Deprecated:** The legacy checksum format from ImpExpNL 1.x (export format 1, plain SHA256 over only `pages`+`tt_content`) is still accepted on import (with a log warning), but **will be removed in version 3.0**. Regenerate old exports in time.

---

## Use across a security boundary

For transfers from a lower- into a higher-classified zone (government operations, zone model), ImpExpNL is deliberately file-based: there is no network connection between the instances — the transport path (data lock, data diode, verified exchange area) remains entirely under operations' control. Recommended minimum configuration and workflow:

**Configuration (both sides):**

1. **Set a signing key** — identical on the source and target systems, provided as a secret (Kubernetes secret via env `IMPEXPNL_SIGNING_KEY` or mount). Never bake the key into the image or repository; assign a key ID (`IMPEXPNL_SIGNING_KEY_ID`) from the start for later rotation.
2. **Signature enforcement on the target system:** `import.require_signature: true` — unsigned files, or files downgraded to a plain checksum, are rejected (exit code `4`).
3. **Limit the scope:** transfer profile with `allowedContext` (only the intended environment), `allowedTables` (only the needed registry tables), and optionally `requireDryRun` for review pipelines.
4. **Limits:** adjust `import.max_file_size_mb` to the tested tree sizes and the pod memory.

**Workflow per transfer (high side):**

1. `impexpnl:check --json` — check prerequisites incl. signature mode.
2. Take the package through the data lock; with `bundle`, a single file is enough, the contained assets are SHA256-verified via the signed manifest.
3. `impexpnl:import <package> <pid> --transfer-profile=<profile> --dry-run --json` — gate: review diff and asset preflight (optionally a four-eyes check on the dry-run result).
4. Run the import; evaluate the exit code (`4` = integrity, `5` = conflict, `6` = rolled-back partial import).
5. Evidence: `impexpnl:report <import-id> --output=<file>` produces a **signed transfer report** as the audit artifact (verifiable via `--verify`); additionally `impexpnl:list --json` and `var/log/impexpnl_transactions.log`. The import can be undone at any time via `impexpnl:undo <import-id>`.

**Roles:** The export (low side) signs; the data lock only transports; the target system verifies and decides (dry-run gate, conflict policy). For high-to-low exports (e.g. prod→dev), additionally enable `export.sanitize` with `hash_key` so that personal data does not leave the higher zone.

### Outbound transfer (`push`) and inbox

The `push` transport places the signed bundle **atomically** into a configured outbox directory (e.g. a mounted data lock) — it is first written to `<outbox>/.incoming/<name>.part`, then moved to `<outbox>/<name>.tar.gz` via `rename()`. A data-lock process therefore never sees an incomplete file. ImpExpNL does not open **any** network or receiving channel; the actual transfer across the zone boundary remains operations' responsibility (rsync/SFTP/data diode).

```bash
# Source: push the signed bundle to the outbox
vendor/bin/typo3 impexpnl:export 123 var/export.jsonl --transport=push
# (transport config: assetTransports.push_outbox.outbox in imp_exp_nl.yaml)
```

On the target side, `impexpnl:inbox` lists incoming packages — **read-only, no auto-import**:

```bash
vendor/bin/typo3 impexpnl:inbox --json        # transfer.inbox_path from the config
vendor/bin/typo3 impexpnl:inbox /path/inbox   # or an explicit directory
```

**Receipt ≠ application:** A package arriving in the inbox never triggers an import on its own. The import remains the existing explicit step (`impexpnl:import <inbox>/<package> <pid>`) with full signature/dry-run checking — a remote actor placing a file in the inbox cannot thereby trigger DB write access.

---

## Practical scenarios

ImpExpNL is a generic TYPO3 extension and does not require a specific sitepackage. The following scenarios work equally on plain TYPO3 v14 and on **GSB 11** (Government Site Builder, TYPO3-13-based; there via the `13.x` branch) — GSB compatibility is a bonus, not a requirement.

**Container layouts.** The `tx_container_parent` links of the b13/container package are rewritten to the new UIDs on import. Grid layouts, accordions and tabs remain intact after import.

**FAL.** File references are resolved via the file path (`identifier`), not via `sys_file.uid`. This allows transfer between instances with different FAL indexing.

**Workspaces.** Import into a draft workspace uses the regular TYPO3 workspace release process (e.g. the editorial workflow in GSB 11).

**Redirects.** `sys_redirect` entries are exported, imported and removed on rollback via the table registry. Links in the `target` field are rewritten automatically.

**Categories.** Path-based matching allows correct assignment across different instances, independent of the local category UID.

**Multi-site.** Slugs are regenerated for the target site after import.

**Kubernetes/air-gap.** The import log is stored in the database and survives container restarts.

---

## Logging

All services log via the PSR-3 logger under the namespace `Robbi\ImpExpNL`. The extension configures its own FileWriter in `ext_localconf.php` that writes all messages from level INFO into a separate log file.

| Destination | Content |
|---|---|
| `var/log/typo3_impexpnl_<hash>.log` | All ImpExpNL messages (own logger channel) |
| `var/log/impexpnl_transactions.log` | Human-readable import/rollback history with UID mappings |
| `tx_impexpnl_import_log` (database table) | UID map for the rollback, persistent across container restarts |

### Log rotation

The file `var/log/impexpnl_transactions.log` is appended to continuously and is not rotated automatically. In continuous operation it should be included in the system's `logrotate`:

```
/var/www/html/var/log/impexpnl_transactions.log {
    weekly
    rotate 12
    compress
    missingok
    notifempty
    copytruncate
}
```

The logger channel (`typo3_impexpnl_<hash>.log`) is written via the TYPO3 logging framework; alternatively, a rotating or central writer can be configured there (see below). In Kubernetes environments, a stdout/syslog writer without local files is generally recommended.

### Adjusting logger configuration

The default configuration writes to a file. For operation in Kubernetes or with a central log aggregator (ELK, Graylog), the writer can be overridden in `config/system/additional.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Robbi']['ImpExpNL']['writerConfiguration'] = [
    \TYPO3\CMS\Core\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\SyslogWriter::class => [],
    ],
];
```

In Kubernetes environments where logs are collected via stdout/stderr, the `PhpErrorLogWriter` can be used instead:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Robbi']['ImpExpNL']['writerConfiguration'] = [
    \TYPO3\CMS\Core\Log\LogLevel::INFO => [
        \TYPO3\CMS\Core\Log\Writer\PhpErrorLogWriter::class => [],
    ],
];
```

### Log filtering

The namespace `Robbi\ImpExpNL` is automatically translated by the TYPO3 logging framework into the path `Robbi.ImpExpNL`. In ELK/Graylog, this path can be filtered on to show only ImpExpNL messages.

---

## System check

The command `impexpnl:check` checks whether all prerequisites for operation are met. The check is intended before first use on a new system and after a TYPO3 update.

```bash
ddev exec vendor/bin/typo3 impexpnl:check
```

Checked:

- **Database schema:** existence of the tables `tx_impexpnl_import_log`, `tx_impexpnl_lock` and `tx_impexpnl_uid_map`.
- **File system:** write permissions on `var/` and `var/log/`. Existence of the profile directory `var/impexpnl_profiles/`.
- **Registry validation (preflight):** `check` runs the same check as `impexpnl:validate-config` — registered tables exist, `pid_field`/`rewrite_links`/MM fields and `match_tables` reference known TCA tables, `type` is valid (incl. origin information and "did you mean?" suggestions for typos).
- **Runtime checks:** FAL default storage reachable, import lock status, signing key mode (HMAC active/inactive), profile parsing incl. existence of the target workspace.
- **Extension scan:** detection of all loaded extensions that provide their own `Configuration/ImpExpNL.yaml`.

For a pure configuration check without runtime checks, there is also `impexpnl:validate-config`. Both commands support `--json`.

On error, `check`/`validate-config` return exit code **`2`** (invalid configuration), otherwise `0`; warnings alone do not cause a failure. The complete list of exit codes is in the [Exit codes](#exit-codes) section. This allows integration into deployment scripts:

```bash
vendor/bin/typo3 impexpnl:check || echo "ImpExpNL: system check failed (see exit code)"
```

---

## Exit codes

All CLI commands return differentiated exit codes so that pipelines can react
in a targeted way (e.g. lock → retry, conflict → force review). With
`--json`, the code is additionally available in the `exitCode` field.

| Code | Meaning |
|---|---|
| `0` | Success |
| `1` | Generic/unexpected error |
| `2` | Invalid configuration or profile (`validate-config`/`check`, profile parsing) |
| `3` | Another import holds the lock (DB or file lock) |
| `4` | Checksum/signature check of the import file failed |
| `5` | Conflicts detected and `--conflict=abort` set |
| `6` | Import aborted mid-run; partial import was (auto-)rolled back |
| `7` | Referenced files/assets missing *(reserved for a future strict asset mode; the default skips missing files)* |

The exit codes allow integration into automated workflows and monitoring:

```bash
# In a deployment script
vendor/bin/typo3 impexpnl:import --profile=dev_to_live \
  && echo "Import successful" \
  || { echo "Import failed"; exit 1; }
```

```bash
# Periodic execution via cron with failure notification
0 6 * * 1 /var/www/html/vendor/bin/typo3 impexpnl:import --profile=weekly_sync 2>&1 | mail -s "ImpExpNL Sync" ops@example.com
```

---

## CI/CD & pipeline operation

ImpExpNL is CLI-first and designed for unattended GitOps/Kubernetes pipelines.

### Machine-readable output (`--json`)

`import`, `export`, `check`, `status`, `list` and `undo` support `--json`. The output is
a single JSON object with `success` (bool) and command-specific fields; progress bars
and titles are suppressed.

```bash
vendor/bin/typo3 impexpnl:import var/export.jsonl 42 --delta --json
# {"success":true,"dryRun":false,"importId":"...","stats":{"new":12,"updated":3,"skipped":480,
#  "conflict_skipped":0,"errors":0},"durationMs":1840}
```

Metrics relevant for dashboards: `stats.{new,updated,skipped,errors}` and `durationMs` (import),
`bytes`/`durationMs` (export). Exit code `1` also indicates a failure (even with
`stats.errors > 0`).

### Idempotency & reproducibility

- **Delta mode** (`--delta`): identical records are skipped — repeated runs of the same
  package are a no-op (safe retries).
- **Stable UID mapping** via the table `tx_impexpnl_uid_map` (source system + source UID → target UID):
  a source record lands on the same target record on every run; multiple source systems remain
  distinguishable via `source_id`.
- **Deterministic export** with an integrity checksum (`sha256:`/`hmac-sha256:`) — the package
  is versionable and reviewable (content-as-code in Git).

### Pipeline pattern (Kubernetes / GitOps)

Recommended job sequence per target (namespace with its own DB):

```bash
set -euo pipefail

# 1) Preflight: check prerequisites + lock
vendor/bin/typo3 impexpnl:check --json
vendor/bin/typo3 impexpnl:status --json   # lock.active=true → abort/wait if needed

# 2) Gate: dry-run diff before the real change (e.g. dev→prod)
vendor/bin/typo3 impexpnl:import "$PKG" "$PID" --delta --dry-run --json

# 3) Import; roll back automatically on error
if ! vendor/bin/typo3 impexpnl:import "$PKG" "$PID" --delta --json; then
    vendor/bin/typo3 impexpnl:undo --force --json   # remove the last (partial) import
    exit 1
fi
```

**Pod-kill resilience (important in Kubernetes):** A hard SIGKILL (OOM, eviction, timeout) bypasses
the built-in auto-rollback. The next run detects the stuck lock
(`impexpnl:status`) and the import log marked as "aborted"; then **undo-then-retry** applies
instead of a blind restart. Size pod memory adequately (large trees) or use JSONL.

### Migration from older versions

`impexpnl:migrate-legacy-schema` migrates existing installations after the
schema update. The command detects both legacy states automatically:

- **ImpExpNL 1.x → 2.0:** the origin field `tx_impexpnl_remote_uid` on
  `pages`/`tt_content` is migrated into the new table `tx_impexpnl_uid_map`.
- **Predecessor extension "robbi_copy":** data from `tx_robbicopy_*` is taken over.

```bash
vendor/bin/typo3 extension:setup                        # create the new schema (tx_impexpnl_uid_map etc.)
vendor/bin/typo3 impexpnl:migrate-legacy-schema         # migrate origin mapping/legacy data
vendor/bin/typo3 impexpnl:migrate-legacy-schema --drop-legacy   # optional: remove old columns/tables
```

> **Order matters:** migrate first, then `--drop-legacy` – otherwise the
> origin mapping is lost. The command is idempotent and pipeline-friendly.

---

## PSR-14 events

For requirements that go beyond the declarative table registry, two PSR-14 events are available:

- `ModifyExportDataEvent` — fired after collecting all data and before writing the JSON file
- `ModifyImportDataEvent` — fired after the import; the complete UID map is available

---

## Automated tests

### Overview

The extension contains a test suite of unit tests and functional tests. Unit tests check isolated logic building blocks without a database. Functional tests run complete export/import/rollback cycles with a dedicated test database.

The test database is created automatically for each test run and deleted afterwards. The production database is never touched.

### Prerequisites

`typo3/testing-framework` (`^9.3` for TYPO3 v14) is already listed as `require-dev` in `composer.json` and is installed by `composer install`. Manually, if needed:

```bash
ddev composer require --dev typo3/testing-framework:^9.3
```

### Database configuration

The functional tests require a database connection. By default, `phpunit.functional.xml` is configured with the MariaDB connection of the DDEV environment:

```xml
<env name="typo3DatabaseDriver" value="mysqli"/>
<env name="typo3DatabaseHost" value="db"/>
<env name="typo3DatabasePort" value="3306"/>
<env name="typo3DatabaseUsername" value="root"/>
<env name="typo3DatabasePassword" value="root"/>
```

The testing framework creates its own database for each test, runs the test, and deletes the database afterwards.

Alternatively, SQLite can be used as the database. This requires no server; the data is stored in a temporary file:

```bash
ddev exec typo3DatabaseDriver=pdo_sqlite vendor/bin/phpunit -c packages/imp_exp_nl/phpunit.functional.xml
```

All values can be overridden via environment variables. The values in `phpunit.functional.xml` serve as defaults.

### Running tests

Unit tests and functional tests are started via separate configuration files. The separation is necessary because both require a different bootstrap.

Which test stage is mandatory when (SQLite vs. MariaDB, real-world E2E), the container setup of the test environment, and the release ritual are described in [`Documentation/DEVELOPMENT.md`](Documentation/DEVELOPMENT.md).

```bash
# Unit tests (no database access, fast)
ddev exec vendor/bin/phpunit -c packages/imp_exp_nl/phpunit.unit.xml

# Functional tests (with database)
ddev exec vendor/bin/phpunit -c packages/imp_exp_nl/phpunit.functional.xml

# Single test
ddev exec vendor/bin/phpunit -c packages/imp_exp_nl/phpunit.unit.xml --filter testDryRunDoesNotWriteToDatabase
```

### Mandatory release runs: MariaDB & real-world E2E

The functional suite often runs against SQLite in the standard CI. The real-world test (see `Prüfbericht.md`, section 7) showed that MySQL/MariaDB-specific behavior remains invisible there (affected-rows semantics, implicit commits caused by `TRUNCATE`, value representations). **Before every release**, two additional runs are therefore mandatory:

```bash
# 1) Functional suite against real MariaDB 11.4 (Docker; creates the DB container itself)
Build/test-functional-mariadb.sh

# 2) Complete transfer lifecycle across two real TYPO3 instances
Build/e2e/run.sh
# scalable: PAGES=2000 ASSETS=2000 FILESIZE=204800 Build/e2e/run.sh
```

The E2E script (`Build/e2e/run.sh`) expects the instance containers `impexpnl-src`/`impexpnl-tgt` (TYPO3 with the extension as a path repository on the working directory) along with MariaDB sidecars `impexpnl-db-src`/`impexpnl-db-tgt`, and automatically checks: signed bundle export with key ID, data-lock transfer, rejection on wrong key (exit 4), dry-run gate, full import with asset hash verification, delta no-op idempotency, SIGKILL mid-import with undo-then-retry, as well as rollback to the exact baseline. Exit code 0 = all checks passed. The seed generator (`Build/e2e/seed_generate.php`) creates real image files in configurable quantity/size for this.

### Generating test data

For load and development tests without a large source instance, a dev tool generates synthetic import files of any size (with a valid checksum):

```bash
# JSON
php Build/generate-testdata.php --pages=5000 --content-per-page=8 --out=var/big.json
# JSONL (more compact, memory-friendly)
php Build/generate-testdata.php --pages=20000 --content-per-page=5 --format=jsonl --out=var/big.jsonl
```

The generated file is imported like a regular export:

```bash
vendor/bin/typo3 impexpnl:import var/big.json <target-pid>
```

The functional test `LargeTreeImportTest` uses the same generator and is scalable via environment variables (`IMPEXPNL_PERF_PAGES`, `IMPEXPNL_PERF_CONTENT`, `IMPEXPNL_PERF_FORMAT=jsonl`); it outputs runtime and peak memory.

### Unit tests

The unit tests check the internal logic of the service and domain classes (selection):

| Class | Focus |
|---|---|
| `IntegrityServiceTest` | Checksum/HMAC, downgrade lock, key rotation (key ID, `signingKeys`), legacy format |
| `BundleImportServiceTest` | Bundle hardening: path traversal protection, signed asset checksums |
| `ConflictResolverTest` / `ImportServiceTest` | Field comparison (incl. empty-value equivalence), conflict timestamps, `buildRecordData` |
| `ConfigurationServiceTest` | Defaults & limits (lock timeout, file size limit, signature enforcement) |
| `ExportServiceTest` | `parseSince`, checksum, asset extraction, broken-link detection, metadata |
| `TableRegistryServiceTest` / `ProfileServiceTest` | Link rewriting, category paths; profile validation |
| `Domain/*` | `ConflictStrategy`, `ExportManifest`, `PageLinkRewriter`, `UidMap` |

### Functional tests

The functional tests work with a real database and check the overall flow (selection of ~40 classes):

| Area | Scenarios |
|---|---|
| `ExportImportTest`, `JsonlRoundtripTest` | Export/import roundtrips (JSON + JSONL), hidden filter, depth limit, link rewriting, sorting |
| `DeltaImportTest`, `DryRunMatchesImportTest` | Delta idempotency, conflict strategies, dry run writes nothing and matches a real import |
| `RollbackTest`, `RollbackSafetyTest`, `FullRoundtripAbortTest` | Full & targeted rollback, protection of locally changed records, auto-rollback after abort |
| `ImportLockTest`, `ExitCodeTest` | Cluster lock incl. owner token/stale reaping, differentiated exit codes |
| `Profile/*ContractTest` | Contract per registry profile: export → import → rollback (core, FAL, redirects, categories, news, tt_address) |
| `Transport/Bundle*` | Bundle roundtrip incl. asset placement and rollback GC |
| `RegistryContainmentTest`, `TransferProfileTest`, `SanitizeTest` | PID containment, `allowedTables`/context binding, PII scrubbing (HMAC) |
| `Workspace*`, `Language*`, `Fal*` | Workspace import/publish, translations, FAL edge cases |
| `LargeTreeImportTest`, `PerformanceBaselineTest` | Scaling and performance regression protection |

### Fixture data

The test data is provided as CSV files under `Tests/Functional/Fixtures/`. Each CSV file corresponds to a database table. The first line contains the column names, each further line a record.

Bundled fixtures:

| File | Content |
|---|---|
| `pages.csv` | 6 test pages (tree structure, hidden page, language version) |
| `tt_content.csv` | 5 content elements (text, textmedia, internal link, language version) |
| `sys_category.csv` | 4 categories (hierarchical structure) |
| `sys_category_record_mm.csv` | 2 category assignments |

### Adding custom tests

**Unit test.** An existing test method is copied and given changed input data:

```php
#[Test]
public function customFieldIsPreservedInBuildRecordData(): void
{
    $source = ['uid' => 1, 'title' => 'Test', 'tx_myext_field' => 'Value'];
    $result = $this->callBuildRecordData($source);

    self::assertArrayHasKey('tx_myext_field', $result);
    self::assertEquals('Value', $result['tx_myext_field']);
}
```

**Functional test.** A fixture CSV is created, loaded in the test, and the result is checked:

```php
#[Test]
public function gsbAccordionContentIsExported(): void
{
    $this->importCSVDataSet(__DIR__ . '/../Fixtures/my_gsb_content.csv');

    $json = GeneralUtility::makeInstance(ExportService::class)->exportTree(1);
    $data = json_decode($json, true);

    $accordions = array_filter($data['tt_content'], fn($c) => $c['CType'] === 'accordion');
    self::assertNotEmpty($accordions);
}
```

**Fixture CSV.** The column names correspond to the database fields:

```csv
"uid","pid","CType","header","tx_gsb_accordion_text","sorting","hidden","deleted"
100,2,"accordion","Title","<p>Content</p>",256,0,0
```

### Test structure

```
Tests/
├── Unit/
│   ├── ConfigurationSchemaTest.php        # YAML config against JSON schema
│   ├── Domain/                            # ConflictStrategy, ExportManifest,
│   │                                      # ManifestReader (in-memory/streaming),
│   │                                      # PageLinkRewriter, UidMap
│   └── Service/                           # BundleImport (hardening), Configuration,
│                                          # ConflictResolver, Export, Import,
│                                          # Integrity (incl. key rotation),
│                                          # Profile, TableRegistry
├── Functional/
│   ├── Command/                           # CLI smoke and status tests
│   ├── Profile/                           # Contract tests of the registry profiles
│   │                                      # (core, FAL, redirects, categories,
│   │                                      # news, tt_address, declarative)
│   ├── Service/                           # Export/import/delta/rollback, locks,
│   │                                      # workspaces, languages, FAL, assets,
│   │                                      # sanitize, transfer profiles,
│   │                                      # registry containment, performance
│   ├── Transport/                         # Bundle: roundtrip, transport, asset GC
│   └── Fixtures/                          # CSV fixtures per table
└── Build/                                 # Test bootstraps
```

Additionally under `Build/`: `test-functional-mariadb.sh` (functional suite against MariaDB), `e2e/run.sh` + `e2e/seed_generate.php` (real-world E2E across two instances), `generate-testdata.php` (synthetic import files).

---

## Directory structure

```
imp_exp_nl/
├── Classes/
│   ├── Command/          # check, export, import, list, migrate-legacy-schema,
│   │                     # status, undo, unlock, validate-config
│   ├── Domain/           # ConflictStrategy, ExitCode, ExportManifest,
│   │                     # PageLinkRewriter, SystemFields, TransferProfile, UidMap
│   ├── Event/            # ModifyExportDataEvent, ModifyImportDataEvent (PSR-14)
│   ├── Exception/        # typed errors with exit codes (Lock, Integrity, …)
│   ├── Service/          # Export/import/rollback, ManifestLoader + JsonlStreamReader
│   │                     # (streaming JSONL import), Integrity (HMAC/key rotation),
│   │                     # locks, TableRegistry, AssetManifest, Sanitize, Configuration,
│   │                     # RootlineCacheGuard, repositories (ImportLog, UidMap)
│   └── Transport/        # external/bundle/push transport + factory
├── Configuration/
│   ├── Services.yaml
│   └── Schema/ImpExpNL.schema.json    # JSON schema for YAML autocomplete/CI
├── Build/
│   ├── test-functional-mariadb.sh     # mandatory release run against MariaDB
│   ├── e2e/                           # real-world E2E (run.sh, seed_generate.php)
│   ├── generate-testdata.php
│   └── demo/                          # DDEV demo project
├── Documentation/
│   ├── DEVELOPMENT.md                 # test operations, container setup, release ritual
│   └── PERFORMANCE.md
├── Tests/                             # see test structure above
├── composer.json
├── ext_emconf.php
├── ext_localconf.php
├── ext_tables.sql
├── phpunit.unit.xml
├── phpunit.functional.xml
├── imp_exp_nl.yaml
├── Prüfbericht.md                     # security review + real-world verification
├── CHANGELOG.md
├── LICENSE
└── README.md
```

## License

Copyright © 2026 Robert Schleiermacher

This extension is free software and is released under the GNU General Public
License, version 2 or later (`GPL-2.0-or-later`). The full license text is
in the file [`LICENSE`](LICENSE).

The license choice follows from TYPO3's requirements: the core is licensed
under `GPL-2.0-or-later`, and an extension is a derivative work of it. The
GPL grants the right to use, modify and redistribute the software, provided
that derivative works are made available under the same terms.

Publication is made in the hope that the software is useful, but without
any warranty; without even the implied warranty of merchantability or
fitness for a particular purpose. Details are governed by the GNU General
Public License.

### Continued maintenance by third parties

The `GPL-2.0-or-later` covers all rights required for use and further
development; a separate transfer of rights is not required to take over
maintenance. If authorship is permanently transferred to another party,
only the copyright notices in the file headers (`Classes/**/*.php`) and in
this section need to be adjusted; the content of the `LICENSE` file remains
unchanged.
