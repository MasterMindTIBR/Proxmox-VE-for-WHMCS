# Proxmox VE for WHMCS (Module) Provision & Manage

We're overhauling the Module to suit our internal needs at [LEOPARD.host](https://leopard.host).

- Automatically Provision VMs & CTs in [Proxmox VE](https://proxmox.com/en/proxmox-ve/features) from [WHMCS](https://www.whmcs.com/tour/)
- Allow clients to view/manage VMs using WHMCS Client Area

The original module was written in 2 months by @cybercoder for sale online in 2016, though didn't sell any copies so they kindly open-sourced it and removed the licensing requirement. _The manual PDF files are due to be updated (these still mention licensing - ignore this)_

https://marketplace.whmcs.com/product/6935-proxmox-ve-for-whmcs

### 🛠️ SYSTEM REQUIREMENTS:

- (WHMCS) v8.x.x stable (HTTPS)
- (WHMCS) Service ID >100
- (PHP) v8.x.x stable
- (Proxmox) VE v7/8
- (Proxmox) 2 users

**SID >100:** The WHMCS Service ID requirement is critical, as Proxmox reserves VMIDs <100.

_If you don't have enough services (any status) in WHMCS, create services until you reach SID 101._

### 🥽 VNC CONSOLE TUNNELING:

noVNC (HTML5) is supported via this WHMCS Module, and TigerVNC (Java) is being removed.

To access VNC via WHMCS Client Area, you need to follow the PVE User Requirement below.

**WIP NOTE:** noVNC is being overhauled to deliver on the dual-ticket PVE requirements.

At the moment, the vnc_secret field in the DB (mod_pvewhmcs tables) can't be set via GUI.

Currently, the noVNC functionality is not operational. We are avoiding using iframe instead.

### 👥 PROXMOX USER REQUIREMENT:

You must have a root (etc) account to Create/Access services. Configured via WHMCS Config > Servers.

Additionally, to improve security, for VNC you must have a Restricted User. "" via Module Config.

For the VNC User in Proxmox you need to:
1. Create User Group "VNC" via PVE > Datacenter / Permissions / Group
2. Create new User > Datacenter / Permissions / Users - select Group: "VNC", Realm: pve
3. Create new Role -> Datacenter / Permissions / Roles - select Name: "VNC", Privileges: VM.Console (only)
3. Add permission to access VNC -> Datacenter / Node / VM / Permissions / Add Group Permissions - select Group: "VNC", Role: "VNC"

### 🤬 ABUSE - ZERO TOLERANCE:

**NOTE:** This module is being overhauled and is in beta. Your support is welcomed.

If you cannot accept this, do not download nor use the code. Complaints, nasty reviews, and similar behaviour is against the spirit of FOSS and will not be tolerated. Be grateful & considerate - thank you!

### 🆘 TECHNICAL SUPPORT:

Including logs, details, steps to reproduce, etc, please raise an [Issue](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues).

Information we will need, at a minimum:

- (PHP) error_log contents
- (WHMCS) Module Debug Log contents
- (Configs) WHMCS/PHP/Module/Proxmox/etc
- (PVE) Logs from Proxmox Host (pveproxy etc)
- (Reproduction) pvesh/etc variants of failing calls

Please note that this is FOSS and Support is not guaranteed.

This module is licensed via the GNU General Public License v3.0.

### 🖥️ LIBRARIES & DEPENDENCIES:

- [PHP Client for PVE2 API](https://github.com/CpuID/pve2-api-php-client) (Dec 5th, 2022)
- [TigerVNC VncViewer.jar](https://sourceforge.net/projects/tigervnc/files/stable/) (v1.13.1 in repo)
- [noVNC HTML5 Viewer](https://github.com/novnc/noVNC) (v1.4.0 in repo)

### 📄 DOCUMENTATION & RESOURCES:

- Proxmox API: https://pve.proxmox.com/pve-docs/api-viewer/
- TigerVNC: https://github.com/TigerVNC/tigervnc/wiki
- noVNC: https://github.com/novnc/noVNC/wiki
- WHMCS: https://developers.whmcs.com/

### 🙌 CONTRIBUTING TO THE MODULE:

If you'd like to contribute to the Module, please open a [PR](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/pulls).

We would like to thank [@cybercoder](https://github.com/cybercoder/) and [@WaldperlachFabi](https://github.com/WaldperlachFabi) for their original contributions and troubleshooting assistance respectively. FOSS is only possible thanks to dedicated individuals!

**[The Network Crew Pty Ltd](https://thenetworkcrew.com.au)**

**[LEOPARD.host](https://leopard.host)**
