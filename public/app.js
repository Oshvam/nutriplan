// VersiÃ³n de la app (tomada del propio ?v= con el que se sirve este script),
// para poder cachear el service worker sin quedarnos con una copia vieja del CDN.
const NP_V = (() => {
  try {
    const src = document.currentScript && document.currentScript.src;
    const m = src && src.match(/[?&]v=([^&]+)/);
    return m ? m[1] : '1';
  } catch (e) { return '1'; }
})();
const SW_URL = '/sw.js?v=' + encodeURIComponent(NP_V);

// Tema claro/oscuro (clase .dark, como el original)
const applyThemeIcons = () => {
  const dark = document.documentElement.classList.contains('dark');
  document.querySelectorAll('[data-theme-icon-moon]').forEach((s) => s.classList.toggle('hidden', dark));
  document.querySelectorAll('[data-theme-icon-sun]').forEach((s) => s.classList.toggle('hidden', !dark));
};
applyThemeIcons();
document.querySelectorAll('[data-theme-toggle]').forEach((b) => b.addEventListener('click', () => {
  const dark = document.documentElement.classList.toggle('dark');
  try { localStorage.setItem('np_theme', dark ? 'dark' : 'light'); } catch (e) {}
  applyThemeIcons();
}));

// Modales (guÃ­a clÃ­nica + ajustes): focus trap, scroll lock y Esc.
let npReturnFocus = null;
const openModal = (id) => {
  const m = document.getElementById(id);
  if (!m) return;
  npReturnFocus = document.activeElement;
  m.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  if (window.lucide) lucide.createIcons();
  const first = m.querySelector('[data-modal-close], button, [href], input, select, textarea');
  if (first) first.focus();
};
const closeModals = () => {
  let any = false;
  document.querySelectorAll('#modal-guidelines, #modal-settings').forEach((m) => {
    if (!m.classList.contains('hidden')) { m.classList.add('hidden'); any = true; }
  });
  if (any) {
    document.body.style.overflow = '';
    if (npReturnFocus && npReturnFocus.focus) npReturnFocus.focus();
    npReturnFocus = null;
  }
};
document.querySelectorAll('[data-open-guidelines]').forEach((b) => b.addEventListener('click', () => openModal('modal-guidelines')));
document.querySelectorAll('[data-open-settings]').forEach((b) => b.addEventListener('click', () => openModal('modal-settings')));
document.querySelectorAll('[data-modal-close]').forEach((b) => b.addEventListener('click', closeModals));
document.querySelectorAll('#modal-guidelines, #modal-settings').forEach((m) => m.addEventListener('click', (e) => { if (e.target === m) closeModals(); }));
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') { closeModals(); return; }
  if (e.key !== 'Tab') return;
  const open = [...document.querySelectorAll('#modal-guidelines, #modal-settings')].find((m) => !m.classList.contains('hidden'));
  if (!open) return;
  const items = [...open.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])')]
    .filter((el) => el.offsetParent !== null);
  if (!items.length) return;
  const first = items[0], last = items[items.length - 1];
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
});

// Interacciones NutriPlan (fetch a la API JSON + estado local).
async function npReq(url, method, body) {
  const r = await fetch(url, { method, headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined });
  const d = await r.json().catch(() => ({}));
  if (!d.success) throw new Error(d.error || ('Error ' + r.status));
  return d;
}
const todayIdx = () => (new Date().getDay() + 6) % 7;

// --- UI: toasts, confirmaciÃ³n propia y estado "ocupado" ---
const toast = (msg, opts) => {
  opts = opts || {};
  const box = document.getElementById('np-toasts');
  if (!box) return;
  const el = document.createElement('div');
  el.className = 'flex items-start gap-3 rounded-xl px-4 py-3 text-sm shadow-2xl border ' +
    (opts.error ? 'bg-rose-600 border-rose-500 text-white'
                : 'bg-slate-900/95 dark:bg-slate-800 border-slate-700 text-white');
  const p = document.createElement('p');
  p.className = 'flex-1';
  p.textContent = msg;
  el.appendChild(p);
  if (opts.action) {
    const a = document.createElement('button');
    a.className = 'font-bold underline underline-offset-2 shrink-0';
    a.textContent = opts.action.label;
    a.onclick = async () => { el.remove(); try { await opts.action.onClick(); } catch (e) { toast(e.message, { error: true }); } };
    el.appendChild(a);
  }
  box.appendChild(el);
  setTimeout(() => el.remove(), opts.action ? 9000 : 4500);
};

const askConfirm = (msg, label) => new Promise((resolve) => {
  const box = document.getElementById('np-confirm');
  if (!box) { resolve(window.confirm(msg)); return; }
  document.getElementById('np-confirm-msg').textContent = msg;
  document.getElementById('np-confirm-yes').textContent = label || 'Continuar';
  const yes = document.getElementById('np-confirm-yes');
  const no = document.getElementById('np-confirm-no');
  box.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
  if (window.lucide) lucide.createIcons();
  setTimeout(() => yes.focus(), 30);
  const done = (v) => {
    box.classList.add('hidden');
    document.body.style.overflow = '';
    box.removeEventListener('click', onBackdrop);
    document.removeEventListener('keydown', onKey);
    yes.onclick = null; no.onclick = null;
    resolve(v);
  };
  const onBackdrop = (e) => { if (e.target === box) done(false); };
  const onKey = (e) => { if (e.key === 'Escape') done(false); if (e.key === 'Enter') done(true); };
  box.addEventListener('click', onBackdrop);
  document.addEventListener('keydown', onKey);
  yes.onclick = () => done(true);
  no.onclick = () => done(false);
});

// Marca un botÃ³n como ocupado (deshabilitado + pulso) mientras corre una peticiÃ³n.
const busy = (btn, on) => {
  if (!btn) return;
  btn.disabled = !!on;
  btn.classList.toggle('opacity-60', !!on);
  btn.classList.toggle('pointer-events-none', !!on);
  btn.classList.toggle('animate-pulse', !!on);
};

// Re-render parcial: sustituye solo las regiones del plan sin recargar la pÃ¡gina.
async function refreshPlanRegions() {
  const r = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
  for (const id of ['plan-days', 'plan-day', 'plan-matrix']) {
    const fresh = doc.getElementById(id), cur = document.getElementById(id);
    if (fresh && cur) cur.innerHTML = fresh.innerHTML;
  }
  if (window.lucide) lucide.createIcons();
}

document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-act]');
  if (!b) return;
  const act = b.dataset.act;
  try {
    if (act === 'toggle') {
      await npReq('/api/plan/toggle', 'POST', { dayNumber: +b.dataset.dia + 1, slotId: b.dataset.slot });
      await refreshPlanRegions();
    } else if (act === 'swap') {
      busy(b, true);
      await npReq('/api/plan/swap', 'POST', { dayNumber: +b.dataset.dia + 1, slotId: b.dataset.slot });
      await refreshPlanRegions();
      toast('Plato sustituido.');
    } else if (act === 'regen') {
      if (!await askConfirm('Â¿Regenerar el plan de la semana? Se perderÃ¡n las comidas marcadas como completadas.', 'Regenerar')) return;
      busy(b, true);
      await npReq('/api/plan/regenerate', 'POST');
      await refreshPlanRegions();
      toast('Plan de la semana regenerado.');
    } else if (act === 'apply' || act === 'apply-hoy') {
      const recipe = JSON.parse(b.dataset.recipe);
      const dia = b.dataset.dia !== undefined && b.dataset.dia !== '' ? +b.dataset.dia : todayIdx();
      busy(b, true);
      await npReq('/api/plan/apply', 'POST', { dayNumber: dia + 1, slotId: b.dataset.slot, recipe });
      if (act === 'apply-hoy') {
        b.classList.remove('animate-pulse', 'opacity-60', 'pointer-events-none');
        b.innerHTML = '<i data-lucide="check" class="w-3.5 h-3.5"></i><span>En tu menÃº</span>';
        if (window.lucide) lucide.createIcons();
        toast('AÃ±adido a tu menÃº de hoy.');
      } else {
        location.href = b.dataset.back || '/';
      }
    } else if (act === 'wdel') {
      if (!await askConfirm('Â¿Borrar este registro de peso?', 'Borrar')) return;
      const back = { date: b.dataset.date, weight: +b.dataset.weight, note: b.dataset.note || '' };
      await npReq('/api/weight', 'DELETE', { date: b.dataset.date });
      toast('Registro borrado.', { action: { label: 'Deshacer', onClick: async () => {
        await npReq('/api/weight', 'POST', back);
        location.reload();
      } } });
      b.closest('tr')?.remove();
    } else if (act === 'logout') {
      await npReq('/api/auth/logout', 'POST');
      // No dejar HTML autenticado en la cachÃ© del service worker.
      if (window.caches) {
        try { const ks = await caches.keys(); await Promise.all(ks.map((k) => caches.delete(k))); } catch (e) {}
      }
      location.href = '/';
    } else if (b.hasAttribute('data-ms')) {
      const msg = document.getElementById('ms-msg');
      const model = document.getElementById('ms-model').value;
      busy(b, true);
      msg.textContent = 'â€¦';
      await npReq('/api/settings/mistral', 'POST', { model });
      msg.textContent = 'Guardado.';
      toast('Modelo de IA guardado.');
    }
  } catch (err) {
    toast(err.message, { error: true });
  } finally {
    busy(b, false);
  }
});

// Refresca un bloque de la pÃ¡gina sin recargar (Ãºtil tras mutaciones).
async function refreshBlock(id) {
  const r = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
  const fresh = doc.getElementById(id), cur = document.getElementById(id);
  if (fresh && cur) {
    cur.innerHTML = fresh.innerHTML;
    if (window.lucide) lucide.createIcons();
  }
}

// Formularios de perfil (delegado: el bloque de peso se re-renderiza sin recargar).
document.addEventListener('submit', async (e) => {
  const form = e.target;
  if (form.id === 'weight-form') {
    e.preventDefault();
    const btn = form.querySelector('button');
    busy(btn, true);
    try {
      await npReq('/api/weight', 'POST', { date: document.getElementById('w-date').value,
        weight: parseFloat(document.getElementById('w-weight').value),
        note: document.getElementById('w-note').value });
      await refreshBlock('weight-block');
      toast('Peso registrado.');
    } catch (err) { toast(err.message, { error: true }); }
    finally { busy(btn, false); }
  } else if (form.id === 'name-form') {
    e.preventDefault();
    const btn = form.querySelector('button');
    busy(btn, true);
    try {
      const d = await npReq('/api/profile', 'GET');
      const p = Object.assign({}, d.profile, { name: document.getElementById('profile-name').value });
      await npReq('/api/profile', 'PUT', { profile: p });
      toast('Nombre guardado.');
    } catch (err) { toast(err.message, { error: true }); }
    finally { busy(btn, false); }
  }
});

document.addEventListener('click', (e) => {
  if (e.target.closest('[data-focus-weight]')) {
    const i = document.getElementById('w-weight');
    if (i) { i.focus(); i.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
  }
});

// Onboarding por pasos (clon del OnboardingModal: botones + resumen en vivo)
const onb = document.getElementById('onb');
if (onb) {
  let step = 1;
  const panes = [...onb.querySelectorAll('[data-pane]')];
  const stepBtns = [...document.querySelectorAll('[data-stepbtn]')];
  const stepNow = document.getElementById('step-now');
  // Captura las dos variantes de clases tal como las renderiza Twig
  const ref = {};
  onb.querySelectorAll('[data-optgroup]').forEach((g) => {
    const name = g.getAttribute('data-optgroup');
    if (name === 'allergies' || ref[name]) return;
    const sel = g.querySelector('[data-sel="1"]'), uns = g.querySelector('[data-sel="0"]');
    if (sel && uns) ref[name] = { on: sel.className, off: uns.className };
  });
  const sref = stepBtns.length ? { on: stepBtns[0].className, off: (stepBtns[1] || stepBtns[0]).className } : null;
  const hid = (n) => onb.querySelector('input[type="hidden"][name="' + n + '"]');
  const paintOpt = (name) => {
    const h = hid(name); if (!h || !ref[name]) return;
    onb.querySelectorAll('[data-opt="' + name + '"]').forEach((b) => {
      const active = String(b.getAttribute('data-val')) === String(h.value);
      b.className = active ? ref[name].on : ref[name].off;
      const chk = b.querySelector('.onb-check'); if (chk) chk.classList.toggle('hidden', !active);
    });
  };
  const paintAllergies = () => {
    const h = hid('allergies'); if (!h) return;
    const list = String(h.value || '').split(',').map((s) => s.trim()).filter(Boolean);
    onb.querySelectorAll('[data-allergy]').forEach((b) => {
      const active = list.includes(b.getAttribute('data-allergy'));
      b.className = active ? refAllergy.on : refAllergy.off;
      const box = b.querySelector('.onb-box'); if (box) box.className = active ? refAllergy.boxOn : refAllergy.boxOff;
      const tick = b.querySelector('.onb-tick'); if (tick) tick.classList.toggle('hidden', !active);
    });
  };
  const refAllergy = (() => {
    const g = onb.querySelector('[data-optgroup="allergies"]');
    const sel = g ? g.querySelector('[data-sel="1"]') : null;
    const uns = g ? g.querySelector('[data-sel="0"]') : null;
    const box = (el) => el ? el.querySelector('.onb-box').className : '';
    return { on: sel ? sel.className : '', off: uns ? uns.className : uns === null && sel ? sel.className : '', boxOn: box(sel) || box(uns), boxOff: box(uns) || box(sel) };
  })();
  onb.querySelectorAll('[data-opt]').forEach((b) => {
    b.addEventListener('click', () => { const h = hid(b.getAttribute('data-opt')); if (h) { h.value = b.getAttribute('data-val'); paintOpt(h.name); live(); } });
  });
  onb.querySelectorAll('[data-allergy]').forEach((b) => {
    b.addEventListener('click', () => {
      const h = hid('allergies'); if (!h) return;
      const list = String(h.value || '').split(',').map((s) => s.trim()).filter(Boolean);
      const v = b.getAttribute('data-allergy');
      h.value = list.includes(v) ? list.filter((x) => x !== v).join(', ') : list.concat([v]).join(', ');
      paintAllergies(); live();
    });
  });
  const upd = () => {
    panes.forEach((p) => p.classList.toggle('hidden', +p.dataset.pane !== step));
    if (sref) stepBtns.forEach((x) => { x.className = (+x.dataset.stepbtn === step) ? sref.on : sref.off; });
    if (stepNow) stepNow.textContent = step;
    document.getElementById('onb-back').classList.toggle('invisible', step === 1);
    document.getElementById('onb-next').classList.toggle('hidden', step === 4);
    const save = document.getElementById('onb-save');
    save.classList.toggle('hidden', step !== 4);
    save.classList.toggle('flex', step === 4);
  };
  const live = async () => {
    const f = new FormData(onb);
    const w = parseFloat(f.get('currentWeight')), h = parseFloat(f.get('height'));
    document.getElementById('imc-val').textContent = (w > 0 && h > 0) ? (w / ((h / 100) ** 2)).toFixed(1) : 'â€“';
    try {
      const q = new URLSearchParams({ goal: f.get('goal'), targetWeeks: f.get('targetWeeks'), gender: f.get('gender'), age: f.get('age'), height: f.get('height'), currentWeight: f.get('currentWeight'), targetWeight: f.get('targetWeight'), activityLevel: f.get('activityLevel') });
      const d = await (await fetch('/api/calc?' + q.toString())).json();
      if (!d.calc) return;
      const c = d.calc;
      document.getElementById('calc-bmr').textContent = c.bmr;
      document.getElementById('calc-tdee').textContent = c.tdee;
      document.getElementById('calc-kcal').textContent = c.targetCalories;
      document.getElementById('calc-sub').textContent = c.deficitOrSurplus > 0 ? '+' + c.deficitOrSurplus + ' superÃ¡vit' : (c.deficitOrSurplus < 0 ? c.deficitOrSurplus + ' dÃ©ficit' : 'Mantenimiento');
      document.getElementById('calc-p').textContent = c.macros.proteinGrams;
      document.getElementById('calc-psub').textContent = c.macros.proteinPct + '% (' + c.macros.proteinCalories + ' kcal)';
      document.getElementById('calc-c').textContent = c.macros.carbsGrams;
      document.getElementById('calc-csub').textContent = c.macros.carbsPct + '% (' + c.macros.carbsCalories + ' kcal)';
      document.getElementById('calc-f').textContent = c.macros.fatsGrams;
      document.getElementById('calc-fsub').textContent = c.macros.fatsPct + '% (' + c.macros.fatsCalories + ' kcal)';
      const pace = document.getElementById('calc-pace');
      const show = String(f.get('currentWeight')) !== String(f.get('targetWeight'));
      pace.classList.toggle('hidden', !show);
      if (show) {
        document.getElementById('calc-rate').textContent = Math.abs(c.weeklyExpectedChangeKg) + ' kg/semana';
        document.getElementById('calc-weeks').textContent = c.estimatedWeeksToGoal;
      }
      const badge = document.getElementById('imc-badge');
      badge.textContent = c.bmiClassification.label;
      badge.className = 'text-xs font-semibold px-2.5 py-1 rounded-md bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 ' + c.bmiClassification.color;
    } catch (e) { /* resumen inicial del servidor queda visible */ }
  };
  ['currentWeight', 'height', 'targetWeight', 'age'].forEach((n) => {
    const el = onb.querySelector('[name="' + n + '"]');
    if (el) el.addEventListener('input', () => {
      if (n === 'age') {
        const teen = parseFloat(el.value) < 18;
        const h = hid('isChildOrTeen'); if (h) h.value = teen ? '1' : '0';
        document.getElementById('teen-note').classList.toggle('hidden', !teen);
      }
      live();
    });
  });
  const water = document.getElementById('onb-water');
  if (water) water.addEventListener('input', () => { document.getElementById('water-label').textContent = water.value; });
  stepBtns.forEach((x) => x.addEventListener('click', () => { step = +x.dataset.stepbtn; upd(); }));
  document.getElementById('onb-next').onclick = () => { if (step < 4) { step++; upd(); } };
  document.getElementById('onb-back').onclick = () => { if (step > 1) { step--; upd(); } };
  onb.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = new FormData(onb);
    const allergies = String(f.get('allergies') || '').split(',').map((s) => s.trim()).filter(Boolean);
    const profile = { goal: f.get('goal'), name: f.get('name'), targetWeeks: +f.get('targetWeeks') || 12,
      currentWeight: +f.get('currentWeight'), targetWeight: +f.get('targetWeight'), height: +f.get('height'),
      age: +f.get('age'), gender: f.get('gender'), isChildOrTeen: f.get('isChildOrTeen') === '1' || +f.get('age') < 18,
      activityLevel: f.get('activityLevel'), mealsPerDay: +f.get('mealsPerDay') || 5,
      dietaryPreference: f.get('dietaryPreference'), allergies,
      dailyWaterTargetLiters: parseFloat(f.get('dailyWaterTargetLiters')) || 2.5 };
    try {
      await npReq('/api/profile', 'POST', { profile });
      location.href = '/?tab=plan';
    } catch (err) { document.getElementById('onb-msg').textContent = err.message; }
  });
  paintAllergies();
  upd();
  live();
}

// Agua (local)
document.querySelectorAll('[data-water]').forEach((b) => b.addEventListener('click', () => {
  const k = 'np_water_' + new Date().toISOString().slice(0, 10);
  let v = Math.max(0, Math.min(20, (+localStorage.getItem(k) || 0) + (+b.dataset.water)));
  localStorage.setItem(k, v);
  document.getElementById('water-count').textContent = v + '/8';
}));

// Lista de la compra (local)
const shop = document.getElementById('shop');
if (shop) {
  const store = JSON.parse(localStorage.getItem('np_shop') || '{}');
  const checks = [...shop.querySelectorAll('.shop-check')];
  const paint = () => {
    let done = 0;
    checks.forEach((c) => {
      const on = !!store[c.dataset.key];
      c.checked = on;
      c.closest('.shop-item').classList.toggle('opacity-40', on);
      c.closest('.shop-item').classList.toggle('line-through', on);
      if (on) done++;
    });
    document.getElementById('shop-count').textContent = done + '/' + checks.length + ' comprados';
    document.getElementById('shop-bar').style.width = (checks.length ? done / checks.length * 100 : 0) + '%';
  };
  checks.forEach((c) => c.addEventListener('change', () => {
    if (c.checked) store[c.dataset.key] = 1; else delete store[c.dataset.key];
    localStorage.setItem('np_shop', JSON.stringify(store)); paint();
  }));
  document.querySelectorAll('[data-filter]').forEach((f) => f.addEventListener('click', () => {
    const m = f.dataset.filter;
    checks.forEach((c) => {
      const done = !!store[c.dataset.key];
      c.closest('.shop-item').style.display = (m === 'all' || (m === 'done') === done) ? '' : 'none';
    });
  }));
  document.getElementById('shop-reset').onclick = async () => {
    const snapshot = JSON.stringify(store);
    if (!await askConfirm('Â¿Reiniciar la lista de la compra? Se desmarcarÃ¡n todos los productos.', 'Reiniciar')) return;
    Object.keys(store).forEach((k) => delete store[k]);
    localStorage.removeItem('np_shop');
    paint();
    toast('Lista reiniciada.', { action: { label: 'Deshacer', onClick: async () => {
      Object.assign(store, JSON.parse(snapshot || '{}'));
      localStorage.setItem('np_shop', JSON.stringify(store));
      paint();
    } } });
  };
  document.getElementById('shop-copy').onclick = async () => {
    let txt = 'ðŸ›’ Lista de la compra â€” NutriPlan\n', h = '';
    shop.querySelectorAll('h3, .shop-item').forEach((el) => {
      if (el.tagName === 'H3') { h = el.textContent.trim(); txt += '\n' + h + '\n'; }
      else if (el.style.display !== 'none') txt += 'Â· ' + el.querySelector('span').textContent.trim().replace(/\s+/g, ' ') + '\n';
    });
    try {
      await navigator.clipboard.writeText(txt);
      toast('Lista copiada al portapapeles.');
    } catch (e) {
      toast('No se pudo copiar al portapapeles.', { error: true });
    }
  };
  paint();
}

// Toggles de aviso (local)
[['tgl-meals', 'np_tgl_meals'], ['tgl-summary', 'np_tgl_summary']].forEach(([id, k]) => {
  const t = document.getElementById(id);
  if (t) { t.checked = localStorage.getItem(k) === '1'; t.addEventListener('change', () => localStorage.setItem(k, t.checked ? '1' : '0')); }
});

// Push
const pushBtn = document.getElementById('push-btn');
if (pushBtn && 'serviceWorker' in navigator) {
  const vapid = document.querySelector('[data-vapid]')?.dataset.vapid;
  const st = document.getElementById('push-state');
  const b64 = (s) => { const b = atob(s.replace(/-/g, '+').replace(/_/g, '/')); const a = new Uint8Array(b.length); for (let i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a; };
  pushBtn.onclick = async () => {
    try {
      const reg = await navigator.serviceWorker.register(SW_URL);
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(vapid) });
      await npReq('/api/push/subscribe', 'POST', { subscription: sub.toJSON() });
      st.textContent = 'activadas âœ“'; pushBtn.textContent = 'Probar aviso';
      toast('Notificaciones push activadas.');
      pushBtn.onclick = async () => {
        busy(pushBtn, true);
        try {
          await npReq('/api/push/test', 'POST', { endpoint: sub.endpoint });
          toast('Aviso enviado.');
        } catch (err) { toast(err.message, { error: true }); }
        finally { busy(pushBtn, false); }
      };
    } catch (err) { toast('No se pudo activar: ' + err.message, { error: true }); }
  };
}

// IA
const iaGo = document.getElementById('ia-go');
if (iaGo) iaGo.addEventListener('click', async () => {
  const msg = document.getElementById('ia-msg'), box = document.getElementById('ia-results');
  const profile = JSON.parse(document.getElementById('px').textContent);
  const needs = JSON.parse(document.getElementById('nx').textContent);
  busy(iaGo, true);
  msg.textContent = 'Generandoâ€¦';
  // Esqueleto mientras la IA responde.
  box.innerHTML = '<article class="rounded-2xl bg-slate-50 dark:bg-slate-800 p-4 animate-pulse"><div class="h-4 w-40 bg-slate-200 dark:bg-slate-700 rounded"></div><div class="h-3 w-56 bg-slate-200 dark:bg-slate-700 rounded mt-2"></div><div class="h-3 w-full bg-slate-200 dark:bg-slate-700 rounded mt-3"></div><div class="h-3 w-2/3 bg-slate-200 dark:bg-slate-700 rounded mt-1.5"></div></article>';
  try {
    const d = await npReq('/api/ai-recipe-recommendation', 'POST', {
      mealType: window.__IACTX.slot || 'comida',
      userGoal: profile.goal, targetCaloriesForMeal: Math.round(needs.targetCalories * 0.3),
      allergies: profile.allergies || [], dietaryPreference: profile.dietaryPreference || 'mediterranea',
      cravingText: document.getElementById('ia-craving').value,
      maxMinutes: +document.getElementById('ia-time').value,
      currentWeight: profile.currentWeight, targetWeight: profile.targetWeight, age: profile.age,
      isChildOrTeen: !!profile.isChildOrTeen });
    msg.textContent = d.isAiGenerated ? 'Generadas con IA.' : 'Generadas localmente (sin clave de IA).';
    box.innerHTML = '';
    d.recipes.forEach((r) => {
      const el = document.createElement('article');
      el.className = 'rounded-2xl bg-slate-50 dark:bg-slate-800 p-4';
      el.innerHTML = `<h3 class="font-bold">${r.imageEmoji || 'ðŸ²'} ${r.title}</h3>
        <p class="text-xs text-slate-500">${r.subtitle || ''} Â· ${r.calories} kcal Â· <i data-lucide="clock" class="inline h-3.5 w-3.5"></i> ${r.prepTimeMinutes} min</p>
        <ul class="text-xs mt-1 space-y-0.5">${(r.ingredients || []).map((g) => `<li>Â· ${g.quantity} ${g.unit} ${g.name}</li>`).join('')}</ul>
        <div class="flex gap-2 mt-2"><button class="ia-assign px-3 py-1.5 rounded-full bg-emerald-600 text-white text-xs font-bold">Asignar a este dÃ­a</button></div>`;
      el.querySelector('.ia-assign').onclick = async () => {
        const dia = window.__IACTX.dia !== null ? window.__IACTX.dia : todayIdx();
        const slot = window.__IACTX.slot || 'comida';
        await npReq('/api/plan/apply', 'POST', { dayNumber: dia + 1, slotId: slot, recipe: r });
        location.href = '/?tab=plan&dia=' + dia;
      };
      box.appendChild(el);
    });
    if (window.lucide) lucide.createIcons();
  } catch (err) { msg.textContent = err.message; box.innerHTML = ''; toast(err.message, { error: true }); }
  finally { busy(iaGo, false); }
});

// PWA: registro del SW + instalaciÃ³n (banner y botÃ³n en Ajustes).
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register(SW_URL).catch(() => {}));
}
(() => {
  const bar = document.getElementById('install-banner');
  const settingsBtn = document.getElementById('install-app');
  const settingsState = document.getElementById('install-state');
  const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent || '');
  let deferred = null, done = false;
  try { done = localStorage.getItem('np_install_dismissed') === '1'; } catch (e) {}
  const sub = document.getElementById('install-sub'), go = document.getElementById('install-go');

  const hide = (remember) => {
    if (bar) bar.classList.add('hidden');
    if (remember) { try { localStorage.setItem('np_install_dismissed', '1'); } catch (e) {} }
  };

  const install = async () => {
    if (standalone) { toast('Ya estÃ¡ instalada en este dispositivo.'); return; }
    if (!deferred) {
      if (isIOS) toast('En iPhone/iPad: Compartir â†’ Â«AÃ±adir a pantalla de inicioÂ».', { timeout: 9000 });
      else toast('Abre el menÃº del navegador (â‹®) â†’ Â«Instalar aplicaciÃ³nÂ».', { timeout: 9000 });
      return;
    }
    deferred.prompt();
    try { await deferred.userChoice; } catch (e) {}
    deferred = null;
    hide(true);
  };

  if (settingsBtn) settingsBtn.onclick = install;
  if (settingsState && standalone) settingsState.textContent = 'Ya instalada en este dispositivo.';

  if (bar) {
    if (!standalone) {
      document.getElementById('install-no').onclick = () => hide(true);
      if (go) go.onclick = install;
      if (isIOS && !('BeforeInstallPromptEvent' in window) && !done) {
        sub.textContent = 'En iPhone: Compartir â†’ Â«AÃ±adir a pantalla de inicioÂ».';
        go.textContent = 'Entendido';
        go.onclick = () => hide(true);
        bar.classList.remove('hidden');
        if (window.lucide) lucide.createIcons();
      }
    }
  }

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferred = e;
    if (settingsState) settingsState.textContent = 'Lista para instalar con un clic.';
    if (bar && !done && !standalone) { bar.classList.remove('hidden'); if (window.lucide) lucide.createIcons(); }
  });
  window.addEventListener('appinstalled', () => {
    hide(true);
    if (settingsState) settingsState.textContent = 'Instalada en este dispositivo.';
    toast('AplicaciÃ³n instalada.');
  });
})();

// ActualizaciÃ³n desde GitHub (solo usuarios con sesiÃ³n).
(() => {
  const chk = document.getElementById('upd-check');
  if (!chk) return;
  const st = document.getElementById('upd-state'), run = document.getElementById('upd-run'), msg = document.getElementById('upd-msg');
  chk.onclick = async () => {
    st.textContent = 'comprobandoâ€¦'; msg.textContent = ''; msg.style.color = '';
    try {
      const d = await npReq('/api/update', 'GET');
      st.textContent = 'instalada ' + d.current + ' Â· Ãºltima ' + d.latest;
      if (d.available) {
        msg.textContent = (d.notes || '').slice(0, 300);
        run.classList.remove('hidden');
      } else { msg.textContent = 'Ya estÃ¡s en la Ãºltima versiÃ³n.'; run.classList.add('hidden'); }
    } catch (err) { st.textContent = 'error'; msg.textContent = err.message; }
  };
  run.onclick = async () => {
    if (!await askConfirm('Se descargarÃ¡ la Ãºltima versiÃ³n y se aplicarÃ¡. Â¿Continuar?', 'Actualizar')) return;
    busy(run, true);
    msg.style.color = ''; msg.textContent = 'Actualizandoâ€¦ puede tardar un minuto.';
    try {
      const d = await npReq('/api/update', 'POST');
      msg.style.color = '#059669'; msg.textContent = d.message;
      st.textContent = 'instalada ' + (d.to || d.current || '');
      run.classList.add('hidden');
      toast('ActualizaciÃ³n aplicada.');
    } catch (err) { msg.style.color = '#dc2626'; msg.textContent = err.message; toast(err.message, { error: true }); }
    finally { busy(run, false); }
  };
})();

// Recetario: filtros y bÃºsqueda reflejados en la URL, sin recargar (debounce en el texto).
(() => {
  const form = document.getElementById('recetas-form');
  if (!form) return;
  const box = document.getElementById('recetas-results');
  let timer = null, seq = 0;

  const apply = async () => {
    const params = new URLSearchParams(new FormData(form));
    params.delete('n');
    const my = ++seq;
    box.classList.add('opacity-60', 'animate-pulse');
    try {
      const r = await fetch('/?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
      const fresh = doc.getElementById('recetas-results');
      if (my !== seq) return;
      if (fresh) box.innerHTML = fresh.innerHTML;
      history.replaceState(null, '', '/?' + params.toString());
      if (window.lucide) lucide.createIcons();
    } catch (e) {
      toast('No se pudieron aplicar los filtros.', { error: true });
    } finally {
      if (my === seq) box.classList.remove('opacity-60', 'animate-pulse');
    }
  };

  form.addEventListener('submit', (e) => { e.preventDefault(); apply(); });
  form.querySelectorAll('select').forEach((s) => s.addEventListener('change', apply));
  const q = form.querySelector('input[name="q"]');
  if (q) q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(apply, 350); });
})();
