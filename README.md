# Proxmox VE for WHMCS (Module) Provision & Manage

**Salvation, a free and open-source solution for beloved PVE!** If you love it, REVIEW & SHARE IT! ❤️

- Configure VM/CT plans with custom CPU/RAM/VLAN/On-boot/Bandwidth/etc
- Automatically Provision VMs & CTs in [Proxmox VE](https://proxmox.com/en/proxmox-ve/features) from [WHMCS](https://www.whmcs.com/tour/) easily
- Allow clients to view/manage VMs using the WHMCS Client Area
- Create/Suspend/Unsuspend/Terminate via WHMCS Admin Area
- Statistics/Graphing is available in the Client Area for services :)

> **Please review the module!** https://marketplace.whmcs.com/product/6935-proxmox-ve-for-whmcs
> 
> If you want it to remain free and fabulous, it could use a moment of your time in reviewing it. Thanks!

We're pretty much done overhauling the Module to suit our needs at [The Network Crew Pty Ltd (TNC)](https://thenetworkcrew.com.au).

PLEASE: Read the entire README.md file before getting started with Proxmox VE for WHMCS. Thanks!

## 🎯 MODULE: PVE/WHMCS System Requirements 🎯

New Biz: Fresh Installations/Businesses using WHMCS need to take note of the Service ID < 100 case.

**SID >100:** The WHMCS Service ID requirement is CRITICAL, as Proxmox reserves VMIDs <100 (system). 

- (WHMCS) v8.x.x stable (HTTPS)
- (WHMCS) **Service ID above 100**
- (PHP) v8.x.x (stable version)
- (Proxmox) VE v7/8 (current)
- (Proxmox) 2 users (API/VNC)

_If you don't have enough services (of any status) in WHMCS (DB: tblhosting.id), create enough dummy/test entries to reach Service ID 101+._ **Else you're likely to see an error which explains this:** `HTTP/1.1 400 Parameter verification failed. (invalid format - value does not look like a valid VM ID)`

## ✅ MODULE: Installation & Configuration ✅

Firstly, you need to upload, activate and make the WHMCS Module available to Administrators.

Once you've done all of that, in order to get the module working properly, you need to:

1. WHMCS Admin > Config > Servers > Add your PVE host/s (user: root; IP: PVE's)
2. WHMCS Admin > Addons > Proxmox VE for WHMCS > Module Config > VNC Secret (see below)
3. WHMCS Admin > Addons > Proxmox VE for WHMCS > Add KVM/LXC Plan/s
4. WHMCS Admin > Addons > Proxmox VE for WHMCS > Add an IP Pool
5. WHMCS Admin > Config > Products/Services > New Service (create offering)
6. " " > Newly-added Service > Tab 3 > SAVE (links Module Plan to WHMCS Service type)

> Note: At the moment, the new Connection Test in WHMCS shows an empty red box. Try an action to test.

## 🥽 noVNC: Console Tunnel (Client Area) 🥽

After forking the module, we considered how to improve security of Console Tunneling via WHMCS. We decided to implement a routing method which uses a secondary user in Proxmox VE with very restrictive permissions. This requires more work to make it function, however improves security.

### To offer VNC via WHMCS Client Area

1. Install & configure the module properly
2. Follow the PVE User Requirement info below
3. Public IPv4 for PVE (or proxy to private)
4. PVE and WHMCS on the same Domain Name*
5. Have valid PTR/rDNS for the PVE Address

noVNC has been overhauled. It isn't guaranteed, nor the project at all. :-)

- Note #1 = You must use different Subdomains on the same Domain Name, for the cookie (anti-CSRF).
- Note #2 = If your Domain Name has a 2-part TLD (ie. co.uk) then you will need to fork & amend novnc_router.php - ideally we/someone will optimise this to better cater to all formats.

## 👥 PVE: User Requirements (API & VNC) 👥

**You must have a root account to use the Module at all.** Configured via WHMCS > Servers.

Additionally, to improve security, for VNC you must also have a Restricted User. Configured in the _Module_.

### Creating the VNC user within PVE

1. Create User Group "VNC" via PVE > Datacenter / Permissions / Group
2. Create new User "vnc" > Datacenter / Permissions / Users - select Group: "VNC", Realm: pve
3. Create new Role -> Datacenter / Permissions / Roles - select Name: "VNC", Privileges: VM.Console (only)
4. Add permission to access VNC -> Datacenter / Node / VM / Permissions / Add Group Permissions - select Group: "VNC", Role: "VNC"
5. Configure the WHMCS > Modules > Proxmox VE for WHMCS > Module Config > VNC Secret with 'vnc' password.

> Do NOT set less restrictive permissions. The above is designed for hypervisor security.

## ⚙️ VM/CT PLANS: Setting everything up ⚙️

These steps explain the unique requirements per-option.

Custom Fields: Values need to go in Name & Select Options.

> **Unsure?** Consult the zMANUAL-PVE4.pdf _legacy_ manual file.

### VM Option 1: KVM, using PVE Template VM

Firstly, create the Template in PVE. You need its unique PVE ID.

Use that ID in the Custom Field `KVMTemplate`, as in `ID|Name`.

> Note: `Name` is what's displayed in the WHMCS Client Area.

### VM Option 2: KVM, WHMCS Plan + PVE ISO

Firstly, create the Plan in WHMCS Module. Then, WHMCS Config > Services.

Under the Service, you need to add a Custom Field `ISO` with the full location.

### CT Option: LXC, using PVE Template File

Firstly, store the Template in PVE. You need its unique File Name.

Use that full file name in the Custom Field `Template`, as in:

`ubuntu-99.99-standard_amd64.tar.gz|Ubuntu 99`

Then make a 2nd Custom Field `Password` for the CT's root user.

## 🤬 ABUSE: Zero Tolerance (ZT) 🤬

This module has been overhauled and remains functionally-OK but not thoroughly tested nor reviewed.

Your support and assistance is always welcomed per the spirit of FOSS (Free Open-source Software)!

If you cannot accept this, do not download nor use the code. Complaints, nasty reviews, and similar behaviour is against the spirit of FOSS and will not be tolerated. 

**Be grateful & considerate - thank you!**

## 🆘 HELP: Best-effort Support 🆘

**Before raising a [GitHub Issue](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues), please check:**

1. The [Wiki](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/wiki)
2. The [README.md](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/tree/master)
3. Open [GitHub Issues](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues)
4. WHMCS Web Server, PHP, WHMCS & debug logs.
5. Proxmox VE logs; check best practices.
6. Also, take screenshots of the issue.

> Help: Including logs, details, steps to reproduce, etc, please raise a [GitHub Issue](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues).

### Info we need in order to help you

- (PHP) error_log contents
- (Visibility) Screenshots of it
- (WHMCS) Module Debug Log contents*
- (Configs) WHMCS/PHP/Module/Proxmox/etc
- (PVE) Logs from Proxmox Host (pveproxy etc)
- (Network) Proof WHMCS Server can talk to PVE OK
- (Reproduction) pvesh/etc variants of failing calls

The more info/context you provide up-front, the quicker & easier it's fixed!

\* Debug: Make sure you enable Debug Logging in the Module Settings, as needed.

**Please note that this is FOSS and Support is not guaranteed.**

## 🔄 UPDATING: Patching the Module 🔄

WHMCS Admin > Addon Modules > Proxmox VE for WHMCS > Support/Health shows updates.

You can download the new version and upload it over the top, then run any needed SQL ops.

Please consult the [UPDATE-SQL.md](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/blob/master/UPDATE-SQL.md) file, open your WHMCS DB & run the statements. Then you're done.

## 🖥️ INC: Libraries & Dependencies 🖥️

- [PHP Client for PVE2 API](https://github.com/CpuID/pve2-api-php-client) (Dec 5th, 2022)
- [TigerVNC VncViewer.jar](https://sourceforge.net/projects/tigervnc/files/stable/) (v1.13.1 in repo)
- [noVNC HTML5 Viewer](https://github.com/novnc/noVNC) (v1.4.0 in repo)

## 📄 DIY: Documentation & Resources 📄

- Proxmox API: https://pve.proxmox.com/pve-docs/api-viewer/
- TigerVNC: https://github.com/TigerVNC/tigervnc/wiki
- noVNC: https://github.com/novnc/noVNC/wiki
- WHMCS: https://developers.whmcs.com/

## 🎉 FOSS: Contributions & Open-source ❤️

If you'd like to contribute to the Module, please open a [PR](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/pulls).

The original module was written in 2 months by @cybercoder for sale online in 2016, though didn't sell any copies so they kindly open-sourced it and removed the licensing requirement.

We would like to thank [@cybercoder](https://github.com/cybercoder/) and [@WaldperlachFabi](https://github.com/WaldperlachFabi) for their original contributions and troubleshooting assistance respectively. FOSS is only possible thanks to dedicated individuals!

_This module is licensed under the GNU General Public License (GPL) v3.0._

### Links to TNC & Co.

**[The Network Crew Pty Ltd](https://thenetworkcrew.com.au)**

**[LEOPARD.host](https://leopard.host)**

> **MODULE SUPPORT:** via [GitHub Issues](https://github.com/The-Network-Crew/Proxmox-VE-for-WHMCS/issues) _only_.
