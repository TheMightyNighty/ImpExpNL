..  _configuration:

=============
Configuration
=============

The main configuration is the YAML file ``imp_exp_nl.yaml`` in the extension
directory. Validate it any time with ``vendor/bin/typo3
impexpnl:validate-config`` (a JSON schema for editor autocompletion ships in
``Configuration/Schema/``).

..  _configuration-main:

Main options
============

..  code-block:: yaml

    export:
      include:
        file_references: true       # export FAL references with file paths

    import:
      include:
        file_references: true       # resolve FAL by file path on import
      container_support: true       # remap b13/container layouts
      require_signature: false      # enforce HMAC signatures (see Security)
      max_file_size_mb: 512         # reject oversized files before parsing
      lock_stale_seconds: 3600      # stale timeout for the import lock
      batch_size: 500               # records per DataHandler batch
      auto_rollback_on_failure: true
      link_rewrite:
        fields: [bodytext, pi_flexform]   # fields with t3://page links

    transfer:
      inbox_path: ''                # inbox directory for pushed bundles

..  _configuration-table-registry:

Table registry
==============

Arbitrary tables can be included in export/import declaratively. ImpExpNL
reads table definitions from two sources: its own ``imp_exp_nl.yaml`` and any
loaded extension providing a ``Configuration/ImpExpNL.yaml`` — third-party
extensions register their tables without modifying ImpExpNL. Tables missing
on the target are skipped silently.

..  code-block:: yaml

    impexpnl:
      tables:
        sys_redirect:               # type "record": rows with a pid
          type: record
          pid_field: pid
          uid_remap: true
          rewrite_links: [target]

        sys_category_record_mm:     # type "mm": relation tables
          type: mm
          match_field: uid_foreign
          match_tablenames_field: tablenames
          match_tables: [pages, tt_content]
          category_match: path      # match categories by rootline path

Contract-tested example profiles for common extensions (news, tt_address,
redirects, categories, FAL) ship with the extension.

..  _configuration-import-profiles:

Import profiles
===============

Recurring import configurations can be stored as YAML files in
``var/impexpnl_profiles/`` and loaded with ``--profile=NAME``. A profile
bundles source file, target PID, workspace, delta mode, and conflict
strategy.

..  _configuration-transfer-profiles:

Transfer profiles (policy guardrails)
=====================================

Transfer profiles separate *what is allowed* (policy) from *how data moves*
(transport). A profile can enforce:

``requireDryRun``
    The import refuses to run unless a dry-run for the same file was
    completed first — a review gate for pipelines.

``allowedContext``
    Binds the profile to a TYPO3 application context (for example only
    ``Production/Staging``), preventing a production profile from running
    on the wrong instance.

``conflictStrategy``
    Fixes the conflict handling, overriding CLI options.

``allowedTables``
    Restricts which registry tables the import may touch.

Named asset transports (``assetTransports``) configure ``bundle`` options
(compressor, baseline) and ``push`` outboxes; ``--transport`` on the export
command resolves them by name. ``impexpnl:validate-config`` validates
profiles and transports.
