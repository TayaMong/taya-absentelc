// Vercel Serverless Function (Node.js 18+) - pengganti scrape_ktm.php
const BASE = 'https://mobile.telkomuniversity.ac.id';

async function httpRequest(url, method = 'GET', jsonBody = null) {
  const headers = {
    Accept: 'application/json, text/plain, */*',
    Origin: 'https://mytelumobile.telkomuniversity.ac.id',
    Referer: 'https://mytelumobile.telkomuniversity.ac.id/',
    'User-Agent':
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  };
  const opts = { method, headers, signal: AbortSignal.timeout(20000) };
  if (method === 'POST' && jsonBody !== null) {
    headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(jsonBody);
  }
  try {
    const r = await fetch(url, opts);
    const body = await r.text();
    return { ok: r.ok, code: r.status, body };
  } catch (e) {
    return { ok: false, code: 0, body: '', err: e.message };
  }
}

function safeJson(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

module.exports = async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');

  if (req.method === 'OPTIONS') return res.status(200).end();
  if (req.method !== 'POST') {
    return res.status(405).json({ status: 'error', message: 'Gunakan metode POST.' });
  }

  let input = req.body;
  if (typeof input === 'string') input = safeJson(input) || {};
  input = input || {};

  const rawValue = String(input.ktm_url || '').trim();
  if (!rawValue) {
    return res.status(400).json({ status: 'error', message: 'Masukkan link KTM atau NIM terlebih dahulu.' });
  }

  let nim = '';
  let ktmUrl = '';
  let decrypted = {};

  if (/^\d{8,15}$/.test(rawValue)) {
    nim = rawValue;
  } else {
    // Isi QR bisa berupa link show-ktm/... atau kode terenkripsi mentah
    const m =
      rawValue.match(/\/show-ktm\/([^/?#]+)/) ||
      (/^[0-9a-f]{32,}(_[0-9a-f]+)?$/i.test(rawValue) ? [null, rawValue] : null);
    if (!m) {
      return res.status(400).json({
        status: 'error',
        message: 'Format input tidak dikenali. Gunakan link show-ktm atau NIM.',
      });
    }
    ktmUrl = /^https?:\/\//i.test(rawValue) ? rawValue : '';
    const encryptedCode = m[1];

    let dec = null;
    for (const key of ['code', 'qrcode', 'encrypted', 'data', 'text']) {
      dec = await httpRequest(`${BASE}/v2/qrcodes/decrypt-ktm-code-admin`, 'POST', { [key]: encryptedCode });
      if (dec.ok) break;
    }

    if (!dec || !dec.ok) {
      return res.status(502).json({
        status: 'error',
        message: `Gagal dekripsi link KTM (HTTP ${dec ? dec.code : 0}).`,
        api_response: dec ? dec.body || dec.err || '' : '',
      });
    }

    const dj = safeJson(dec.body);
    if (dj && dj.data && typeof dj.data === 'object') {
      decrypted = dj.data;
      nim = String(decrypted.number_id || decrypted.numberid || decrypted.nim || '').trim();
    } else if (dj && typeof dj.data === 'string') {
      nim = dj.data.trim();
    }

    if (!nim) {
      return res.status(422).json({
        status: 'error',
        message: 'NIM tidak ditemukan pada hasil dekripsi.',
        api_response: dj || dec.body,
      });
    }
  }

  let profile = {};
  const ktmRes = await httpRequest(`${BASE}/v2/users/ktm/${nim}`, 'GET');
  if (ktmRes.ok) {
    const kj = safeJson(ktmRes.body);
    if (kj && kj.data && typeof kj.data === 'object') profile = kj.data;
  }

  const u = { ...profile, ...decrypted };
  const finalNim = String(u.number_id || u.numberid || nim).trim();
  const finalNama = String(u.name || u.fullname || '').trim();

  if (!finalNim || !finalNama) {
    return res.status(404).json({
      status: 'error',
      message: `Data KTM tidak ditemukan untuk NIM ${nim}`,
      api_response: u,
    });
  }

  const now = new Date(Date.now() + 7 * 3600 * 1000).toISOString().replace('T', ' ').slice(0, 19);

  const record = {
    nim: finalNim,
    username: String(u.username || '').trim(),
    nama: finalNama,
    photo: String(u.photo || '').trim(),
    role: String(u.status || u.role || 'MAHASISWA').trim().toUpperCase(),
    fakultas: String(u.faculty || '').trim(),
    prodi: String(u.study_program || '').trim(),
    kelas: String(u.class || '').trim(),
    angkatan: String(u.class_of || '').trim(),
    ktm_url: ktmUrl,
    updated_at: now,
  };

  return res.status(200).json({
    status: 'success',
    message: 'Data KTM berhasil diambil.',
    data: record,
  });
};
