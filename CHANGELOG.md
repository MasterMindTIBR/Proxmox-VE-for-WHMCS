# Changelog
All notable changes to Proxmox VE for WHMCS will be documented in this file.

## [1.2.3] - TBC

### 🚀 Feature
- x86-64-ABI: Add options; Emulation default now `x86-64-v2-AES`

### 💅 Polish
- README.md: Add VM/CT creation explanations from old manual
- Manual PDFs: Remove pointless one, archive good one, merge in
- Logo: Per request from Proxmox Server Solutions, we got a logo :)

### 🐛 Bug Fix
- PHP v8.0/8.1: Verified no problems operating on the v8 branch

## [1.2.2] - 2023-09-15

### 🚀 Feature
- Debugging Mode: Allow admin to turn on/off Module Log feed (#38)*
- VLAN ID: Set the required Virtual LAN ID against VM/CT Plan (#35)*
- Version: Report in-use & latest versions in Health; ver alert (#21)
- Power Actions: Now available in Admin Area as well as Client Area
- (Note: Suspend/Unsuspend/Terminate remain admin-only functions)

### 💅 Polish
- Suspend/Unsuspend: Functions changed to Stop/Start (fixes #34)
- Client Area: Power Action wording amended (Soft Stop, Hard Stop)
- Admin, Module Config: Explain what the VNC Secret field is about
- Admin, Module Config: House-keeping to design, Support/Health tab

### 🐛 Bug Fix
- Admin, Create Service: Fails if Plan/Pool not assigned in WHMCS (#36)
- Client, VNC: Fails early if VNC Secret is not set or adequate (#27)
- On-boot Status: Enabled/Disabled now properly applied for CTs (#34)

(\*): SQL Note: There are new columns in 2 of the module tables, see [SQL.md](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/blob/master/SQL.md)

## [1.2.1b] - 2023-06-19

### 🚀 Feature
- Module Config tab, allowing for configuration of the VNC Secret
- Reboot command/action added to Client Area (ie. on/off/hard-off)
- Link from Health tab of Admin GUI to WHMCS Marketplace re: reviews
- Images for all supported Operating Systems & Kernel types (some fixed)
- noVNC overhauled, to send PVE Cookie (ticket) and VNC Access Ticket also

### 💅 Polish
- Stop VM/CT (Client Area) renamed to Hard Stop, compared to Shut Down
- Modify the PHP API2 class, adding getTicket() so we can dual-auth (VNC)
- Move VNC Clients from root-level to vnc-only-level access to Proxmox VE

### 🐛 Bug Fix
- noVNC render method updated to stop out-of-order data flow problem
- noVNC back-end vncproxy and vncwebsocket methods updated re: spec
- Client Area actions (Power Off/On, etc) fixed for LXC (QEMU OK)
- Error with both VNC methods. We are going to remove TigerVNC

## [1.2.0b] - 2023-06-18

### 🚀 Feature
- Link off to GitHub Issues for Support from the Module page in WHMCS
- CHANGELOG.md file added to repository to track in recommended format
- Try-catch around the Creation API Call, routing OK/error into WHMCS
- Feed the IP/GW configuration into QEMU and LXC creation attempts
- PVE Storage > Volume Name and Disk I/O Limit fields added (#7)
- Module, PHP & Server reported on the Health/Support GUI tab
- Licensed repository/module via GPLv3 (link-back attribution)
- Warning in README.md re: WHMCS Service ID being > 100
- Zero Tolerance Abuse Policy added to README file

### 💅 Polish
- Module versioning changed to semver (semantic versioning) 1.2.0
- Change rel. path to ROOTDIR in IPv4 file, in case of other issues
- Use /cluster/resources via API, not /node/, to get stats (ex. swap)
- Updated noVNC, TigerVNC, Ubuntu, Debian and CentOS interface images
- Improved error handling and pass-back from Proxmox to Class to WHMCS
- Updated the PVE2 API Class and improved its logging (prefix/exception)
- Method to fire API Calls updated due to reduction in WHMCS param scope

### 🐛 Bug Fix
- Regression in v1.1 with missing semicolon breaking activation (#14)
- Edit Icon not rendering on IP/Pool edit page, missing asset (#13)
- Relative link to PVE2 API Class file broken, use ROOTDIR (#13/15)
- IPv4 Address functions, update file to use float not real (#13)
- Container (CT/LXC) Swap reporting in Client Area now working
- RRD (Usage) measurements: params attached to requests OK
- API Requests for Creation now functional (fixes #17)
- Client Area pages/actions now fixed (fixes #19)
- Font Awesome icons fixed in the Client Area

## [1.1b] - 2023-06-06
 
### 🚀 Feature
- Swap space editing for plans; back-end existed but not GUI editing
- Modern-day language to GUI according to changes in the 6 years
- README now links out to all Dependencies and Documentation
 
### 💅 Polish
- Module Name from "PRVE" to "pvewhmcs" (ie. Proxmox VE for WHMCS)
- Default storage/disk type changed from IDE to Virtio (fastest)
- Updated 3 dependencies to latest: PVE2-PHP, noVNC, TigerVNC
- Removed all code segments relating to Software Licensing
- DNS defaults changed from Google DNS to Cloudflare DNS
 
### 🐛 Bug Fix
- Module can now be installed onto WHMCS 8.x installations
- OpenVZ changed to LXC, to support PVE 4.x installs & up
- Removed I/O Priority setting, to re-do via Throttling
- Catch exception in Client Area if can't reach Proxmox

## [1.0] - 2017-01-26

Thank you @cybercoder for open-sourcing your code! 

### 🚀 Feature
- Open-sourced the previously commercial plugin

### 💅 Polish
- Commented out the licensing code segments

### 🐛 Bug Fix
- Removed old database schema import file
