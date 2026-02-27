#!/usr/bin/env node
/**
 * BridgeKit — SVG to PNG Icon Generator
 *
 * Converts SVG icons in the icons/ directory to PNG format
 * required by Chrome Extension manifest.
 *
 * Usage: node scripts/generate-icons.js
 * Requires: sharp (npm install sharp)
 */

const fs = require('fs');
const path = require('path');

async function generateIcons() {
  let sharp;
  try {
    sharp = require('sharp');
  } catch (e) {
    console.log('Installing sharp...');
    const { execSync } = require('child_process');
    execSync('npm install sharp --no-save', { cwd: __dirname, stdio: 'inherit' });
    sharp = require('sharp');
  }

  const iconsDir = path.join(__dirname, '..', 'bridgekit-extension', 'icons');
  const sizes = [16, 48, 128];

  for (const size of sizes) {
    const svgPath = path.join(iconsDir, `icon-${size}.svg`);
    const pngPath = path.join(iconsDir, `icon-${size}.png`);

    if (!fs.existsSync(svgPath)) {
      console.warn(`⚠ SVG not found: ${svgPath}`);
      continue;
    }

    await sharp(svgPath)
      .resize(size, size)
      .png()
      .toFile(pngPath);

    console.log(`✓ Generated icon-${size}.png`);
  }

  console.log('\nDone! PNG icons are ready.');
}

generateIcons().catch(err => {
  console.error('Error generating icons:', err.message);

  // Fallback: Create minimal valid PNGs if sharp isn't available
  console.log('\nFallback: Creating minimal PNG files...');
  createMinimalPNGs();
});

function createMinimalPNGs() {
  const iconsDir = path.join(__dirname, '..', 'bridgekit-extension', 'icons');

  // Minimal valid 1x1 PNG header — this is just a placeholder
  // Users should replace with proper icons converted from the SVGs
  const sizes = [16, 48, 128];

  for (const size of sizes) {
    const pngPath = path.join(iconsDir, `icon-${size}.png`);
    // Create a minimal valid PNG (purple square)
    const png = createPNG(size);
    fs.writeFileSync(pngPath, png);
    console.log(`✓ Created placeholder icon-${size}.png (${size}x${size})`);
  }

  console.log('\n⚠ These are placeholder PNGs. For production, convert the SVGs properly.');
  console.log('  Install sharp: npm install sharp');
  console.log('  Then run:      node scripts/generate-icons.js');
}

/**
 * Create a minimal valid PNG image (solid color).
 * This generates a proper PNG file without any external dependencies.
 */
function createPNG(size) {
  // PNG signature
  const signature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);

  // IHDR chunk
  const ihdrData = Buffer.alloc(13);
  ihdrData.writeUInt32BE(size, 0);  // width
  ihdrData.writeUInt32BE(size, 4);  // height
  ihdrData.writeUInt8(8, 8);        // bit depth
  ihdrData.writeUInt8(2, 9);        // color type: RGB
  ihdrData.writeUInt8(0, 10);       // compression
  ihdrData.writeUInt8(0, 11);       // filter
  ihdrData.writeUInt8(0, 12);       // interlace

  const ihdr = createChunk('IHDR', ihdrData);

  // IDAT chunk - raw image data with zlib
  const zlib = require('zlib');

  // Create raw image data: filter byte (0) + RGB pixels per row
  const rowSize = 1 + size * 3; // filter byte + RGB
  const rawData = Buffer.alloc(rowSize * size);

  for (let y = 0; y < size; y++) {
    const rowOffset = y * rowSize;
    rawData[rowOffset] = 0; // No filter

    for (let x = 0; x < size; x++) {
      const pixOffset = rowOffset + 1 + x * 3;
      // Gradient from #6366f1 to #8b5cf6 (indigo to purple)
      const t = (x + y) / (size * 2);
      rawData[pixOffset]     = Math.round(99 + t * (139 - 99));   // R
      rawData[pixOffset + 1] = Math.round(102 + t * (92 - 102));  // G
      rawData[pixOffset + 2] = Math.round(241 + t * (246 - 241)); // B
    }
  }

  const compressed = zlib.deflateSync(rawData);
  const idat = createChunk('IDAT', compressed);

  // IEND chunk
  const iend = createChunk('IEND', Buffer.alloc(0));

  return Buffer.concat([signature, ihdr, idat, iend]);
}

function createChunk(type, data) {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length, 0);

  const typeBuffer = Buffer.from(type, 'ascii');
  const crcData = Buffer.concat([typeBuffer, data]);

  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(crcData), 0);

  return Buffer.concat([length, typeBuffer, data, crc]);
}

function crc32(buf) {
  let crc = 0xFFFFFFFF;
  for (let i = 0; i < buf.length; i++) {
    crc ^= buf[i];
    for (let j = 0; j < 8; j++) {
      crc = (crc >>> 1) ^ (crc & 1 ? 0xEDB88320 : 0);
    }
  }
  return (crc ^ 0xFFFFFFFF) >>> 0;
}
