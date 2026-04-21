/**
 * api.js — Layer komunikasi frontend ↔ PHP backend
 * Semua fetch ke server terpusat di sini.
 *
 * Penggunaan:
 *   const reports = await Api.getReports();
 *   await Api.submitReport({ roadName, ... });
 *   await Api.login(username, password);
 */

(function(global) {
  'use strict';

  // ── Base URL ─────────────────────────────────────────────
  // Deteksi otomatis: kalau diakses via localhost → pakai PHP API
  // Kalau buka via file:// → fallback ke Store (mode offline/demo)
  const isLocalhost = location.protocol !== 'file:';
  const BASE = isLocalhost
    ? (location.origin + location.pathname.replace(/\/[^/]*$/, '') + '/api')
    : null;

  // ── CSRF Token ───────────────────────────────────────────
  let _csrfToken = '';

  // ── Helper fetch ─────────────────────────────────────────
  async function req(url, options = {}) {
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    // Sertakan CSRF token di semua POST request
    if ((options.method || 'GET').toUpperCase() === 'POST' && _csrfToken) {
      headers['X-CSRF-Token'] = _csrfToken;
    }
    const res = await fetch(url, {
      headers,
      credentials: 'include', // penting untuk session cookie PHP
      ...options,
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || data.errors?.join(', ') || 'Terjadi kesalahan.');
    return data;
  }

  // ── API object ───────────────────────────────────────────
  const Api = {

    isOnline: isLocalhost && !!BASE,

    // ── Laporan publik ──────────────────────────────────────
    async getReports({ status = 'all', from = '', to = '', page = 1, perPage = 20 } = {}) {
      const params = new URLSearchParams({ status, page, per_page: perPage });
      if (from) params.set('from', from);
      if (to)   params.set('to',   to);
      const data = await req(`${BASE}/reports.php?${params}`);
      return { data: data.data, total: data.total, page: data.page, totalPages: data.total_pages };
    },

    // ── Detail 1 laporan ────────────────────────────────────
    async getReportDetail(id) {
      const data = await req(`${BASE}/report_detail.php?id=${encodeURIComponent(id)}`);
      const r = data.data;
      if (!r) return null;
      // Mapping snake_case DB → camelCase Store
      return {
        ...r,
        id:              r.id,
        roadName:        r.road_name    || r.roadName    || '',
        description:     r.description  || '',
        reporter:        r.reporter     || 'Anonim',
        lat:             parseFloat(r.lat) || 0,
        lng:             parseFloat(r.lng) || 0,
        status:          r.status       || 'pending',
        verifiedBy:      r.verified_by  || r.verifiedBy  || null,
        rejectionReason: r.rejection_reason || r.rejectionReason || null,
        photo_urls:      Array.isArray(r.photo_urls) ? r.photo_urls : [],
        history:         Array.isArray(r.history)    ? r.history    : [],
        createdAt:       r.created_at   || r.createdAt  || '',
        updatedAt:       r.updated_at   || r.updatedAt  || '',
      };
    },

    // ── Kirim laporan baru (warga) ──────────────────────────
    async submitReport({ roadName, description, reporter, lat, lng, photoUrls = [] }) {
      const data = await req(`${BASE}/reports.php`, {
        method: 'POST',
        body: JSON.stringify({ roadName, description, reporter, lat, lng, photo_urls: photoUrls }),
      });
      return data; // { success, id, message }
    },

    // ── Upload foto ─────────────────────────────────────────
    async uploadPhoto(file) {
      const formData = new FormData();
      formData.append('photo', file);
      const res = await fetch(`${BASE}/upload.php`, {
        method: 'POST',
        credentials: 'include',
        body: formData, // JANGAN set Content-Type, biarkan browser set boundary
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Upload foto gagal.');
      return data; // { success, url, filename }
    },

    // ── Admin: ambil semua laporan (+ pending) ──────────────
    async getAdminReports({ status = 'all', from = '', to = '', search = '', page = 1, perPage = 10 } = {}) {
      const params = new URLSearchParams({ action: 'get_all', status, page, per_page: perPage });
      if (from)   params.set('from',   from);
      if (to)     params.set('to',     to);
      if (search) params.set('search', search);
      const data = await req(`${BASE}/reports.php?${params}`);
      return { data: data.data, total: data.total, page: data.page, totalPages: data.total_pages };
    },

    // ── Admin: update status ───────────────────────────────
    async updateStatus(id, status, note = '') {
      return await req(`${BASE}/admin.php`, {
        method: 'POST',
        body: JSON.stringify({ action: 'update_status', id, status, note }),
      });
    },

    // ── Admin/Petugas: tolak laporan ────────────────────────
    async rejectReport(id, reason) {
      return await req(`${BASE}/admin.php`, {
        method: 'POST',
        body: JSON.stringify({ action: 'reject', id, reason }),
      });
    },

    // ── Admin: hapus permanen ───────────────────────────────
    async deleteReport(id) {
      return await req(`${BASE}/admin.php`, {
        method: 'POST',
        body: JSON.stringify({ action: 'delete', id }),
      });
    },

    // ── Admin: login ────────────────────────────────────────
    async login(username, password) {
      const data = await req(`${BASE}/admin.php`, {
        method: 'POST',
        body: JSON.stringify({ action: 'login', username, password }),
      });
      if (data.csrf_token) _csrfToken = data.csrf_token;
      return data.user; // { id, username, name, role }
    },

    // ── Admin: logout ───────────────────────────────────────
    async logout() {
      await req(`${BASE}/admin.php`, {
        method: 'POST',
        body: JSON.stringify({ action: 'logout' }),
      });
    },

    // ── Admin: cek session (refresh halaman) ────────────────
    async checkSession() {
      try {
        const data = await req(`${BASE}/admin.php?action=check_session`);
        if (data.csrf_token) _csrfToken = data.csrf_token;
        return data.user;
      } catch {
        return null;
      }
    },

    // ── Ambil CSRF token fresh (panggil saat halaman load) ───
    async fetchCsrfToken() {
      try {
        const data = await req(`${BASE}/admin.php?action=get_csrf`);
        if (data.csrf_token) _csrfToken = data.csrf_token;
      } catch(e) { /* abaikan */ }
    },

  };

  global.Api = Api;
})(window);
