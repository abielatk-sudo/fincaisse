// Interactive SVG Bar/Comparison Chart for FINCAISSE (Zero external dependencies)
import { esc, fmtMoney } from './api.js';

export function renderBarChart(container, data, options = {}) {
  if (!container) return;
  const rows = Array.isArray(data) ? data : [];
  const showExpenses = options.showExpenses !== false;

  if (!rows.length) {
    container.innerHTML = `<div class="empty-state"><p>Aucune donnée à afficher sur cette période.</p></div>`;
    return;
  }

  const maxVal = Math.max(
    1000,
    ...rows.map((r) => Math.max(Number(r.revenue || 0), showExpenses ? Number(r.expenses || 0) : 0))
  );

  const w = 680;
  const h = 235;
  const padL = 56;
  const padR = 16;
  const padT = 18;
  const padB = 34;
  const innerW = w - padL - padR;
  const innerH = h - padT - padB;
  const groupW = innerW / rows.length;
  const barW = Math.max(6, Math.min(26, showExpenses ? groupW * 0.32 : groupW * 0.54));

  const gridLines = [0, 0.25, 0.5, 0.75, 1].map((t) => {
    const y = padT + innerH * (1 - t);
    const val = Math.round(maxVal * t);
    const shortVal = val >= 1000000 ? `${(val / 1000000).toFixed(1)}M` : val >= 1000 ? `${Math.round(val / 1000)}k` : `${val}`;
    return `
      <line x1="${padL}" y1="${y}" x2="${w - padR}" y2="${y}" stroke="#e2e8f0" stroke-dasharray="3 3"/>
      <text x="${padL - 8}" y="${y + 4}" text-anchor="end" font-size="10" fill="#64748b">${shortVal}</text>
    `;
  }).join('');

  const bars = rows.map((r, i) => {
    const rev = Number(r.revenue || 0);
    const exp = Number(r.expenses || 0);
    const revH = (rev / maxVal) * innerH;
    const expH = (exp / maxVal) * innerH;
    const cx = padL + i * groupW + groupW / 2;

    const revX = showExpenses ? cx - barW - 2 : cx - barW / 2;
    const expX = cx + 2;
    const showLabel = rows.length <= 10 || i % Math.ceil(rows.length / 8) === 0 || i === rows.length - 1;

    return `
      <g class="chart-col" data-idx="${i}">
        <rect x="${revX}" y="${padT + innerH - revH}" width="${barW}" height="${Math.max(2, revH)}" rx="4" fill="url(#fcBlueGrad)" />
        ${showExpenses ? `<rect x="${expX}" y="${padT + innerH - expH}" width="${barW}" height="${Math.max(exp > 0 ? 2 : 0, expH)}" rx="4" fill="#f59e0b" />` : ''}
        ${showLabel ? `<text x="${cx}" y="${h - 10}" text-anchor="middle" font-size="10" fill="#64748b">${esc(r.label || r.day || '')}</text>` : ''}
        <rect x="${padL + i * groupW}" y="${padT}" width="${groupW}" height="${innerH}" fill="transparent" class="chart-hit" data-idx="${i}" />
      </g>
    `;
  }).join('');

  container.innerHTML = `
    <div class="chart-wrap">
      <div class="chart-tooltip" id="fc-chart-tip"></div>
      <svg viewBox="0 0 ${w} ${h}" style="width:100%;height:auto;display:block;overflow:visible">
        <defs>
          <linearGradient id="fcBlueGrad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="#3b82f6"/>
            <stop offset="100%" stop-color="#1d4ed8"/>
          </linearGradient>
        </defs>
        ${gridLines}
        ${bars}
      </svg>
      <div style="display:flex;gap:1rem;justify-content:center;font-size:.76rem;color:#64748b;margin-top:.2rem">
        <span style="display:inline-flex;align-items:center;gap:.35rem"><span style="width:10px;height:10px;border-radius:3px;background:#2563eb;display:inline-block"></span>Ventes</span>
        ${showExpenses ? `<span style="display:inline-flex;align-items:center;gap:.35rem"><span style="width:10px;height:10px;border-radius:3px;background:#f59e0b;display:inline-block"></span>Dépenses</span>` : ''}
      </div>
    </div>
  `;

  const tip = container.querySelector('#fc-chart-tip');
  container.querySelectorAll('.chart-hit').forEach((el) => {
    el.addEventListener('mouseenter', (e) => {
      const idx = Number(el.dataset.idx);
      const item = rows[idx];
      if (!item || !tip) return;
      tip.innerHTML = `
        <strong>${esc(item.label || item.day || '')}</strong><br/>
        Ventes : ${fmtMoney(item.revenue || 0)} (${item.count || 0})<br/>
        ${showExpenses ? `Dépenses : ${fmtMoney(item.expenses || 0)}` : ''}
      `;
      tip.style.opacity = '1';
      const rect = container.getBoundingClientRect();
      tip.style.left = `${Math.min(rect.width - 170, Math.max(8, e.clientX - rect.left - 40))}px`;
      tip.style.top = '8px';
    });
    el.addEventListener('mouseleave', () => {
      if (tip) tip.style.opacity = '0';
    });
  });
}
