import { api, esc, fmtDate, fmtMoney, icon, openModal, Store, toast } from './api.js';

export function buildReceiptHtml(r, mode = 'thermal') {
  const cur = r.currency || Store.settings.currency || 'FCFA';
  const isCancelled = r.status === 'CANCELLED';
  const linesHtml = (r.items || []).map((it) => `
    <div class="receipt-line">
      <span>${esc(it.product_name)}<br><small class="text-muted">${it.quantity} × ${fmtMoney(it.unit_price, cur)}</small></span>
      <span class="num fw-bold">${fmtMoney(it.line_total, cur)}</span>
    </div>
  `).join('');

  return `
    <div class="receipt-paper ${mode === 'a4' ? 'a4-style' : ''}">
      <div class="receipt-center">
        <h3 style="font-size:1.15rem;margin-bottom:.15rem">${esc(r.store_name || Store.settings.store_name || 'FINCAISSE')}</h3>
        ${r.store_tagline ? `<div style="font-size:.78rem;color:#475569">${esc(r.store_tagline)}</div>` : ''}
        ${r.store_address ? `<div style="font-size:.76rem;color:#64748b">${esc(r.store_address)}</div>` : ''}
        ${r.store_phone ? `<div style="font-size:.76rem;color:#64748b">Tél : ${esc(r.store_phone)}</div>` : ''}
        ${isCancelled ? `<div style="margin-top:.45rem"><span class="badge badge-red">VENTE ANNULÉE</span></div>` : ''}
      </div>
      <hr class="receipt-divider">
      <div class="receipt-line"><span>Reçu N°</span><strong class="num">${esc(r.receipt_number)}</strong></div>
      <div class="receipt-line"><span>Date</span><span class="num">${fmtDate(r.created_at)}</span></div>
      ${r.cashier_name ? `<div class="receipt-line"><span>Caissier</span><span>${esc(r.cashier_name)}</span></div>` : ''}
      ${r.customer_name ? `<div class="receipt-line"><span>Client</span><strong>${esc(r.customer_name)}</strong></div>` : ''}
      <hr class="receipt-divider">
      ${linesHtml}
      <hr class="receipt-divider">
      ${Number(r.discount_amount || 0) > 0 ? `
        <div class="receipt-line"><span>Sous-total</span><span class="num">${fmtMoney(r.subtotal, cur)}</span></div>
        <div class="receipt-line"><span>Remise</span><span class="num">-${fmtMoney(r.discount_amount, cur)}</span></div>
      ` : ''}
      <div class="receipt-line" style="font-size:1.05rem;font-weight:800;margin-top:.25rem">
        <span>TOTAL À PAYER</span>
        <span class="num">${fmtMoney(r.total_amount, cur)}</span>
      </div>
      <div class="receipt-line"><span>Paiement</span><span>${esc(r.payment_method)}</span></div>
      <div class="receipt-line"><span>Montant remis</span><span class="num">${fmtMoney(r.amount_given ?? r.total_amount, cur)}</span></div>
      <div class="receipt-line"><span>Monnaie rendue</span><span class="num">${fmtMoney(r.change_amount ?? 0, cur)}</span></div>
      ${isCancelled && r.cancel_reason ? `<hr class="receipt-divider"><div style="color:#b91c1c;font-size:.78rem"><strong>Motif d'annulation :</strong> ${esc(r.cancel_reason)}</div>` : ''}
      <hr class="receipt-divider">
      <div class="receipt-center" style="font-size:.78rem;color:#475569">
        <p>${esc(r.receipt_footer || Store.settings.receipt_footer || 'Merci de votre visite !')}</p>
      </div>
    </div>
  `;
}

export function showReceiptModal(receiptData, saleId = null) {
  const triggerPrint = async (printMode) => {
    const area = document.getElementById('print-area');
    if (!area) return;
    document.body.dataset.printMode = printMode;
    area.innerHTML = buildReceiptHtml(receiptData, printMode);
    if (saleId || receiptData.sale_id) {
      api('receipts/print', { method: 'POST', body: { sale_id: saleId || receiptData.sale_id } }).catch(() => {});
    }
    window.print();
  };

  openModal({
    title: `Reçu de vente — ${receiptData.receipt_number || ''}`,
    bodyHtml: buildReceiptHtml(receiptData, 'thermal'),
    footerHtml: `
      <button type="button" class="btn btn-outline" id="btn-print-a4">${icon('printer', 15)} Imprimer format A4</button>
      <button type="button" class="btn btn-primary" id="btn-print-thermal">${icon('printer', 15)} Imprimer ticket caisse</button>
      <button type="button" class="btn btn-navy" data-close>Fermer</button>
    `,
    onMount: (dlg) => {
      dlg.querySelector('#btn-print-thermal')?.addEventListener('click', () => triggerPrint('thermal'));
      dlg.querySelector('#btn-print-a4')?.addEventListener('click', () => triggerPrint('a4'));
    },
  });
}

export async function renderPosWorkspace(container, opts = {}) {
  const {
    actor = 'Caissier',
    allowDiscount = true,
    viewMode = 'split', // 'split' | 'cart-only'
    onAfterSale = null,
  } = opts;

  let products = [];
  let categories = [];
  let customers = [];
  let searchQ = '';
  let activeCat = 0;
  let selectedCustId = '';
  let customCustName = '';
  let discountAmount = 0;
  let selectedPaymentCode = (Store.paymentMethods.find((m) => Number(m.active) === 1)?.code) || 'CASH';
  let amountGiven = '';

  const loadPosData = async () => {
    const [pRes, cRes, cuRes] = await Promise.all([
      api('products'),
      api('categories'),
      api('customers'),
    ]);
    products = pRes.products || [];
    categories = cRes.categories || [];
    customers = cuRes.customers || [];
  };

  await loadPosData();

  const updateCartBadge = () => {
    const count = Store.cart.reduce((acc, it) => acc + it.quantity, 0);
    document.querySelectorAll('[data-cart-badge]').forEach((el) => {
      el.textContent = String(count);
      el.style.display = count > 0 ? 'inline-flex' : 'none';
    });
  };

  const getCartTotals = () => {
    const subtotal = Store.cart.reduce((s, it) => s + it.unit_price * it.quantity, 0);
    const disc = allowDiscount ? Math.min(subtotal, Math.max(0, Number(discountAmount) || 0)) : 0;
    const total = Math.max(0, subtotal - disc);
    const pm = Store.paymentMethods.find((m) => m.code === selectedPaymentCode) || { code: 'CASH', label: 'Espèces', is_cash: 1 };
    const isCash = Number(pm.is_cash) === 1;
    const given = isCash ? (amountGiven === '' ? 0 : Number(amountGiven) || 0) : total;
    const change = Math.max(0, given - total);
    const canPay = Store.cart.length > 0 && (!isCash || given >= total);
    return { subtotal, disc, total, pm, isCash, given, change, canPay };
  };

  const addToCart = (prod) => {
    if (Number(prod.stock) <= 0) {
      toast(`« ${prod.name} » est en rupture de stock.`, 'warning');
      return;
    }
    const existing = Store.cart.find((c) => c.product_id === Number(prod.id));
    if (existing) {
      if (existing.quantity >= Number(prod.stock)) {
        toast(`Stock maximum atteint (${prod.stock}) pour ${prod.name}.`, 'warning');
        return;
      }
      existing.quantity += 1;
    } else {
      Store.cart.push({
        product_id: Number(prod.id),
        name: prod.name,
        unit_price: Number(prod.sell_price),
        max_stock: Number(prod.stock),
        quantity: 1,
      });
    }
    updateCartBadge();
    renderCartSection();
  };

  const changeQty = (productId, delta) => {
    const idx = Store.cart.findIndex((c) => c.product_id === productId);
    if (idx === -1) return;
    const item = Store.cart[idx];
    const next = item.quantity + delta;
    if (next <= 0) {
      Store.cart.splice(idx, 1);
    } else if (next > item.max_stock) {
      toast(`Stock disponible limité à ${item.max_stock}.`, 'warning');
      item.quantity = item.max_stock;
    } else {
      item.quantity = next;
    }
    updateCartBadge();
    renderCartSection();
  };

  const renderProductsGrid = () => {
    const grid = container.querySelector('#pos-prod-grid');
    if (!grid) return;
    const q = searchQ.trim().toLowerCase();
    const filtered = products.filter((p) => {
      if (activeCat > 0 && Number(p.category_id) !== activeCat) return false;
      if (q && !(`${p.name} ${p.sku || ''} ${p.category_name || ''}`.toLowerCase().includes(q))) return false;
      return true;
    });

    if (!filtered.length) {
      grid.innerHTML = `<div class="card card-body empty-state" style="grid-column:1/-1">
        <p>Aucun produit ne correspond à votre recherche.</p>
      </div>`;
      return;
    }

    grid.innerHTML = filtered.map((p) => {
      const stock = Number(p.stock);
      const low = stock > 0 && stock <= Number(p.alert_threshold);
      const out = stock <= 0;
      return `
        <button type="button" class="prod-card" data-prod-id="${p.id}" ${out ? 'disabled' : ''}>
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

    grid.querySelectorAll('[data-prod-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const pid = Number(btn.dataset.prodId);
        const prod = products.find((x) => Number(x.id) === pid);
        if (prod) addToCart(prod);
      });
    });
  };

  const renderCartSection = () => {
    const cartWrap = container.querySelector('#pos-cart-panel');
    if (!cartWrap) return;
    const { subtotal, disc, total, isCash, given, change, canPay } = getCartTotals();
    const activeMethods = Store.paymentMethods.filter((m) => Number(m.active) === 1);

    cartWrap.innerHTML = `
      <div class="cart-header">
        <div>
          <h3 style="font-size:1rem;color:var(--navy-900)">Panier en cours</h3>
          <small class="text-muted">${Store.cart.reduce((a, b) => a + b.quantity, 0)} article(s)</small>
        </div>
        ${Store.cart.length ? `<button type="button" class="btn btn-ghost btn-sm text-red" id="btn-clear-cart">Vider</button>` : ''}
      </div>

      <div class="cart-items">
        ${Store.cart.length ? Store.cart.map((it) => `
          <div class="cart-row">
            <div>
              <div class="fw-bold" style="font-size:.88rem">${esc(it.name)}</div>
              <small class="text-muted num">${fmtMoney(it.unit_price)} / unité</small>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.25rem">
              <div class="cart-row-controls">
                <button type="button" class="qty-btn" data-qty-dec="${it.product_id}">−</button>
                <span class="num fw-bold" style="min-width:22px;text-align:center">${it.quantity}</span>
                <button type="button" class="qty-btn" data-qty-inc="${it.product_id}">+</button>
                <button type="button" class="btn btn-ghost btn-sm text-red" style="padding:2px 5px" data-qty-del="${it.product_id}" title="Retirer">✕</button>
              </div>
              <span class="num fw-bold" style="font-size:.86rem">${fmtMoney(it.unit_price * it.quantity)}</span>
            </div>
          </div>
        `).join('') : `
          <div class="empty-state" style="padding:1.8rem .5rem">
            ${icon('pos', 30)}
            <p>Sélectionnez des produits pour démarrer la vente.</p>
          </div>
        `}
      </div>

      <div class="cart-footer">
        <div class="form-field">
          <label for="pos-cust-select">Client (optionnel)</label>
          <select id="pos-cust-select" class="select">
            <option value="">— Client comptoir / saisie libre —</option>
            ${customers.map((c) => `<option value="${c.id}" ${String(c.id) === String(selectedCustId) ? 'selected' : ''}>${esc(c.name)}${c.phone ? ` (${esc(c.phone)})` : ''}</option>`).join('')}
          </select>
        </div>

        ${!selectedCustId ? `
          <div class="form-field">
            <input id="pos-cust-free" class="input" placeholder="Ou tapez le nom du client…" value="${esc(customCustName)}">
          </div>
        ` : ''}

        <div class="form-grid" style="gap:.55rem">
          <div class="form-field ${allowDiscount ? '' : 'full'}">
            <label for="pos-pay-method">Mode de paiement</label>
            <select id="pos-pay-method" class="select">
              ${activeMethods.map((m) => `<option value="${esc(m.code)}" ${m.code === selectedPaymentCode ? 'selected' : ''}>${esc(m.label)}</option>`).join('')}
            </select>
          </div>
          ${allowDiscount ? `
            <div class="form-field">
              <label for="pos-discount">Remise (FCFA)</label>
              <input id="pos-discount" type="number" min="0" class="input num" value="${discountAmount}">
            </div>
          ` : ''}
        </div>

        ${isCash ? `
          <div class="form-field">
            <label for="pos-given">Montant remis (FCFA)</label>
            <input id="pos-given" type="number" min="0" class="input num" placeholder="Ex : ${total}" value="${esc(amountGiven)}">
            <div class="quick-bills">
              <button type="button" class="bill-chip" data-bill="${total}">Exact</button>
              ${[1000, 2000, 5000, 10000, 20000].map((v) => `<button type="button" class="bill-chip" data-bill="${v}">${v.toLocaleString('fr-FR')}</button>`).join('')}
            </div>
          </div>
        ` : ''}

        <div class="summary-line"><span>Sous-total</span><span class="num">${fmtMoney(subtotal)}</span></div>
        ${disc > 0 ? `<div class="summary-line text-amber"><span>Remise</span><span class="num">−${fmtMoney(disc)}</span></div>` : ''}
        <div class="summary-total"><span>Total à payer</span><span class="num">${fmtMoney(total)}</span></div>
        ${isCash && Store.cart.length ? `
          <div class="summary-line ${given >= total ? 'text-green fw-bold' : 'text-red fw-bold'}">
            <span>${given >= total ? 'Monnaie à rendre' : 'Reste à percevoir'}</span>
            <span class="num">${fmtMoney(given >= total ? change : total - given)}</span>
          </div>
        ` : ''}

        <button type="button" id="btn-confirm-sale" class="btn btn-primary btn-lg btn-block" ${canPay ? '' : 'disabled'}>
          ${icon('receipt', 18)} Confirmer et générer le reçu
        </button>
      </div>
    `;

    cartWrap.querySelector('#btn-clear-cart')?.addEventListener('click', () => {
      Store.cart = [];
      amountGiven = '';
      discountAmount = 0;
      updateCartBadge();
      renderCartSection();
    });
    cartWrap.querySelectorAll('[data-qty-inc]').forEach((b) => b.addEventListener('click', () => changeQty(Number(b.dataset.qtyInc), 1)));
    cartWrap.querySelectorAll('[data-qty-dec]').forEach((b) => b.addEventListener('click', () => changeQty(Number(b.dataset.qtyDec), -1)));
    cartWrap.querySelectorAll('[data-qty-del]').forEach((b) => b.addEventListener('click', () => {
      const pid = Number(b.dataset.qtyDel);
      Store.cart = Store.cart.filter((c) => c.product_id !== pid);
      updateCartBadge();
      renderCartSection();
    }));

    cartWrap.querySelector('#pos-cust-select')?.addEventListener('change', (e) => {
      selectedCustId = e.target.value;
      renderCartSection();
    });
    cartWrap.querySelector('#pos-cust-free')?.addEventListener('input', (e) => {
      customCustName = e.target.value;
    });
    cartWrap.querySelector('#pos-pay-method')?.addEventListener('change', (e) => {
      selectedPaymentCode = e.target.value;
      renderCartSection();
    });
    cartWrap.querySelector('#pos-discount')?.addEventListener('input', (e) => {
      discountAmount = Number(e.target.value) || 0;
      renderCartSection();
    });
    cartWrap.querySelector('#pos-given')?.addEventListener('input', (e) => {
      amountGiven = e.target.value;
      // Update only summary without stealing input focus
      const t = getCartTotals();
      const btn = cartWrap.querySelector('#btn-confirm-sale');
      if (btn) btn.disabled = !t.canPay;
      const lines = cartWrap.querySelectorAll('.summary-line');
      const lastLine = lines[lines.length - 1];
      if (lastLine && t.isCash && Store.cart.length) {
        lastLine.className = `summary-line ${t.given >= t.total ? 'text-green fw-bold' : 'text-red fw-bold'}`;
        lastLine.innerHTML = `<span>${t.given >= t.total ? 'Monnaie à rendre' : 'Reste à percevoir'}</span><span class="num">${fmtMoney(t.given >= t.total ? t.change : t.total - t.given)}</span>`;
      }
    });
    cartWrap.querySelectorAll('[data-bill]').forEach((b) => b.addEventListener('click', () => {
      amountGiven = String(b.dataset.bill);
      renderCartSection();
    }));

    cartWrap.querySelector('#btn-confirm-sale')?.addEventListener('click', async () => {
      const btn = cartWrap.querySelector('#btn-confirm-sale');
      if (btn) btn.disabled = true;
      try {
        const t = getCartTotals();
        const payload = {
          items: Store.cart.map((c) => ({ product_id: c.product_id, quantity: c.quantity })),
          customer_id: selectedCustId ? Number(selectedCustId) : null,
          customer_name: selectedCustId ? '' : customCustName.trim(),
          payment_code: t.pm.code,
          payment_method: t.pm.label,
          discount_amount: t.disc,
          amount_given: t.given,
          cashier_name: actor,
        };
        const res = await api('sales', { method: 'POST', body: payload });
        Store.cart = [];
        selectedCustId = '';
        customCustName = '';
        discountAmount = 0;
        amountGiven = '';
        updateCartBadge();
        toast(`Vente ${res.receipt_number} enregistrée avec succès !`, 'success');
        await loadPosData();
        renderProductsGrid();
        renderCartSection();
        showReceiptModal(res.receipt, res.sale_id);
        if (onAfterSale) onAfterSale(res);
      } catch (err) {
        toast(err.message, 'error');
        if (btn) btn.disabled = false;
      }
    });
  };

  if (viewMode === 'cart-only') {
    container.innerHTML = `<div style="max-width:680px;margin:0 auto"><div id="pos-cart-panel" class="cart-panel" style="position:static;max-height:none"></div></div>`;
    renderCartSection();
    updateCartBadge();
    return;
  }

  container.innerHTML = `
    <div class="pos-layout">
      <div>
        <div class="toolbar">
          <div class="search-box" style="flex:1;min-width:240px">
            ${icon('search', 16)}
            <input id="pos-search" class="input" placeholder="Rechercher un produit par nom, code SKU…">
          </div>
          <button type="button" class="btn btn-outline" id="pos-refresh">Actualiser</button>
        </div>

        <div class="cat-pills" id="pos-cat-pills">
          <button type="button" class="cat-pill ${activeCat === 0 ? 'active' : ''}" data-cat="0">Tous (${products.length})</button>
          ${categories.map((c) => `
            <button type="button" class="cat-pill ${activeCat === Number(c.id) ? 'active' : ''}" data-cat="${c.id}">
              <span style="width:8px;height:8px;border-radius:999px;background:${esc(c.color || '#2563EB')};display:inline-block"></span>
              ${esc(c.name)}
            </button>
          `).join('')}
        </div>

        <div class="prod-grid" id="pos-prod-grid"></div>
      </div>

      <div id="pos-cart-panel" class="cart-panel"></div>
    </div>
  `;

  container.querySelector('#pos-search')?.addEventListener('input', (e) => {
    searchQ = e.target.value;
    renderProductsGrid();
  });
  container.querySelector('#pos-refresh')?.addEventListener('click', async () => {
    await loadPosData();
    renderProductsGrid();
    toast('Catalogue actualisé');
  });
  container.querySelectorAll('[data-cat]').forEach((b) => {
    b.addEventListener('click', () => {
      activeCat = Number(b.dataset.cat);
      container.querySelectorAll('[data-cat]').forEach((x) => x.classList.toggle('active', Number(x.dataset.cat) === activeCat));
      renderProductsGrid();
    });
  });

  renderProductsGrid();
  renderCartSection();
  updateCartBadge();
}
