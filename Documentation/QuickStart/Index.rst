..  _quickstart:

===========
Quick start
===========

A content transfer follows a fixed sequence: export on the source, transfer
files, dry-run on the target, import, and — if needed — rollback.

..  _quickstart-export:

1. Export on the source system
==============================

The page tree is collected recursively starting from a page UID and written
to a JSON (or, recommended for large exports, JSONL) file. A companion file
listing all referenced asset paths is created alongside.

..  code-block:: bash

    vendor/bin/typo3 impexpnl:export 123 var/export.jsonl --jsonl

..  _quickstart-files:

2. Transfer the files
=====================

Physical files (images, documents) are transferred separately. The generated
``impexpnl_assets.txt`` is formatted for ``rsync --files-from``:

..  code-block:: bash

    rsync -avz --files-from=var/impexpnl_assets.txt \
        fileadmin/ user@target:/var/www/html/fileadmin/

Alternatively, use the ``bundle`` transport to create a single self-contained
``.tar.gz`` including all assets (see :ref:`security-zones`).

..  _quickstart-dryrun:

3. Dry-run on the target system
===============================

The dry-run compares the import data with the current state of the target
and prints a diff preview (new / changed / identical records) plus an asset
preflight. No data is written.

..  code-block:: bash

    vendor/bin/typo3 impexpnl:import var/export.jsonl 456 --dry-run

..  _quickstart-import:

4. Import
=========

..  code-block:: bash

    # Direct import
    vendor/bin/typo3 impexpnl:import var/export.jsonl 456

    # Delta import (skips identical records, idempotent)
    vendor/bin/typo3 impexpnl:import var/export.jsonl 456 --delta

    # Import into a workspace for editorial review before publishing
    vendor/bin/typo3 impexpnl:import var/export.jsonl 456 --target-workspace=1

..  _quickstart-rollback:

5. Rollback (if needed)
=======================

..  code-block:: bash

    # Undo the last import, with preview and confirmation
    vendor/bin/typo3 impexpnl:undo

Every import is logged; ``impexpnl:list`` shows the history,
``impexpnl:status`` the current lock and open imports.
