import { api, esc, fmtMoney, icon, Store, toast } from './api.js';
import { renderPatronView } from './patron.js';
import { renderAccueilView } from './accueil.js';
import { renderCaissierView, stopCaissierPolling } from './caissier.js';

const PATRON_NAV = [
  { id: 'dashboard', label: 'Tableau de bord', icon: 'dashboard', sub: 'Vue globale en temps réel' },
  { id: 'cash', label: 'Caisse', icon: 'cash', sub: 'Ouverture, fermeture et mouvements' },
  { id: 'new-sale', label: 'Nouvelle vente', icon: 'pos', sub: 'Encaissement direct' },
  { id: 'products', label: 'Produits', icon: 'products', sub: 'Catalogue, prix et mouvements de stock' },
  { id: 'categories', label: 'Catégories', icon: 'categories', sub: 'Organisation du catalogue' },
  { id: 'customers', label: 'Clients', icon: 'customers', sub: 'Répertoire et historique d’achats' },
  { id: 'expenses', label: 'Dépenses', icon: 'expenses', sub: 'Suivi et justification des sorties' },
  { id: 'transactions', label: 'Transactions', icon: 'transactions', sub: 'Ventes, reçus et annulations tracées' },
  { id: 'reports', label: 'Rapports', icon: 'reports', sub: 'Statistiques, bénéfices et exports PDF/Excel' },
  { id: 'history', label: 'Historique', icon: 'history', sub: 'Journal d’audit complet des opérations' },
  { id: 'users', label: 'Utilisateurs', icon: 'user', sub: 'Comptes Patron, Accueil et Caissier' },
  { id: 'settings', label: 'Paramètres', icon: 'settings', sub: 'Commerce, paiements, conservation 30j & démo' },
];

const ACCUEIL_NAV = [
  { id: 'accueil-order', label: 'Prise de commande', icon: 'pos', sub: 'Préparer la commande et l’envoyer à la caisse', orderBadge: true },
  { id: 'accueil-list', label: 'Commandes envoyées', icon: 'transactions', sub: 'Suivi en direct (En attente / Encaissées)' },
];

function getCaissierNav() {
  const items = [
    { id: 'pos-pending', label: 'Commandes à encaisser', icon: 'cash', sub: 'Commandes envoyées par l’Accueil — Encaissement & reçu', pendingBadge: true },
    { id: 'pos-sale', label: 'Vente directe', icon: 'pos', sub: 'Encaissement rapide au comptoir' },
    { id: 'pos-cart', label: 'Panier', icon: 'receipt', sub: 'Validation et paiement direct', badge: true },
  ];
  if (Store.settings.cashier_can_view_history !== '0') {
    items.push({ id: 'pos-history', label: 'Historique de mes ventes', icon: 'history', sub: 'Ventes du jour' });
  }
  items.push({ id: 'pos-receipts', label: 'Reçus', icon: 'printer', sub: 'Consultation et réimpression des reçus' });
  return items;
}

function spaceForRole(role) {
  if (role === 'ACCUEIL') return 'accueil';
  if (role === 'CAISSE') return 'caissier';
  return 'patron';
}

function defaultTabForSpace(space) {
  if (space === 'patron') return 'dashboard';
  if (space === 'accueil') return 'accueil-order';
  return 'pos-pending';
}

async function refreshHealth() {
  const h = await api('health');
  Store.user = h.user || Store.user;
  Store.settings = h.settings || {};
  Store.paymentMethods = h.payment_methods || [];
  Store.openSession = h.open_session || null;
  Store.pendingOrdersCount = Number(h.pending_orders_count || 0);
  Store.demo = h.demo || { active: false };
  Store.cppEngine = h.cpp_engine || { available: false };

  if (Store.user) {
    const displayName = Store.user.full_name || Store.user.username;
    if (Store.user.role === 'ACCUEIL') Store.accueilName = displayName;
    if (Store.user.role === 'CAISSE') Store.cashierName = displayName;
  }
}

function currentNavList() {
  if (Store.space === 'patron') return PATRON_NAV;
  if (Store.space === 'accueil') return ACCUEIL_NAV;
  return getCaissierNav();
}

function ensureValidTab() {
  if (Store.user?.role !== 'ADMIN') {
    Store.space = spaceForRole(Store.user?.role);
  }
  const list = currentNavList();
  if (!list.some((item) => item.id === Store.tab)) {
    Store.tab = list[0].id;
    localStorage.setItem('fincaisse_tab', Store.tab);
  }
}

function renderSetupView() {
  stopCaissierPolling();
  const app = document.getElementById('app');
  app.innerHTML = `
    <div class="auth-shell">
      <div class="auth-card">
        <div class="auth-brand">
          <div class="brand-mark">${icon('logo', 22)}</div>
          <div>
            <h1 style="font-size:1.25rem;font-weight:800;color:var(--navy-900)">FINCAISSE</h1>
            <small class="text-muted">Système de Gestion Commerciale & Caisse</small>
          </div>
        </div>

        <h2 class="auth-title">Créer le premier compte administrateur</h2>
        <p class="auth-sub">Aucun compte n’existe encore dans la base. Créez le compte Patron (Administrateur) pour sécuriser et démarrer FINCAISSE.</p>

        <div class="auth-alert">
          Une fois ce compte créé, vous pourrez ajouter vos comptes <strong>Accueil</strong> et <strong>Caissier</strong> dans le menu <em>Utilisateurs</em>.
        </div>

        <form id="f-setup" class="form-grid" style="grid-template-columns:1fr">
          <div class="form-field">
            <label for="st-full">Nom complet (Patron)</label>
            <input id="st-full" name="full_name" class="input" required placeholder="Ex : Kouassi Jean (Patron)">
          </div>
          <div class="form-field">
            <label for="st-user">Nom d’utilisateur *</label>
            <input id="st-user" name="username" class="input" required minlength="3" autocomplete="username" placeholder="Ex : admin">
          </div>
          <div class="form-field">
            <label for="st-pw">Mot de passe (8 caractères minimum) *</label>
            <input id="st-pw" name="password" type="password" class="input" required minlength="8" autocomplete="new-password" placeholder="••••••••">
          </div>
          <div class="form-field">
            <label for="st-pw2">Confirmer le mot de passe *</label>
            <input id="st-pw2" name="password_confirm" type="password" class="input" required minlength="8" autocomplete="new-password" placeholder="••••••••">
          </div>
          <label style="display:flex;align-items:center;gap:.45rem;font-size:.82rem;color:var(--ink-700);cursor:pointer">
            <input type="checkbox" id="st-show-pw"> Afficher les mots de passe
          </label>
          <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:.35rem">
            Créer le compte administrateur
          </button>
        </form>
      </div>
    </div>
  `;

  app.querySelector('#st-show-pw').addEventListener('change', (e) => {
    const t = e.target.checked ? 'text' : 'password';
    app.querySelector('#st-pw').type = t;
    app.querySelector('#st-pw2').type = t;
  });

  app.querySelector('#f-setup').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const pw = String(fd.get('password') || '');
    const pw2 = String(fd.get('password_confirm') || '');
    if (pw !== pw2) {
      toast('Les deux mots de passe ne correspondent pas.', 'error');
      return;
    }
    try {
      const res = await api('auth/setup', {
        method: 'POST',
        body: {
          full_name: fd.get('full_name'),
          username: fd.get('username'),
          password: pw,
        },
      });
      Store.user = res.user;
      Store.space = 'patron';
      Store.tab = 'dashboard';
      localStorage.setItem('fincaisse_space', Store.space);
      localStorage.setItem('fincaisse_tab', Store.tab);
      toast('Compte administrateur créé ! Bienvenue dans FINCAISSE.', 'success');
      await refreshHealth();
      renderShell();
      await renderActivePage();
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}

function renderLoginView() {
  stopCaissierPolling();
  const app = document.getElementById('app');
  app.innerHTML = `
    <div class="auth-shell">
      <div class="auth-card">
        <div class="auth-brand">
          <div class="brand-mark">${icon('logo', 22)}</div>
          <div>
            <h1 style="font-size:1.25rem;font-weight:800;color:var(--navy-900)">FINCAISSE</h1>
            <small class="text-muted">Système de Gestion Commerciale & Caisse</small>
          </div>
        </div>

        <h2 class="auth-title">Connexion à votre espace</h2>
        <p class="auth-sub">Connectez-vous avec votre compte Patron, Accueil ou Caissier.</p>

        <form id="f-login" class="form-grid" style="grid-template-columns:1fr">
          <div class="form-field">
            <label for="lg-user">Nom d’utilisateur</label>
            <input id="lg-user" name="username" class="input" required autocomplete="username" placeholder="Votre identifiant">
          </div>
          <div class="form-field">
            <label for="lg-pw">Mot de passe</label>
            <input id="lg-pw" name="password" type="password" class="input" required autocomplete="current-password" placeholder="••••••••">
          </div>
          <label style="display:flex;align-items:center;gap:.45rem;font-size:.82rem;color:var(--ink-700);cursor:pointer">
            <input type="checkbox" id="lg-show-pw"> Afficher le mot de passe
          </label>
          <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:.35rem">
            Se connecter
          </button>
        </form>
      </div>
    </div>
  `;

  app.querySelector('#lg-show-pw').addEventListener('change', (e) => {
    app.querySelector('#lg-pw').type = e.target.checked ? 'text' : 'password';
  });

  app.querySelector('#f-login').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const res = await api('auth/login', {
        method: 'POST',
        body: {
          username: fd.get('username'),
          password: fd.get('password'),
        },
      });
      Store.user = res.user;
      Store.space = spaceForRole(res.user.role);
      Store.tab = defaultTabForSpace(Store.space);
      localStorage.setItem('fincaisse_space', Store.space);
      localStorage.setItem('fincaisse_tab', Store.tab);
      toast(`Connecté en tant que ${res.user.full_name || res.user.username}`, 'success');
      await refreshHealth();
      renderShell();
      await renderActivePage();
    } catch (err) {
      toast(err.message, 'error');
    }
  });
}

function renderShell() {
  ensureValidTab();
  const nav = currentNavList();
  const activeItem = nav.find((n) => n.id === Store.tab) || nav[0];
  const cartCount = Store.cart.reduce((a, b) => a + b.quantity, 0);
  const orderCartCount = Store.orderCart.reduce((a, b) => a + b.quantity, 0);
  const cashBal = Store.openSession?.summary?.expected_amount ?? Store.openSession?.expected_amount ?? 0;
  const isAdmin = Store.user?.role === 'ADMIN';
  const displayName = Store.user?.full_name || Store.user?.username || 'Utilisateur';

  const sectionTitle = Store.space === 'patron'
    ? 'Administration'
    : Store.space === 'accueil'
      ? 'Accueil & Commandes'
      : 'Caisse & Reçus';

  const app = document.getElementById('app');
  app.innerHTML = `
    <aside class="sidebar" id="fc-sidebar">
      <div class="brand">
        <div class="brand-mark">${icon('logo', 22)}</div>
        <div class="brand-text">
          <h1>FINCAISSE</h1>
          <small>${esc(Store.settings.store_name || 'Gestion Commerciale')}</small>
        </div>
      </div>

      ${isAdmin ? `
        <div class="mode-switch" role="tablist" aria-label="Choisir l'espace">
          <button type="button" class="mode-btn ${Store.space === 'patron' ? 'active' : ''}" data-space="patron">Patron</button>
          <button type="button" class="mode-btn ${Store.space === 'accueil' ? 'active' : ''}" data-space="accueil">Accueil</button>
          <button type="button" class="mode-btn ${Store.space === 'caissier' ? 'active' : ''}" data-space="caissier">Caissier</button>
        </div>
      ` : ''}

      <div class="nav-section-label">${sectionTitle}</div>
      <nav class="nav-list">
        ${nav.map((item) => `
          <button type="button" class="nav-item ${item.id === Store.tab ? 'active' : ''}" data-tab="${item.id}">
            ${icon(item.icon, 18)}
            <span>${esc(item.label)}</span>
            ${item.badge ? `<span class="nav-badge" data-cart-badge style="display:${cartCount > 0 ? 'inline-flex' : 'none'}">${cartCount}</span>` : ''}
            ${item.orderBadge ? `<span class="nav-badge" data-order-badge style="display:${orderCartCount > 0 ? 'inline-flex' : 'none'}">${orderCartCount}</span>` : ''}
            ${item.pendingBadge ? `<span class="nav-badge" data-pending-badge style="background:#f59e0b;display:${Store.pendingOrdersCount > 0 ? 'inline-flex' : 'none'}">${Store.pendingOrdersCount}</span>` : ''}
          </button>
        `).join('')}
      </nav>

      <div class="sidebar-footer">
        <div class="cash-pill">
          <div class="cash-pill-top">
            <span style="display:inline-flex;align-items:center;gap:.4rem">
              <span class="dot ${Store.openSession ? 'open' : 'closed'}"></span>
              <span>${Store.openSession ? 'Caisse ouverte' : 'Caisse fermée'}</span>
            </span>
            <strong class="num">${Store.openSession ? fmtMoney(cashBal) : '—'}</strong>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.35rem;gap:.35rem">
            <small style="color:#cbd5e1;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(displayName)}</small>
            <span class="badge badge-blue" style="font-size:.66rem;padding:.08rem .42rem">${esc(Store.user?.role || '')}</span>
          </div>
        </div>
        <button type="button" class="btn btn-outline btn-sm btn-block" id="btn-logout" style="background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.16);color:#f8fafc">
          Déconnexion
        </button>
        <div class="engine-tag">
          <span>Moteur calcul</span>
          <span>${Store.cppEngine.available ? 'C++20 + MySQL' : 'PHP + MySQL'}</span>
        </div>
      </div>
    </aside>

    <div class="main-wrap">
      <header class="topbar">
        <div class="topbar-left">
          <button type="button" class="mobile-menu-btn" id="btn-mobile-menu" aria-label="Menu">${icon('menu', 20)}</button>
          <div class="page-title-group">
            <h2>${esc(activeItem.label)}</h2>
            <p>${esc(activeItem.sub)}</p>
          </div>
        </div>
        <div class="topbar-right">
          ${Store.pendingOrdersCount > 0 && Store.space !== 'caissier' && isAdmin ? `
            <button type="button" class="btn btn-outline btn-sm" id="top-go-cashier">
              <span class="badge badge-amber">${Store.pendingOrdersCount}</span> Commande(s) en caisse
            </button>
          ` : ''}
          ${Store.space === 'patron' && Store.tab !== 'new-sale' ? `
            <button type="button" class="btn btn-primary btn-sm" id="top-quick-sale">${icon('pos', 15)} Nouvelle vente</button>
          ` : ''}
          ${Store.space === 'accueil' ? `<span class="badge badge-blue">Poste Accueil : ${esc(displayName)}</span>` : ''}
          ${Store.space === 'caissier' ? `<span class="badge badge-blue">Poste Caisse : ${esc(displayName)}</span>` : ''}
        </div>
      </header>

      ${Store.demo.active && isAdmin ? `
        <div class="demo-banner">
          <div class="demo-banner-left">
            <span class="badge badge-demo">Mode Démonstration</span>
            <span>Des données d’exemple sont chargées (${Store.demo.products} produits, ${Store.demo.sales} ventes) pour vos tests locaux.</span>
          </div>
          <button type="button" class="btn btn-outline btn-sm" id="banner-clear-demo">${icon('trash', 14)} Supprimer les données démo</button>
        </div>
      ` : ''}

      <main class="page-content" id="page-root"></main>
    </div>
  `;

  app.querySelectorAll('[data-space]').forEach((btn) => {
    btn.addEventListener('click', () => {
      stopCaissierPolling();
      Store.space = btn.dataset.space;
      localStorage.setItem('fincaisse_space', Store.space);
      Store.tab = defaultTabForSpace(Store.space);
      localStorage.setItem('fincaisse_tab', Store.tab);
      renderShell();
      renderActivePage();
    });
  });

  app.querySelectorAll('[data-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      stopCaissierPolling();
      Store.tab = btn.dataset.tab;
      localStorage.setItem('fincaisse_tab', Store.tab);
      closeMobileDrawer();
      renderShell();
      renderActivePage();
    });
  });

  app.querySelector('#top-quick-sale')?.addEventListener('click', () => {
    Store.tab = 'new-sale';
    localStorage.setItem('fincaisse_tab', Store.tab);
    renderShell();
    renderActivePage();
  });

  app.querySelector('#top-go-cashier')?.addEventListener('click', () => {
    Store.space = 'caissier';
    Store.tab = 'pos-pending';
    localStorage.setItem('fincaisse_space', Store.space);
    localStorage.setItem('fincaisse_tab', Store.tab);
    renderShell();
    renderActivePage();
  });

  app.querySelector('#btn-logout')?.addEventListener('click', async () => {
    await api('auth/logout', { method: 'POST' }).catch(() => {});
    Store.user = null;
    Store.cart = [];
    Store.orderCart = [];
    toast('Vous êtes déconnecté.');
    await boot();
  });

  app.querySelector('#banner-clear-demo')?.addEventListener('click', async () => {
    if (!confirm('Supprimer toutes les données de démonstration ? Vos propres données resteront intactes.')) return;
    await api('demo/clear', { method: 'POST' });
    toast('Données de démonstration supprimées.', 'success');
    await refreshHealth();
    renderShell();
    renderActivePage();
  });

  app.querySelector('#btn-mobile-menu')?.addEventListener('click', () => {
    const sb = document.getElementById('fc-sidebar');
    if (!sb) return;
    sb.classList.add('open');
    const bd = document.createElement('div');
    bd.className = 'sidebar-backdrop';
    bd.id = 'fc-sb-backdrop';
    bd.addEventListener('click', closeMobileDrawer);
    document.body.appendChild(bd);
  });
}

function closeMobileDrawer() {
  document.getElementById('fc-sidebar')?.classList.remove('open');
  document.getElementById('fc-sb-backdrop')?.remove();
}

async function renderActivePage() {
  const root = document.getElementById('page-root');
  if (!root) return;
  root.innerHTML = `<div class="boot-loader" style="min-height:220px"><div class="boot-spinner"></div></div>`;

  const onRefreshQuiet = async () => {
    await refreshHealth();
    const balEl = document.querySelector('.cash-pill strong');
    if (balEl) {
      const cashBal = Store.openSession?.summary?.expected_amount ?? Store.openSession?.expected_amount ?? 0;
      balEl.textContent = Store.openSession ? fmtMoney(cashBal) : '—';
    }
    document.querySelectorAll('[data-pending-badge]').forEach((el) => {
      el.textContent = String(Store.pendingOrdersCount);
      el.style.display = Store.pendingOrdersCount > 0 ? 'inline-flex' : 'none';
    });
  };

  try {
    if (Store.space === 'patron') {
      await renderPatronView(Store.tab, root, async () => {
        await refreshHealth();
        renderShell();
      });
    } else if (Store.space === 'accueil') {
      await renderAccueilView(Store.tab, root, onRefreshQuiet);
    } else {
      await renderCaissierView(Store.tab, root, onRefreshQuiet);
    }
  } catch (err) {
    root.innerHTML = `
      <div class="card card-body empty-state">
        ${icon('alert', 32)}
        <h3>Impossible de charger cette section</h3>
        <p class="text-red">${esc(err.message)}</p>
      </div>`;
    toast(err.message, 'error');
  }
}

async function boot() {
  try {
    const status = await api('auth/status');
    if (status.needs_setup) {
      renderSetupView();
      return;
    }
    if (!status.authenticated || !status.user) {
      renderLoginView();
      return;
    }
    Store.user = status.user;
    if (status.user.role !== 'ADMIN') {
      Store.space = spaceForRole(status.user.role);
    } else if (!['patron', 'accueil', 'caissier'].includes(Store.space)) {
      Store.space = 'patron';
    }
    await refreshHealth();
    renderShell();
    await renderActivePage();
  } catch (err) {
    document.getElementById('app').innerHTML = `
      <div class="boot-loader">
        <h2>Erreur de connexion FINCAISSE</h2>
        <p style="color:#dc2626">${esc(err.message)}</p>
        <p style="color:#64748b;font-weight:400">Vérifiez que MySQL (MAMP port 8889 ou port 3306) est démarré.</p>
      </div>`;
  }
}

window.addEventListener('fincaisse:unauthorized', () => {
  boot();
});

boot();
