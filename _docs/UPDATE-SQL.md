# SQL Statements for Updates (nav to DB first)

## v1.3.6 pre-release installs (registered 1.3.6 between 2026-09-25 and 2026-10-02)

> [!IMPORTANT]
> WHMCS runs `pvewhmcs_upgrade()` only when the registered addon version changes. An install that registered a pre-release 1.3.6 build never runs migrations added later to the same 1.3.6 block. Check what is missing, then run only those statements.

```
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND (
  (TABLE_NAME = 'mod_pvewhmcs' AND COLUMN_NAME IN ('console_relay_secret','console_relay_host','console_relay_port','name_pattern'))
  OR (TABLE_NAME = 'mod_pvewhmcs_vms' AND COLUMN_NAME = 'ha_suspended')
  OR (TABLE_NAME = 'mod_pvewhmcs_plans' AND COLUMN_NAME = 'vmbr'));
SHOW TABLES LIKE 'mod_pvewhmcs_logs';
```

```
ALTER TABLE mod_pvewhmcs_plans MODIFY COLUMN `vmbr` varchar(64) DEFAULT NULL;
ALTER TABLE mod_pvewhmcs ADD COLUMN `console_relay_secret` varchar(255) DEFAULT NULL AFTER `debug_mode`;
ALTER TABLE mod_pvewhmcs ADD COLUMN `console_relay_host` varchar(255) DEFAULT NULL AFTER `console_relay_secret`;
ALTER TABLE mod_pvewhmcs ADD COLUMN `console_relay_port` int(5) unsigned DEFAULT NULL AFTER `console_relay_host`;
ALTER TABLE mod_pvewhmcs ADD COLUMN `name_pattern` varchar(255) DEFAULT NULL AFTER `console_relay_port`;
ALTER TABLE mod_pvewhmcs_vms ADD COLUMN `ha_suspended` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `v6prefix`;
CREATE TABLE IF NOT EXISTS `mod_pvewhmcs_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auth_id` int(11) NOT NULL DEFAULT '0',
  `user_id` int(11) NOT NULL DEFAULT '0',
  `service` int(11) NOT NULL DEFAULT '0',
  `timestamp` datetime NOT NULL,
  `node_id` int(11) NOT NULL DEFAULT '0',
  `target_id` int(11) NOT NULL DEFAULT '0',
  `level` varchar(10) NOT NULL,
  `type` text NOT NULL,
  `action` text NOT NULL,
  `request` text NOT NULL,
  `response` text NOT NULL,
  `raw` text NOT NULL,
  PRIMARY KEY (`id`)
);
```

## v1.2.14 & onwards...

> [!NOTE]  
> As we transition to auto-updating, you can interpret manual queries in the `pvewhmcs_upgrade` function.
> 
> It is located in the /modules/addons/pvewhmcs/pvewhmcs.php file near-ish the top. Thank you.

## v1.2.10 to v1.2.12

```
ALTER TABLE mod_pvewhmcs_plans ADD COLUMN `unpriv` int(1) unsigned DEFAULT 0;
```

## v1.2.8 to v1.2.10

```
ALTER TABLE mod_pvewhmcs ADD COLUMN `start_vmid` int(10) DEFAULT 100;
ALTER TABLE mod_pvewhmcs_vms ADD COLUMN `vmid` int(10) DEFAULT NULL;
UPDATE mod_pvewhmcs_vms SET vmid = id WHERE vmid IS NULL;
```

## v1.2.7 to v1.2.8

```
ALTER TABLE mod_pvewhmcs_plans MODIFY COLUMN `vmbr` tinyint(1) unsigned DEFAULT NULL;
```

## v1.2.6 to v1.2.7

```
ALTER TABLE mod_pvewhmcs_plans ADD COLUMN `balloon` varchar(10) DEFAULT '0';
```

## v1.2.3 to v1.2.4

```
ALTER TABLE mod_pvewhmcs_vms ADD COLUMN `v6prefix` varchar(128) DEFAULT NULL;
ALTER TABLE mod_pvewhmcs_plans ADD COLUMN `ipv6` varchar(10) DEFAULT 'auto';
```

## v1.2.1 to v1.2.2

```
ALTER TABLE mod_pvewhmcs ADD COLUMN `debug_mode` tinyint(1) unsigned DEFAULT 0;
ALTER TABLE mod_pvewhmcs_plans ADD COLUMN `vlanid` varchar(10) DEFAULT NULL;
```
