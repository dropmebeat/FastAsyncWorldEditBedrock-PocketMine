# FastAsyncWorldEdit (FAWE)

**FastAsyncWorldEdit** is a high-performance version of WorldEdit for **PocketMine-MP**, specifically designed to handle massive terrain changes without crashing your server or causing TPS drops.

## Why FastAsyncWorldEdit?

*   **Speed & Efficiency:** Perform millions of block changes in seconds with minimal memory usage.
*   **Asynchronous Processing:** Operations run in the background, preventing the server from freezing (no "Server Full" or lag spikes).
*   **Bedrock Optimized:** Specifically tuned for Bedrock Edition block states and data.
*   **Undo/Redo System:** Massive safety net with a disk-based undo system that doesn't eat up RAM.
*   **Advanced Tools:** Includes brushes, patterns, masks, and schematic support.

## Key Commands


| Command | Description | Permission |
|---------|-------------|------------|
| `//wand` | Get the region selection tool | `worldedit.selection.wand` |
| `//set <block>` | Set all blocks in selection | `worldedit.region.set` |
| `//replace <from> <to>` | Replace specific blocks | `worldedit.region.replace` |
| `//copy` / `//paste` | Copy and paste structures | `worldedit.clipboard.copy` |
| `//br` | Access powerful brush tools | `worldedit.brush.options` |

## Performance Settings (config.yml)

```yaml
# FAWE Optimization
limits:
  max-blocks-changed: 1000000
  max-polygonal-points: 20
queue:
  target-size: 64
  max-wait-ms: 1000
