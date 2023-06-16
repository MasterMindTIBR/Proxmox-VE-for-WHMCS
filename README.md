# Proxmox VE for WHMCS (Module) Provision & Manage

We're slowly overhauling the Module to suit our internal needs at [LEOPARD.host](https://leopard.host).

- Automatically Provision VMs & CTs in [Proxmox VE](https://proxmox.com/en/proxmox-ve/features) from [WHMCS](https://www.whmcs.com/tour/)
- Allow clients to view/manage VMs using WHMCS Client Area

The original module was written in 2 months by @cybercoder for sale online in 2016, though didn't sell any copies so they kindly open-sourced it and removed the licensing requirement. The manual PDF files are due to be updated (these still mention licensing - ignore this)

https://marketplace.whmcs.com/product/6935-proxmox-ve-for-whmcs

### 🛠️ SYSTEM REQUIREMENTS:

- WHMCS 8.x.x stable (HTTPS)
- WHMCS Service ID >100
- PHP 8.x.x stable
- Proxmox VE 7/8

NOTE: The SID >100 requirement is critical, as Proxmox reserves VMIDs <100.

If you don't have enough in WHMCS, create dummy services until you reach SID 101.

PROXMOX 8: As this major release is in beta (as of June 2023), support is experimental.

### 🤬 ABUSE - ZERO TOLERANCE:

NOTE: This module is being overhauled and is in BETA. Your support is welcomed.

If you cannot accept this, do not download nor use the code. Complaints, nasty reviews, and similar behaviour is against the spirit of FOSS and will not be tolerated. Be grateful & considerate - thank you!

### 🆘 TECHNICAL SUPPORT:

Including logs, details, steps to reproduce, etc, please raise an [Issue](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues).

Information we will need:

- PHP error_log contents
- WHMCS Module Debug Log contents
- Your service/plan/IP/pool/etc configs
- Logs from your Proxmox Host (pveproxy logs)
- Reproduction of errors via pvesh on Proxmox Host

Please note that this is FOSS and Support is not guaranteed.

### 🖥️ LIBRARIES & DEPENDENCIES:

- [PHP Client for PVE2 API](https://github.com/CpuID/pve2-api-php-client) (Dec 5th, 2022)
- [TigerVNC VncViewer.jar](https://sourceforge.net/projects/tigervnc/files/stable/) (v1.13.1 in repo)
- [NoVNC HTML5 Viewer](https://github.com/novnc/noVNC) (v1.4.0 in repo)

### 📄 DOCUMENTATION & RESOURCES:

- Proxmox API: https://pve.proxmox.com/pve-docs/api-viewer/
- TigerVNC: https://github.com/TigerVNC/tigervnc/wiki
- NoVNC: https://github.com/novnc/noVNC/wiki
- WHMCS: https://developers.whmcs.com/

### 🙌 CONTRIBUTING TO THE MODULE:

If you'd like to contribute to the Module, please open a [PR](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/pulls).

**[The Network Crew Pty Ltd](https://thenetworkcrew.com.au)**
