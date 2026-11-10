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

// Modales (guía clínica + ajustes Mistral)
const openModal = (id) => { const m = document.getElementById(id); if (m) { m.classList.remove('hidden'); if (window.lucide) lucide.createIcons(); } };
const closeModals = () => document.querySelectorAll('#modal-guidelines, #modal-settings').forEach((m) => m.classList.add('hidden'));
document.querySelectorAll('[data-open-guidelines]').forEach((b) => b.addEventListener('click', () => openModal('modal-guidelines')));
document.querySelectorAll('[data-open-settings]').forEach((b) => b.addEventListener('click', () => openModal('modal-settings')));
document.querySelectorAll('[data-modal-close]').forEach((b) => b.addEventListener('click', closeModals));
document.querySelectorAll('#modal-guidelines, #modal-settings').forEach((m) => m.addEventListener('click', (e) => { if (e.target === m) closeModals(); }));
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModals(); });

// Interacciones NutriPlan (fetch a la API JSON + estado local).
async function npReq(url, method, body) {
  const r = await fetch(url, { method, headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined });
  const d = await r.json().catch(() => ({}));
  if (!d.success) throw new Error(d.error || ('Error ' + r.status));
  return d;
}
const todayIdx = () => (new Date().getDay() + 6) % 7;

// Re-render parcial: sustituye solo las regiones del plan sin recargar la página.
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
      await npReq('/api/plan/swap', 'POST', { dayNumber: +b.dataset.dia + 1, slotId: b.dataset.slot });
      await refreshPlanRegions();
    } else if (act === 'regen') {
      if (!confirm('¿Regenerar el plan de la semana? Se pierden los completados.')) return;
      await npReq('/api/plan/regenerate', 'POST');
      await refreshPlanRegions();
    } else if (act === 'apply' || act === 'apply-hoy') {
      const recipe = JSON.parse(b.dataset.recipe);
      const dia = b.dataset.dia !== undefined && b.dataset.dia !== '' ? +b.dataset.dia : todayIdx();
      await npReq('/api/plan/apply', 'POST', { dayNumber: dia + 1, slotId: b.dataset.slot, recipe });
      if (act === 'apply-hoy') {
        b.disabled = true;
        b.innerHTML = '<i data-lucide="check" class="w-3.5 h-3.5"></i><span>En tu menú</span>';
        if (window.lucide) lucide.createIcons();
      } else {
        location.href = b.dataset.back || '/';
      }
    } else if (act === 'wdel') {
      if (!confirm('¿Borrar este registro?')) return;
      await npReq('/api/weight', 'DELETE', { date: b.dataset.date });
      location.reload();
    } else if (act === 'logout') {
      await npReq('/api/auth/logout', 'POST');
      location.href = '/';
    } else if (b.hasAttribute('data-ms')) {
      const msg = document.getElementById('ms-msg');
      const model = document.getElementById('ms-model').value;
      msg.textContent = '…';
      await npReq('/api/settings/mistral', 'POST', { model });
      msg.textContent = 'Guardado.';
    }
  } catch (err) { alert(err.message); }
});

// Formularios: peso, nombre, onboarding
const wf = document.getElementById('weight-form');
if (wf) wf.addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await npReq('/api/weight', 'POST', { date: document.getElementById('w-date').value,
      weight: parseFloat(document.getElementById('w-weight').value),
      note: document.getElementById('w-note').value });
    location.reload();
  } catch (err) { alert(err.message); }
});

const nf = document.getElementById('name-form');
if (nf) nf.addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const d = await npReq('/api/profile', 'GET');
    const p = Object.assign({}, d.profile, { name: document.getElementById('profile-name').value });
    await npReq('/api/profile', 'PUT', { profile: p });
    location.reload();
  } catch (err) { alert(err.message); }
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
    document.getElementById('imc-val').textContent = (w > 0 && h > 0) ? (w / ((h / 100) ** 2)).toFixed(1) : '–';
    try {
      const q = new URLSearchParams({ goal: f.get('goal'), targetWeeks: f.get('targetWeeks'), gender: f.get('gender'), age: f.get('age'), height: f.get('height'), currentWeight: f.get('currentWeight'), targetWeight: f.get('targetWeight'), activityLevel: f.get('activityLevel') });
      const d = await (await fetch('/api/calc?' + q.toString())).json();
      if (!d.calc) return;
      const c = d.calc;
      document.getElementById('calc-bmr').textContent = c.bmr;
      document.getElementById('calc-tdee').textContent = c.tdee;
      document.getElementById('calc-kcal').textContent = c.targetCalories;
      document.getElementById('calc-sub').textContent = c.deficitOrSurplus > 0 ? '+' + c.deficitOrSurplus + ' superávit' : (c.deficitOrSurplus < 0 ? c.deficitOrSurplus + ' déficit' : 'Mantenimiento');
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
  document.getElementById('shop-reset').onclick = () => { localStorage.removeItem('np_shop'); location.reload(); };
  document.getElementById('shop-copy').onclick = async () => {
    let txt = '🛒 Lista de la compra — NutriPlan\n', h = '';
    shop.querySelectorAll('h3, .shop-item').forEach((el) => {
      if (el.tagName === 'H3') { h = el.textContent.trim(); txt += '\n' + h + '\n'; }
      else if (el.style.display !== 'none') txt += '· ' + el.querySelector('span').textContent.trim().replace(/\s+/g, ' ') + '\n';
    });
    await navigator.clipboard.writeText(txt).catch(() => {});
    alert('Lista copiada.');
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
      const reg = await navigator.serviceWorker.register('/sw.js');
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(vapid) });
      await npReq('/api/push/subscribe', 'POST', { subscription: sub.toJSON() });
      st.textContent = 'activadas ✓'; pushBtn.textContent = 'Probar aviso';
      pushBtn.onclick = async () => { await npReq('/api/push/test', 'POST', { endpoint: sub.endpoint }); alert('Aviso enviado.'); };
    } catch (err) { alert('No se pudo activar: ' + err.message); }
  };
}

// IA
const iaGo = document.getElementById('ia-go');
if (iaGo) iaGo.addEventListener('click', async () => {
  const msg = document.getElementById('ia-msg'), box = document.getElementById('ia-results');
  const profile = JSON.parse(document.getElementById('px').textContent);
  const needs = JSON.parse(document.getElementById('nx').textContent);
  msg.textContent = 'Generando…'; box.innerHTML = '';
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
    d.recipes.forEach((r) => {
      const el = document.createElement('article');
      el.className = 'rounded-2xl bg-slate-50 dark:bg-slate-800 p-4';
      el.innerHTML = `<h3 class="font-bold">${r.imageEmoji || '🍲'} ${r.title}</h3>
        <p class="text-xs text-slate-500">${r.subtitle || ''} · ${r.calories} kcal · <i data-lucide="clock" class="inline h-3.5 w-3.5"></i> ${r.prepTimeMinutes} min</p>
        <ul class="text-xs mt-1 space-y-0.5">${(r.ingredients || []).map((g) => `<li>· ${g.quantity} ${g.unit} ${g.name}</li>`).join('')}</ul>
        <div class="flex gap-2 mt-2"><button class="ia-assign px-3 py-1.5 rounded-full bg-emerald-600 text-white text-xs font-bold">Asignar a este día</button></div>`;
      el.querySelector('.ia-assign').onclick = async () => {
        const dia = window.__IACTX.dia !== null ? window.__IACTX.dia : todayIdx();
        const slot = window.__IACTX.slot || 'comida';
        await npReq('/api/plan/apply', 'POST', { dayNumber: dia + 1, slotId: slot, recipe: r });
        location.href = '/?tab=plan&dia=' + dia;
      };
      box.appendChild(el);
    });
    if (window.lucide) lucide.createIcons();
  } catch (err) { msg.textContent = err.message; }
});

// PWA: registro del SW + popup de instalación.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}
(() => {
  const bar = document.getElementById('install-banner');
  if (!bar) return;
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) return;
  let deferred = null, done = false;
  try { done = localStorage.getItem('np_install_dismissed') === '1'; } catch (e) {}
  const sub = document.getElementById('install-sub'), go = document.getElementById('install-go');
  const hide = (remember) => { bar.classList.add('hidden'); if (remember) { try { localStorage.setItem('np_install_dismissed', '1'); } catch (e) {} } };
  document.getElementById('install-no').onclick = () => hide(true);
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent || '');
  if (isIOS && !('BeforeInstallPromptEvent' in window)) {
    if (!done) {
      sub.textContent = 'En iPhone: Compartir → «Añadir a pantalla de inicio».';
      go.textContent = 'Entendido'; go.onclick = () => hide(true);
      bar.classList.remove('hidden'); if (window.lucide) lucide.createIcons();
    }
    return;
  }
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); deferred = e;
    if (!done) { bar.classList.remove('hidden'); if (window.lucide) lucide.createIcons(); }
  });
  go.onclick = async () => {
    if (!deferred) { hide(false); return; }
    deferred.prompt();
    try { await deferred.userChoice; } catch (e) {}
    deferred = null; hide(true);
  };
  window.addEventListener('appinstalled', () => hide(true));
})();

// Actualización desde GitHub (solo usuarios con sesión).
(() => {
  const chk = document.getElementById('upd-check');
  if (!chk) return;
  const st = document.getElementById('upd-state'), run = document.getElementById('upd-run'), msg = document.getElementById('upd-msg');
  chk.onclick = async () => {
    st.textContent = 'comprobando…'; msg.textContent = ''; msg.style.color = '';
    try {
      const d = await npReq('/api/update', 'GET');
      st.textContent = 'instalada ' + d.current + ' · última ' + d.latest;
      if (d.available) {
        msg.textContent = (d.notes || '').slice(0, 300);
        run.classList.remove('hidden');
      } else { msg.textContent = 'Ya estás en la última versión.'; run.classList.add('hidden'); }
    } catch (err) { st.textContent = 'error'; msg.textContent = err.message; }
  };
  run.onclick = async () => {
    if (!confirm('Se descargará la última versión y se aplicará. ¿Continuar?')) return;
    msg.style.color = ''; msg.textContent = 'Actualizando… puede tardar un minuto.';
    try {
      const d = await npReq('/api/update', 'POST');
      msg.style.color = '#059669'; msg.textContent = d.message;
      st.textContent = 'instalada ' + (d.to || d.current || '');
      run.classList.add('hidden');
    } catch (err) { msg.style.color = '#dc2626'; msg.textContent = err.message; }
  };
})();
