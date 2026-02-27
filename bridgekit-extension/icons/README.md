# BridgeKit Extension Icons

## Generating PNG Icons

Chrome extensions require PNG icons. SVG source files are provided and must be converted to PNG.

### Option 1: Using the script (requires Node.js)

```bash
cd /path/to/Xvato
node scripts/generate-icons.js
```

If `sharp` is not installed, the script will create solid-color placeholder PNGs as a fallback.

### Option 2: Manual conversion

Convert the SVG files to PNG using any image editor or online tool:

| Source | Target | Size |
|--------|--------|------|
| `icon-16.svg` | `icon-16.png` | 16×16 px |
| `icon-48.svg` | `icon-48.png` | 48×48 px |
| `icon-128.svg` | `icon-128.png` | 128×128 px |

### Option 3: macOS command line

```bash
# Using rsvg-convert (install via: brew install librsvg)
rsvg-convert -w 16 -h 16 icon-16.svg > icon-16.png
rsvg-convert -w 48 -h 48 icon-48.svg > icon-48.png
rsvg-convert -w 128 -h 128 icon-128.svg > icon-128.png
```

### Option 4: Using ImageMagick

```bash
# Install via: brew install imagemagick
convert icon-16.svg icon-16.png
convert icon-48.svg icon-48.png
convert icon-128.svg icon-128.png
```
