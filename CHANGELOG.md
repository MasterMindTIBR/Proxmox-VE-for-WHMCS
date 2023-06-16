# Changelog
All notable changes to Proxmox VE for WHMCS will be documented in this file.

## [1.2] - Not yet released

### Added
- Link off to GitHub Issues for Support from the Module page in WHMCS
- CHANGELOG.md file added to repository to track in recommended format
- Try-catch around the Creation API Call, routing OK/error into WHMCS
- Feed the IP/GW configuration into QEMU and LXC creation attempts
- PVE Storage volume field and Disk I/O Limit fields added (#7)
- PHP and Web Server reported on the Health/Support GUI tab
- License the repository/module via GPLv3 (link-back etc)

### Changed
- Change relative to ROOTDIR in IPv4 file, in case of other issues
- Use /cluster/resources via API, not /node/, to get stats (ex. swap)
- Updated noVNC, TigerVNC, Ubuntu, Debian and CentOS interface images
- Improved error handling and pass-back from Proxmox to Class to WHMCS
- Updated the PVE2 API Class and improved its logging (prefix/exception)

### Fixed
- Regression in v1.1 with missing semicolon breaking activation (#14)
- Edit Icon not rendering on IP/Pool edit page, missing WHMCS (#13)
- Relative link to PVE2 API Class file broken, use ROOTDIR (#13/15)
- IPv4 Address functions, update file to use float not real (#13)
- Container (CT/LXC) Swap reporting in Client Area now working
- RRD (Usage) measurements: parameters attached to requests

## [1.1] - 2023-06-06
 
### Added
- Swap space editing for plans; back-end existed but not GUI editing
- Modern-day language to GUI according to changes in the 6 years
 
### Changed
- Module Name from "PRVE" to "pvewhmcs" (ie. Proxmox VE for WHMCS)
- Default storage/disk type changed from IDE to Virtio (fastest)
- Updated 3 dependencies to latest: PVE2-PHP, NoVNC, TigerVNC
- Removed all code segments relating to software licensing
- DNS defaults changed from Google DNS to Cloudflare DNS
 
### Fixed
- Module can now be installed onto WHMCS 8.x installations
- OpenVZ changed to LXC, to support PVE 4 installs and up
- Removed I/O Priority setting, to re-do via Throttling
- Catch error in Client Area if can't reach Proxmox

## [1.0] - 2017-01-26

### Added
- Open-sourced the previously commercial plugin

### Changed
- Commented out the licensing code segments

### Fixed
- Removed old database schema import file