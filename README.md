# Proxmox VE for WHMCS (Module) Provision & Manage

We're mostly done overhauling the Module to suit our internal needs at [LEOPARD.host](https://leopard.host).

PLEASE: Read the entire README.md file before getting started with Proxmox VE for WHMCS. Thanks!

- Configure VM/CT plans with custom CPU/RAM/VLAN/On-boot/Bandwidth/etc
- Automatically Provision VMs & CTs in [Proxmox VE](https://proxmox.com/en/proxmox-ve/features) from [WHMCS](https://www.whmcs.com/tour/) easily
- Allow clients to view/manage VMs using the WHMCS Client Area
- Create/Suspend/Unsuspend/Terminate via WHMCS Admin Area
- Statistics/Graphing is available in the Client Area for services :)

The original module was written in 2 months by @cybercoder for sale online in 2016, though didn't sell any copies so they kindly open-sourced it and removed the licensing requirement. _The manual PDF files are due to be updated (these still mention licensing - ignore this)_

> **Please review the module!** https://marketplace.whmcs.com/product/6935-proxmox-ve-for-whmcs
> 
> If you want it to remain free and fabulous, it could use a moment of your time in reviewing it. Thanks!

## 🎯 MODULE: SYSTEM REQUIREMENTS 🎯

New Biz: Fresh Installations/Businesses using WHMCS need to take note of the Service ID < 100 case.

**SID >100:** The WHMCS Service ID requirement is CRITICAL, as Proxmox reserves VMIDs <100 (system). 

- (WHMCS) v8.x.x stable (HTTPS)
- (WHMCS) **Service ID above 100**
- (PHP) v8.x.x (stable version)
- (Proxmox) VE v7/8 (current)
- (Proxmox) 2 users (API/VNC)

_If you don't have enough services (of any status) in WHMCS (DB: tblhosting.id), create enough dummy/test entries to reach Service ID 101+._ **Else you're likely to see an error which explains this:** `HTTP/1.1 400 Parameter verification failed. (invalid format - value does not look like a valid VM ID)`

## ✅ MODULE: INSTALL & CONFIG ✅

Firstly, you need to upload, activate and make the WHMCS Module available to Administrators.

Once you've done all of that, in order to get the module working properly, you need to:

1. WHMCS Admin > Addons > Proxmox VE for WHMCS > Module Config > VNC Secret (see below)
2. WHMCS Admin > Addons > Proxmox VE for WHMCS > Add KVM/LXC Plan/s
3. WHMCS Admin > Addons > Proxmox VE for WHMCS > Add an IP Pool
4. WHMCS Admin > Config > Products/Services > New Service (create offering)
5. " " > Newly-added Service > Tab 3 > SAVE (associates Plan/Pool to WHMCS Service type)

#### "MANUAL" PDF FILES (5-10 years old)

For now, please use the Manual PDFs as supplementary information, re: ISO files, LXC templates, etc. This is out-dated though still helpful contextually - please read the note at the top of this README.

## 🥽 noVNC: CONSOLE TUNNELING 🥽

After forking the module, we considered how to improve security of Console Tunneling via WHMCS. We decided to implement a routing method which uses a secondary user in Proxmox VE with very restrictive permissions. This requires more work to make it function, however improves security.

**To access VNC via WHMCS Client Area, you need to:**

1. Install & configure the module properly
2. Follow the PVE User Requirement info below
3. Public IPv4 for PVE (or proxy to private)
4. PVE and WHMCS on the same Domain Name*
5. Have valid PTR/rDNS for the PVE Address

noVNC has been overhauled. It is not guaranteed, nor the project at all. :-)

\* Notice 1 = You must use different Subdomains on the same Domain Name, for the cookie (anti-CSRF).

\* Notice 2 = If your Domain Name has a 2-part TLD (ie. co.uk) then you will need to fork & amend novnc_router.php - ideally we/someone will optimise this to better cater to all formats.

## 👥 PROXMOX: USERS (ROOT & VNC) 👥

You must have a root (etc) account to Create/Access services. Configured via WHMCS Config > Servers.

Additionally, to improve security, for VNC you must have a Restricted User. Configured in the Module.

**For the VNC User in Proxmox, you need to:**

1. Create User Group "VNC" via PVE > Datacenter / Permissions / Group
2. Create new User "vnc" > Datacenter / Permissions / Users - select Group: "VNC", Realm: pve
3. Create new Role -> Datacenter / Permissions / Roles - select Name: "VNC", Privileges: VM.Console (only)
4. Add permission to access VNC -> Datacenter / Node / VM / Permissions / Add Group Permissions - select Group: "VNC", Role: "VNC"
5. Configure the WHMCS > Modules > Proxmox VE for WHMCS > Module Config > VNC Secret with 'vnc' password.

## 🤬 ABUSE: ZERO TOLERANCE 🤬

This module has been overhauled and remains functionally-OK but not thoroughly tested nor reviewed.

Your support and assistance is welcomed per the spirit of FOSS (Free Open-source Software)!

If you cannot accept this, do not download nor use the code. Complaints, nasty reviews, and similar behaviour is against the spirit of FOSS and will not be tolerated. 

Be grateful & considerate - thank you!

## 🆘 TECHNICAL SUPPORT 🆘

Wiki: https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/wiki

Including logs, details, steps to reproduce, etc, please raise an [Issue](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues).

Information we will need, at a minimum please:

- (PHP) error_log contents
- (WHMCS) Module Debug Log contents
- (Configs) WHMCS/PHP/Module/Proxmox/etc
- (PVE) Logs from Proxmox Host (pveproxy etc)
- (Reproduction) pvesh/etc variants of failing calls

Please note that this is FOSS and Support is not guaranteed.

This module is licensed under the GNU General Public License (GPL) v3.0.

## 🔄 UPDATING THE ADDON MODULE 🔄

WHMCS Admin > Addon Modules > Proxmox VE for WHMCS > Support/Health shows updates.

You can download the new version and upload it over the top, then run any SQL ops.

Please consult the [SQL.md](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/blob/master/SQL.md) file, open your WHMCS DB & run the statements. Then you're done.

## 🖥️ LIBRARIES & DEPENDENCIES 🖥️

- [PHP Client for PVE2 API](https://github.com/CpuID/pve2-api-php-client) (Dec 5th, 2022)
- [TigerVNC VncViewer.jar](https://sourceforge.net/projects/tigervnc/files/stable/) (v1.13.1 in repo)
- [noVNC HTML5 Viewer](https://github.com/novnc/noVNC) (v1.4.0 in repo)

## 📄 DOCUMENTATION & RESOURCES 📄

- Proxmox API: https://pve.proxmox.com/pve-docs/api-viewer/
- TigerVNC: https://github.com/TigerVNC/tigervnc/wiki
- noVNC: https://github.com/novnc/noVNC/wiki
- WHMCS: https://developers.whmcs.com/

## 🎉 CONTRIBUTING TO THE MODULE 🎉

If you'd like to contribute to the Module, please open a [PR](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/pulls).

We would like to thank [@cybercoder](https://github.com/cybercoder/) and [@WaldperlachFabi](https://github.com/WaldperlachFabi) for their original contributions and troubleshooting assistance respectively. FOSS is only possible thanks to dedicated individuals!

**[The Network Crew Pty Ltd](https://thenetworkcrew.com.au)**

**[LEOPARD.host](https://leopard.host)**

SUPPORT: via GitHub Issues only
