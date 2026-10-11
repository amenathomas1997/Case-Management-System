/* Minimal, dependency-free QR Code generator (byte mode, EC level M, versions 1-10).
   Implements the ISO/IEC 18004 algorithm: Reed-Solomon over GF(256), pattern
   placement, data masking with penalty scoring, and format/version info.
   Exposes window.QRCode.svg(text) -> SVG string, and .render(el, text, opts). */
(function () {
  'use strict';

  // GF(256) with primitive polynomial 0x11d
  var EXP = new Array(512), LOG = new Array(256);
  (function () {
    var x = 1;
    for (var i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 0x100) x ^= 0x11d; }
    for (i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
  })();
  function gmul(a, b) { return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }

  // EC level M block structure per version: [ecPerBlock, [[numBlocks, dataPerBlock], ...]]
  var EC_M = {
    1: [10, [[1, 16]]], 2: [16, [[1, 28]]], 3: [26, [[1, 44]]], 4: [18, [[2, 32]]],
    5: [24, [[2, 43]]], 6: [16, [[4, 27]]], 7: [18, [[4, 31]]], 8: [22, [[2, 38], [2, 39]]],
    9: [22, [[3, 36], [2, 37]]], 10: [26, [[4, 43], [1, 44]]]
  };
  var ALIGN = {
    1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30], 6: [6, 34],
    7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50]
  };

  function totalData(v) { var s = 0, b = EC_M[v][1]; for (var i = 0; i < b.length; i++) s += b[i][0] * b[i][1]; return s; }

  function rsGenPoly(deg) {
    var poly = [1];
    for (var i = 0; i < deg; i++) {
      var next = new Array(poly.length + 1).fill(0);
      for (var j = 0; j < poly.length; j++) {
        next[j] ^= gmul(poly[j], EXP[i]);
        next[j + 1] ^= poly[j];
      }
      poly = next;
    }
    // Built low-degree-first; rsEncode divides with the monic leading term at
    // index 0, so return it high-degree-first (gen[0] = 1).
    return poly.reverse();
  }
  function rsEncode(data, ecLen) {
    var gen = rsGenPoly(ecLen), res = data.slice().concat(new Array(ecLen).fill(0));
    for (var i = 0; i < data.length; i++) {
      var coef = res[i]; if (coef === 0) continue;
      for (var j = 0; j < gen.length; j++) res[i + j] ^= gmul(gen[j], coef);
    }
    return res.slice(data.length);
  }

  // BCH for format (15,5) and version (18,6) info
  function bch(data, gen, glen) {
    var d = data << (glen - 1);
    while (bitLen(d) >= glen) d ^= gen << (bitLen(d) - glen);
    return d;
  }
  function bitLen(x) { var n = 0; while (x) { n++; x >>>= 1; } return n; }

  function formatBits(mask) {
    // EC level M indicator = 0b00
    var data = (0 << 3) | mask;
    var bits = (data << 10) | bch(data, 0x537, 11); // generator 10100110111
    return bits ^ 0x5412; // mask pattern 101010000010010
  }
  function versionBits(v) {
    return (v << 12) | bch(v, 0x1f25, 13); // generator 1111100100101
  }

  function encode(text) {
    var bytes = toUtf8(text);
    // choose smallest version that fits (byte mode: header = 4 + charCountBits)
    var v = 1;
    for (; v <= 10; v++) {
      var ccBits = v <= 9 ? 8 : 16;
      var cap = totalData(v) * 8;
      if (4 + ccBits + bytes.length * 8 <= cap) break;
    }
    if (v > 10) throw new Error('data too long for QR v1-10');

    var ccBits = v <= 9 ? 8 : 16;
    var bs = new BitStream();
    bs.put(0x4, 4);            // byte mode
    bs.put(bytes.length, ccBits);
    for (var i = 0; i < bytes.length; i++) bs.put(bytes[i], 8);
    var capCw = totalData(v);
    // terminator (up to 4 zero bits), then byte-align
    var rem = capCw * 8 - bs.length();
    bs.put(0, Math.min(4, rem));
    while (bs.length() % 8 !== 0) bs.put(0, 1);
    // pad bytes alternate 0xEC / 0x11 up to capacity
    var dataCw = bs.bytes();
    var padByte = 0xEC;
    while (dataCw.length < capCw) { dataCw.push(padByte); padByte = (padByte === 0xEC) ? 0x11 : 0xEC; }

    // split into blocks, compute EC, interleave
    var spec = EC_M[v], ecLen = spec[0], groups = spec[1];
    var blocks = [], ecBlocks = [], offset = 0;
    for (var g = 0; g < groups.length; g++) {
      for (var b = 0; b < groups[g][0]; b++) {
        var dlen = groups[g][1];
        var blk = dataCw.slice(offset, offset + dlen); offset += dlen;
        blocks.push(blk);
        ecBlocks.push(rsEncode(blk, ecLen));
      }
    }
    var maxData = Math.max.apply(null, blocks.map(function (x) { return x.length; }));
    var finalCw = [];
    for (i = 0; i < maxData; i++) for (var bb = 0; bb < blocks.length; bb++) if (i < blocks[bb].length) finalCw.push(blocks[bb][i]);
    for (i = 0; i < ecLen; i++) for (bb = 0; bb < ecBlocks.length; bb++) finalCw.push(ecBlocks[bb][i]);

    return buildMatrix(v, finalCw);
  }

  function buildMatrix(v, codewords) {
    var size = 17 + v * 4;
    var m = [], reserved = [];
    for (var r = 0; r < size; r++) { m.push(new Array(size).fill(null)); reserved.push(new Array(size).fill(false)); }

    function place(r, c, val) { m[r][c] = val; reserved[r][c] = true; }
    function finder(r, c) {
      for (var i = -1; i <= 7; i++) for (var j = -1; j <= 7; j++) {
        var rr = r + i, cc = c + j; if (rr < 0 || cc < 0 || rr >= size || cc >= size) continue;
        var on = (i >= 0 && i <= 6 && (j === 0 || j === 6)) || (j >= 0 && j <= 6 && (i === 0 || i === 6)) || (i >= 2 && i <= 4 && j >= 2 && j <= 4);
        place(rr, cc, on ? 1 : 0);
      }
    }
    finder(0, 0); finder(0, size - 7); finder(size - 7, 0);
    // timing
    for (var i = 8; i < size - 8; i++) { if (m[6][i] === null) place(6, i, i % 2 === 0 ? 1 : 0); if (m[i][6] === null) place(i, 6, i % 2 === 0 ? 1 : 0); }
    // alignment
    var ac = ALIGN[v], first = ac[0], last = ac[ac.length - 1];
    for (var a = 0; a < ac.length; a++) for (var b = 0; b < ac.length; b++) {
      var cr = ac[a], cc = ac[b];
      // Skip only the three centres inside the finders. Centres on the timing
      // lines (v7+) are required; their modules match the timing pattern there.
      if ((cr === first && (cc === first || cc === last)) || (cr === last && cc === first)) continue;
      for (var dr = -2; dr <= 2; dr++) for (var dc = -2; dc <= 2; dc++) {
        var on = Math.max(Math.abs(dr), Math.abs(dc)) !== 1;
        place(cr + dr, cc + dc, on ? 1 : 0);
      }
    }
    // dark module
    place(size - 8, 8, 1);
    // reserve format areas
    for (i = 0; i < 9; i++) { if (m[8][i] === null) { reserved[8][i] = true; } if (m[i][8] === null) { reserved[i][8] = true; } }
    for (i = 0; i < 8; i++) { reserved[8][size - 1 - i] = true; reserved[size - 1 - i][8] = true; }
    reserved[8][size - 8] = true;
    // reserve version info areas (v>=7)
    if (v >= 7) for (i = 0; i < 6; i++) for (var k = 0; k < 3; k++) { reserved[i][size - 11 + k] = true; reserved[size - 11 + k][i] = true; }

    // place data in zigzag
    var bitIdx = 0, dir = -1, row = size - 1;
    for (var col = size - 1; col > 0; col -= 2) {
      if (col === 6) col--; // skip timing column
      for (; row >= 0 && row < size; row += dir) {
        for (var cX = 0; cX < 2; cX++) {
          var cc2 = col - cX;
          if (m[row][cc2] !== null || reserved[row][cc2]) continue;
          var bit = 0;
          if (bitIdx < codewords.length * 8) {
            var byteV = codewords[bitIdx >> 3];
            bit = (byteV >> (7 - (bitIdx & 7))) & 1;
          }
          m[row][cc2] = bit; bitIdx++;
        }
      }
      dir = -dir; row += dir;
    }

    // try all masks, pick lowest penalty
    var best = null, bestPen = Infinity, bestMask = 0;
    for (var mask = 0; mask < 8; mask++) {
      var cand = applyMask(m, reserved, size, mask);
      writeFormat(cand, size, mask);
      if (v >= 7) writeVersion(cand, size, v);
      var pen = penalty(cand, size);
      if (pen < bestPen) { bestPen = pen; best = cand; bestMask = mask; }
    }
    return { size: size, modules: best };
  }

  function maskFn(mask, r, c) {
    switch (mask) {
      case 0: return (r + c) % 2 === 0;
      case 1: return r % 2 === 0;
      case 2: return c % 3 === 0;
      case 3: return (r + c) % 3 === 0;
      case 4: return (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0;
      case 5: return ((r * c) % 2) + ((r * c) % 3) === 0;
      case 6: return (((r * c) % 2) + ((r * c) % 3)) % 2 === 0;
      case 7: return (((r + c) % 2) + ((r * c) % 3)) % 2 === 0;
    }
  }
  function applyMask(m, reserved, size, mask) {
    var out = [];
    for (var r = 0; r < size; r++) {
      out.push(m[r].slice());
      for (var c = 0; c < size; c++) {
        if (!reserved[r][c] && m[r][c] !== null && maskFn(mask, r, c)) out[r][c] = m[r][c] ^ 1;
      }
    }
    return out;
  }
  function writeFormat(m, size, mask) {
    var bits = formatBits(mask);
    // gb(i) is the i-th bit counting from the most significant (bit 14), the
    // order ISO/IEC 18004 places and readers collect them: (8,0) holds bit 14.
    function gb(i) { return (bits >> (14 - i)) & 1; }
    // First copy, around the top-left finder (canonical placement).
    for (var i = 0; i <= 5; i++) m[8][i] = gb(i);
    m[8][7] = gb(6); m[8][8] = gb(7); m[7][8] = gb(8);
    for (i = 9; i < 15; i++) m[14 - i][8] = gb(i);
    // Second copy: the first 7 bits up from the bottom-left corner (col 8), the
    // last 8 across the top-right (row 8). The dark module sits at (size-8, 8).
    for (i = 0; i < 7; i++) m[size - 1 - i][8] = gb(i);
    for (i = 7; i < 15; i++) m[8][size - 15 + i] = gb(i);
    m[size - 8][8] = 1; // dark module
  }
  function writeVersion(m, size, v) {
    var bits = versionBits(v);
    for (var i = 0; i < 18; i++) {
      var bit = (bits >> i) & 1;
      var r = Math.floor(i / 3), c = i % 3;
      m[r][size - 11 + c] = bit;
      m[size - 11 + c][r] = bit;
    }
  }

  function penalty(m, size) {
    var p = 0, r, c, i;
    // rule 1: runs of 5+
    for (r = 0; r < size; r++) for (var t = 0; t < 2; t++) {
      var run = 1, prev = -1;
      for (c = 0; c < size; c++) {
        var val = t === 0 ? m[r][c] : m[c][r];
        if (val === prev) { run++; if (run === 5) p += 3; else if (run > 5) p += 1; }
        else { run = 1; prev = val; }
      }
    }
    // rule 2: 2x2 blocks
    for (r = 0; r < size - 1; r++) for (c = 0; c < size - 1; c++) {
      var v0 = m[r][c];
      if (v0 === m[r][c + 1] && v0 === m[r + 1][c] && v0 === m[r + 1][c + 1]) p += 3;
    }
    // rule 3: finder-like patterns
    var pat1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0], pat2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
    for (r = 0; r < size; r++) for (c = 0; c < size; c++) {
      if (c <= size - 11) { if (match(m, r, c, pat1, true) || match(m, r, c, pat2, true)) p += 40; }
      if (r <= size - 11) { if (match(m, r, c, pat1, false) || match(m, r, c, pat2, false)) p += 40; }
    }
    // rule 4: dark proportion
    var dark = 0; for (r = 0; r < size; r++) for (c = 0; c < size; c++) if (m[r][c]) dark++;
    var ratio = dark * 100 / (size * size);
    p += Math.floor(Math.abs(ratio - 50) / 5) * 10;
    return p;
  }
  function match(m, r, c, pat, horiz) {
    for (var i = 0; i < pat.length; i++) { var val = horiz ? m[r][c + i] : m[r + i][c]; if ((val ? 1 : 0) !== pat[i]) return false; }
    return true;
  }

  // helpers
  function toUtf8(s) { var out = [], u = unescape(encodeURIComponent(s)); for (var i = 0; i < u.length; i++) out.push(u.charCodeAt(i)); return out; }
  function BitStream() { this.bits = []; }
  BitStream.prototype.put = function (val, len) { for (var i = len - 1; i >= 0; i--) this.bits.push((val >> i) & 1); };
  BitStream.prototype.length = function () { return this.bits.length; };
  BitStream.prototype.bytes = function () { var out = []; for (var i = 0; i + 8 <= this.bits.length; i += 8) { var b = 0; for (var j = 0; j < 8; j++) b = (b << 1) | this.bits[i + j]; out.push(b); } return out; };

  function svg(text, opts) {
    opts = opts || {};
    var q = encode(text), size = q.size, quiet = opts.quiet == null ? 4 : opts.quiet, scale = opts.scale || 4;
    var dim = (size + quiet * 2) * scale;
    var rects = '';
    for (var r = 0; r < size; r++) for (var c = 0; c < size; c++) if (q.modules[r][c]) {
      rects += '<rect x="' + (c + quiet) * scale + '" y="' + (r + quiet) * scale + '" width="' + scale + '" height="' + scale + '"/>';
    }
    // The image needs a text alternative: opts.label, or a generic name.
    var label = String(opts.label == null || opts.label === '' ? 'QR code' : opts.label)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + dim + '" height="' + dim + '" viewBox="0 0 ' + dim + ' ' + dim + '" role="img" aria-label="' + label + '">'
      + '<rect width="100%" height="100%" fill="#fff"/><g fill="#000">' + rects + '</g></svg>';
  }
  function render(el, text, opts) { el.innerHTML = svg(text, opts); }

  window.QRCode = { svg: svg, render: render, encode: encode };
})();
