..  _commands:

========
Commands
========

All functionality is exposed as CLI commands below the ``impexpnl:``
namespace. Every command supports ``--json`` for machine-readable output.

..  contents::
    :local:
    :depth: 1

..  _command-export:

impexpnl:export
===============

Exports a page tree to a JSON/JSONL file, including content elements, FAL
references, localizations, and registered tables. Creates
``impexpnl_assets.txt`` (for ``rsync --files-from``) and the signed asset
manifest ``impexpnl_assets.json`` alongside.

..  code-block:: bash

    vendor/bin/typo3 impexpnl:export <startPid> <outputFile> [options]

==========================  ==================================================
Option                      Description
==========================  ==================================================
``--depth=N``               Maximum tree depth (0 = unlimited).
``--include-hidden``        Include hidden/disabled records.
``--pages=1,2,3``           Export only the given page UIDs (no recursion).
``--exclude-pages=9,10``    Exclude page UIDs (and their content).
``--since=Y-m-d``           Only records changed since the given date.
``--content-types=a,b``     Restrict to the given CTypes.
``--csv``                   Additionally write CSV files for tabular review.
``--jsonl``                 Streaming JSONL format — recommended for large
                            exports (constant memory on import).
``--transport=NAME``        Asset transport: ``external`` (default),
                            ``bundle``, ``push``, or a named transport from
                            the configuration.
``--bundle-baseline=FILE``  Incremental bundle: skip files already present
                            (sha256-identical) in the given target manifest.
``--outbox=DIR``            Target directory for ``--transport=push``.
==========================  ==================================================

..  _command-import:

impexpnl:import
===============

Imports an export file (or a ``.tar.gz`` bundle) below a target page.

..  code-block:: bash

    vendor/bin/typo3 impexpnl:import <file> <targetPid> [options]

===========================  =================================================
Option                       Description
===========================  =================================================
``--dry-run`` / ``-d``       Diff analysis only; no lock, no writes. Includes
                             an asset preflight against the manifest.
``--delta``                  Delta import: identical records are skipped,
                             changed ones updated, new ones created.
                             Idempotent on re-runs.
``--conflict=X``             Conflict strategy for delta imports:
                             ``overwrite`` (default), ``skip``, ``ask``,
                             ``abort``.
``--verbose`` / ``-v``       Show per-field differences for changed records.
``--target-workspace=N``     Import into a workspace (0 = live). Workspace
                             imports go through the regular review/publish
                             workflow and can be undone before publishing.
``--profile=NAME``           Load arguments and options from a stored import
                             profile (``var/impexpnl_profiles/``).
``--transfer-profile=NAME``  Apply a transfer profile (policy guardrails,
                             see :ref:`configuration-transfer-profiles`).
===========================  =================================================

..  _command-undo:

impexpnl:undo
=============

Rolls back an import completely: FAL references, registry records, content
elements, and pages (children before parents), in one database transaction.
Files newly placed by a bundle import are removed reference-checked. If
imported records were modified locally after the import, the rollback stops
and lists them (override with ``--force``).

..  code-block:: bash

    vendor/bin/typo3 impexpnl:undo [importId] [--dry-run] [--force]

..  _command-status:

impexpnl:status / impexpnl:list
===============================

``impexpnl:status`` shows the current import lock (owner, age, stale state),
the number of undoable imports, and the last import. ``impexpnl:list`` prints
the import history (``--limit=N``, default 20).

..  _command-inbox:

impexpnl:inbox
==============

Lists packages that arrived in the configured inbox directory
(``transfer.inbox_path``). Read-only by design: receiving a package **never**
triggers an import — importing remains a separate, explicit operator step.

..  code-block:: bash

    vendor/bin/typo3 impexpnl:inbox [path]

..  _command-ops:

impexpnl:check / validate-config / unlock / migrate-legacy-schema
=================================================================

``impexpnl:check``
    Environment and configuration health check with actionable findings.

``impexpnl:validate-config``
    Validates ``imp_exp_nl.yaml`` and all registered
    ``Configuration/ImpExpNL.yaml`` files (schema, profiles, transports).

``impexpnl:unlock``
    Releases a stuck import lock (confirmation prompt; ``--force`` skips it).

``impexpnl:migrate-legacy-schema``
    One-time migration from pre-2.x schema (``--drop-legacy`` removes the old
    columns/tables after migration).

..  _command-exit-codes:

Exit codes
==========

Commands return differentiated exit codes for CI/CD pipelines — for example
``4`` (integrity/signature failure) and ``7`` (assets missing on the target,
reported by the dry-run preflight). A non-zero exit prints operator guidance
describing the next step. The full list is part of the command help and the
project README.
