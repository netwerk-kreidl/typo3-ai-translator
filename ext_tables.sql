#
# Protokoll aller KI-Übersetzungen
#
CREATE TABLE tx_aitranslator_log (
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    tablename varchar(255) DEFAULT '' NOT NULL,
    record_uid int(11) unsigned DEFAULT '0' NOT NULL,
    language int(11) DEFAULT '0' NOT NULL,
    provider varchar(50) DEFAULT '' NOT NULL,
    model varchar(100) DEFAULT '' NOT NULL,
    records int(11) unsigned DEFAULT '0' NOT NULL,
    segments int(11) unsigned DEFAULT '0' NOT NULL,
    characters int(11) unsigned DEFAULT '0' NOT NULL,
    input_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    output_tokens int(11) unsigned DEFAULT '0' NOT NULL,
    status varchar(20) DEFAULT '' NOT NULL,
    message text,

    KEY page_language (page_uid, language),
    KEY crdate (crdate)
);

#
# Stand jeder übersetzten Feldes: Fingerabdruck des Ausgangstexts zum Zeitpunkt der Übersetzung.
# Weicht der aktuelle Ausgangstext ab, ist die Übersetzung veraltet.
#
CREATE TABLE tx_aitranslator_state (
    tablename varchar(255) DEFAULT '' NOT NULL,
    translation_uid int(11) unsigned DEFAULT '0' NOT NULL,
    source_uid int(11) unsigned DEFAULT '0' NOT NULL,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    language int(11) DEFAULT '0' NOT NULL,
    field varchar(255) DEFAULT '' NOT NULL,
    source_hash varchar(32) DEFAULT '' NOT NULL,
    translation_hash varchar(32) DEFAULT '' NOT NULL,
    provider varchar(50) DEFAULT '' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    UNIQUE KEY translation_field (tablename, translation_uid, field),
    KEY source (tablename, source_uid, language),
    KEY page_language (page_uid, language)
);

#
# Warteschlange für Massenübersetzungen (je Eintrag eine Seite in einer Sprache)
#
CREATE TABLE tx_aitranslator_queue (
    uid int(11) unsigned NOT NULL auto_increment,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    language int(11) DEFAULT '0' NOT NULL,
    provider varchar(50) DEFAULT '' NOT NULL,
    status varchar(20) DEFAULT 'pending' NOT NULL,
    message text,

    PRIMARY KEY (uid),
    KEY status (status, uid),
    KEY page_language (page_uid, language)
);
