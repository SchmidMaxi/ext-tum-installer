CREATE TABLE tx_webinfo_domain_model_website (
    url varchar(500) NOT NULL DEFAULT '',
    domain varchar(255) NOT NULL DEFAULT '',
    nav_name varchar(100) NOT NULL DEFAULT '',
    wid varchar(20) NOT NULL DEFAULT '',
    setup varchar(50) NOT NULL DEFAULT '',
    umgebung varchar(50) NOT NULL DEFAULT '',
    organization_unit varchar(255) NOT NULL DEFAULT '',
    website_type varchar(100) NOT NULL DEFAULT '',
    typo3_version varchar(10) NOT NULL DEFAULT '',
    created_at int(11) unsigned NOT NULL DEFAULT '0',
    valid_until int(11) unsigned NOT NULL DEFAULT '0',
    after_expiry varchar(50) NOT NULL DEFAULT '',
    note text,

    KEY url (url(191)),
    KEY domain (domain),
    KEY wid (wid)
);
