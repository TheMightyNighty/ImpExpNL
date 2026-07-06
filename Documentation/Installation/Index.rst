..  _installation:

============
Installation
============

..  _requirements:

Requirements
============

===========  =========================================
TYPO3        14.x LTS (2.x line) / 13.4 LTS (1.x line)
PHP          8.2 – 8.4
Database     MariaDB / MySQL (PostgreSQL untested)
CLI access   required (all operations are commands)
===========  =========================================

The 1.x line for TYPO3 13.4 is in maintenance mode and receives security
fixes only.

..  _installation-composer:

Installation via Composer
=========================

..  code-block:: bash

    composer require themightynighty/impexpnl

Then update the database schema (the extension adds its own tables for the
UID mapping, import log, and import lock):

..  code-block:: bash

    vendor/bin/typo3 extension:setup --extension=imp_exp_nl

..  _installation-verify:

Verify the installation
=======================

..  code-block:: bash

    vendor/bin/typo3 impexpnl:check

``impexpnl:check`` validates the environment (schema, configuration,
signature setup, lock table) and reports actionable findings.

..  _installation-configuration-file:

Configuration file
==================

The main configuration lives in the extension file ``imp_exp_nl.yaml`` and
can be validated at any time:

..  code-block:: bash

    vendor/bin/typo3 impexpnl:validate-config

See :ref:`configuration` for all options.
