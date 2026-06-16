CREATE TABLE pages (
    tx_impexpnl_remote_uid int DEFAULT 0 NOT NULL
);

CREATE TABLE tt_content (
    tx_impexpnl_remote_uid int DEFAULT 0 NOT NULL
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
