/**
 * Pantau Jalan — Nav Component v5
 * Injects mobile hamburger + bottom nav + drawer
 */
window.initMobileNav = function() {
  const page = location.pathname.split('/').pop() || 'index.html';

  // Inject hamburger ke topnav
  const navEnd = document.querySelector('.nav-end');
  if (navEnd && !document.querySelector('.nav-hamburger')) {
    const hb = document.createElement('button');
    hb.className = 'nav-hamburger';
    hb.setAttribute('aria-label', 'Buka menu navigasi');
    hb.setAttribute('aria-expanded', 'false');
    hb.innerHTML = '<span></span><span></span><span></span>';
    navEnd.prepend(hb);

    // Drawer
    const drawer = document.createElement('div');
    drawer.className = 'mobile-nav-drawer';
    drawer.setAttribute('aria-label', 'Menu navigasi mobile');
    const links = [
      { href: 'index.html',   icon: '📊', label: 'Dashboard' },
      { href: 'peta.html',    icon: '🗺', label: 'Peta' },
      { href: 'laporan.html', icon: '📝', label: 'Kirim Laporan' },
      { href: 'admin.html',   icon: '🔐', label: 'Admin / Petugas' },
    ];
    const user = window.Auth?.getUser();
    drawer.innerHTML = links.map(l =>
      `<a href="${l.href}" class="${l.href === page ? 'active' : ''}">${l.icon} ${l.label}</a>`
    ).join('') + `
      <div class="drawer-footer">
        <span style="font-size:var(--text-xs);color:var(--color-text-muted);">${user ? `Login sebagai <strong>${user.name}</strong>` : 'Belum login'}</span>
        <button class="theme-toggle" id="drawer-theme-toggle" aria-label="Ganti tema" style="padding:var(--space-2);">🌙</button>
      </div>`;
    document.body.appendChild(drawer);

    hb.addEventListener('click', () => {
      const open = drawer.classList.toggle('open');
      hb.classList.toggle('open', open);
      hb.setAttribute('aria-expanded', open);
    });

    // Tutup drawer klik di luar
    document.addEventListener('click', e => {
      if (!drawer.contains(e.target) && !hb.contains(e.target)) {
        drawer.classList.remove('open');
        hb.classList.remove('open');
        hb.setAttribute('aria-expanded', 'false');
      }
    });

    // Theme toggle di drawer
    document.getElementById('drawer-theme-toggle')?.addEventListener('click', () => {
      document.querySelector('[data-theme-toggle]')?.click();
    });
  }

  // Bottom nav
  if (!document.querySelector('.bottom-nav')) {
    const bnav = document.createElement('nav');
    bnav.className = 'bottom-nav';
    bnav.setAttribute('aria-label', 'Navigasi bawah');
    bnav.innerHTML = `<div class="bottom-nav-inner">
      <a href="index.html"   class="bottom-nav-item ${page==='index.html'?'active':''}">   <span class="bnav-icon">📊</span><span>Dashboard</span></a>
      <a href="peta.html"    class="bottom-nav-item ${page==='peta.html'?'active':''}">    <span class="bnav-icon">🗺</span><span>Peta</span></a>
      <a href="laporan.html" class="bottom-nav-item ${page==='laporan.html'?'active':''}"> <span class="bnav-icon">📝</span><span>Laporan</span></a>
      <a href="admin.html"   class="bottom-nav-item ${page==='admin.html'?'active':''}">   <span class="bnav-icon">🔐</span><span>Admin</span></a>
    </div>`;
    document.body.appendChild(bnav);
  }
};
