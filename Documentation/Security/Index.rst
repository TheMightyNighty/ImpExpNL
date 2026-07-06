..  _security:

========
Security
========

ImpExpNL is designed for transfers across security zones. This chapter
summarizes the built-in protections and the recommended setup. For reporting
vulnerabilities see `SECURITY.md
<https://github.com/TheMightyNighty/ImpExpNL/blob/main/SECURITY.md>`__.

..  _security-integrity:

Integrity and signatures
========================

Corruption protection (default)
    Every export carries a SHA256 checksum over the **complete** data block
    (all tables, FAL references, IRRE relations, registry tables). Imports
    verify it and abort on mismatch. This detects accidental damage, not
    deliberate tampering — a plain checksum can be recomputed by anyone.

Tamper protection (recommended)
    With a configured secret key the checksum becomes an **HMAC-SHA256
    signature**. Set the key identically on source and target, either via the
    environment variable ``IMPEXPNL_SIGNING_KEY`` or in
    ``config/system/additional.php``:

    ..  code-block:: php

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKey'] = 'a-long-secret';

    Once any key is configured, **only** HMAC signatures are accepted —
    downgrading to a plain checksum is rejected.

Signature enforcement
    For imports across a security boundary, make signatures mandatory on the
    target. Files without a signature are then rejected with exit code ``4``:

    ..  code-block:: yaml

        import:
          require_signature: true

Key rotation
    With an optional key ID the signature is written as
    ``hmac-sha256:<key-id>:<hash>``. Old keys stay valid during a transition
    period via a configured key map, so packages signed before the rotation
    still verify. Remove old keys from the map after the transition.

    ..  code-block:: php

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeyId'] = 'k2026';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['imp_exp_nl']['signingKeys'] = [
            'k2025' => 'old-key',
        ];

..  _security-hardening:

Hardening measures
==================

- **Bundle hardening:** assets from a ``.tar.gz`` bundle are placed only
  after the export file's signature has been verified; every file is checked
  against its signed sha256; identifiers are validated against path
  traversal; archives containing link members (symlinks/hardlinks) are
  rejected **before** extraction.
- **Path containment:** export targets and import sources must reside inside
  the project directory; profile names are traversal-safe.
- **Cluster-wide import lock:** a database lock (owner token, heartbeat,
  stale timeout) prevents concurrent imports across all nodes; a lost lock
  aborts the import instead of writing in parallel.
- **Crash-safe rollback log:** an aborted import leaves a rollback-capable
  log of everything created so far — no orphaned partial data.
- **Size limits:** ``import.max_file_size_mb`` rejects oversized files
  before parsing.
- **CSV injection protection** on CSV exports; **PII scrubbing**
  (``export.sanitize`` with HMAC pseudonymization) for high-to-low transfers
  such as production → development.

..  _security-zones:

Transfers across security zones
===============================

ImpExpNL is deliberately file-based: there is **no network connection between
the instances**. The transport path (data lock, diode, controlled exchange
area) stays fully under operational control.

Recommended setup:

#.  **Signing key on both sides** (as a secret, never baked into images or
    repositories), with a key ID assigned from the start.
#.  **Signature enforcement on the target** (``import.require_signature``).
#.  **Scope limitation** via a transfer profile: ``allowedContext``,
    ``allowedTables``, and ``requireDryRun`` as a review gate.
#.  **Per transfer:** health check (``impexpnl:check``), take the package
    through the lock, dry-run gate with diff and asset preflight, import,
    evaluate the exit code, keep the import ID and transaction log as an
    audit trail.

..  _security-push:

Outbound push and inbox
=======================

For source-initiated delivery, the ``push`` transport writes a signed bundle
**atomically** into an outbox directory (staging file, then ``rename()``) —
the operator's transport (rsync/SFTP/diode) moves it to the target's inbox.
ImpExpNL never opens a listening service.

**Receiving is not applying:** ``impexpnl:inbox`` only lists arrived
packages. The import remains a separate, explicitly triggered step on the
target — a package arriving can never trigger database writes by itself.
