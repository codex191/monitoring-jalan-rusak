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

  // ── Helper fetch ─────────────────────────────────────────
  async function req(url, options = {}) {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
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
    async getReports({ status = 'all', from = '', to = '' } = {}) {
      const params = new URLSearchParams({ status });
      if (from) params.set('from', from);
      if (to)   params.set('to',   to);
      const data = await req(`${BASE}/reports.php?${params}`);
      return data.data; // array laporan
    },

    // ── Detail 1 laporan ────────────────────────────────────
    async getReportDetail(id) {
      const data = await req(`${BASE}/report_detail.php?id=${encodeURIComponent(id)}`);
      return data.data;
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
    async getAdminReports({ status = 'all', from = '', to = '', search = '' } = {}) {
      const params = new URLSearchParams({ action: 'get_all', status: 'all' });
      if (from)   params.set('from',   from);
      if (to)     params.set('to',     to);
      if (search) params.set('search', search);
      const data = await req(`${BASE}/reports.php?${params}`);
      return data.data;
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
        return data.user;
      } catch {
        return null;
      }
    },

    // ── Admin: update status laporan ────────────────────────
    async updateStatus(id, status, note = '') {
      await req(`${BASE}/admin.php`, {
        method: 'POST',
        body: JSON.stringify({ action: 'update_status', id, status, note }),
      });
    },

    // ── Admin: hapus laporan ────────────────────────────────
    async deleteReport(id) {
      await req(`${BASE}/admin.php`, {
        method: 'POST',
        body: JSON.stringify({ action: 'delete', id }),
      });
    },
  };

  global.Api = Api;
})(window);
