..  _introduction:

============
Introduction
============

..  _what-it-does:

What does it do?
================

ImpExpNL transfers page trees, content elements, and referenced files between
TYPO3 installations. All operations are CLI commands, which makes them
scriptable, CI/CD-friendly, and usable on systems without backend access.

Typical use case: content is produced on a development or editing instance and
transferred — reviewed and verifiable — to staging and then to a live
production site.

..  _features:

Features
========

Export
    Page trees (recursive or selective), content elements, FAL file
    references, localizations, and tables registered by third-party
    extensions. Output is human-readable JSON, streaming JSONL (constant
    memory), or additional CSV for tabular review.

Import
    Dry-run with a diff preview (new / changed / identical records), delta
    mode that skips identical records (idempotent re-runs), configurable
    conflict strategies, workspace support, automatic slug regeneration, and
    link rewriting (``t3://`` and configurable URL rewrites).

Integrity and signatures
    Every export carries a checksum over the complete data block. With a
    configured signing key the checksum becomes an HMAC-SHA256 signature
    including key rotation support. Imports can enforce signatures.

Asset transfer
    A signed asset manifest (storage, identifier, size, sha256) describes all
    referenced files. Transports: ``external`` (rsync & Co. driven by the
    operator), ``bundle`` (self-contained ``.tar.gz`` incl. incremental
    baseline), and ``push`` (outbound-only delivery into a target inbox).

Rollback
    Every import is logged and can be undone (``impexpnl:undo``), including
    reference-checked garbage collection for files placed by the import.

Operations
    Cluster-wide import lock with stale detection, differentiated exit codes
    for CI/CD, JSON output for all commands, status and history commands,
    and a signed transfer report per import (``impexpnl:report``) as an
    audit artifact.

..  _comparison-impexp:

Relation to EXT:impexp
======================

The core extension ``impexp`` (T3D/XML) targets interactive one-off transfers
via the backend. ImpExpNL targets repeatable, automated transfers: readable
and diffable formats, delta semantics, signatures, rollback, and transports
designed for separated security zones. There is no backend module by design —
the CLI is the product.

..  _support:

Support
=======

- Source code and issues: `github.com/TheMightyNighty/ImpExpNL
  <https://github.com/TheMightyNighty/ImpExpNL>`__
- Security reports: see `SECURITY.md
  <https://github.com/TheMightyNighty/ImpExpNL/blob/main/SECURITY.md>`__
  (private vulnerability reporting, no public issues).
