# WorldGuard

**WorldGuard** is the industry-standard region management and protection plugin for **PocketMine-MP**. It allows you to create protected zones (claims) where you can control exactly what players are allowed to do, from building and breaking blocks to using items and PVP.

## Features

*   **Region Protection:** Create rectangular or polygonal zones to protect spawns, shops, or player bases.
*   **Flag System:** Apply specific rules to regions, such as `pvp: deny`, `tnt: deny`, or `use: allow`.
*   **Priority Levels:** Layer regions on top of each other (e.g., a shop region inside a protected city).
*   **Owner/Member System:** Easily manage who has building rights within a specific area.
*   **Parenting:** Inherit settings from one region to another for easier management.
*   **Integration:** Works seamlessly with **WorldEdit** for region selection.

## Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `/rg define <name>` | Create a new region from your selection | `worldguard.region.define` |
| `/rg addowner <name> <player>` | Add an owner to a region | `worldguard.region.addowner` |
| `/rg flag <name> <flag> <value>` | Set a custom rule for a region | `worldguard.region.flag` |
| `/rg info` | View details about the region you are in | `worldguard.region.info` |
| `/rg remove <name>` | Delete a region | `worldguard.region.remove` |

## How to Protect an Area

1.  Select two points using the **WorldEdit** wand (`//wand`).
2.  Type `/rg define spawn` to create a region named "spawn".
3.  Type `/rg flag spawn pvp deny` to disable combat in that area.

## Installation

1.  **Requirement:** Ensure **WorldEdit** is installed.
2.  Download `WorldGuard.phar` and place it in your `/plugins/` folder.
3.  Restart your server.
4.  Start protecting your world!

---
*The ultimate tool for server safety and territory control.*
