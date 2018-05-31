CREATE TABLE IF NOT EXISTS `#__apirone_sale` (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        address text NOT NULL,
        order_id int DEFAULT '0' NOT NULL,
        PRIMARY KEY  (id)
    ) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `#__apirone_transactions` (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        paid bigint DEFAULT '0' NOT NULL,
        confirmations int DEFAULT '0' NOT NULL,
        thash text NOT NULL,
        order_id int DEFAULT '0' NOT NULL,
        PRIMARY KEY  (id)
    ) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `#__apirone_key` (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        mdkey text NOT NULL,
        PRIMARY KEY  (id)
    ) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;

INSERT INTO `#__apirone_key` (`id`, `mdkey`) VALUES (NULL, MD5(NOW()));