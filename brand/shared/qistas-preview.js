/* Qistas dashboard preview — a faithful, themeable mock of the home screen.
 * Used by the Theme Studio (live preview), the intro and the brand guidelines.
 * API: QistasPreview.render(el, { tokens, mode, lang, country, copy, motifs, numerals })
 */
(function (root) {
  'use strict';
  var Q = root.QistasTheme, I = root.QIcon, Logo = root.QistasLogo;

  var I18N = {
    en: { dir: 'ltr', hello: 'Good morning', name: 'Layla Haddad', search: 'Search customers, contracts…',
      tiles: ['Customer', 'Contract', 'Payment', 'Report'], outstanding: 'Total outstanding', collected: 'Collected this month',
      overdue: 'Overdue', rate: 'Collection rate', due: 'Due today', seeAll: 'See all', paid: 'Paid', soon: 'Due', late: 'Overdue',
      demo: 'Demo plan', demoUsed: '3 of 5 customers used', upgrade: 'Upgrade', nav: ['Home', 'Customers', 'Reports', 'Settings'],
      people: ['Ahmed Al-Fares', 'Mona Salem', 'Khalid Otaibi'], items: ['65" TV · 5 of 12', 'Sofa set · 2 of 6', 'Gold ring · 9 of 10'] },
    ar: { dir: 'rtl', hello: 'صباح الخير', name: 'ليلى حداد', search: 'ابحث عن عميل أو عقد…',
      tiles: ['عميل', 'عقد', 'دفعة', 'تقرير'], outstanding: 'إجمالي المستحق', collected: 'المحصّل هذا الشهر',
      overdue: 'المتأخر', rate: 'نسبة التحصيل', due: 'مستحق اليوم', seeAll: 'عرض الكل', paid: 'مدفوع', soon: 'مستحق', late: 'متأخر',
      demo: 'الباقة التجريبية', demoUsed: '3 من 5 عملاء', upgrade: 'ترقية', nav: ['الرئيسية', 'العملاء', 'التقارير', 'الإعدادات'],
      people: ['أحمد الفارس', 'منى السالم', 'خالد العتيبي'], items: ['تلفزيون 65 · 5 من 12', 'طقم كنب · 2 من 6', 'خاتم ذهب · 9 من 10'] },
    fr: { dir: 'ltr', hello: 'Bonjour', name: 'Layla Haddad', search: 'Rechercher clients, contrats…',
      tiles: ['Client', 'Contrat', 'Paiement', 'Rapport'], outstanding: 'Total à encaisser', collected: 'Encaissé ce mois-ci',
      overdue: 'En retard', rate: "Taux d'encaissement", due: "À encaisser aujourd'hui", seeAll: 'Tout voir', paid: 'Payé', soon: 'À payer', late: 'En retard',
      demo: 'Offre démo', demoUsed: '3 clients sur 5 utilisés', upgrade: 'Passer Pro', nav: ['Accueil', 'Clients', 'Rapports', 'Réglages'],
      people: ['Ahmed Al-Fares', 'Mona Salem', 'Khalid Otaibi'], items: ['TV 65" · 5 sur 12', 'Canapé · 2 sur 6', 'Bague en or · 9 sur 10'] },
    es: { dir: 'ltr', hello: 'Buenos días', name: 'Layla Haddad', search: 'Buscar clientes, contratos…',
      tiles: ['Cliente', 'Contrato', 'Pago', 'Informe'], outstanding: 'Total pendiente', collected: 'Cobrado este mes',
      overdue: 'Vencido', rate: 'Tasa de cobro', due: 'Vence hoy', seeAll: 'Ver todo', paid: 'Pagado', soon: 'Pendiente', late: 'Vencido',
      demo: 'Plan demo', demoUsed: '3 de 5 clientes usados', upgrade: 'Mejorar', nav: ['Inicio', 'Clientes', 'Informes', 'Ajustes'],
      people: ['Ahmed Al-Fares', 'Mona Salem', 'Khalid Otaibi'], items: ['TV 65" · 5 de 12', 'Sofá · 2 de 6', 'Anillo de oro · 9 de 10'] },
    ur: { dir: 'rtl', hello: 'صبح بخیر', name: 'لیلیٰ حداد', search: 'گاہک یا معاہدہ تلاش کریں…',
      tiles: ['گاہک', 'معاہدہ', 'ادائیگی', 'رپورٹ'], outstanding: 'کل واجب الادا', collected: 'اس ماہ وصول شدہ',
      overdue: 'تاخیر شدہ', rate: 'وصولی کی شرح', due: 'آج واجب الادا', seeAll: 'سب دیکھیں', paid: 'ادا شدہ', soon: 'واجب', late: 'تاخیر',
      demo: 'ڈیمو پلان', demoUsed: '5 میں سے 3 گاہک استعمال', upgrade: 'اپ گریڈ', nav: ['ہوم', 'گاہک', 'رپورٹس', 'سیٹنگز'],
      people: ['احمد خان', 'منیٰ سلیم', 'خالد علی'], items: ['ٹی وی 65 · 5 از 12', 'صوفہ سیٹ · 2 از 6', 'سونے کی انگوٹھی · 9 از 10'] }
  };

  var MOTIFS = {
    crescent: '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M70 10A40 40 0 1 0 70 90A60 60 0 0 1 70 10Z"/><path d="M80 34l2.4 6.6 6.8.2-5.4 4.2 1.9 6.6-5.7-3.9-5.7 3.9 1.9-6.6-5.4-4.2 6.8-.2z"/></svg>',
    sparkle: '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M50 6 58 42 94 50 58 58 50 94 42 58 6 50 42 42Z"/><path d="M82 10 85 20 95 23 85 26 82 36 79 26 69 23 79 20Z"/></svg>',
    bars: '<svg viewBox="0 0 100 100" aria-hidden="true"><path d="M0 18h100v14H0zM0 43h100v14H0zM0 68h100v14H0z"/></svg>'
  };

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }

  function money(n, country, numerals, lang) {
    var meta = Q.COUNTRIES[country] || { currency: 'USD' };
    var loc = (lang || 'en') + '-u-nu-' + (numerals === 'arab' ? 'arab' : 'latn');
    try { return new Intl.NumberFormat(loc, { style: 'currency', currency: meta.currency, maximumFractionDigits: 0 }).format(n); }
    catch (e) { return n + ' ' + meta.currency; }
  }
  function num(n, lang, numerals) {
    try { return new Intl.NumberFormat((lang || 'en') + '-u-nu-' + (numerals === 'arab' ? 'arab' : 'latn')).format(n); } catch (e) { return String(n); }
  }
  function initials(name) {
    var parts = name.split(/\s+/);
    return (parts[0].charAt(0) + (parts[1] ? parts[1].charAt(0) : '')).toUpperCase();
  }

  function render(el, o) {
    var lang = I18N[o.lang] ? o.lang : 'en';
    var t = I18N[lang];
    var tokens = o.tokens, mode = o.mode || 'light';
    var greeting = Q.t(o.copy || {}, 'greeting', lang, '');
    var amt = function (n) { return money(n, o.country, o.numerals, lang); };
    var motifs = (o.motifs || []).filter(function (m) { return MOTIFS[m]; });

    var tileIcons = ['userPlus', 'fileText', 'wallet', 'chart'];
    var tileTints = ['sky', 'sand', 'mint', 'blush'];
    var rows = [
      { who: 0, amt: 450, st: 'paid' }, { who: 1, amt: 1200, st: 'soon' }, { who: 2, amt: 320, st: 'late' }
    ];
    var navIcons = ['home', 'users', null, 'chart', 'sliders'];
    var navLabels = [t.nav[0], t.nav[1], '', t.nav[2], t.nav[3]];

    var html =
      '<div class="qp-phone" dir="' + t.dir + '" lang="' + lang + '">' +
      '<div class="qp-screen" data-q-mode="' + mode + '">' +
        '<div class="qp-status" aria-hidden="true"><span class="tnum">9:41</span><span class="qp-island"></span><span class="qp-sig"><i></i><i></i><i></i><b></b></span></div>' +
        '<div class="qp-scroll">' +
          '<header class="qp-head">' +
            '<div class="qp-who"><span class="qp-avatar" aria-hidden="true">' + esc(initials(t.name)) + '</span>' +
              '<div><p class="qp-hello">' + (greeting ? '<span class="qp-greet-ic">' + I.svg('sparkles', { size: 13 }) + '</span>' + esc(greeting) : esc(t.hello)) + '</p>' +
              '<h3 class="qp-name">' + esc(t.name) + '</h3></div></div>' +
            '<button class="qp-iconbtn" aria-label="Notifications" tabindex="-1">' + I.svg('bell', { size: 19 }) + '<i class="qp-dot"></i></button>' +
          '</header>' +
          '<div class="qp-search">' + I.svg('search', { size: 17 }) + '<span>' + esc(t.search) + '</span><i>' + I.svg('sliders', { size: 16 }) + '</i></div>' +
          '<div class="qp-tiles">' + t.tiles.map(function (label, i) {
            return '<div class="qp-tile"><span class="qp-tile-ic" style="--tint:var(--q-tint-' + tileTints[i] + ')">' + I.svg(tileIcons[i], { size: 20 }) + '</span><span>' + esc(label) + '</span></div>';
          }).join('') + '</div>' +
          '<section class="qp-hero">' +
            motifs.map(function (m, i) { return '<div class="qp-motif qp-motif-' + m + ' qp-motif-' + i + '">' + MOTIFS[m] + '</div>'; }).join('') +
            '<div class="qp-hero-top"><div><p class="qp-kicker">' + esc(t.outstanding) + '</p><p class="qp-amount tnum">' + esc(amt(128450)) + '</p></div>' +
              '<div class="qp-ring" role="img" aria-label="' + esc(t.rate) + ' 86%"><svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="20" class="qp-ring-bg"/><circle cx="24" cy="24" r="20" class="qp-ring-fg" pathLength="100" stroke-dasharray="86 100" transform="rotate(-90 24 24)"/></svg><b class="tnum">' + esc(num(86, lang, o.numerals)) + '%</b></div></div>' +
            '<div class="qp-stats"><div><span><i class="up">' + I.svg('trendUp', { size: 13 }) + '</i>' + esc(t.collected) + '</span><b class="tnum">' + esc(amt(42300)) + '</b></div>' +
              '<div><span>' + esc(t.overdue) + '</span><b class="tnum">' + esc(amt(6120)) + '</b></div></div>' +
          '</section>' +
          '<div class="qp-demo"><div><b>' + esc(t.demo) + '</b><span>' + esc(t.demoUsed) + '</span><div class="qp-meter"><i style="width:60%"></i></div></div><span class="qp-pill-btn">' + esc(t.upgrade) + '</span></div>' +
          '<section class="qp-list"><div class="qp-list-head"><h4>' + esc(t.due) + '</h4><a tabindex="-1">' + esc(t.seeAll) + '</a></div>' +
            rows.map(function (r) {
              return '<div class="qp-row"><span class="qp-av2" aria-hidden="true">' + esc(initials(t.people[r.who])) + '</span>' +
                '<div class="qp-row-main"><b>' + esc(t.people[r.who]) + '</b><span>' + esc(t.items[r.who]) + '</span></div>' +
                '<div class="qp-row-end"><b class="tnum">' + esc(amt(r.amt)) + '</b><em class="qp-chip ' + r.st + '">' +
                (r.st === 'paid' ? I.svg('check', { size: 11, stroke: 2 }) : r.st === 'late' ? I.svg('alert', { size: 11, stroke: 2 }) : I.svg('clock', { size: 11, stroke: 2 })) +
                esc(t[r.st]) + '</em></div></div>';
            }).join('') +
          '</section>' +
        '</div>' +
        '<nav class="qp-nav" aria-hidden="true">' + navIcons.map(function (ic, i) {
          if (!ic) return '<span class="qp-fab">' + I.svg('plus', { size: 22, stroke: 2 }) + '</span>';
          return '<span class="qp-nav-i' + (i === 0 ? ' on' : '') + '">' + I.svg(ic, { size: 20 }) + '<small>' + esc(navLabels[i]) + '</small></span>';
        }).join('') + '</nav>' +
      '</div></div>';

    el.innerHTML = html;
    var screen = el.querySelector('.qp-screen');
    Q.applyToDOM(screen, tokens, mode);
    return screen;
  }

  root.QistasPreview = { render: render, I18N: I18N, MOTIFS: MOTIFS };
})(typeof self !== 'undefined' ? self : this);
