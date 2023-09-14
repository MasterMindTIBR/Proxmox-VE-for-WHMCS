# SQL Statements for Updates (nav to DB first)

### v1.2.1 to v1.2.2

```
ALTER TABLE mod_pvewhmcs ADD COLUMN `debug_mode` tinyint(1) unsigned DEFAULT '0';
ALTER TABLE mod_pvewhmcs_plans ADD COLUMN `vlanid` varchar(10) DEFAULT NULL;
```