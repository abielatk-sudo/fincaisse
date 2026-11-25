import { api, esc, fmtDate, fmtMoney, icon, openModal, Store, toast } from './api.js';

export async function renderAccueilView(tab, container, onRefreshShell) {
  if (tab === 'accueil-list') {
    return renderSentOrders(container, onRefreshShell);
  }
  return renderOrderBuilder(container, onRefreshShell);
}

async function renderOrderBuilder(container, onRefreshShell) {
  let products = [];
  let categories = [];
  let customers = [];
  let searchQ = '';
  let activeCat = 0;
  let selectedCustId = '';
  let customCustName = '';
  let orderNotes = '';

  const loadData = async () => {
    const [pRes, cRes, cuRes] = await Promise.all([
      api('products'),
      api('categories'),
      api('customers'),
    ]);
    products = pRes.products || [];
    categories = cRes.categories || [];
    customers = cuRes.customers || [];
  };
  await loadData();

  const updateOrderBadge = () => {
    const count = Store.orderCart.reduce((s, it) => s + it.quantity, 0);
    document.querySelectorAll('[data-order-badge]').forEach((el) => {
      el.textContent = String(count);
      el.style.display = count > 0 ? 'inline-flex' : 'none';
    });
  };

  const addItem = (prod) => {
    if (Number(prod.stock) <= 0) {
      toast(`« ${prod.name} » est en rupture de stock.`, 'warning');
      return;
    }
    const existing = Store.orderCart.find((c) => c.product_id === Number(prod.id));
    if (existing) {
      if (existing.quantity >= Number(prod.stock)) {
        toast(`Stock maximum atteint (${prod.stock}) pour ${prod.name}.`, 'warning');
        return;
      }
      existing.quantity += 1;
    } else {
      Store.orderCart.push({
        product_id: Number(prod.id),
        name: prod.name,
        unit_price: Number(prod.sell_price),
        max_stock: Number(prod.stock),
        quantity: 1,
      });
    }
    updateOrderBadge();
    renderOrderCart();
  };

  const changeQty = (pid, delta) => {
    const idx = Store.orderCart.findIndex((c) => c.product_id === pid);
    if (idx === -1) return;
    const item = Store.orderCart[idx];
    const next = item.quantity + delta;
    if (next <= 0) {
      Store.orderCart.splice(idx, 1);
    } else if (next > item.max_stock) {
      toast(`Stock limité à ${item.max_stock}.`, 'warning');
      item.quantity = item.max_stock;
    } else {
      item.quantity = next;
    }
    updateOrderBadge();
    renderOrderCart();
  };

  const renderGrid = () => {
    const grid = container.querySelector('#acc-prod-grid');
    if (!grid) return;
    const q = searchQ.trim().toLowerCase();
    const filtered = products.filter((p) => {
      if (activeCat > 0 && Number(p.category_id) !== activeCat) return false;
      if (q && !(`${p.name} ${p.sku || ''} ${p.category_name || ''}`.toLowerCase().includes(q))) return false;
      return true;
    });

    if (!filtered.length) {
      grid.innerHTML = `<div class="card card-body empty-state" style="grid-column:1/-1"><p>Aucun produit trouvé.</p></div>`;
      return;
    }

    grid.innerHTML = filtered.map((p) => {
      const stock = Number(p.stock);
      const out = stock <= 0;
      const low = stock > 0 && stock <= Number(p.alert_threshold);
      return `
        <button type="button" class="prod-card" data-acc-pid="${p.id}" ${out ? 'disabled' : ''}>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:.35rem">
            <span class="badge ${p.category_name ? 'badge-blue' : 'badge-slate'}" style="font-size:.68rem">${esc(p.category_name || 'Général')}</span>
            ${Number(p.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}
          </div>
          <div class="prod-card-name">${esc(p.name)}</div>
          <div class="prod-card-price num">${fmtMoney(p.sell_price)}</div>
          <div class="prod-card-meta">
            <span>${p.sku ? esc(p.sku) : '—'}</span>
            <span class="${out ? 'text-red fw-bold' : low ? 'text-amber fw-bold' : 'text-green'}">
              ${out ? 'Rupture' : `Stock : ${stock}`}
            </span>
          </div>
        </button>
      `;
    }).join('');

    grid.querySelectorAll('[data-acc-pid]').forEach((b) => b.addEventListener('click', () => {
      const prod = products.find((x) => Number(x.id) === Number(b.dataset.accPid));
      if (prod) addItem(prod);
    }));
  };

  const renderOrderCart = () => {
    const panel = container.querySelector('#acc-cart-panel');
    if (!panel) return;
    const total = Store.orderCart.reduce((s, it) => s + it.unit_price * it.quantity, 0);

    panel.innerHTML = `
      <div class="cart-header">
        <div>
          <h3 style="font-size:1rem;color:var(--navy-900)">Commande client</h3>
          <small class="text-muted">${Store.orderCart.reduce((a, b) => a + b.quantity, 0)} article(s) — à envoyer à la caisse</small>
        </div>
        ${Store.orderCart.length ? `<button type="button" class="btn btn-ghost btn-sm text-red" id="acc-clear">Vider</button>` : ''}
      </div>

      <div class="cart-items">
        ${Store.orderCart.length ? Store.orderCart.map((it) => `
          <div class="cart-row">
            <div>
              <div class="fw-bold" style="font-size:.88rem">${esc(it.name)}</div>
              <small class="text-muted num">${fmtMoney(it.unit_price)} / u.</small>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.25rem">
              <div class="cart-row-controls">
                <button type="button" class="qty-btn" data-acc-dec="${it.product_id}">−</button>
                <span class="num fw-bold" style="min-width:22px;text-align:center">${it.quantity}</span>
                <button type="button" class="qty-btn" data-acc-inc="${it.product_id}">+</button>
                <button type="button" class="btn btn-ghost btn-sm text-red" style="padding:2px 5px" data-acc-del="${it.product_id}">✕</button>
              </div>
              <span class="num fw-bold" style="font-size:.86rem">${fmtMoney(it.unit_price * it.quantity)}</span>
            </div>
          </div>
        `).join('') : `
          <div class="empty-state" style="padding:1.8rem .5rem">
            ${icon('pos', 30)}
            <p>Sélectionnez les produits demandés par le client puis envoyez la commande à la caissière.</p>
          </div>
        `}
      </div>

      <div class="cart-footer">
        <div class="form-field">
          <label for="acc-cust">Client (optionnel)</label>
          <select id="acc-cust" class="select">
            <option value="">— Client comptoir / saisie libre —</option>
            ${customers.map((c) => `<option value="${c.id}" ${String(c.id) === String(selectedCustId) ? 'selected' : ''}>${esc(c.name)}${c.phone ? ` (${esc(c.phone)})` : ''}</option>`).join('')}
          </select>
        </div>
        ${!selectedCustId ? `<div class="form-field"><input id="acc-cust-free" class="input" placeholder="Nom du client…" value="${esc(customCustName)}"></div>` : ''}
        <div class="form-field">
          <label for="acc-notes">Note pour la caissière (optionnel)</label>
          <input id="acc-notes" class="input" placeholder="Ex : Client prioritaire, emballage cadeau…" value="${esc(orderNotes)}">
        </div>

        <div class="summary-total"><span>Total commande</span><span class="num">${fmtMoney(total)}</span></div>

        <button type="button" id="btn-send-to-cashier" class="btn btn-primary btn-lg btn-block" ${Store.orderCart.length ? '' : 'disabled'}>
          ${icon('receipt', 18)} Envoyer à la caisse
        </button>
      </div>
    `;

    panel.querySelector('#acc-clear')?.addEventListener('click', () => {
      Store.orderCart = [];
      updateOrderBadge();
      renderOrderCart();
    });
    panel.querySelectorAll('[data-acc-inc]').forEach((b) => b.addEventListener('click', () => changeQty(Number(b.dataset.accInc), 1)));
    panel.querySelectorAll('[data-acc-dec]').forEach((b) => b.addEventListener('click', () => changeQty(Number(b.dataset.accDec), -1)));
    panel.querySelectorAll('[data-acc-del]').forEach((b) => b.addEventListener('click', () => {
      Store.orderCart = Store.orderCart.filter((c) => c.product_id !== Number(b.dataset.accDel));
      updateOrderBadge();
      renderOrderCart();
    }));
    panel.querySelector('#acc-cust')?.addEventListener('change', (e) => { selectedCustId = e.target.value; renderOrderCart(); });
    panel.querySelector('#acc-cust-free')?.addEventListener('input', (e) => { customCustName = e.target.value; });
    panel.querySelector('#acc-notes')?.addEventListener('input', (e) => { orderNotes = e.target.value; });

    panel.querySelector('#btn-send-to-cashier')?.addEventListener('click', async () => {
      const btn = panel.querySelector('#btn-send-to-cashier');
      if (btn) btn.disabled = true;
      try {
        const res = await api('orders', {
          method: 'POST',
          body: {
            items: Store.orderCart.map((c) => ({ product_id: c.product_id, quantity: c.quantity })),
            customer_id: selectedCustId ? Number(selectedCustId) : null,
            customer_name: selectedCustId ? '' : customCustName.trim(),
            notes: orderNotes.trim(),
            created_by: Store.accueilName,
          },
        });
        Store.orderCart = [];
        selectedCustId = '';
        customCustName = '';
        orderNotes = '';
        updateOrderBadge();
        toast(`Commande ${res.order_number} envoyée à la caissière !`, 'success');
        await onRefreshShell();
        renderOrderCart();
      } catch (err) {
        toast(err.message, 'error');
        if (btn) btn.disabled = false;
      }
    });
  };

  container.innerHTML = `
    <div class="pos-layout">
      <div>
        <div class="toolbar">
          <div class="search-box" style="flex:1;min-width:240px">
            ${icon('search', 16)}
            <input id="acc-search" class="input" placeholder="Rechercher un produit par nom, code SKU…">
          </div>
          <button type="button" class="btn btn-outline" id="acc-refresh">Actualiser</button>
        </div>
        <div class="cat-pills">
          <button type="button" class="cat-pill ${activeCat === 0 ? 'active' : ''}" data-acc-cat="0">Tous (${products.length})</button>
          ${categories.map((c) => `
            <button type="button" class="cat-pill ${activeCat === Number(c.id) ? 'active' : ''}" data-acc-cat="${c.id}">
              <span style="width:8px;height:8px;border-radius:999px;background:${esc(c.color || '#2563EB')};display:inline-block"></span>
              ${esc(c.name)}
            </button>
          `).join('')}
        </div>
        <div class="prod-grid" id="acc-prod-grid"></div>
      </div>
      <div id="acc-cart-panel" class="cart-panel"></div>
    </div>
  `;

  container.querySelector('#acc-search')?.addEventListener('input', (e) => { searchQ = e.target.value; renderGrid(); });
  container.querySelector('#acc-refresh')?.addEventListener('click', async () => { await loadData(); renderGrid(); toast('Catalogue actualisé'); });
  container.querySelectorAll('[data-acc-cat]').forEach((b) => b.addEventListener('click', () => {
    activeCat = Number(b.dataset.accCat);
    container.querySelectorAll('[data-acc-cat]').forEach((x) => x.classList.toggle('active', Number(x.dataset.accCat) === activeCat));
    renderGrid();
  }));

  renderGrid();
  renderOrderCart();
  updateOrderBadge();
}

async function renderSentOrders(container, onRefreshShell) {
  let statusFilter = '';
  const loadOrders = async () => {
    const res = await api(`orders${statusFilter ? `?status=${statusFilter}` : ''}`);
    const orders = res.orders || [];
    const tbody = container.querySelector('#acc-orders-body');
    if (!tbody) return;

    tbody.innerHTML = orders.length ? orders.map((o) => `
      <tr>
        <td><strong class="num">${esc(o.order_number)}</strong> ${Number(o.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}<br><small class="text-muted">${fmtDate(o.created_at)}</small></td>
        <td>${esc(o.customer_name || 'Client comptoir')}</td>
        <td><small class="text-muted">${esc(o.items_summary || `${o.items_count} art.`)}</small></td>
        <td>
          ${o.status === 'PENDING' ? '<span class="badge badge-amber">En attente caisse</span>'
            : o.status === 'PAID' ? '<span class="badge badge-green">Encaissée</span>'
            : '<span class="badge badge-red">Annulée</span>'}
        </td>
        <td class="text-right num fw-bold">${fmtMoney(o.total_amount)}</td>
        <td class="td-actions">
          ${o.status === 'PENDING' ? `<button type="button" class="btn btn-ghost btn-sm text-red" data-cancel-ord="${o.id}">Annuler</button>` : ''}
        </td>
      </tr>
    `).join('') : `<tr><td colspan="6" class="empty-state">Aucune commande envoyée.</td></tr>`;

    tbody.querySelectorAll('[data-cancel-ord]').forEach((b) => b.addEventListener('click', () => {
      const o = orders.find((x) => Number(x.id) === Number(b.dataset.cancelOrd));
      if (!o) return;
      openModal({
        title: `Annuler la commande ${o.order_number}`,
        bodyHtml: `<form id="f-acc-cancel"><div class="form-field"><label>Motif d’annulation *</label><input name="reason" class="input" required placeholder="Ex : Client parti, modification…"></div></form>`,
        footerHtml: `<button type="button" class="btn btn-outline" data-close>Fermer</button><button type="submit" form="f-acc-cancel" class="btn btn-danger">Annuler la commande</button>`,
        onMount: (dlg, close) => {
          dlg.querySelector('#f-acc-cancel').addEventListener('submit', async (e) => {
            e.preventDefault();
            const reason = new FormData(e.target).get('reason');
            await api('orders/cancel', { method: 'POST', body: { order_id: o.id, reason, actor: Store.accueilName } });
            toast('Commande annulée.', 'success');
            close();
            await onRefreshShell();
            await loadOrders();
          });
        },
      });
    }));
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="tabs">
        <button type="button" class="tab-btn active" data-ost="">Toutes</button>
        <button type="button" class="tab-btn" data-ost="PENDING">En attente caisse</button>
        <button type="button" class="tab-btn" data-ost="PAID">Encaissées</button>
        <button type="button" class="tab-btn" data-ost="CANCELLED">Annulées</button>
      </div>
      <button type="button" class="btn btn-outline" id="acc-orders-refresh">Actualiser</button>
    </div>
    <div class="card">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>N° Commande</th><th>Client</th><th>Articles</th><th>Statut</th><th class="text-right">Total</th><th class="text-right">Action</th></tr></thead>
          <tbody id="acc-orders-body"></tbody>
        </table>
      </div>
    </div>
  `;
  container.querySelectorAll('[data-ost]').forEach((b) => b.addEventListener('click', () => {
    statusFilter = b.dataset.ost;
    container.querySelectorAll('[data-ost]').forEach((x) => x.classList.toggle('active', x.dataset.ost === statusFilter));
    loadOrders();
  }));
  container.querySelector('#acc-orders-refresh').addEventListener('click', loadOrders);
  await loadOrders();
}
