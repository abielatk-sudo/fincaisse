import { api, esc, fmtDate, fmtMoney, icon, openModal, Store, toast } from './api.js';
import { renderPosWorkspace, showReceiptModal } from './pos.js';

let pendingPollTimer = null;

export function stopCaissierPolling() {
  if (pendingPollTimer) {
    clearInterval(pendingPollTimer);
    pendingPollTimer = null;
  }
}

export async function renderCaissierView(tab, container, onRefreshShell) {
  stopCaissierPolling();

  if (tab === 'pos-pending') {
    return renderPendingOrdersCashier(container, onRefreshShell);
  }

  if (tab === 'pos-sale') {
    await renderPosWorkspace(container, {
      actor: Store.cashierName,
      allowDiscount: false,
      viewMode: 'split',
      onAfterSale: onRefreshShell,
    });
    return;
  }

  if (tab === 'pos-cart') {
    await renderPosWorkspace(container, {
      actor: Store.cashierName,
      allowDiscount: false,
      viewMode: 'cart-only',
      onAfterSale: onRefreshShell,
    });
    return;
  }

  if (tab === 'pos-history') {
    if (Store.settings.cashier_can_view_history === '0') {
      container.innerHTML = `
        <div class="card card-body empty-state">
          ${icon('alert', 32)}
          <h3>Accès restreint par le patron</h3>
          <p>La consultation de l’historique des ventes n’est pas autorisée pour l’espace caissier dans les paramètres actuels.</p>
        </div>`;
      return;
    }

    const res = await api('sales?scope=today');
    const sales = res.sales || [];
    container.innerHTML = `
      <div class="card">
        <div class="card-header">
          <div class="card-title">${icon('history', 18)} Historique des ventes du jour</div>
          <span class="badge badge-blue">${sales.length} vente(s)</span>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>N° Reçu</th>
                <th>Heure</th>
                <th>Client</th>
                <th>Articles</th>
                <th>Paiement</th>
                <th>Statut</th>
                <th class="text-right">Total</th>
                <th class="text-right">Reçu</th>
              </tr>
            </thead>
            <tbody>
              ${sales.length ? sales.map((s) => `
                <tr>
                  <td class="fw-bold num">${esc(s.receipt_number)} ${Number(s.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}</td>
                  <td class="num text-muted">${fmtDate(s.created_at)}</td>
                  <td>${esc(s.customer_name || 'Client comptoir')}</td>
                  <td><small class="text-muted">${esc(s.items_summary || `${s.items_count} art.`)}</small></td>
                  <td><span class="badge badge-slate">${esc(s.payment_method)}</span></td>
                  <td>${s.status === 'COMPLETED' ? '<span class="badge badge-green">Validée</span>' : '<span class="badge badge-red">Annulée</span>'}</td>
                  <td class="text-right num fw-bold">${fmtMoney(s.total_amount)}</td>
                  <td class="td-actions">
                    <button type="button" class="btn btn-outline btn-sm" data-view-sale="${s.id}">${icon('receipt', 14)} Reçu</button>
                  </td>
                </tr>
              `).join('') : `<tr><td colspan="8" class="empty-state">Aucune vente enregistrée aujourd’hui.</td></tr>`}
            </tbody>
          </table>
        </div>
      </div>
    `;
    container.querySelectorAll('[data-view-sale]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const d = await api(`sales/detail?id=${btn.dataset.viewSale}`);
        if (d.receipt) showReceiptModal(d.receipt, d.sale.id);
      });
    });
    return;
  }

  if (tab === 'pos-receipts') {
    let q = '';
    const loadReceipts = async () => {
      const res = await api(`receipts${q ? `?q=${encodeURIComponent(q)}` : ''}`);
      const list = res.receipts || [];
      const tbody = container.querySelector('#cashier-receipts-body');
      if (!tbody) return;
      tbody.innerHTML = list.length ? list.map((r) => `
        <tr>
          <td class="fw-bold num">${esc(r.receipt_number)} ${Number(r.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}</td>
          <td class="num text-muted">${fmtDate(r.created_at)}</td>
          <td>${esc(r.customer_name || 'Client comptoir')}</td>
          <td><span class="badge badge-slate">${esc(r.payment_method)}</span></td>
          <td>${r.status === 'COMPLETED' ? '<span class="badge badge-green">Valide</span>' : '<span class="badge badge-red">Annulé</span>'}</td>
          <td class="text-right num fw-bold">${fmtMoney(r.total_amount)}</td>
          <td class="text-right num">${r.printed_count}</td>
          <td class="td-actions">
            <button type="button" class="btn btn-primary btn-sm" data-open-rec="${r.sale_id}">${icon('printer', 14)} Ouvrir / Imprimer</button>
          </td>
        </tr>
      `).join('') : `<tr><td colspan="8" class="empty-state">Aucun reçu trouvé.</td></tr>`;

      tbody.querySelectorAll('[data-open-rec]').forEach((b) => {
        b.addEventListener('click', () => {
          const rec = list.find((x) => Number(x.sale_id) === Number(b.dataset.openRec));
          if (rec?.payload) showReceiptModal(rec.payload, rec.sale_id);
        });
      });
    };

    container.innerHTML = `
      <div class="toolbar">
        <div class="search-box">
          ${icon('search', 16)}
          <input id="rec-search" class="input" placeholder="Rechercher par n° reçu ou client…">
        </div>
        <button type="button" class="btn btn-outline" id="rec-refresh">Actualiser</button>
      </div>
      <div class="card">
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>N° Reçu</th>
                <th>Date</th>
                <th>Client</th>
                <th>Paiement</th>
                <th>Statut</th>
                <th class="text-right">Montant</th>
                <th class="text-right">Impressions</th>
                <th class="text-right">Action</th>
              </tr>
            </thead>
            <tbody id="cashier-receipts-body"></tbody>
          </table>
        </div>
      </div>
    `;

    container.querySelector('#rec-search')?.addEventListener('input', (e) => {
      q = e.target.value;
      loadReceipts();
    });
    container.querySelector('#rec-refresh')?.addEventListener('click', loadReceipts);
    await loadReceipts();
  }
}

async function renderPendingOrdersCashier(container, onRefreshShell) {
  let orders = [];
  let selectedId = null;
  let selectedDetail = null;
  let selectedPaymentCode = (Store.paymentMethods.find((m) => Number(m.active) === 1)?.code) || 'CASH';
  let amountGiven = '';

  const updatePendingBadge = (count) => {
    Store.pendingOrdersCount = count;
    document.querySelectorAll('[data-pending-badge]').forEach((el) => {
      el.textContent = String(count);
      el.style.display = count > 0 ? 'inline-flex' : 'none';
    });
  };

  const renderQueue = () => {
    const listEl = container.querySelector('#cashier-pending-list');
    if (!listEl) return;
    if (!orders.length) {
      listEl.innerHTML = `
        <div class="card card-body empty-state">
          ${icon('receipt', 32)}
          <h3>Aucune commande en attente</h3>
          <p>Les commandes envoyées par l’<strong>Accueil</strong> apparaîtront ici automatiquement pour encaissement.</p>
        </div>`;
      return;
    }

    listEl.innerHTML = orders.map((o) => {
      const isSel = Number(o.id) === Number(selectedId);
      return `
        <button type="button" class="card card-body" data-pick-order="${o.id}"
          style="width:100%;text-align:left;margin-bottom:.7rem;border-color:${isSel ? 'var(--blue-500)' : 'var(--border)'};box-shadow:${isSel ? 'inset 4px 0 0 var(--blue-500), var(--shadow-md)' : 'var(--shadow-xs)'}">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.35rem">
            <strong class="num" style="font-size:.98rem;color:var(--navy-900)">${esc(o.order_number)} ${Number(o.is_demo) === 1 ? '<span class="badge badge-demo">DÉMO</span>' : ''}</strong>
            <span class="num fw-bold" style="font-size:1.02rem;color:var(--blue-600)">${fmtMoney(o.total_amount)}</span>
          </div>
          <div style="font-size:.82rem;color:var(--ink-700);margin-bottom:.25rem">
            Client : <strong>${esc(o.customer_name || 'Client comptoir')}</strong> · Envoyé par ${esc(o.created_by)}
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;font-size:.76rem;color:var(--ink-500)">
            <span>${esc(o.items_summary || `${o.items_count} article(s)`)}</span>
            <span class="num">${fmtDate(o.created_at)}</span>
          </div>
        </button>
      `;
    }).join('');

    listEl.querySelectorAll('[data-pick-order]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        selectedId = Number(btn.dataset.pickOrder);
        amountGiven = '';
        selectedDetail = await api(`orders/detail?id=${selectedId}`);
        renderQueue();
        renderCheckoutPanel();
      });
    });
  };

  const renderCheckoutPanel = () => {
    const panel = container.querySelector('#cashier-checkout-panel');
    if (!panel) return;
    if (!selectedDetail || !selectedDetail.order) {
      panel.innerHTML = `
        <div class="card card-body empty-state">
          ${icon('cash', 32)}
          <p>Sélectionnez une commande envoyée par l’Accueil pour procéder à l’encaissement et délivrer le reçu.</p>
        </div>`;
      return;
    }

    const o = selectedDetail.order;
    const items = selectedDetail.items || [];
    const total = Number(o.total_amount);
    const activeMethods = Store.paymentMethods.filter((m) => Number(m.active) === 1);
    const pm = activeMethods.find((m) => m.code === selectedPaymentCode) || activeMethods[0] || { code: 'CASH', label: 'Espèces', is_cash: 1 };
    const isCash = Number(pm.is_cash) === 1;
    const given = isCash ? (amountGiven === '' ? 0 : Number(amountGiven) || 0) : total;
    const change = Math.max(0, given - total);
    const canPay = !isCash || given >= total;

    panel.innerHTML = `
      <div class="cart-header">
        <div>
          <h3 class="num" style="font-size:1.02rem;color:var(--navy-900)">${esc(o.order_number)}</h3>
          <small class="text-muted">Client : <strong>${esc(o.customer_name || 'Client comptoir')}</strong> · Accueil : ${esc(o.created_by)}</small>
        </div>
        <button type="button" class="btn btn-ghost btn-sm text-red" id="btn-cancel-pending">Annuler</button>
      </div>

      <div class="cart-items">
        ${o.notes ? `<div class="badge badge-blue" style="margin-bottom:.4rem;white-space:normal">Note : ${esc(o.notes)}</div>` : ''}
        ${items.map((it) => `
          <div class="cart-row">
            <div>
              <div class="fw-bold" style="font-size:.88rem">${esc(it.product_name)}</div>
              <small class="text-muted num">${it.quantity} × ${fmtMoney(it.unit_price)}</small>
            </div>
            <div class="num fw-bold" style="align-self:center">${fmtMoney(it.line_total)}</div>
          </div>
        `).join('')}
      </div>

      <div class="cart-footer">
        <div class="form-field">
          <label for="ord-pay-method">Mode de paiement</label>
          <select id="ord-pay-method" class="select">
            ${activeMethods.map((m) => `<option value="${esc(m.code)}" ${m.code === pm.code ? 'selected' : ''}>${esc(m.label)}</option>`).join('')}
          </select>
        </div>

        ${isCash ? `
          <div class="form-field">
            <label for="ord-given">Montant remis (FCFA)</label>
            <input id="ord-given" type="number" min="0" class="input num" placeholder="Ex : ${total}" value="${esc(amountGiven)}">
            <div class="quick-bills">
              <button type="button" class="bill-chip" data-ord-bill="${total}">Exact</button>
              ${[1000, 2000, 5000, 10000, 20000].map((v) => `<button type="button" class="bill-chip" data-ord-bill="${v}">${v.toLocaleString('fr-FR')}</button>`).join('')}
            </div>
          </div>
        ` : ''}

        <div class="summary-total"><span>Total à encaisser</span><span class="num">${fmtMoney(total)}</span></div>
        ${isCash ? `
          <div id="ord-chg-line" class="summary-line ${given >= total ? 'text-green fw-bold' : 'text-red fw-bold'}">
            <span>${given >= total ? 'Monnaie à rendre' : 'Reste à percevoir'}</span>
            <span class="num">${fmtMoney(given >= total ? change : total - given)}</span>
          </div>
        ` : ''}

        <button type="button" id="btn-pay-order" class="btn btn-primary btn-lg btn-block" ${canPay ? '' : 'disabled'}>
          ${icon('receipt', 18)} Encaisser et délivrer le reçu
        </button>
      </div>
    `;

    panel.querySelector('#ord-pay-method')?.addEventListener('change', (e) => {
      selectedPaymentCode = e.target.value;
      renderCheckoutPanel();
    });
    panel.querySelector('#ord-given')?.addEventListener('input', (e) => {
      amountGiven = e.target.value;
      const g = amountGiven === '' ? 0 : Number(amountGiven) || 0;
      const btn = panel.querySelector('#btn-pay-order');
      if (btn) btn.disabled = g < total;
      const line = panel.querySelector('#ord-chg-line');
      if (line) {
        line.className = `summary-line ${g >= total ? 'text-green fw-bold' : 'text-red fw-bold'}`;
        line.innerHTML = `<span>${g >= total ? 'Monnaie à rendre' : 'Reste à percevoir'}</span><span class="num">${fmtMoney(g >= total ? g - total : total - g)}</span>`;
      }
    });
    panel.querySelectorAll('[data-ord-bill]').forEach((b) => b.addEventListener('click', () => {
      amountGiven = String(b.dataset.ordBill);
      renderCheckoutPanel();
    }));

    panel.querySelector('#btn-pay-order')?.addEventListener('click', async () => {
      const btn = panel.querySelector('#btn-pay-order');
      if (btn) btn.disabled = true;
      try {
        const g = isCash ? (Number(amountGiven) || 0) : total;
        const res = await api('orders/pay', {
          method: 'POST',
          body: {
            order_id: o.id,
            payment_code: pm.code,
            payment_method: pm.label,
            amount_given: g,
            cashier_name: Store.cashierName,
          },
        });
        toast(`Commande ${o.order_number} encaissée ! Reçu ${res.receipt_number} délivré.`, 'success');
        selectedId = null;
        selectedDetail = null;
        amountGiven = '';
        await onRefreshShell();
        await fetchPending();
        showReceiptModal(res.receipt, res.sale_id);
      } catch (err) {
        toast(err.message, 'error');
        if (btn) btn.disabled = false;
      }
    });

    panel.querySelector('#btn-cancel-pending')?.addEventListener('click', () => {
      openModal({
        title: `Annuler la commande ${o.order_number}`,
        bodyHtml: `<form id="f-c-ord-cancel"><div class="form-field"><label>Motif d’annulation *</label><input name="reason" class="input" required placeholder="Ex : Client désisté…"></div></form>`,
        footerHtml: `<button type="button" class="btn btn-outline" data-close>Fermer</button><button type="submit" form="f-c-ord-cancel" class="btn btn-danger">Confirmer l’annulation</button>`,
        onMount: (dlg, close) => {
          dlg.querySelector('#f-c-ord-cancel').addEventListener('submit', async (e) => {
            e.preventDefault();
            const reason = new FormData(e.target).get('reason');
            await api('orders/cancel', { method: 'POST', body: { order_id: o.id, reason, actor: Store.cashierName } });
            toast('Commande annulée.', 'success');
            close();
            selectedId = null;
            selectedDetail = null;
            await onRefreshShell();
            await fetchPending();
          });
        },
      });
    });
  };

  const fetchPending = async () => {
    const res = await api('orders?status=PENDING');
    orders = res.orders || [];
    updatePendingBadge(orders.length);
    if (selectedId && !orders.some((x) => Number(x.id) === Number(selectedId))) {
      selectedId = null;
      selectedDetail = null;
    }
    if (!selectedId && orders.length) {
      selectedId = Number(orders[0].id);
      selectedDetail = await api(`orders/detail?id=${selectedId}`);
    }
    renderQueue();
    renderCheckoutPanel();
  };

  container.innerHTML = `
    <div class="toolbar">
      <div class="toolbar-group">
        <span class="badge badge-blue">Flux Accueil → Caisse (actualisation automatique)</span>
      </div>
      <button type="button" class="btn btn-outline" id="btn-refresh-pending">Actualiser maintenant</button>
    </div>
    <div class="pos-layout">
      <div id="cashier-pending-list"></div>
      <div id="cashier-checkout-panel" class="cart-panel"></div>
    </div>
  `;

  container.querySelector('#btn-refresh-pending')?.addEventListener('click', fetchPending);
  await fetchPending();
  pendingPollTimer = setInterval(() => {
    if (document.body.contains(container)) {
      fetchPending().catch(() => {});
    } else {
      stopCaissierPolling();
    }
  }, 4500);
}
