..  _start:

===============================
ImpExpNL – CLI Content Transfer
===============================

:Extension key:
    imp_exp_nl

:Package name:
    themightynighty/impexpnl

:Version:
    |release|

:Language:
    en

:Author:
    Robert Schleiermacher

:License:
    This document is published under the
    `Open Publication License <https://www.opencontent.org/openpub/>`__.

:Rendered:
    |today|

----

ImpExpNL transfers pages, content elements, and files between TYPO3
installations — CLI-first, scriptable, and designed for operation across
security zones (development → staging → production).

Exports are human-readable JSON or streaming JSONL, optionally signed with
HMAC-SHA256. Imports support dry-run diff previews, delta synchronization,
conflict handling, FAL asset transfer, workspaces, and a full rollback.

----

**Table of contents**

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    QuickStart/Index
    Commands/Index
    Configuration/Index
    Security/Index
