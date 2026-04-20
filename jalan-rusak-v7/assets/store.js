/**
 * Pantau Jalan — Store v7
 * MySQL-compatible schema:
 *  - ID      : UUID v4 (CHAR 36) — kompatibel dengan MySQL CHAR(36) / VARCHAR(36)
 *  - Timestamps : ISO 8601 string → MySQL DATETIME / TIMESTAMP
 *  - Foto    : Array of URL string (bukan Base64) → MySQL TEXT / JSON
 *  - Status  : ENUM('pending','damaged','in_progress','fixed','reported')
 */

// ── UUID Generator (tanpa library) ───────────────────────────
function generateUUID() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
    var r = Math.random() * 16 | 0;
    var v = c === 'x' ? r : (r & 0x3 | 0x8);
    return v.toString(16);
  });
}

// ── ISO Timestamp ─────────────────────────────────────────────
function nowISO() {
  return new Date().toISOString(); // "2026-04-20T06:53:00.000Z"
}

function formatDisplay(isoStr) {
  if (!isoStr) return '-';
  try {
    const d = new Date(isoStr);
    const now = new Date();
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60)   return diff + ' detik lalu';
    if (diff < 3600) return Math.floor(diff/60) + ' menit lalu';
    if (diff < 86400) return Math.floor(diff/3600) + ' jam lalu';
    return d.toLocaleDateString('id-ID', { day:'numeric', month:'short', year:'numeric' });
  } catch(e) { return isoStr; }
}

// ── Status Meta ───────────────────────────────────────────────
// value harus cocok dengan ENUM di MySQL
const STATUS = {
  pending:     { label: 'Menunggu Verifikasi', color: '#7a7974' },
  damaged:     { label: 'Rusak',               color: '#d13d4f' },
  in_progress: { label: 'Sedang Diperbaiki',   color: '#d18a1f' },
  fixed:       { label: 'Sudah Diperbaiki',    color: '#4b9b53' },
  reported:    { label: 'Dilaporkan',          color: '#2a78c8' },
};

// ── Auth ──────────────────────────────────────────────────────
const USERS = [
  { id: generateUUID(), username: 'admin',   password: 'admin123',   name: 'Admin Pantau Jalan', role: 'admin'   },
  { id: generateUUID(), username: 'petugas', password: 'petugas123', name: 'Petugas Lapangan',   role: 'petugas' },
];

window.Auth = {
  _user: null,
  login(username, password) {
    const u = USERS.find(x => x.username === username && x.password === password);
    if (u) { this._user = u; return true; }
    return false;
  },
  logout()      { this._user = null; },
  getUser()     { return this._user; },
  isLoggedIn()  { return !!this._user; },
  isAdmin()     { return this._user?.role === 'admin'; },
  isOfficer()   { return this._user?.role === 'admin' || this._user?.role === 'petugas'; },
  getRoleLabel(){ return this._user ? this._user.role : 'warga'; },
};

// ── Data Store ────────────────────────────────────────────────
/**
 * Schema laporan (MySQL-compatible):
 * {
 *   id          : CHAR(36) UUID
 *   roadName    : VARCHAR(120)
 *   description : TEXT (maks 500 char di frontend)
 *   lat         : DECIMAL(10,7)
 *   lng         : DECIMAL(10,7)
 *   status      : ENUM('pending','damaged','in_progress','fixed','reported')
 *   reporter    : VARCHAR(60)
 *   verifiedBy  : VARCHAR(60) NULL
 *   photo_urls  : JSON (array of URL string) — di MySQL: JSON atau TEXT
 *   createdAt   : ISO string → DATETIME
 *   updatedAt   : ISO string → DATETIME
 *   history     : JSON (array of {status, actor, role, note, timestamp})
 * }
 */

function makeReport(data) {
  const now = nowISO();
  return {
    id:          generateUUID(),
    roadName:    data.roadName    || '',
    description: data.description || '',
    lat:         parseFloat(data.lat)  || 0,
    lng:         parseFloat(data.lng)  || 0,
    status:      data.status      || 'pending',
    reporter:    data.reporter    || 'Anonim',
    verifiedBy:  data.verifiedBy  || null,
    photo_urls:  data.photo_urls  || [],   // Array of URL string (bukan Base64)
    createdAt:   data.createdAt   || now,
    updatedAt:   data.updatedAt   || now,
    history:     data.history     || [{
      status:    data.status || 'pending',
      actor:     data.reporter || 'Anonim',
      role:      'warga',
      note:      'Laporan dikirim oleh warga.',
      timestamp: now,
    }],
  };
}

// ── Seed Data ─────────────────────────────────────────────────
const now = new Date();
function daysAgo(n) { return new Date(now - n*86400000).toISOString(); }
function hoursAgo(n){ return new Date(now - n*3600000).toISOString(); }

let _reports = [
  makeReport({
    roadName: 'Jl. Yos Sudarso',
    description: 'Lubang lebar di sisi kiri jalan, rawan saat malam hari. Perkiraan diameter 60 cm, kedalaman 20 cm.',
    lat: -2.2082, lng: 113.9136,
    status: 'damaged',
    reporter: 'Warga (Anonim)',
    verifiedBy: 'petugas',
    photo_urls: [],
    createdAt: daysAgo(5), updatedAt: daysAgo(3),
    history: [
      { status:'pending',  actor:'Warga (Anonim)', role:'warga',   note:'Laporan dikirim.',                   timestamp: daysAgo(5) },
      { status:'damaged',  actor:'Petugas Lapangan', role:'petugas', note:'Diverifikasi — kondisi rusak parah.', timestamp: daysAgo(3) },
    ],
  }),
  makeReport({
    roadName: 'Jl. Ahmad Yani KM 3',
    description: 'Aspal retak di jalur tengah sepanjang ± 5 meter. Berpotensi menjadi lubang jika tidak segera ditangani.',
    lat: -2.2134, lng: 113.9201,
    status: 'in_progress',
    reporter: 'Budi Santoso',
    verifiedBy: 'admin',
    photo_urls: [],
    createdAt: daysAgo(10), updatedAt: daysAgo(1),
    history: [
      { status:'pending',     actor:'Budi Santoso',     role:'warga',   note:'Laporan dikirim.',            timestamp: daysAgo(10) },
      { status:'damaged',     actor:'Admin Pantau Jalan', role:'admin', note:'Diverifikasi — rusak sedang.', timestamp: daysAgo(8)  },
      { status:'in_progress', actor:'Admin Pantau Jalan', role:'admin', note:'Perbaikan dijadwalkan.',       timestamp: daysAgo(1)  },
    ],
  }),
  makeReport({
    roadName: 'Jl. Lambung Mangkurat',
    description: 'Jalan berlubang di depan toko Sumber Jaya. Kedalaman ± 15 cm, lebar ± 40 cm.',
    lat: -2.2051, lng: 113.9089,
    status: 'fixed',
    reporter: 'Siti Rahmah',
    verifiedBy: 'petugas',
    photo_urls: [],
    createdAt: daysAgo(20), updatedAt: daysAgo(2),
    history: [
      { status:'pending',     actor:'Siti Rahmah',      role:'warga',   note:'Laporan dikirim.',        timestamp: daysAgo(20) },
      { status:'damaged',     actor:'Petugas Lapangan', role:'petugas', note:'Dikonfirmasi rusak.',      timestamp: daysAgo(18) },
      { status:'in_progress', actor:'Petugas Lapangan', role:'petugas', note:'Tim pengaspalan masuk.',   timestamp: daysAgo(10) },
      { status:'fixed',       actor:'Petugas Lapangan', role:'petugas', note:'Perbaikan selesai 100%.', timestamp: daysAgo(2)  },
    ],
  }),
  makeReport({
    roadName: 'Jl. Pangeran Samudera',
    description: 'Aspal mengelupas hampir di seluruh badan jalan sepanjang 30 m. Bahaya saat hujan.',
    lat: -2.2198, lng: 113.9154,
    status: 'pending',
    reporter: 'Anonim',
    verifiedBy: null,
    photo_urls: [],
    createdAt: hoursAgo(3), updatedAt: hoursAgo(3),
    history: [
      { status:'pending', actor:'Anonim', role:'warga', note:'Laporan dikirim oleh warga.', timestamp: hoursAgo(3) },
    ],
  }),
  makeReport({
    roadName: 'Jl. Haryono MT',
    description: 'Drainase jebol menyebabkan aspal ambles di pinggir jalan. Ukuran ± 1m x 0.5m.',
    lat: -2.2243, lng: 113.9067,
    status: 'reported',
    reporter: 'Agus Prasetyo',
    verifiedBy: null,
    photo_urls: [],
    createdAt: hoursAgo(1), updatedAt: hoursAgo(1),
    history: [
      { status:'reported', actor:'Agus Prasetyo', role:'warga', note:'Laporan dikirim oleh warga.', timestamp: hoursAgo(1) },
    ],
  }),
];

let _subscribers = [];

// ── Store API ─────────────────────────────────────────────────
window.Store = {
  statusMeta: STATUS,

  getAll()     { return _reports.slice().sort((a,b) => new Date(b.createdAt) - new Date(a.createdAt)); },
  getPublic()  { return this.getAll().filter(r => r.status !== 'pending'); },
  getPending() { return this.getAll().filter(r => r.status === 'pending'); },
  getById(id)  { return _reports.find(r => r.id === id) || null; },

  /** Warga mengirim laporan — status SELALU pending */
  addByWarga(data) {
    const now2 = nowISO();
    const report = makeReport({
      ...data,
      status:     'pending',
      verifiedBy: null,
      photo_urls: data.photo_urls || [], // URL string array, bukan Base64
      createdAt:  now2,
      updatedAt:  now2,
      history: [{
        status:    'pending',
        actor:     data.reporter || 'Anonim',
        role:      'warga',
        note:      'Laporan dikirim oleh warga.',
        timestamp: now2,
      }],
    });
    _reports.unshift(report);
    this._notify();
    return report;
  },

  /** Officer mengubah status laporan */
  updateStatusByOfficer(id, newStatus, actorName, actorRole, note) {
    const r = _reports.find(x => x.id === id);
    if (!r) return false;
    const now2 = nowISO();
    r.status     = newStatus;
    r.verifiedBy = actorName;
    r.updatedAt  = now2;
    r.history    = r.history || [];
    r.history.push({
      status:    newStatus,
      actor:     actorName,
      role:      actorRole || 'petugas',
      note:      note || 'Status diubah oleh ' + actorName + '.',
      timestamp: now2,
    });
    this._notify();
    return true;
  },

  /** Admin menghapus laporan */
  deleteReport(id) {
    const idx = _reports.findIndex(r => r.id === id);
    if (idx === -1) return false;
    _reports.splice(idx, 1);
    this._notify();
    return true;
  },

  /** Statistik untuk dashboard */
  getStats() {
    return {
      total:    _reports.length,
      pending:  _reports.filter(r => r.status === 'pending').length,
      damaged:  _reports.filter(r => r.status === 'damaged').length,
      progress: _reports.filter(r => r.status === 'in_progress').length,
      fixed:    _reports.filter(r => r.status === 'fixed').length,
      reported: _reports.filter(r => r.status === 'reported').length,
    };
  },

  /** Format timestamp ISO → tampilan */
  formatDate(isoStr) { return formatDisplay(isoStr); },

  /** Export schema untuk referensi MySQL */
  getMySQLSchema() {
    return `
-- Pantau Jalan — MySQL Schema v7
CREATE TABLE reports (
  id          CHAR(36)     NOT NULL PRIMARY KEY,  -- UUID v4
  road_name   VARCHAR(120) NOT NULL,
  description TEXT         NOT NULL,
  lat         DECIMAL(10,7) NOT NULL,
  lng         DECIMAL(10,7) NOT NULL,
  status      ENUM('pending','damaged','in_progress','fixed','reported') NOT NULL DEFAULT 'pending',
  reporter    VARCHAR(60)  NOT NULL DEFAULT 'Anonim',
  verified_by VARCHAR(60)  NULL,
  photo_urls  JSON         NULL,                  -- Array of URL string
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_created_at (created_at)
);

CREATE TABLE report_history (
  id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_id   CHAR(36)     NOT NULL,
  status      ENUM('pending','damaged','in_progress','fixed','reported') NOT NULL,
  actor       VARCHAR(60)  NOT NULL,
  role        ENUM('warga','petugas','admin') NOT NULL DEFAULT 'warga',
  note        TEXT         NULL,
  timestamp   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
  INDEX idx_report_id (report_id)
);

CREATE TABLE users (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  username    VARCHAR(60)  NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,  -- bcrypt hash
  name        VARCHAR(100) NOT NULL,
  role        ENUM('admin','petugas','warga') NOT NULL DEFAULT 'warga',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);`;
  },

  _notify() { _subscribers.forEach(fn => fn()); },
  subscribe(fn) { _subscribers.push(fn); },
};

// ── Demo Data ─────────────────────────────────────────────────
Store.addDemoData = function() {
  const now2 = nowISO();
  const r1 = makeReport({
    roadName:   'Jl. Pahlawan (Demo)',
    description:'Jalan retak parah akibat akar pohon. Butuh penanganan segera.',
    lat: -2.2301, lng: 113.9188,
    status: 'pending', reporter: 'Warga Demo',
    photo_urls: [], createdAt: now2, updatedAt: now2,
    history: [{ status:'pending', actor:'Warga Demo', role:'warga', note:'Demo data.', timestamp: now2 }],
  });
  const r2 = makeReport({
    roadName:   'Jl. Veteran (Demo)',
    description:'Jembatan kecil amblas di bagian tengah, kendaraan berat dilarang lewat.',
    lat: -2.2178, lng: 113.9233,
    status: 'pending', reporter: 'Pelapor Demo',
    photo_urls: [], createdAt: now2, updatedAt: now2,
    history: [{ status:'pending', actor:'Pelapor Demo', role:'warga', note:'Demo data.', timestamp: now2 }],
  });
  _reports.unshift(r2, r1);
  Store._notify();
};

// ── Toast ─────────────────────────────────────────────────────
window.Toast = {
  show(msg, type, dur) {
    dur = dur || 3500;
    const t = document.createElement('div');
    t.className = 'toast toast-'+type;
    const icons = { success:'✅', error:'❌', warning:'⚠️', info:'ℹ️' };
    t.innerHTML = '<span>'+(icons[type]||'')+'</span> '+msg;
    let wrap = document.getElementById('toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'toast-wrap';
      wrap.style.cssText = 'position:fixed;bottom:80px;right:var(--space-5);z-index:2000;display:flex;flex-direction:column;gap:var(--space-2);max-width:360px;';
      document.body.appendChild(wrap);
    }
    wrap.appendChild(t);
    requestAnimationFrame(() => t.classList.add('visible'));
    setTimeout(() => {
      t.classList.remove('visible');
      setTimeout(() => t.remove(), 300);
    }, dur);
  },
  success(m,d){ this.show(m,'success',d); },
  error(m,d)  { this.show(m,'error',d);   },
  warning(m,d){ this.show(m,'warning',d); },
  info(m,d)   { this.show(m,'info',d);    },
};

// ── Theme ─────────────────────────────────────────────────────
window.initTheme = function() {
  const r   = document.documentElement;
  const btn = document.querySelector('[data-theme-toggle]');
  let dark  = window.matchMedia('(prefers-color-scheme:dark)').matches;
  r.setAttribute('data-theme', dark ? 'dark' : 'light');
  const moonSVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
  const sunSVG  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>';
  if (btn) btn.innerHTML = dark ? sunSVG : moonSVG;
  if (btn) btn.addEventListener('click', () => {
    dark = !dark;
    r.setAttribute('data-theme', dark ? 'dark' : 'light');
    btn.innerHTML = dark ? sunSVG : moonSVG;
    btn.setAttribute('aria-label', 'Ganti ke mode '+(dark?'terang':'gelap'));
  });
};

// ── Nav Badge ─────────────────────────────────────────────────
window.initNav = function() {
  const badge = document.getElementById('nav-user-badge');
  if (!badge) return;
  const u = Auth.getUser();
  if (u) {
    badge.innerHTML = '<span class="nav-role-badge nav-role-'+u.role+'">'+u.role+'</span>'
      +'<span style="font-size:var(--text-xs);font-weight:600;">'+u.name+'</span>'
      +'<button onclick="Auth.logout();location.reload();" style="font-size:var(--text-xs);color:var(--color-text-muted);background:none;border:none;cursor:pointer;padding:2px 6px;border-radius:4px;" title="Logout">✕</button>';
  }
};
