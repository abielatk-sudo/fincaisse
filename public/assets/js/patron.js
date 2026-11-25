import { api, esc, fmtDate, fmtMoney, fmtShortDate, icon, openModal, Store, toast } from './api.js';
import { renderBarChart } from './charts.js';
import { renderPosWorkspace, showReceiptModal } from './pos.js';

export async function renderPatronView(tab, container, onRefreshShell) {
  switch (tab) {
    case 'dashboard': return renderDashboard(container, onRefreshShell);
    case 'cash': return renderCash(container, onRefreshShell);
    case 'new-sale': return renderPosWorkspace(container, { actor: 'Patron', allowDiscount: true, viewMode: 'split', onAfterSale: onRefreshShell });
    case 'products': return renderProducts(container);
    case 'categories': return renderCategories(container);
    case 'customers': return renderCustomers(container);
    case 'expenses': return renderExpenses(container, onRefreshShell);
    case 'transactions': return renderTransactions(container, onRefreshShell);
    case 'reports': return renderReports(container);
    case 'history': return renderAuditHistory(container);
    case 'users': return renderUsers(container);
    case 'settings': return renderSettings(container, onRefreshShell);
    default: return renderDashboard(container, onRefreshShell);
  }
}

async function renderDashboard(container, onRefreshShell) {
  const d = await api('dashboard');
  let chartMode = 'daily';
  const isEmpty = Number(d.products.total_products) === 0 && Number(d.periods.month.count) === 0;

  container.innerHTML = `
    ${isEmpty ? `
      <div class="card card-body" style="margin-bottom:1.2rem;border-color:#93c5fd;background:linear-gradient(135deg,#eff6ff,#ffffff);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <div>
          <h3 style="color:var(--navy-900);margin-bottom:.25rem">Bienvenue dans FINCAISSE</h3>
          <p class="text-muted">Votre base MySQL est prête et vierge. Ajoutez vos propres produits ou activez le mode démonstration pour tester immédiatement avec des données d’exemple identifiées.</p>
        </div>
        <div class="toolbar-group">
          <button type="button" class="btn btn-primary" id="dash-seed-demo">${icon('sparkles', 16)} Activer le mode démonstration</button>
        </div>
      </div>
    ` : ''}

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-top">
          <span>Solde actuel de caisse</span>
          <div class="kpi-icon navy">${icon('cash', 18)}</div>
        </div>
        <div class="kpi-value num">${fmtMoney(d.cash.balance)}</div>
        <div class="kpi-sub">
          ${d.cash.status === 'OPEN'
            ? '<span class="badge badge-green">Caisse ouverte</span>'
            : '<span class="badge badge-amber">Caisse fermée</span>'}
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-top">
          <span>Ventes du jour</span>
          <div class="kpi-icon blue">${icon('pos', 18)}</div>
        </div>
        <div class="kpi-value num">${fmtMoney(d.today.sales_total)}</div>
        <div class="kpi-sub">${d.today.sales_count} vente(s) effectuée(s) aujourd’hui</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-top">
          <span>Dépenses du jour</span>
          <div class="kpi-icon amber">${icon('expenses', 18)}</div>
        </div>
        <div class="kpi-value num">${fmtMoney(d.today.expenses_total)}</div>
        <div class="kpi-sub">${d.today.expenses_count} dépense(s) enregistrée(s)</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-top">
          <span>Produits & Stocks</span>
          <div class="kpi-icon ${Number(d.products.out_of_stock_count) > 0 ? 'red' : 'green'}">${icon('products', 18)}</div>
        </div>
        <div class="kpi-value num">${d.products.available_products} <small style="font-size:.82rem;font-weight:600;color:var(--ink-500)">/ ${d.products.total_products}</small></div>
        <div class="kpi-sub">
          <span class="${Number(d.products.out_of_stock_count) > 0 ? 'text-red fw-bold' : ''}">${d.products.out_of_stock_count} rupture(s)</span>
          · ${d.products.low_stock_count} stock faible
        </div>
      </div>
    </div>

    <div class="grid-3">
      <div class="card card-body">
        <small class="text-muted fw-bold">AUJOURD'HUI</small>
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:.35rem">
          <span class="fw-bold num" style="font-size:1.15rem">${fmtMoney(d.periods.day.sales)}</span>
          <span class="badge badge-blue">${d.periods.day.count} ventes</span>
        </div>
        <small class="text-muted">Dépenses : ${fmtMoney(d.periods.day.expenses)}</small>
      </div>
      <div class="card card-body">
        <small class="text-muted fw-bold">CETTE SEMAINE</small>
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:.35rem">
          <span class="fw-bold num" style="font-size:1.15rem">${fmtMoney(d.periods.week.sales)}</span>
          <span class="badge badge-blue">${d.periods.week.count} ventes</span>
        </div>
        <small class="text-muted">Dépenses : ${fmtMoney(d.periods.week.expenses)}</small>
      </div>
      <div class="card card-body">
        <small class="text-muted fw-bold">CE MOIS</small>
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:.35rem">
          <span class="fw-bold num" style="font-size:1.15rem">${fmtMoney(d.periods.month.sales)}</span>
          <span class="badge badge-blue">${d.periods.month.count} ventes</span>
        </div>
        <small class="text-muted">Dépenses : ${fmtMoney(d.periods.month.expenses)}</small>
      </div>
    </div>

    <div class="grid-2">
      <div class="card">
        <div class="card-header">
          <div class="card-title">${icon('reports', 18)} Évolution des ventes & dépenses</div>
          <div class="tabs" id="dash-chart-tabs">
            <button type="button" class="tab-btn active" data-cmode="daily">Journalier</button>
            <button type="button" class="tab-btn" data-cmode="weekly">Hebdomadaire</button>
            <button type="button" class="tab-btn" data-cmode="monthly">Mensuel</button>
          </div>
        </div>
        <div class="card-body" id="dash-chart-box"></div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">${icon('alert', 18)} Alertes stock & ruptures</div>
          <span class="badge ${d.stock_alerts.length ? 'badge-red' : 'badge-green'}">${d.stock_alerts.length} alerte(s)</span>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Produit</th><th class="text-right">Stock</th><th class="text-right">Seuil</th></tr></thead>
            <tbody>
              ${d.stock_alerts.length ? d.stock_alerts.map((p) => `
                <tr>
                  <td><strong>${esc(p.name)}</strong><br><small class="text-muted">${esc(p.category_name || 'Général')}</small></td>
                  <td class="text-right num fw-bold ${Number(p.stock) <= 0 ? 'text-red' : 'text-amber'}">${p.stock <= 0 ? 'Rupture (0)' : p.stock}</td>
                  <td class="text-right num text-muted">${p.alert_threshold}</td>
                </tr>
              `).join('') : `<tr><td colspan="3" class="empty-state">Tous les produits ont un stock satisfaisant.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="grid-equal-2">
      <div class="card">
        <div class="card-header">
          <div class="card-title">${icon('products', 18)} Produits les plus vendus</div>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Produit</th><th class="text-right">Qté vendue</th><th class="text-right">CA généré</th></tr></thead>
            <tbody>
              ${d.top_products.length ? d.top_products.map((tp) => `
                <tr>
                  <td class="fw-bold">${esc(tp.name)}</td>
                  <td class="text-right num"><span class="badge badge-blue">${tp.qty}</span></td>
                  <td class="text-right num fw-bold">${fmtMoney(tp.revenue)}</td>
                </tr>
              `).join('') : `<tr><td colspan="3" class="empty-state">Aucune vente enregistrée pour le moment.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">${icon('transactions', 18)} Dernières transactions</div>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Reçu</th><th>Client</th><th>Statut</th><th class="text-right">Montant</th></tr></thead>
            <tbody>
              ${d.recent_transactions.length ? d.recent_transactions.map((t) => `
                <tr>
                  <td><strong class="num">${esc(t.receipt_number)}</strong><br><small class="text-muted">${fmtDate(t.created_at)}</small></td>
                  <td>${esc(t.customer_name || 'Comptoir')}</td>
                  <td>${t.status === 'COMPLETED' ? '<span class="badge badge-green">Validée</span>' : '<span class="badge badge-red">Annulée</span>'}</td>
                  <td class="text-right num fw-bold">${fmtMoney(t.total_amount)}</td>
                </tr>
              `).join('') : `<tr><td colspan="4" class="empty-state">Aucune transaction récente.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `;

  const drawChart = () => {
    const box = container.querySelector('#dash-chart-box');
    const series = d.charts[chartMode] || [];
    renderBarChart(box, series, { showExpenses: chartMode === 'daily' });
  };
  drawChart();

  container.querySelectorAll('[data-cmode]').forEach((b) => {
    b.addEventListener('click', () => {
      chartMode = b.dataset.cmode;
      container.querySelectorAll('[data-cmode]').forEach((x) => x.classList.toggle('active', x.dataset.cmode === chartMode));
      drawChart();
    });
  });

  container.querySelector('#dash-seed-demo')?.addEventListener('click', async () => {
    await api('demo/seed', { method: 'POST' });
    toast('Mode démonstration activé !', 'success');
    await onRefreshShell();
    await renderDashboard(container, onRefreshShell);
  });
}

async function renderCash(container, onRefreshShell) {
  const data = await api('cash');
  const open = data.open_session;
  const sum = data.summary || {};
  const movements = data.movements || [];
  const history = data.history || [];

  container.innerHTML = `
    <div class="toolbar">
      <div class="toolbar-group">
        ${open
          ? `<span class="badge badge-green">Session #${open.id} ouverte le ${fmtDate(open.opened_at)}</span>`
          : `<span class="badge badge-amber">Aucune session de caisse ouverte</span>`}
      </div>
      <div class="toolbar-group">
        ${!open ? `<button type="button" class="btn btn-primary" id="btn-open-cash">${icon('cash', 16)} Ouvrir la caisse</button>` : `
          <button type="button" class="btn btn-outline" id="btn-cash-in">${icon('plus', 16)} Entrée caisse</button>
          <button type="button" class="btn btn-outline" id="btn-cash-out">${icon('expenses', 16)} Sortie caisse</button>
          <button type="button" class="btn btn-danger" id="btn-close-cash">Fermer la caisse</button>
        `}
      </div>
    </div>

    ${open ? `
      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-top"><span>Montant de départ</span></div>
          <div class="kpi-value num">${fmtMoney(sum.opening_amount)}</div>
          <div class="kpi-sub">Ouvert par ${esc(open.opened_by)}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><span>Entrées (Espèces)</span></div>
          <div class="kpi-value num text-green">+${fmtMoney(sum.total_in)}</div>
          <div class="kpi-sub">Ventes : ${fmtMoney(sum.cash_sales)} · Manuelles : ${fmtMoney(sum.manual_in)}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><span>Sorties</span></div>
          <div class="kpi-value num text-red">−${fmtMoney(sum.total_out)}</div>
          <div class="kpi-sub">Dépenses : ${fmtMoney(sum.cash_expenses)} · Retraits : ${fmtMoney(sum.manual_out)}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><span>Solde calculé (Moteur C++)</span></div>
          <div class="kpi-value num">${fmtMoney(sum.expected_amount)}</div>
          <div class="kpi-sub">En caisse théorique temps réel</div>
        </div>
      </div>
    ` : ''}

    <div class="grid-equal-2">
      <div class="card">
        <div class="card-header"><div class="card-title">${icon('history', 18)} Opérations de caisse</div></div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="text-right">Montant</th></tr></thead>
            <tbody>
              ${movements.length ? movements.map((m) => `
                <tr>
                  <td class="num text-muted">${fmtDate(m.created_at)}</td>
                  <td><span class="badge ${['OPENING','SALE','IN'].includes(m.type) ? 'badge-green' : m.type === 'CLOSING' ? 'badge-blue' : 'badge-red'}">${esc(m.type)}</span></td>
                  <td>${esc(m.description)}</td>
                  <td class="text-right num fw-bold">${fmtMoney(m.amount)}</td>
                </tr>
              `).join('') : `<tr><td colspan="4" class="empty-state">Aucune opération enregistrée.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">${icon('cash', 18)} Historique des sessions & écarts</div></div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Session</th><th>Statut</th><th class="text-right">Attendu</th><th class="text-right">Réel</th><th class="text-right">Écart</th></tr></thead>
            <tbody>
              ${history.length ? history.map((h) => {
                const disc = Number(h.discrepancy || 0);
                return `
                  <tr>
                    <td><strong>#${h.id}</strong><br><small class="text-muted">${fmtDate(h.opened_at)}</small></td>
                    <td>${h.status === 'OPEN' ? '<span class="badge badge-green">Ouverte</span>' : '<span class="badge badge-slate">Fermée</span>'}</td>
                    <td class="text-right num">${fmtMoney(h.expected_amount)}</td>
                    <td class="text-right num">${h.actual_amount !== null ? fmtMoney(h.actual_amount) : '—'}</td>
                    <td class="text-right num fw-bold ${disc === 0 ? 'text-green' : disc > 0 ? 'text-blue' : 'text-red'}">${h.actual_amount !== null ? `${disc > 0 ? '+' : ''}${fmtMoney(disc)}` : '—'}</td>
                  </tr>
                `;
              }).join('') : `<tr><td colspan="5" class="empty-state">Aucune session de caisse.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `;

  container.querySelector('#btn-open-cash')?.addEventListener('click', () => {
    openModal({
      title: 'Ouverture de caisse',
      bodyHtml: `
        <form id="f-open-cash" class="form-grid">
          <div class="form-field"><label>Montant de départ (FCFA)</label><input name="opening_amount" type="number" min="0" class="input num" value="0" required></div>
          <div class="form-field"><label>Responsable</label><input name="opened_by" class="input" value="Patron" required></div>
          <div class="form-field full"><label>Observations</label><textarea name="notes" class="textarea" rows="2"></textarea></div>
        </form>`,
      footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-open-cash" class="btn btn-primary">Ouvrir la caisse</button>`,
      onMount: (dlg, close) => {
        dlg.querySelector('#f-open-cash').addEventListener('submit', async (e) => {
          e.preventDefault();
          const fd = new FormData(e.target);
          await api('cash/open', { method: 'POST', body: Object.fromEntries(fd) });
          toast('Caisse ouverte avec succès.', 'success');
          close();
          await onRefreshShell();
          await renderCash(container, onRefreshShell);
        });
      },
    });
  });

  const openMovementModal = (type) => {
    const label = type === 'IN' ? 'Entrée de caisse' : 'Sortie de caisse';
    openModal({
      title: label,
      bodyHtml: `
        <form id="f-cash-mv" class="form-grid">
          <div class="form-field"><label>Montant (FCFA)</label><input name="amount" type="number" min="1" class="input num" required></div>
          <div class="form-field"><label>Effectué par</label><input name="actor" class="input" value="Patron" required></div>
          <div class="form-field full"><label>Motif / Description</label><input name="description" class="input" required placeholder="Ex : Apport fond de caisse, dépôt banque…"></div>
        </form>`,
      footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-cash-mv" class="btn btn-primary">Enregistrer</button>`,
      onMount: (dlg, close) => {
        dlg.querySelector('#f-cash-mv').addEventListener('submit', async (e) => {
          e.preventDefault();
          const body = Object.fromEntries(new FormData(e.target));
          body.type = type;
          await api('cash/movement', { method: 'POST', body });
          toast(`${label} enregistrée.`, 'success');
          close();
          await onRefreshShell();
          await renderCash(container, onRefreshShell);
        });
      },
    });
  };
  container.querySelector('#btn-cash-in')?.addEventListener('click', () => openMovementModal('IN'));
  container.querySelector('#btn-cash-out')?.addEventListener('click', () => openMovementModal('OUT'));

  container.querySelector('#btn-close-cash')?.addEventListener('click', () => {
    const expected = Number(sum.expected_amount || 0);
    openModal({
      title: 'Fermeture et comptage de caisse',
      bodyHtml: `
        <div class="card card-body" style="background:var(--bg-subtle);margin-bottom:1rem">
          <div class="summary-line"><span>Solde théorique attendu :</span><strong class="num">${fmtMoney(expected)}</strong></div>
        </div>
        <form id="f-close-cash" class="form-grid">
          <div class="form-field"><label>Montant réel compté (FCFA)</label><input id="inp-actual" name="actual_amount" type="number" min="0" class="input num" value="${expected}" required></div>
          <div class="form-field"><label>Clôturé par</label><input name="closed_by" class="input" value="Patron" required></div>
          <div class="form-field full"><div id="close-diff-preview" class="badge badge-green">Écart : 0 FCFA (Équilibrée)</div></div>
          <div class="form-field full"><label>Commentaire de clôture</label><textarea name="notes" class="textarea" rows="2"></textarea></div>
        </form>`,
      footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-close-cash" class="btn btn-danger">Valider la fermeture</button>`,
      onMount: (dlg, close) => {
        const inp = dlg.querySelector('#inp-actual');
        const prev = dlg.querySelector('#close-diff-preview');
        inp.addEventListener('input', () => {
          const diff = (Number(inp.value) || 0) - expected;
          prev.className = `badge ${diff === 0 ? 'badge-green' : diff > 0 ? 'badge-blue' : 'badge-red'}`;
          prev.textContent = `Écart : ${diff > 0 ? '+' : ''}${fmtMoney(diff)} (${diff === 0 ? 'Équilibrée' : diff > 0 ? 'Excédent' : 'Manquant'})`;
        });
        dlg.querySelector('#f-close-cash').addEventListener('submit', async (e) => {
          e.preventDefault();
          await api('cash/close', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
          toast('Caisse fermée et réconciliée.', 'success');
          close();
          await onRefreshShell();
          await renderCash(container, onRefreshShell);
        });
      },
    });
  });
}

async function renderProducts(container) {
  let subTab = 'catalog'; // 'catalog' | 'movements'
  let q = '';
  let catId = '';
  let stockFilter = '';

  const catRes = await api('categories');
  const categories = catRes.categories || [];

  const openProductModal = (prod = null) => {
    const isEdit = Boolean(prod);
    openModal({
      title: isEdit ? `Modifier : ${prod.name}` : 'Nouveau produit',
      bodyHtml: `
        <form id="f-prod" class="form-grid">
          <div class="form-field full"><label>Nom du produit *</label><input name="name" class="input" required value="${esc(prod?.name || '')}"></div>
          <div class="form-field"><label>Catégorie</label>
            <select name="category_id" class="select">
              <option value="">— Sans catégorie —</option>
              ${categories.map((c) => `<option value="${c.id}" ${Number(prod?.category_id) === Number(c.id) ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}
            </select>
          </div>
          <div class="form-field"><label>Référence / SKU</label><input name="sku" class="input" value="${esc(prod?.sku || '')}" placeholder="Ex : BOI-001"></div>
          <div class="form-field"><label>Prix de vente (FCFA) *</label><input name="sell_price" type="number" min="0" class="input num" required value="${prod?.sell_price ?? ''}"></div>
          <div class="form-field"><label>Prix d’achat (FCFA)</label><input name="buy_price" type="number" min="0" class="input num" value="${prod?.buy_price ?? 0}"></div>
          <div class="form-field"><label>Quantité en stock *</label><input name="stock" type="number" min="0" class="input num" required value="${prod?.stock ?? 0}"></div>
          <div class="form-field"><label>Seuil d’alerte stock bas</label><input name="alert_threshold" type="number" min="0" class="input num" value="${prod?.alert_threshold ?? 5}"></div>
          <div class="form-field full"><label>Description</label><textarea name="description" class="textarea" rows="2">${esc(prod?.description || '')}</textarea></div>
        </form>`,
      footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-prod" class="btn btn-primary">${isEdit ? 'Enregistrer' : 'Ajouter le produit'}</button>`,
      onMount: (dlg, close) => {
        dlg.querySelector('#f-prod').addEventListener('submit', async (e) => {
          e.preventDefault();
          const body = Object.fromEntries(new FormData(e.target));
          if (isEdit) body.id = prod.id;
          await api('products', { method: isEdit ? 'PUT' : 'POST', body });
          toast(isEdit ? 'Produit mis à jour.' : 'Produit ajouté.', 'success');
          close();
          await loadView();
        });
      },
    });
  };

  const openStockModal = (prod) => {
    openModal({
      title: `Mouvement de stock — ${prod.name}`,
      bodyHtml: `
        <p class="text-muted" style="margin-bottom:1rem">Stock actuel : <strong class="num">${prod.stock}</strong> unité(s)</p>
        <form id="f-stock" class="form-grid">
          <div class="form-field">
            <label>Type de mouvement</label>
            <select name="type" class="select">
              <option value="IN">Entrée (Réapprovisionnement)</option>
              <option value="OUT">Sortie (Perte, casse, don)</option>
              <option value="ADJUSTMENT">Inventaire (Fixer nouveau stock)</option>
            </select>
          </div>
          <div class="form-field"><label>Quantité</label><input name="quantity" type="number" min="0" class="input num" required value="1"></div>
          <div class="form-field full"><label>Motif / Note</label><input name="note" class="input" placeholder="Ex : Livraison fournisseur"></div>
        </form>`,
      footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-stock" class="btn btn-primary">Valider le mouvement</button>`,
      onMount: (dlg, close) => {
        dlg.querySelector('#f-stock').addEventListener('submit', async (e) => {
          e.preventDefault();
          const body = Object.fromEntries(new FormData(e.target));
          body.product_id = prod.id;
          await api('products/stock', { method: 'POST', body });
          toast('Stock mis à jour avec traçabilité.', 'success');
          close();
          await loadView();
        });
      },
    });
  };

  const loadView = async () => {
    const bodyEl = container.querySelector('#prod-content');
    if (!bodyEl) return;

    if (subTab === 'movements') {
      const res = await api('stock-movements');
      const mvs = res.movements || [];
      bodyEl.innerHTML = `
        <div class="card">
          <div class="table-wrap">
            <table class="data-table">
              <thead><tr><th>Date</th><th>Produit</th><th>Type</th><th class="text-right">Variation</th><th class="text-right">Avant → Après</th><th>Motif</th></tr></thead>
              <tbody>
                ${mvs.length ? mvs.map((m) => `
                  <tr>
                    <td class="num text-muted">${fmtDate(m.created_at)}</td>
                    <td class="fw-bold">${esc(m.product_name)}</td>
                    <td><span class="badge ${['IN','RETURN'].includes(m.type) ? 'badge-green' : m.type === 'ADJUSTMENT' ? 'badge-blue' : 'badge-amber'}">${esc(m.type)}</span></td>
                    <td class="text-right num fw-bold ${Number(m.quantity_delta) >= 0 ? 'text-green' : 'text-red'}">${Number(m.quantity_delta) > 0 ? '+' : ''}${m.quantity_delta}</td>
                    <td class="text-right num">${m.stock_before} → <strong>${m.stock_after}</strong></td>
                    <td><small class="text-muted">${esc(m.note || m.reference || '—')}</small></td>
                  </tr>
                `).join('') : `<tr><td colspan="6" class="empty-state">Aucun mouvement de stock enregistré.</td></tr>`}
              </tbody>
            </table>
          </div>
        </div>
      `;
      return;
    }

    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (catId) params.set('category_id', catId);
    if (stockFilter) params.set('stock', stockFilter);
    const res = await api(`products?${params.toString()}`);
    const list = res.products || [];

    bodyEl.innerHTML = `
      <div class="card">
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Produit</th>
                <th>Catégorie</th>
                <th class="text-right">Prix achat</th>
                <th class="text-right">Prix vente</th>
                <th class="text-right">Stock</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              ${list.length ? list.map((p) => {
                const st = Number(p.stock);
                const out = st <= 0;
                const low = st > 0 && st <= Number(p.alert_threshold);
                return `
                  <tr>
                    <td>
                      <strong>${esc(p.name)}</strong> ${Number(p.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}
                      ${p.sku ? `<br><small class="text-muted">SKU : ${esc(p.sku)}</small>` : ''}
                    </td>
                    <td><span class="badge badge-blue">${esc(p.category_name || 'Général')}</span></td>
                    <td class="text-right num text-muted">${fmtMoney(p.buy_price)}</td>
                    <td class="text-right num fw-bold">${fmtMoney(p.sell_price)}</td>
                    <td class="text-right num">
                      <span class="badge ${out ? 'badge-red' : low ? 'badge-amber' : 'badge-green'}">
                        ${out ? 'Rupture (0)' : `${st} u.`}
                      </span>
                    </td>
                    <td class="td-actions">
                      <button type="button" class="btn btn-outline btn-sm" data-stock="${p.id}">Stock</button>
                      <button type="button" class="btn btn-outline btn-sm" data-edit="${p.id}">${icon('edit', 14)}</button>
                      <button type="button" class="btn btn-ghost btn-sm text-red" data-del="${p.id}">${icon('trash', 14)}</button>
                    </td>
                  </tr>
                `;
              }).join('') : `<tr><td colspan="6" class="empty-state">Aucun produit trouvé.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>
    `;

    bodyEl.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
      const p = list.find((x) => Number(x.id) === Number(b.dataset.edit));
      if (p) openProductModal(p);
    }));
    bodyEl.querySelectorAll('[data-stock]').forEach((b) => b.addEventListener('click', () => {
      const p = list.find((x) => Number(x.id) === Number(b.dataset.stock));
      if (p) openStockModal(p);
    }));
    bodyEl.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
      const p = list.find((x) => Number(x.id) === Number(b.dataset.del));
      if (!p || !confirm(`Supprimer le produit « ${p.name} » ?`)) return;
      await api(`products?id=${p.id}`, { method: 'DELETE' });
      toast('Produit supprimé.', 'success');
      await loadView();
    }));
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="toolbar-group">
        <div class="tabs">
          <button type="button" class="tab-btn active" data-ptab="catalog">Catalogue produits</button>
          <button type="button" class="tab-btn" data-ptab="movements">Mouvements de stock</button>
        </div>
        <div class="search-box">
          ${icon('search', 16)}
          <input id="prod-q" class="input" placeholder="Rechercher un produit…">
        </div>
        <select id="prod-cat" class="select" style="width:auto">
          <option value="">Toutes catégories</option>
          ${categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('')}
        </select>
        <select id="prod-stock-f" class="select" style="width:auto">
          <option value="">Tous stocks</option>
          <option value="available">Disponibles</option>
          <option value="low">Stock faible</option>
          <option value="out">En rupture</option>
        </select>
      </div>
      <button type="button" class="btn btn-primary" id="btn-add-prod">${icon('plus', 16)} Ajouter un produit</button>
    </div>
    <div id="prod-content"></div>
  `;

  container.querySelectorAll('[data-ptab]').forEach((b) => b.addEventListener('click', () => {
    subTab = b.dataset.ptab;
    container.querySelectorAll('[data-ptab]').forEach((x) => x.classList.toggle('active', x.dataset.ptab === subTab));
    loadView();
  }));
  container.querySelector('#prod-q').addEventListener('input', (e) => { q = e.target.value; loadView(); });
  container.querySelector('#prod-cat').addEventListener('change', (e) => { catId = e.target.value; loadView(); });
  container.querySelector('#prod-stock-f').addEventListener('change', (e) => { stockFilter = e.target.value; loadView(); });
  container.querySelector('#btn-add-prod').addEventListener('click', () => openProductModal(null));

  await loadView();
}

async function renderCategories(container) {
  const res = await api('categories');
  const list = res.categories || [];

  const openCatModal = (cat = null) => {
    const isEdit = Boolean(cat);
    openModal({
      title: isEdit ? `Modifier catégorie : ${cat.name}` : 'Nouvelle catégorie',
      bodyHtml: `
        <form id="f-cat" class="form-grid">
          <div class="form-field"><label>Nom *</label><input name="name" class="input" required value="${esc(cat?.name || '')}"></div>
          <div class="form-field"><label>Couleur</label><input name="color" type="color" class="input" style="height:40px;padding:4px" value="${esc(cat?.color || '#2563EB')}"></div>
          <div class="form-field full"><label>Description</label><textarea name="description" class="textarea" rows="2">${esc(cat?.description || '')}</textarea></div>
        </form>`,
      footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-cat" class="btn btn-primary">Enregistrer</button>`,
      onMount: (dlg, close) => {
        dlg.querySelector('#f-cat').addEventListener('submit', async (e) => {
          e.preventDefault();
          const body = Object.fromEntries(new FormData(e.target));
          if (isEdit) body.id = cat.id;
          await api('categories', { method: isEdit ? 'PUT' : 'POST', body });
          toast(isEdit ? 'Catégorie modifiée.' : 'Catégorie ajoutée.', 'success');
          close();
          await renderCategories(container);
        });
      },
    });
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="text-muted">${list.length} catégorie(s) enregistrée(s)</div>
      <button type="button" class="btn btn-primary" id="btn-add-cat">${icon('plus', 16)} Nouvelle catégorie</button>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Catégorie</th><th>Description</th><th class="text-right">Produits</th><th class="text-right">Actions</th></tr></thead>
          <tbody>
            ${list.length ? list.map((c) => `
              <tr>
                <td>
                  <span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:${esc(c.color || '#2563EB')};margin-right:.45rem"></span>
                  <strong>${esc(c.name)}</strong> ${Number(c.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}
                </td>
                <td class="text-muted">${esc(c.description || '—')}</td>
                <td class="text-right num"><span class="badge badge-slate">${c.product_count}</span></td>
                <td class="td-actions">
                  <button type="button" class="btn btn-outline btn-sm" data-edit-cat="${c.id}">${icon('edit', 14)} Modifier</button>
                  <button type="button" class="btn btn-ghost btn-sm text-red" data-del-cat="${c.id}">${icon('trash', 14)}</button>
                </td>
              </tr>
            `).join('') : `<tr><td colspan="4" class="empty-state">Aucune catégorie créée.</td></tr>`}
          </tbody>
        </table>
      </div>
    </div>
  `;

  container.querySelector('#btn-add-cat').addEventListener('click', () => openCatModal(null));
  container.querySelectorAll('[data-edit-cat]').forEach((b) => b.addEventListener('click', () => {
    const c = list.find((x) => Number(x.id) === Number(b.dataset.editCat));
    if (c) openCatModal(c);
  }));
  container.querySelectorAll('[data-del-cat]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Supprimer cette catégorie ? Les produits associés seront conservés sans catégorie.')) return;
    await api(`categories?id=${b.dataset.delCat}`, { method: 'DELETE' });
    toast('Catégorie supprimée.', 'success');
    await renderCategories(container);
  }));
}

async function renderCustomers(container) {
  let q = '';

  const openCustModal = (cust = null) => {
    const isEdit = Boolean(cust);
    openModal({
      title: isEdit ? `Modifier : ${cust.name}` : 'Nouveau client',
      bodyHtml: `
        <form id="f-cust" class="form-grid">
          <div class="form-field full"><label>Nom complet / Raison sociale *</label><input name="name" class="input" required value="${esc(cust?.name || '')}"></div>
          <div class="form-field"><label>Téléphone</label><input name="phone" class="input" value="${esc(cust?.phone || '')}"></div>
          <div class="form-field"><label>Email</label><input name="email" type="email" class="input" value="${esc(cust?.email || '')}"></div>
          <div class="form-field full"><label>Adresse</label><input name="address" class="input" value="${esc(cust?.address || '')}"></div>
          <div class="form-field full"><label>Notes</label><textarea name="notes" class="textarea" rows="2">${esc(cust?.notes || '')}</textarea></div>
        </form>`,
      footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-cust" class="btn btn-primary">Enregistrer</button>`,
      onMount: (dlg, close) => {
        dlg.querySelector('#f-cust').addEventListener('submit', async (e) => {
          e.preventDefault();
          const body = Object.fromEntries(new FormData(e.target));
          if (isEdit) body.id = cust.id;
          await api('customers', { method: isEdit ? 'PUT' : 'POST', body });
          toast(isEdit ? 'Client modifié.' : 'Client ajouté.', 'success');
          close();
          await loadCustomers();
        });
      },
    });
  };

  const openHistoryModal = async (custId) => {
    const res = await api(`customers/history?id=${custId}`);
    const c = res.customer;
    const sales = res.sales || [];
    openModal({
      title: `Historique d'achats — ${c.name}`,
      large: true,
      bodyHtml: `
        <div style="margin-bottom:1rem;display:flex;gap:1rem;flex-wrap:wrap">
          <span class="badge badge-blue">Contact : ${esc(c.phone || c.email || 'Non renseigné')}</span>
          <span class="badge badge-green">${sales.filter((s) => s.status === 'COMPLETED').length} achat(s) validé(s)</span>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Reçu</th><th>Date</th><th>Paiement</th><th>Statut</th><th class="text-right">Total</th><th class="text-right">Reçu</th></tr></thead>
            <tbody>
              ${sales.length ? sales.map((s) => `
                <tr>
                  <td class="num fw-bold">${esc(s.receipt_number)}</td>
                  <td class="num text-muted">${fmtDate(s.created_at)}</td>
                  <td>${esc(s.payment_method)}</td>
                  <td>${s.status === 'COMPLETED' ? '<span class="badge badge-green">Validée</span>' : '<span class="badge badge-red">Annulée</span>'}</td>
                  <td class="text-right num fw-bold">${fmtMoney(s.total_amount)}</td>
                  <td class="td-actions"><button type="button" class="btn btn-outline btn-sm" data-rec-sale="${s.id}">Voir</button></td>
                </tr>
              `).join('') : `<tr><td colspan="6" class="empty-state">Aucun achat enregistré pour ce client.</td></tr>`}
            </tbody>
          </table>
        </div>`,
      footerHtml: `<button type="button" class="btn btn-navy" data-close>Fermer</button>`,
      onMount: (dlg) => {
        dlg.querySelectorAll('[data-rec-sale]').forEach((b) => b.addEventListener('click', async () => {
          const det = await api(`sales/detail?id=${b.dataset.recSale}`);
          if (det.receipt) showReceiptModal(det.receipt, det.sale.id);
        }));
      },
    });
  };

  const loadCustomers = async () => {
    const res = await api(`customers${q ? `?q=${encodeURIComponent(q)}` : ''}`);
    const list = res.customers || [];
    const tbody = container.querySelector('#cust-tbody');
    if (!tbody) return;
    tbody.innerHTML = list.length ? list.map((c) => `
      <tr>
        <td><strong>${esc(c.name)}</strong> ${Number(c.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}</td>
        <td>${esc(c.phone || '—')}<br><small class="text-muted">${esc(c.email || '')}</small></td>
        <td class="text-right num">${c.purchase_count}</td>
        <td class="text-right num fw-bold">${fmtMoney(c.total_spent)}</td>
        <td class="td-actions">
          <button type="button" class="btn btn-outline btn-sm" data-hist="${c.id}">Historique</button>
          <button type="button" class="btn btn-outline btn-sm" data-edit="${c.id}">${icon('edit', 14)}</button>
          <button type="button" class="btn btn-ghost btn-sm text-red" data-del="${c.id}">${icon('trash', 14)}</button>
        </td>
      </tr>
    `).join('') : `<tr><td colspan="5" class="empty-state">Aucun client enregistré.</td></tr>`;

    tbody.querySelectorAll('[data-hist]').forEach((b) => b.addEventListener('click', () => openHistoryModal(b.dataset.hist)));
    tbody.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
      const c = list.find((x) => Number(x.id) === Number(b.dataset.edit));
      if (c) openCustModal(c);
    }));
    tbody.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
      if (!confirm('Supprimer ce client ?')) return;
      await api(`customers?id=${b.dataset.del}`, { method: 'DELETE' });
      toast('Client supprimé.', 'success');
      await loadCustomers();
    }));
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="search-box">
        ${icon('search', 16)}
        <input id="cust-q" class="input" placeholder="Rechercher un client (nom, tél)…">
      </div>
      <button type="button" class="btn btn-primary" id="btn-add-cust">${icon('plus', 16)} Ajouter un client</button>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Client</th><th>Contact</th><th class="text-right">Achats</th><th class="text-right">Total dépensé</th><th class="text-right">Actions</th></tr></thead>
          <tbody id="cust-tbody"></tbody>
        </table>
      </div>
    </div>
  `;

  container.querySelector('#cust-q').addEventListener('input', (e) => { q = e.target.value; loadCustomers(); });
  container.querySelector('#btn-add-cust').addEventListener('click', () => openCustModal(null));
  await loadCustomers();
}

async function renderExpenses(container, onRefreshShell) {
  let includeDeleted = false;
  let q = '';
  const expenseCategories = ['Fournitures & Emballages', 'Transport & Livraison', 'Loyer & Charges', 'Électricité & Internet', 'Salaires & Primes', 'Achat marchandise', 'Entretien local', 'Autre'];

  const openExpenseModal = (exp = null) => {
    const isEdit = Boolean(exp);
    openModal({
      title: isEdit ? 'Modifier la dépense' : 'Nouvelle dépense',
      bodyHtml: `
        <form id="f-exp" class="form-grid">
          <div class="form-field"><label>Catégorie *</label>
            <select name="category" class="select">
              ${expenseCategories.map((c) => `<option value="${esc(c)}" ${exp?.category === c ? 'selected' : ''}>${esc(c)}</option>`).join('')}
            </select>
          </div>
          <div class="form-field"><label>Montant (FCFA) *</label><input name="amount" type="number" min="1" class="input num" required value="${exp?.amount ?? ''}"></div>
          <div class="form-field"><label>Mode de paiement</label>
            <select name="payment_method" class="select">
              ${Store.paymentMethods.map((m) => `<option value="${esc(m.label)}" ${exp?.payment_method === m.label ? 'selected' : ''}>${esc(m.label)}</option>`).join('')}
            </select>
          </div>
          <div class="form-field"><label>Date</label><input name="expense_date" type="date" class="input" required value="${esc(exp?.expense_date || new Date().toISOString().slice(0, 10))}"></div>
          <div class="form-field full"><label>Description *</label><input name="description" class="input" required value="${esc(exp?.description || '')}" placeholder="Ex : Facture électricité, carburant livraison…"></div>
        </form>`,
      footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-exp" class="btn btn-primary">Enregistrer</button>`,
      onMount: (dlg, close) => {
        dlg.querySelector('#f-exp').addEventListener('submit', async (e) => {
          e.preventDefault();
          const body = Object.fromEntries(new FormData(e.target));
          if (isEdit) body.id = exp.id;
          await api('expenses', { method: isEdit ? 'PUT' : 'POST', body });
          toast(isEdit ? 'Dépense modifiée.' : 'Dépense enregistrée.', 'success');
          close();
          await onRefreshShell();
          await loadExpenses();
        });
      },
    });
  };

  const loadExpenses = async () => {
    const res = await api(`expenses?include_deleted=${includeDeleted ? 1 : 0}${q ? `&q=${encodeURIComponent(q)}` : ''}`);
    const list = res.expenses || [];
    const total = list.filter((x) => !x.deleted_at).reduce((s, x) => s + Number(x.amount), 0);
    const tbody = container.querySelector('#exp-tbody');
    const sumEl = container.querySelector('#exp-total-badge');
    if (sumEl) sumEl.textContent = `Total actif : ${fmtMoney(total)}`;
    if (!tbody) return;

    tbody.innerHTML = list.length ? list.map((ex) => {
      const del = Boolean(ex.deleted_at);
      return `
        <tr style="${del ? 'opacity:.6;background:#fef2f2' : ''}">
          <td class="num">${fmtShortDate(ex.expense_date)}</td>
          <td><span class="badge badge-amber">${esc(ex.category)}</span></td>
          <td>${esc(ex.description)} ${Number(ex.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}
            ${del ? `<br><small class="text-red">Supprimée : ${esc(ex.delete_reason)}</small>` : ''}
          </td>
          <td>${esc(ex.payment_method)}</td>
          <td class="text-right num fw-bold text-red">−${fmtMoney(ex.amount)}</td>
          <td class="td-actions">
            ${!del ? `
              <button type="button" class="btn btn-outline btn-sm" data-edit="${ex.id}">${icon('edit', 14)}</button>
              <button type="button" class="btn btn-ghost btn-sm text-red" data-del="${ex.id}">${icon('trash', 14)}</button>
            ` : '<span class="badge badge-red">Archivée</span>'}
          </td>
        </tr>
      `;
    }).join('') : `<tr><td colspan="6" class="empty-state">Aucune dépense enregistrée.</td></tr>`;

    tbody.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
      const ex = list.find((x) => Number(x.id) === Number(b.dataset.edit));
      if (ex) openExpenseModal(ex);
    }));
    tbody.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', () => {
      const ex = list.find((x) => Number(x.id) === Number(b.dataset.del));
      if (!ex) return;
      openModal({
        title: 'Supprimer la dépense (avec traçabilité)',
        bodyHtml: `
          <p style="margin-bottom:.75rem">Montant : <strong>${fmtMoney(ex.amount)}</strong> — ${esc(ex.description)}</p>
          <form id="f-del-exp">
            <div class="form-field"><label>Motif de suppression *</label><input name="reason" class="input" required placeholder="Ex : Saisie en double, erreur de montant…"></div>
          </form>`,
        footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-del-exp" class="btn btn-danger">Confirmer la suppression</button>`,
        onMount: (dlg, close) => {
          dlg.querySelector('#f-del-exp').addEventListener('submit', async (e) => {
            e.preventDefault();
            const reason = new FormData(e.target).get('reason');
            await api('expenses', { method: 'DELETE', body: { id: ex.id, reason } });
            toast('Dépense supprimée et tracée dans le journal.', 'success');
            close();
            await onRefreshShell();
            await loadExpenses();
          });
        },
      });
    }));
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="toolbar-group">
        <div class="search-box">${icon('search', 16)}<input id="exp-q" class="input" placeholder="Rechercher une dépense…"></div>
        <label style="display:inline-flex;align-items:center;gap:.4rem;font-size:.82rem;cursor:pointer">
          <input type="checkbox" id="exp-show-del"> Voir supprimées
        </label>
        <span class="badge badge-red" id="exp-total-badge">Total actif : 0 FCFA</span>
      </div>
      <button type="button" class="btn btn-primary" id="btn-add-exp">${icon('plus', 16)} Ajouter une dépense</button>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Date</th><th>Catégorie</th><th>Description</th><th>Paiement</th><th class="text-right">Montant</th><th class="text-right">Actions</th></tr></thead>
          <tbody id="exp-tbody"></tbody>
        </table>
      </div>
    </div>
  `;

  container.querySelector('#exp-q').addEventListener('input', (e) => { q = e.target.value; loadExpenses(); });
  container.querySelector('#exp-show-del').addEventListener('change', (e) => { includeDeleted = e.target.checked; loadExpenses(); });
  container.querySelector('#btn-add-exp').addEventListener('click', () => openExpenseModal(null));
  await loadExpenses();
}

async function renderTransactions(container, onRefreshShell) {
  let scope = 'all';
  let status = '';
  let q = '';
  let from = '';
  let to = '';

  const loadSales = async () => {
    const p = new URLSearchParams({ scope });
    if (status) p.set('status', status);
    if (q) p.set('q', q);
    if (from) p.set('from', from);
    if (to) p.set('to', to);
    const res = await api(`sales?${p.toString()}`);
    const sales = res.sales || [];
    const tbody = container.querySelector('#sales-tbody');
    if (!tbody) return;

    tbody.innerHTML = sales.length ? sales.map((s) => `
      <tr>
        <td><strong class="num">${esc(s.receipt_number)}</strong> ${Number(s.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}<br><small class="text-muted">${fmtDate(s.created_at)}</small></td>
        <td>${esc(s.customer_name || 'Client comptoir')}</td>
        <td><small class="text-muted">${esc(s.items_summary || `${s.items_count} art.`)}</small></td>
        <td><span class="badge badge-slate">${esc(s.payment_method)}</span></td>
        <td>${s.status === 'COMPLETED' ? '<span class="badge badge-green">Validée</span>' : `<span class="badge badge-red" title="${esc(s.cancel_reason || '')}">Annulée</span>`}</td>
        <td class="text-right num fw-bold">${fmtMoney(s.total_amount)}</td>
        <td class="td-actions">
          <button type="button" class="btn btn-outline btn-sm" data-rec="${s.id}">${icon('receipt', 14)} Reçu</button>
          ${s.status === 'COMPLETED' ? `<button type="button" class="btn btn-ghost btn-sm text-red" data-cancel="${s.id}">Annuler</button>` : ''}
        </td>
      </tr>
    `).join('') : `<tr><td colspan="7" class="empty-state">Aucune vente trouvée pour ces critères.</td></tr>`;

    tbody.querySelectorAll('[data-rec]').forEach((b) => b.addEventListener('click', async () => {
      const det = await api(`sales/detail?id=${b.dataset.rec}`);
      if (det.receipt) showReceiptModal(det.receipt, det.sale.id);
    }));

    tbody.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => {
      const s = sales.find((x) => Number(x.id) === Number(b.dataset.cancel));
      if (!s) return;
      openModal({
        title: `Annuler la vente ${s.receipt_number}`,
        bodyHtml: `
          <p style="margin-bottom:.8rem">Montant : <strong>${fmtMoney(s.total_amount)}</strong>. L’annulation remettra automatiquement les articles en stock et sera tracée dans l’historique.</p>
          <form id="f-cancel-sale">
            <div class="form-field"><label>Motif de l’annulation *</label><textarea name="reason" class="textarea" rows="2" required placeholder="Ex : Erreur de saisie, retour client…"></textarea></div>
          </form>`,
        footerHtml: `<button type="button" class="btn btn-outline" data-close>Fermer</button><button type="submit" form="f-cancel-sale" class="btn btn-danger">Confirmer l’annulation</button>`,
        onMount: (dlg, close) => {
          dlg.querySelector('#f-cancel-sale').addEventListener('submit', async (e) => {
            e.preventDefault();
            const reason = new FormData(e.target).get('reason');
            await api('sales/cancel', { method: 'POST', body: { sale_id: s.id, reason, actor: 'Patron' } });
            toast('Vente annulée et stock restauré.', 'success');
            close();
            await onRefreshShell();
            await loadSales();
          });
        },
      });
    }));
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="toolbar-group">
        <div class="tabs">
          <button type="button" class="tab-btn active" data-sc="all">Toutes</button>
          <button type="button" class="tab-btn" data-sc="today">Aujourd’hui</button>
          <button type="button" class="tab-btn" data-sc="archived">Archivées</button>
        </div>
        <div class="search-box">${icon('search', 16)}<input id="tx-q" class="input" placeholder="Reçu, client, produit…"></div>
        <select id="tx-status" class="select" style="width:auto">
          <option value="">Tous statuts</option>
          <option value="COMPLETED">Validées</option>
          <option value="CANCELLED">Annulées</option>
        </select>
        <input id="tx-from" type="date" class="input" style="width:auto" title="Du">
        <input id="tx-to" type="date" class="input" style="width:auto" title="Au">
      </div>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>N° Reçu & Date</th><th>Client</th><th>Produits vendus</th><th>Paiement</th><th>Statut</th><th class="text-right">Montant</th><th class="text-right">Actions</th></tr></thead>
          <tbody id="sales-tbody"></tbody>
        </table>
      </div>
    </div>
  `;

  container.querySelectorAll('[data-sc]').forEach((b) => b.addEventListener('click', () => {
    scope = b.dataset.sc;
    container.querySelectorAll('[data-sc]').forEach((x) => x.classList.toggle('active', x.dataset.sc === scope));
    loadSales();
  }));
  container.querySelector('#tx-q').addEventListener('input', (e) => { q = e.target.value; loadSales(); });
  container.querySelector('#tx-status').addEventListener('change', (e) => { status = e.target.value; loadSales(); });
  container.querySelector('#tx-from').addEventListener('change', (e) => { from = e.target.value; loadSales(); });
  container.querySelector('#tx-to').addEventListener('change', (e) => { to = e.target.value; loadSales(); });
  await loadSales();
}

async function renderReports(container) {
  let period = 'month';
  let from = '';
  let to = '';
  let latestReport = null;

  const loadReports = async () => {
    const p = new URLSearchParams({ period });
    if (period === 'custom') {
      if (from) p.set('from', from);
      if (to) p.set('to', to);
    }
    latestReport = await api(`reports?${p.toString()}`);
    const a = latestReport.analytics;
    const outEl = container.querySelector('#rep-body');
    if (!outEl) return;

    const profitPos = Number(a.estimated_profit) >= 0;

    outEl.innerHTML = `
      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-top"><span>Ventes (${latestReport.from} → ${latestReport.to})</span></div>
          <div class="kpi-value num">${fmtMoney(a.total_revenue)}</div>
          <div class="kpi-sub">${a.sales_count} vente(s) · Panier moyen : ${fmtMoney(a.avg_basket)}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><span>Marge brute (Ventes − Coût achat)</span></div>
          <div class="kpi-value num text-blue">${fmtMoney(a.gross_profit)}</div>
          <div class="kpi-sub">Coût d’achat : ${fmtMoney(a.total_cogs)}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><span>Dépenses de la période</span></div>
          <div class="kpi-value num text-amber">${fmtMoney(a.total_expenses)}</div>
          <div class="kpi-sub">${latestReport.expenses.length} dépense(s)</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top"><span>Bénéfice net estimé (C++)</span></div>
          <div class="kpi-value num ${profitPos ? 'text-green' : 'text-red'}">${fmtMoney(a.estimated_profit)}</div>
          <div class="kpi-sub">Taux de marge nette : ${a.profit_margin_percent}%</div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-header"><div class="card-title">${icon('reports', 18)} Revenus vs Dépenses sur la période</div></div>
          <div class="card-body" id="rep-chart"></div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">${icon('cash', 18)} Répartition par mode de paiement</div></div>
          <div class="card-body">
            ${Object.keys(a.by_payment || {}).length ? Object.entries(a.by_payment).map(([pm, val]) => `
              <div class="summary-line" style="padding:.45rem 0;border-bottom:1px solid var(--border)">
                <strong>${esc(pm)}</strong>
                <span class="num fw-bold">${fmtMoney(val)}</span>
              </div>
            `).join('') : '<p class="text-muted">Aucune vente sur cette période.</p>'}
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">${icon('products', 18)} Produits les plus vendus sur la période</div></div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Produit</th><th class="text-right">Quantité vendue</th><th class="text-right">Chiffre d’affaires</th><th class="text-right">Marge brute estimée</th></tr></thead>
            <tbody>
              ${(a.top_products || []).length ? a.top_products.map((tp) => `
                <tr>
                  <td class="fw-bold">${esc(tp.name)}</td>
                  <td class="text-right num">${tp.quantity}</td>
                  <td class="text-right num fw-bold">${fmtMoney(tp.revenue)}</td>
                  <td class="text-right num text-green fw-bold">${fmtMoney(tp.profit)}</td>
                </tr>
              `).join('') : `<tr><td colspan="4" class="empty-state">Aucun article vendu sur cette période.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>
    `;

    renderBarChart(outEl.querySelector('#rep-chart'), a.daily_series || [], { showExpenses: true });
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="toolbar-group">
        <div class="tabs">
          <button type="button" class="tab-btn" data-per="day">Jour</button>
          <button type="button" class="tab-btn" data-per="week">Semaine</button>
          <button type="button" class="tab-btn active" data-per="month">Mois</button>
          <button type="button" class="tab-btn" data-per="custom">Période</button>
        </div>
        <input id="rep-from" type="date" class="input" style="width:auto;display:none">
        <input id="rep-to" type="date" class="input" style="width:auto;display:none">
      </div>
      <div class="toolbar-group">
        <button type="button" class="btn btn-outline" id="btn-exp-excel">${icon('download', 16)} Exporter Excel (.xls)</button>
        <button type="button" class="btn btn-primary" id="btn-exp-pdf">${icon('printer', 16)} Exporter PDF</button>
      </div>
    </div>
    <div id="rep-body"></div>
  `;

  container.querySelectorAll('[data-per]').forEach((b) => b.addEventListener('click', () => {
    period = b.dataset.per;
    container.querySelectorAll('[data-per]').forEach((x) => x.classList.toggle('active', x.dataset.per === period));
    const showDates = period === 'custom';
    container.querySelector('#rep-from').style.display = showDates ? 'block' : 'none';
    container.querySelector('#rep-to').style.display = showDates ? 'block' : 'none';
    loadReports();
  }));
  container.querySelector('#rep-from').addEventListener('change', (e) => { from = e.target.value; loadReports(); });
  container.querySelector('#rep-to').addEventListener('change', (e) => { to = e.target.value; loadReports(); });

  container.querySelector('#btn-exp-excel').addEventListener('click', () => {
    const p = new URLSearchParams({ period, format: 'excel' });
    if (period === 'custom') {
      if (from) p.set('from', from);
      if (to) p.set('to', to);
    }
    window.location.href = `/api/reports/export?${p.toString()}`;
    toast('Téléchargement du rapport Excel lancé.', 'success');
  });

  container.querySelector('#btn-exp-pdf').addEventListener('click', () => {
    if (!latestReport) return;
    const a = latestReport.analytics;
    const area = document.getElementById('print-area');
    document.body.dataset.printMode = 'a4';
    area.innerHTML = `
      <div style="font-family:sans-serif;color:#0f172a">
        <h2>${esc(Store.settings.store_name || 'FINCAISSE')} — Rapport Financier</h2>
        <p style="color:#475569;margin-bottom:14px">Période : du ${latestReport.from} au ${latestReport.to}</p>
        <table style="width:100%;border-collapse:collapse;margin-bottom:16px">
          <tr><th style="text-align:left;border:1px solid #cbd5e1;padding:6px;background:#f1f5f9">Indicateur</th><th style="text-align:right;border:1px solid #cbd5e1;padding:6px;background:#f1f5f9">Valeur</th></tr>
          <tr><td style="border:1px solid #cbd5e1;padding:6px">Chiffre d’affaires (${a.sales_count} ventes)</td><td style="text-align:right;border:1px solid #cbd5e1;padding:6px">${fmtMoney(a.total_revenue)}</td></tr>
          <tr><td style="border:1px solid #cbd5e1;padding:6px">Coût d’achat marchandises</td><td style="text-align:right;border:1px solid #cbd5e1;padding:6px">${fmtMoney(a.total_cogs)}</td></tr>
          <tr><td style="border:1px solid #cbd5e1;padding:6px">Marge brute</td><td style="text-align:right;border:1px solid #cbd5e1;padding:6px">${fmtMoney(a.gross_profit)}</td></tr>
          <tr><td style="border:1px solid #cbd5e1;padding:6px">Total des dépenses</td><td style="text-align:right;border:1px solid #cbd5e1;padding:6px">${fmtMoney(a.total_expenses)}</td></tr>
          <tr><td style="border:1px solid #cbd5e1;padding:6px"><strong>Bénéfice net estimé</strong></td><td style="text-align:right;border:1px solid #cbd5e1;padding:6px"><strong>${fmtMoney(a.estimated_profit)}</strong></td></tr>
        </table>
        <h3>Produits les plus vendus</h3>
        <table style="width:100%;border-collapse:collapse">
          <tr><th style="text-align:left;border:1px solid #cbd5e1;padding:6px;background:#f1f5f9">Produit</th><th style="text-align:right;border:1px solid #cbd5e1;padding:6px;background:#f1f5f9">Qté</th><th style="text-align:right;border:1px solid #cbd5e1;padding:6px;background:#f1f5f9">CA</th></tr>
          ${(a.top_products || []).map((t) => `<tr><td style="border:1px solid #cbd5e1;padding:6px">${esc(t.name)}</td><td style="text-align:right;border:1px solid #cbd5e1;padding:6px">${t.quantity}</td><td style="text-align:right;border:1px solid #cbd5e1;padding:6px">${fmtMoney(t.revenue)}</td></tr>`).join('')}
        </table>
      </div>`;
    window.print();
  });

  await loadReports();
}

async function renderAuditHistory(container) {
  let type = '';
  let q = '';
  const loadLogs = async () => {
    const p = new URLSearchParams();
    if (type) p.set('entity_type', type);
    if (q) p.set('q', q);
    const res = await api(`audit?${p.toString()}`);
    const logs = res.logs || [];
    const tbody = container.querySelector('#audit-tbody');
    if (!tbody) return;
    tbody.innerHTML = logs.length ? logs.map((l) => `
      <tr>
        <td class="num text-muted">${fmtDate(l.created_at)}</td>
        <td><span class="badge badge-blue">${esc(l.entity_type)}</span></td>
        <td class="fw-bold">${esc(l.action)}</td>
        <td>${esc(l.actor)}</td>
        <td>${esc(l.details)} ${Number(l.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}</td>
      </tr>
    `).join('') : `<tr><td colspan="5" class="empty-state">Aucune opération enregistrée dans le journal.</td></tr>`;
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="toolbar-group">
        <div class="search-box">${icon('search', 16)}<input id="log-q" class="input" placeholder="Rechercher dans le journal…"></div>
        <select id="log-type" class="select" style="width:auto">
          <option value="">Toutes opérations</option>
          <option value="SALE">Ventes & Annulations</option>
          <option value="PRODUCT">Produits</option>
          <option value="STOCK">Mouvements de stock</option>
          <option value="CASH">Caisse (Ouverture/Fermeture)</option>
          <option value="EXPENSE">Dépenses</option>
          <option value="CUSTOMER">Clients</option>
          <option value="CATEGORY">Catégories</option>
          <option value="SETTINGS">Paramètres</option>
          <option value="SYSTEM">Système & Sauvegardes</option>
        </select>
      </div>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Date & Heure</th><th>Module</th><th>Action</th><th>Auteur</th><th>Détails</th></tr></thead>
          <tbody id="audit-tbody"></tbody>
        </table>
      </div>
    </div>
  `;
  container.querySelector('#log-q').addEventListener('input', (e) => { q = e.target.value; loadLogs(); });
  container.querySelector('#log-type').addEventListener('change', (e) => { type = e.target.value; loadLogs(); });
  await loadLogs();
}

async function renderSettings(container, onRefreshShell) {
  const [setRes, retRes] = await Promise.all([api('settings'), api('retention/preview')]);
  const s = setRes.settings || {};
  const methods = setRes.payment_methods || [];

  container.innerHTML = `
    <div class="grid-equal-2">
      <div class="card">
        <div class="card-header"><div class="card-title">${icon('settings', 18)} Paramètres du commerce & reçus</div></div>
        <div class="card-body">
          <form id="f-settings" class="form-grid">
            <div class="form-field"><label>Nom du commerce</label><input name="store_name" class="input" value="${esc(s.store_name || '')}"></div>
            <div class="form-field"><label>Devise</label><input name="currency" class="input" value="${esc(s.currency || 'FCFA')}"></div>
            <div class="form-field full"><label>Slogan / Activité</label><input name="store_tagline" class="input" value="${esc(s.store_tagline || '')}"></div>
            <div class="form-field"><label>Téléphone</label><input name="store_phone" class="input" value="${esc(s.store_phone || '')}"></div>
            <div class="form-field"><label>Adresse</label><input name="store_address" class="input" value="${esc(s.store_address || '')}"></div>
            <div class="form-field full"><label>Message de remerciement sur reçu</label><input name="receipt_footer" class="input" value="${esc(s.receipt_footer || '')}"></div>
            <div class="form-field full">
              <label style="display:flex;align-items:center;gap:.55rem;cursor:pointer">
                <input type="checkbox" name="cashier_can_view_history" value="1" ${s.cashier_can_view_history === '1' ? 'checked' : ''}>
                Autoriser le caissier à consulter l’historique des ventes du jour
              </label>
            </div>
            <div class="form-field full"><button type="submit" class="btn btn-primary">Enregistrer les paramètres</button></div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">${icon('cash', 18)} Modes de paiement configurables</div></div>
        <div class="card-body">
          <div style="display:flex;flex-direction:column;gap:.55rem;margin-bottom:1rem">
            ${methods.map((m) => `
              <div style="display:flex;align-items:center;justify-content:space-between;padding:.55rem .75rem;border:1px solid var(--border);border-radius:8px">
                <div>
                  <strong>${esc(m.label)}</strong>
                  ${Number(m.is_cash) === 1 ? '<span class="badge badge-green">Impacte tiroir-caisse</span>' : '<span class="badge badge-blue">Électronique</span>'}
                </div>
                <div class="toolbar-group">
                  <button type="button" class="btn btn-outline btn-sm" data-tog-pm="${m.id}" data-act="${Number(m.active) === 1 ? 0 : 1}">${Number(m.active) === 1 ? 'Actif' : 'Inactif'}</button>
                  ${m.code !== 'CASH' ? `<button type="button" class="btn btn-ghost btn-sm text-red" data-del-pm="${m.id}">${icon('trash', 14)}</button>` : ''}
                </div>
              </div>
            `).join('')}
          </div>
          <form id="f-add-pm" class="form-grid">
            <div class="form-field"><label>Nouveau mode de paiement</label><input name="label" class="input" placeholder="Ex : Wave, Orange Money, Chèque…" required></div>
            <div class="form-field"><label>Nature</label>
              <select name="is_cash" class="select">
                <option value="0">Électronique / Bancaire</option>
                <option value="1">Espèces (Tiroir-caisse)</option>
              </select>
            </div>
            <div class="form-field full"><button type="submit" class="btn btn-outline">Ajouter ce mode de paiement</button></div>
          </form>
        </div>
      </div>
    </div>

    <div class="grid-equal-2">
      <div class="card">
        <div class="card-header"><div class="card-title">${icon('history', 18)} Conservation des données (Règle 30 jours)</div></div>
        <div class="card-body">
          <p class="text-muted" style="margin-bottom:.9rem">
            Les ventes et reçus sont conservés durablement et ne disparaissent jamais automatiquement.
            Après le délai configuré (minimum 30 jours), vous pouvez archiver ou supprimer les anciennes données uniquement sur action manuelle après sauvegarde.
          </p>
          <form id="f-ret" class="form-grid" style="margin-bottom:1rem">
            <div class="form-field"><label>Délai de conservation (jours ≥ 30)</label><input name="retention_days" type="number" min="30" class="input num" value="${retRes.retention_days}"></div>
            <div class="form-field"><label>Action autorisée après délai</label>
              <select name="retention_policy" class="select">
                <option value="KEEP" ${retRes.retention_policy === 'KEEP' ? 'selected' : ''}>Conserver durablement (aucune action)</option>
                <option value="ARCHIVE" ${retRes.retention_policy === 'ARCHIVE' ? 'selected' : ''}>Archiver les ventes & reçus</option>
                <option value="DELETE" ${retRes.retention_policy === 'DELETE' ? 'selected' : ''}>Supprimer définitivement après sauvegarde</option>
              </select>
            </div>
            <div class="form-field full"><button type="submit" class="btn btn-outline">Mettre à jour la règle de conservation</button></div>
          </form>

          <div class="card card-body" style="background:var(--bg-subtle);margin-bottom:.9rem">
            <div class="summary-line"><span>Ventes & reçus de plus de ${retRes.retention_days} jours :</span><strong class="num">${retRes.eligible_sales}</strong></div>
            <div class="summary-line"><span>Ventes déjà archivées :</span><strong class="num">${retRes.already_archived_sales}</strong></div>
            <div class="summary-line"><span>Dernière sauvegarde :</span><span class="num">${retRes.last_backup_at ? fmtDate(retRes.last_backup_at) : 'Aucune'}</span></div>
          </div>

          <div class="toolbar-group">
            <button type="button" class="btn btn-primary" id="btn-dl-backup">${icon('download', 16)} Sauvegarder tout (JSON)</button>
            <button type="button" class="btn btn-navy" id="btn-apply-ret" ${retRes.eligible_sales === 0 || retRes.retention_policy === 'KEEP' ? 'disabled' : ''}>
              ${retRes.retention_policy === 'DELETE' ? 'Purger > ' + retRes.retention_days + ' jours' : 'Archiver > ' + retRes.retention_days + ' jours'}
            </button>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">${icon('sparkles', 18)} Mode Démonstration</div></div>
        <div class="card-body">
          <p class="text-muted" style="margin-bottom:1rem">
            Utilisez le mode démonstration pour explorer FINCAISSE avec des produits, clients, ventes et dépenses d’exemple clairement identifiés par un badge <strong>DÉMO</strong>. Vous pouvez supprimer ces données à tout moment sans affecter vos vrais enregistrements.
          </p>
          ${Store.demo.active ? `
            <div class="badge badge-demo" style="margin-bottom:1rem;padding:.4rem .75rem">Mode démonstration actif (${Store.demo.products} produits, ${Store.demo.sales} ventes)</div><br>
            <button type="button" class="btn btn-danger" id="btn-clear-demo">${icon('trash', 16)} Supprimer toutes les données de démonstration</button>
          ` : `
            <button type="button" class="btn btn-primary" id="btn-seed-demo">${icon('sparkles', 16)} Charger les données de démonstration</button>
          `}
        </div>
      </div>
    </div>
  `;

  container.querySelector('#f-settings').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = Object.fromEntries(fd);
    body.cashier_can_view_history = fd.get('cashier_can_view_history') ? '1' : '0';
    await api('settings', { method: 'POST', body });
    toast('Paramètres enregistrés.', 'success');
    await onRefreshShell();
  });

  container.querySelector('#f-add-pm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = Object.fromEntries(new FormData(e.target));
    await api('payment-methods', { method: 'POST', body });
    toast('Mode de paiement ajouté.', 'success');
    await onRefreshShell();
    await renderSettings(container, onRefreshShell);
  });

  container.querySelectorAll('[data-tog-pm]').forEach((b) => b.addEventListener('click', async () => {
    await api('payment-methods', { method: 'PUT', body: { id: Number(b.dataset.togPm), active: Number(b.dataset.act) } });
    await onRefreshShell();
    await renderSettings(container, onRefreshShell);
  }));

  container.querySelectorAll('[data-del-pm]').forEach((b) => b.addEventListener('click', async () => {
    await api(`payment-methods?id=${b.dataset.delPm}`, { method: 'DELETE' });
    await onRefreshShell();
    await renderSettings(container, onRefreshShell);
  }));

  container.querySelector('#f-ret').addEventListener('submit', async (e) => {
    e.preventDefault();
    await api('settings', { method: 'POST', body: Object.fromEntries(new FormData(e.target)) });
    toast('Règle de conservation mise à jour.', 'success');
    await renderSettings(container, onRefreshShell);
  });

  container.querySelector('#btn-dl-backup').addEventListener('click', () => {
    window.location.href = '/api/retention/backup';
    toast('Sauvegarde JSON téléchargée.', 'success');
    setTimeout(() => renderSettings(container, onRefreshShell), 900);
  });

  container.querySelector('#btn-apply-ret')?.addEventListener('click', async () => {
    const act = retRes.retention_policy;
    const msg = act === 'DELETE'
      ? `Supprimer définitivement ${retRes.eligible_sales} vente(s) et reçus de plus de ${retRes.retention_days} jours ? Avez-vous téléchargé une sauvegarde ?`
      : `Archiver ${retRes.eligible_sales} vente(s) et reçus de plus de ${retRes.retention_days} jours ?`;
    if (!confirm(msg)) return;
    const res = await api('retention/apply', { method: 'POST', body: { action: act } });
    toast(res.message, 'success');
    await renderSettings(container, onRefreshShell);
  });

  container.querySelector('#btn-seed-demo')?.addEventListener('click', async () => {
    await api('demo/seed', { method: 'POST' });
    toast('Mode démonstration activé.', 'success');
    await onRefreshShell();
    await renderSettings(container, onRefreshShell);
  });

  container.querySelector('#btn-clear-demo')?.addEventListener('click', async () => {
    if (!confirm('Supprimer toutes les données de démonstration ? Vos propres données seront conservées.')) return;
    await api('demo/clear', { method: 'POST' });
    toast('Données de démonstration supprimées.', 'success');
    await onRefreshShell();
    await renderSettings(container, onRefreshShell);
  });
}

async function renderUsers(container) {
  const res = await api('users');
  const users = res.users || [];

  const roleBadge = (role) => {
    if (role === 'ADMIN') return '<span class="badge badge-blue">Patron (ADMIN)</span>';
    if (role === 'ACCUEIL') return '<span class="badge badge-amber">Accueil (ACCUEIL)</span>';
    return '<span class="badge badge-green">Caissier (CAISSE)</span>';
  };

  const openUserModal = (u = null) => {
    const isEdit = Boolean(u);
    openModal({
      title: isEdit ? `Modifier le compte : ${u.username}` : 'Créer un compte utilisateur',
      bodyHtml: `
        <form id="f-user" class="form-grid">
          <div class="form-field"><label>Nom complet</label><input name="full_name" class="input" value="${esc(u?.full_name || '')}" placeholder="Ex : Kouadio Marie"></div>
          <div class="form-field"><label>Nom d’utilisateur *</label><input name="username" class="input" ${isEdit ? 'disabled' : 'required'} value="${esc(u?.username || '')}" placeholder="Ex : caisse1"></div>
          <div class="form-field"><label>Rôle *</label>
            <select name="role" class="select">
              <option value="CAISSE" ${u?.role === 'CAISSE' ? 'selected' : ''}>Caissier (CAISSE)</option>
              <option value="ACCUEIL" ${u?.role === 'ACCUEIL' ? 'selected' : ''}>Accueil (ACCUEIL)</option>
              <option value="ADMIN" ${u?.role === 'ADMIN' ? 'selected' : ''}>Patron (ADMIN)</option>
            </select>
          </div>
          <div class="form-field"><label>${isEdit ? 'Nouveau mot de passe (optionnel)' : 'Mot de passe (8 car. min) *'}</label>
            <input name="password" type="password" class="input" ${isEdit ? '' : 'required minlength="8"'} placeholder="••••••••">
          </div>
        </form>`,
      footerHtml: `<button type="button" class="btn btn-outline" data-close>Annuler</button><button type="submit" form="f-user" class="btn btn-primary">${isEdit ? 'Enregistrer' : 'Créer le compte'}</button>`,
      onMount: (dlg, close) => {
        dlg.querySelector('#f-user').addEventListener('submit', async (e) => {
          e.preventDefault();
          const body = Object.fromEntries(new FormData(e.target));
          if (isEdit) body.id = u.id;
          await api('users', { method: isEdit ? 'PUT' : 'POST', body });
          toast(isEdit ? 'Compte mis à jour.' : 'Compte utilisateur créé.', 'success');
          close();
          await renderUsers(container);
        });
      },
    });
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="text-muted">${users.length} compte(s) enregistré(s)</div>
      <button type="button" class="btn btn-primary" id="btn-add-user">${icon('plus', 16)} Nouveau compte (Accueil / Caissier / Patron)</button>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Identifiant</th><th>Nom complet</th><th>Rôle</th><th>État</th><th>Créé le</th><th class="text-right">Actions</th></tr></thead>
          <tbody>
            ${users.map((u) => `
              <tr>
                <td class="fw-bold">${esc(u.username)} ${Number(u.id) === Number(Store.user?.id) ? '<span class="badge badge-slate">Vous</span>' : ''}</td>
                <td>${esc(u.full_name || '—')}</td>
                <td>${roleBadge(u.role)}</td>
                <td>${Number(u.active) === 1 ? '<span class="badge badge-green">Actif</span>' : '<span class="badge badge-red">Désactivé</span>'}</td>
                <td class="num text-muted">${fmtDate(u.created_at)}</td>
                <td class="td-actions">
                  <button type="button" class="btn btn-outline btn-sm" data-edit-u="${u.id}">${icon('edit', 14)} Modifier</button>
                  ${Number(u.id) !== Number(Store.user?.id) ? `
                    <button type="button" class="btn btn-ghost btn-sm ${Number(u.active) === 1 ? 'text-red' : 'text-green'}" data-tog-u="${u.id}">
                      ${Number(u.active) === 1 ? 'Désactiver' : 'Réactiver'}
                    </button>
                  ` : ''}
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
    </div>
  `;

  container.querySelector('#btn-add-user').addEventListener('click', () => openUserModal(null));
  container.querySelectorAll('[data-edit-u]').forEach((b) => b.addEventListener('click', () => {
    const u = users.find((x) => Number(x.id) === Number(b.dataset.editU));
    if (u) openUserModal(u);
  }));
  container.querySelectorAll('[data-tog-u]').forEach((b) => b.addEventListener('click', async () => {
    await api('users/toggle', { method: 'POST', body: { id: Number(b.dataset.togU) } });
    toast('Statut du compte mis à jour.', 'success');
    await renderUsers(container);
  }));
}
