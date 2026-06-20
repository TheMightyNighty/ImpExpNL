#
# Herkunfts-Mapping (Quell-Record -> Ziel-Record) für idempotente (Delta-)Importe.
# Ersetzt die früheren tx_impexpnl_remote_uid-Spalten auf pages/tt_content:
# hält Core-Tabellen sauber, funktioniert einheitlich für ALLE Tabellen und
# unterscheidet Quellsysteme über source_id (Multi-Source-fähig).
#
CREATE TABLE tx_impexpnl_uid_map (
    uid int NOT NULL auto_increment,
    source_id varchar(255) DEFAULT '' NOT NULL,
    table_name varchar(255) DEFAULT '' NOT NULL,
    source_uid int DEFAULT 0 NOT NULL,
    target_uid int DEFAULT 0 NOT NULL,
    import_id varchar(30) DEFAULT '' NOT NULL,
    crdate int DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY source_lookup (source_id, table_name, source_uid),
    KEY target_lookup (table_name, target_uid),
    KEY import_id (import_id)
);

#
# Datenbankbasiertes Import-Protokoll (ersetzt dateisystembasierte Lock-Dateien)
# Überlebt Container-Neustarts in Kubernetes/OpenShift (GSB 11 Air-Gap)
#
CREATE TABLE tx_impexpnl_import_log (
    uid int NOT NULL auto_increment,
    import_id varchar(30) DEFAULT '' NOT NULL,
    tstamp int DEFAULT 0 NOT NULL,
    workspace_id int DEFAULT 0 NOT NULL,
    uid_map mediumtext,
    source_file varchar(500) DEFAULT '' NOT NULL,
    delta_mode tinyint DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    KEY import_id (import_id)
);

#
# Cluster-weiter Import-Lock (Concurrency-Schutz über alle Pods/Knoten hinweg).
# Eine eindeutige lock_id verhindert parallele Importe; veraltete Locks werden
# nach einer Zeitspanne automatisch freigegeben.
#
CREATE TABLE tx_impexpnl_lock (
    lock_id varchar(64) DEFAULT '' NOT NULL,
    info varchar(255) DEFAULT '' NOT NULL,
    created int DEFAULT 0 NOT NULL,

    PRIMARY KEY (lock_id)
);
