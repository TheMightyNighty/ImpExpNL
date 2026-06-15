CREATE TABLE pages (
    tx_robbicopy_remote_uid int DEFAULT 0 NOT NULL
);

CREATE TABLE tt_content (
    tx_robbicopy_remote_uid int DEFAULT 0 NOT NULL
);

#
# Datenbankbasiertes Import-Protokoll (ersetzt dateisystembasierte Lock-Dateien)
# Überlebt Container-Neustarts in Kubernetes/OpenShift (GSB 11 Air-Gap)
#
CREATE TABLE tx_robbicopy_import_log (
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
