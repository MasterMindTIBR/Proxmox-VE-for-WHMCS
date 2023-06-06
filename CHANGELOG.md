# Changelog
All notable changes to Proxmox VE for WHMCS will be documented in this file.

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