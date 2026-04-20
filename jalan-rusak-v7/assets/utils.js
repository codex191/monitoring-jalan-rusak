/**
 * Pantau Jalan — Utils v6
 * Sanitasi, validasi, error handling, loading state, pagination
 */

// ── 1. SANITASI ──────────────────────────────────────────────
window.Sanitize = {
  /** Escape HTML entities untuk mencegah XSS */
  html(str) {
    if (typeof str !== 'string') return '';
    return str
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#x27;')
      .trim();
  },
  /** Strip semua tag HTML */
  text(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/<[^>]*>/g,'').trim();
  },
  /** Sanitasi koordinat — hanya angka dan tanda minus/titik */
  coord(val) {
    const n = parseFloat(String(val).replace(/[^0-9.\-]/g,''));
    return isNaN(n) ? null : n;
  },
  /** Sanitasi nama jalan — hanya karakter aman */
  roadName(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/[<>"'`]/g,'').trim().slice(0,120);
  },
  /** Sanitasi deskripsi umum */
  desc(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/[<>"'`]/g,'').trim().slice(0,500);
  }
};

// ── 2. VALIDASI ──────────────────────────────────────────────
window.Validate = {
  /** Validasi form laporan. Kembalikan {ok, errors:{field:msg}} */
  reportForm(data) {
    const errors = {};
    const name = (data.roadName||'').trim();
    const desc = (data.description||'').trim();
    const lat  = data.lat;
    const lng  = data.lng;
    const reporter = (data.reporter||'').trim();

    if (!name)              errors.roadName    = 'Nama jalan wajib diisi.';
    else if (name.length<5) errors.roadName    = 'Nama jalan minimal 5 karakter.';
    else if (name.length>120) errors.roadName  = 'Nama jalan maksimal 120 karakter.';

    if (!desc)              errors.description = 'Deskripsi kerusakan wajib diisi.';
    else if (desc.length<10) errors.description= 'Deskripsi minimal 10 karakter.';
    else if (desc.length>500) errors.description='Deskripsi maksimal 500 karakter.';

    if (lat === null || lat === undefined || lat === '')
                            errors.lat = 'Koordinat latitude wajib diisi.';
    else if (isNaN(Number(lat)) || Number(lat)<-90 || Number(lat)>90)
                            errors.lat = 'Latitude tidak valid (−90 hingga 90).';

    if (lng === null || lng === undefined || lng === '')
                            errors.lng = 'Koordinat longitude wajib diisi.';
    else if (isNaN(Number(lng)) || Number(lng)<-180 || Number(lng)>180)
                            errors.lng = 'Longitude tidak valid (−180 hingga 180).';

    if (reporter && reporter.length > 60)
                            errors.reporter = 'Nama pelapor maksimal 60 karakter.';

    return { ok: Object.keys(errors).length === 0, errors };
  },

  /** Validasi login */
  loginForm(data) {
    const errors = {};
    if (!(data.username||'').trim()) errors.username = 'Username wajib diisi.';
    if (!(data.password||'').trim()) errors.password = 'Password wajib diisi.';
    return { ok: Object.keys(errors).length === 0, errors };
  }
};

// ── 3. FORM ERROR UI ─────────────────────────────────────────
window.FormError = {
  /** Tampilkan error di bawah field */
  show(fieldId, message) {
    const field = document.getElementById(fieldId);
    if (!field) return;
    field.classList.add('field-error');
    const existing = document.getElementById(`${fieldId}-err`);
    if (existing) { existing.textContent = message; return; }
    const msg = document.createElement('span');
    msg.id = `${fieldId}-err`;
    msg.className = 'field-error-msg';
    msg.setAttribute('role','alert');
    msg.textContent = message;
    field.parentNode.insertBefore(msg, field.nextSibling);
  },
  /** Hapus error dari field */
  clear(fieldId) {
    const field = document.getElementById(fieldId);
    if (field) field.classList.remove('field-error');
    const msg = document.getElementById(`${fieldId}-err`);
    if (msg) msg.remove();
  },
  /** Hapus semua error dalam form */
  clearAll(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.querySelectorAll('.field-error').forEach(el=>el.classList.remove('field-error'));
    form.querySelectorAll('.field-error-msg').forEach(el=>el.remove());
  },
  /** Tampilkan semua error dari objek {field:msg} */
  showAll(errors) {
    Object.entries(errors).forEach(([f,m])=>FormError.show(f,m));
    // Scroll ke error pertama
    const firstErr = document.querySelector('.field-error');
    if (firstErr) firstErr.scrollIntoView({behavior:'smooth',block:'center'});
  }
};

// ── 4. LOADING STATE ─────────────────────────────────────────
window.LoadingBtn = {
  /** Set tombol ke mode loading */
  start(btnId, loadingText='Memproses...') {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn._originalText = btn.innerHTML;
    btn._originalDisabled = btn.disabled;
    btn.disabled = true;
    btn.setAttribute('aria-busy','true');
    btn.innerHTML = `<span class="btn-spinner" aria-hidden="true"></span> ${loadingText}`;
  },
  /** Reset tombol ke semula */
  stop(btnId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = btn._originalDisabled || false;
    btn.removeAttribute('aria-busy');
    if (btn._originalText) btn.innerHTML = btn._originalText;
  }
};

// ── 5. AUTO-SAVE DRAFT FORM ───────────────────────────────────
window.FormDraft = {
  _key: 'pantaujalan_draft_laporan',
  /** Simpan draft ke sessionStorage */
  save(data) {
    try { sessionStorage.setItem(this._key, JSON.stringify(data)); } catch(e){}
  },
  /** Muat draft dari sessionStorage */
  load() {
    try { const d = sessionStorage.getItem(this._key); return d ? JSON.parse(d) : null; } catch(e){ return null; }
  },
  /** Hapus draft */
  clear() {
    try { sessionStorage.removeItem(this._key); } catch(e){}
  },
  /** Auto-bind ke semua input dalam form */
  bind(formId, fields) {
    fields.forEach(id => {
      const el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', () => {
        const data = {};
        fields.forEach(f => { const e = document.getElementById(f); if(e) data[f]=e.value; });
        FormDraft.save(data);
      });
    });
  },
  /** Restore draft ke form (dengan notifikasi) */
  restore(formId, fields) {
    const draft = this.load();
    if (!draft) return;
    let restored = false;
    fields.forEach(id => {
      if (draft[id] !== undefined && draft[id] !== '') {
        const el = document.getElementById(id);
        if (el) { el.value = draft[id]; restored = true; }
      }
    });
    if (restored) {
      // Banner notifikasi draft
      const form = document.getElementById(formId);
      if (!form) return;
      const banner = document.createElement('div');
      banner.className = 'draft-banner';
      banner.innerHTML = `
        <span>📝 Draft tersimpan ditemukan. Data sebelumnya sudah dipulihkan.</span>
        <button type="button" onclick="FormDraft.discard('${formId}','${fields.join(',')}')">Buang Draft</button>`;
      form.prepend(banner);
    }
  },
  /** Buang draft dan clear form */
  discard(formId, fieldsStr) {
    FormDraft.clear();
    const fields = fieldsStr.split(',');
    fields.forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
    document.querySelector('.draft-banner')?.remove();
  }
};

// ── 6. BEFOREUNLOAD (cegah kehilangan isian form) ────────────
window.FormGuard = {
  _active: false,
  _handler: null,
  enable() {
    if (this._active) return;
    this._active = true;
    this._handler = e => { e.preventDefault(); e.returnValue=''; };
    window.addEventListener('beforeunload', this._handler);
  },
  disable() {
    this._active = false;
    if (this._handler) window.removeEventListener('beforeunload', this._handler);
  }
};

// ── 7. PAGINATION ─────────────────────────────────────────────
window.Paginator = {
  /**
   * Render pagination untuk array data
   * @param {Array} data - semua data
   * @param {number} page - halaman saat ini (1-based)
   * @param {number} perPage - item per halaman
   * @param {string} containerId - id container pagination UI
   * @param {Function} onChange - callback(newPage)
   * @returns {Array} - slice data untuk halaman ini
   */
  paginate(data, page, perPage, containerId, onChange) {
    const total = data.length;
    const totalPages = Math.ceil(total / perPage);
    const safePage = Math.min(Math.max(1, page), totalPages || 1);
    const start = (safePage - 1) * perPage;
    const slice = data.slice(start, start + perPage);

    // Render UI
    const container = document.getElementById(containerId);
    if (container) {
      if (totalPages <= 1) { container.innerHTML=''; }
      else {
        const pages = [];
        for (let i=1;i<=totalPages;i++) pages.push(i);
        container.innerHTML = `
          <div class="pagination">
            <button class="pag-btn" ${safePage<=1?'disabled':''} onclick="(${onChange.toString()})(${safePage-1})">‹ Sebelumnya</button>
            <span class="pag-info">Halaman ${safePage} / ${totalPages} <span style="color:var(--color-text-faint);">(${total} data)</span></span>
            <button class="pag-btn" ${safePage>=totalPages?'disabled':''} onclick="(${onChange.toString()})(${safePage+1})">Berikutnya ›</button>
          </div>`;
      }
    }
    return slice;
  }
};

// ── 8. OFFLINE INDICATOR ─────────────────────────────────────
window.OfflineIndicator = {
  init() {
    const bar = document.createElement('div');
    bar.id = 'offline-bar';
    bar.setAttribute('role','status');
    bar.setAttribute('aria-live','polite');
    bar.innerHTML = '📡 Tidak ada koneksi internet. Data yang ditampilkan mungkin belum terbaru.';
    document.body.prepend(bar);

    const BAR_H   = 36;
    const isMobile = () => window.innerWidth <= 768;

    const update = () => {
      const offline = !navigator.onLine;
      bar.classList.toggle('visible', offline);
      // Desktop: dorong page-content ke bawah agar tidak tertutup bar
      const content = document.querySelector('.page-content');
      if (content) {
        content.style.marginTop = (!isMobile() && offline) ? (BAR_H + 8) + 'px' : '';
      }
    };
    window.addEventListener('online',  update);
    window.addEventListener('offline', update);
    window.addEventListener('resize',  update);
    update();
  }
};
