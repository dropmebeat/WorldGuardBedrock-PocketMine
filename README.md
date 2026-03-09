# WorldEdit

**WorldEdit** is the ultimate in-game map editor for **PocketMine-MP**. It allows you to build, fix, and transform your world using powerful commands and brushes, turning complex building tasks into simple operations.

## Features

*   **Mass Editing:** Set, replace, or delete thousands of blocks instantly.
*   **Region Selection:** Use the iconic wooden axe to select areas by defining two points.
*   **Clipboard Management:** Copy, cut, and paste structures with ease.
*   **Schematics:** Save your creations to files and load them into any world.
*   **Mathematical Transforms:** Rotate, flip, or stack your selections.
*   **Generation Tools:** Create spheres, cylinders, and pyramids with a single command.
*   **Powerful Brushes:** Sculpt terrain or paint blocks from a distance.

## Essential Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `//wand` | Get the edit wand (Wooden Axe) | `worldedit.selection.wand` |
| `//set <block>` | Fill the selection with a block | `worldedit.region.set` |
| `//replace <to>` | Replace all blocks in selection | `worldedit.region.replace` |
| `//undo` / `//redo` | Revert or restore your last action | `worldedit.history.undo` |
| `//copy` / `//paste` | Use the clipboard to move builds | `worldedit.clipboard.copy` |

## How to Start

1.  Type `//wand` to get the selection tool.
2.  **Left-click** a block to set **Point 1**.
3.  **Right-click** a block to set **Point 2**.
4.  Run a command like `//set stone` to modify the selected area.

## Installation

1. Download the `WorldEdit.phar` from [Poggit](https://poggit.pmmp.io).
2. Place it in your `/plugins/` folder.
3. Restart your server.
4. Grant the `worldedit.*` permission to your admin group.

---
*Built for precision. Designed for scale.*
