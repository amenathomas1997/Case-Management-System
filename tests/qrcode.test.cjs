// Independent check of public/assets/qrcode.js against ISO/IEC 18004:
// 1) format words for every error-correction level and mask against the
//    standard's published table;
// 2) both format-information copies read back in a reader's (ZXing's) order;
// 3) a full decode of generated symbols (versions 1-10) by a decoder written
//    from the standard (it reuses nothing from the encoder but the BCH helper,
//    which step 1 checks against the table first).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const src = fs.readFileSync(__dirname + '/../public/assets/qrcode.js', 'utf8');
const anchor = 'window.QRCode = {';
assert.ok(src.includes(anchor), 'Test harness: qrcode.js no longer assigns window.QRCode, so its helpers cannot be inspected');
const win = {};
vm.runInNewContext(src.replace(anchor, 'window.__t = {bch: bch, formatBits: formatBits, writeFormat: writeFormat}; ' + anchor), {window: win, unescape, encodeURIComponent});
const {bch, formatBits, writeFormat} = win.__t;

const TABLE = {
  L: ['111011111000100','111001011110011','111110110101010','111100010011101','110011000101111','110001100011000','110110001000001','110100101110110'],
  M: ['101010000010010','101000100100101','101111001111100','101101101001011','100010111111001','100000011001110','100111110010111','100101010100000'],
  Q: ['011010101011111','011000001101000','011111100110001','011101000000110','010010010110100','010000110000011','010111011011010','010101111101101'],
  H: ['001011010001001','001001110111110','001110011100111','001100111010000','000011101100010','000001001010101','000110100001100','000100000111011'],
};
const EC_IND = {L: 1, M: 0, Q: 3, H: 2};
for (const lvl of Object.keys(TABLE)) for (let mask = 0; mask < 8; mask++) {
  const data = (EC_IND[lvl] << 3) | mask;
  const word = ((data << 10) | bch(data, 0x537, 11)) ^ 0x5412;
  assert.equal(word.toString(2).padStart(15, '0'), TABLE[lvl][mask], `BCH format word ${lvl}/${mask}`);
}
for (let mask = 0; mask < 8; mask++) assert.equal(formatBits(mask).toString(2).padStart(15, '0'), TABLE.M[mask], `formatBits M/${mask}`);
console.log('PASS: All 32 format words match the published table, and the encoder uses the level M words');

// Reader order (ZXing BitMatrixParser.readFormatInformation); m[row][col].
function readFormat(m) {
  const n = m.length; let a = 0, b = 0;
  const bit = (r, c) => (m[r][c] ? 1 : 0);
  for (let c = 0; c < 6; c++) a = (a << 1) | bit(8, c);
  a = (a << 1) | bit(8, 7); a = (a << 1) | bit(8, 8); a = (a << 1) | bit(7, 8);
  for (let r = 5; r >= 0; r--) a = (a << 1) | bit(r, 8);
  for (let r = n - 1; r >= n - 7; r--) b = (b << 1) | bit(r, 8);
  for (let c = n - 8; c < n; c++) b = (b << 1) | bit(8, c);
  return [a, b];
}
for (const size of [21, 45, 57]) for (let mask = 0; mask < 8; mask++) {
  const m = Array.from({length: size}, () => new Array(size).fill(0));
  writeFormat(m, size, mask);
  const [a, b] = readFormat(m);
  assert.equal(a, formatBits(mask), `format copy 1 reads back for size ${size} mask ${mask}`);
  assert.equal(b, formatBits(mask), `format copy 2 reads back for size ${size} mask ${mask}`);
  assert.equal(m[size - 8][8], 1, `dark module for size ${size}`);
}
console.log('PASS: Both format-information copies read back correctly, with the dark module set');

// ---- Decoder written from the standard ----
const EXP = [], LOG = [];
for (let i = 0, x = 1; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 256) x ^= 0x11d; }
const mul = (a, b) => (a && b ? EXP[(LOG[a] + LOG[b]) % 255] : 0);
const ALIGN = {1: [], 2: [6, 18], 3: [6, 22], 4: [6, 26], 5: [6, 30], 6: [6, 34], 7: [6, 22, 38], 8: [6, 24, 42], 9: [6, 26, 46], 10: [6, 28, 50]};
// Level M: [EC codewords per block, [[block count, data codewords per block], ...]].
const BLOCKS_M = {1: [10, [[1, 16]]], 2: [16, [[1, 28]]], 3: [26, [[1, 44]]], 4: [18, [[2, 32]]], 5: [24, [[2, 43]]], 6: [16, [[4, 27]]], 7: [18, [[4, 31]]], 8: [22, [[2, 38], [2, 39]]], 9: [22, [[3, 36], [2, 37]]], 10: [26, [[4, 43], [1, 44]]]};
const MASKS = [(i, j) => (i + j) % 2 === 0, i => i % 2 === 0, (i, j) => j % 3 === 0, (i, j) => (i + j) % 3 === 0,
  (i, j) => (Math.floor(i / 2) + Math.floor(j / 3)) % 2 === 0, (i, j) => ((i * j) % 2) + ((i * j) % 3) === 0,
  (i, j) => (((i * j) % 2) + ((i * j) % 3)) % 2 === 0, (i, j) => (((i + j) % 2) + ((i * j) % 3)) % 2 === 0];
function functionMap(v) {
  const n = 17 + 4 * v, f = Array.from({length: n}, () => new Array(n).fill(false));
  const box = (r0, c0, h, w) => { for (let r = r0; r < r0 + h; r++) for (let c = c0; c < c0 + w; c++) if (r >= 0 && c >= 0 && r < n && c < n) f[r][c] = true; };
  box(0, 0, 9, 9); box(0, n - 8, 9, 8); box(n - 8, 0, 8, 9);           // finders, separators, format areas
  for (let i = 0; i < n; i++) { f[6][i] = true; f[i][6] = true; }      // timing patterns
  const ac = ALIGN[v], last = ac[ac.length - 1];
  for (const r of ac) for (const c of ac) {
    if ((r === 6 && c === 6) || (r === 6 && c === last) || (r === last && c === 6)) continue;
    box(r - 2, c - 2, 5, 5);
  }
  if (v >= 7) { box(0, n - 11, 6, 3); box(n - 11, 0, 3, 6); }           // version information
  return f;
}
// A Reed-Solomon block is error-free when every syndrome (at alpha^0 .. alpha^(ec-1)) is zero.
function syndromesZero(codewords, ecLen) {
  for (let i = 0; i < ecLen; i++) {
    let s = 0;
    for (const byte of codewords) s = mul(s, EXP[i]) ^ byte;
    if (s !== 0) return false;
  }
  return true;
}
function decode(modules) {
  const n = modules.length, v = (n - 17) / 4;
  const [fa, fb] = readFormat(modules);
  if (fa !== fb) return {error: 'format copies differ'};
  const word = fa ^ 0x5412, ec = word >> 13, mask = (word >> 10) & 7;
  if ((((word >> 10) << 10) | bch(word >> 10, 0x537, 11)) !== word) return {error: 'format BCH invalid'};
  if (ec !== 0) return {error: 'format says EC level ' + ec + ', not M'};
  if (v >= 7) { // version information, least significant bit at (0, n-11)
    let vb = 0; for (let i = 17; i >= 0; i--) vb = (vb << 1) | (modules[Math.floor(i / 3)][n - 11 + (i % 3)] ? 1 : 0);
    if (vb >> 12 !== v) return {error: 'version information says ' + (vb >> 12)};
  }
  const f = functionMap(v), bits = [];
  let up = true;
  for (let col = n - 1; col > 0; col -= 2) {
    if (col === 6) col = 5;
    for (let k = 0; k < n; k++) {
      const r = up ? n - 1 - k : k;
      for (let d = 0; d < 2; d++) {
        const c = col - d;
        if (f[r][c]) continue;
        bits.push((modules[r][c] ? 1 : 0) ^ (MASKS[mask](r, c) ? 1 : 0));
      }
    }
    up = !up;
  }
  const cws = []; for (let i = 0; i + 8 <= bits.length; i += 8) { let b = 0; for (let j = 0; j < 8; j++) b = (b << 1) | bits[i + j]; cws.push(b); }
  const [ecLen, groups] = BLOCKS_M[v], blocks = [];
  for (const [cnt, dl] of groups) for (let i = 0; i < cnt; i++) blocks.push({dl, data: [], ec: []});
  const maxD = Math.max(...blocks.map(b => b.dl)); let p = 0;
  for (let i = 0; i < maxD; i++) for (const b of blocks) if (i < b.dl) b.data.push(cws[p++]);
  for (let i = 0; i < ecLen; i++) for (const b of blocks) b.ec.push(cws[p++]);
  for (const b of blocks) if (!syndromesZero(b.data.concat(b.ec), ecLen)) return {error: 'Reed-Solomon syndrome non-zero'};
  const data = blocks.flatMap(b => b.data), dbits = [];
  for (const byte of data) for (let i = 7; i >= 0; i--) dbits.push((byte >> i) & 1);
  let q = 0; const take = k => { let x = 0; for (let i = 0; i < k; i++) x = (x << 1) | dbits[q++]; return x; };
  if (take(4) !== 4) return {error: 'not byte mode'};
  const len = take(v <= 9 ? 8 : 16), out = [];
  for (let i = 0; i < len; i++) out.push(take(8));
  return {text: Buffer.from(out).toString('utf8'), version: v, mask};
}
const seen = new Set();
for (const length of [1, 10, 14, 20, 26, 40, 60, 62, 84, 100, 106, 107, 122, 140, 152, 180, 213]) {
  const text = ('https://example.test/equipment.php?scan=' + 'x'.repeat(Math.max(0, length - 40))).slice(0, length);
  const symbol = win.QRCode.encode(text);
  const version = (symbol.size - 17) / 4;
  const result = decode(symbol.modules);
  seen.add(version);
  assert.equal(result.error, undefined, `decoding a ${length}-character text (version ${version}): ${result.error}`);
  assert.equal(result.text, text, `decoded text for length ${length} (version ${version})`);
}
assert.deepEqual([...seen].sort((a, b) => a - b), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
assert.match(win.QRCode.svg('Synthetic label'), /^<svg /);
console.log('PASS: Generated symbols for versions 1-10 decode to the encoded text, with valid error correction');
