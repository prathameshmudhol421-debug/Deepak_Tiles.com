/* =====================================================
   Depak Tiles & Granite — single-page app
   Talks to PHP API at /api/*. PHP session holds the shopkeeper login.

   Visitors don't need to sign up. They provide a display name + an email
   (kept private — only its SHA-256 hash is stored on the server) to leave
   likes, comments, shares, and ratings. The shop owner sees everyone's
   feedback in the dashboard and can reply to any comment or rating.
   ===================================================== */

const API = {
  auth:         'api/auth.php',
  products:     'api/blogs.php',  // filename kept for compatibility
  blogs:        'api/blogs.php?kind=blog',
  upload:       'api/upload.php',
  interactions: 'api/interactions.php',
};

/* ===== App state ===== */
let SHOPKEEPER       = null;
let SHOP_PROFILE     = null;
let ALL_PRODUCTS     = [];
let ALL_BLOGS        = [];
let EDIT_PRODUCT_ID  = null;
let EDIT_BLOG_ID     = null;
let CURRENT_PRODUCT  = null;     // product being viewed
let CURRENT_BLOG     = null;

/* ===== Guest identity (localStorage) =====
 * Visitors don't sign up. Their name + email is stored locally so they
 * don't have to retype it on every comment, like, share, or rating. The
 * server only ever sees the SHA-256 hash of the email. */
const GUEST_KEY = 'spider_guest_v1';

function getGuestIdentity() {
  try {
    const raw = localStorage.getItem(GUEST_KEY);
    if (!raw) return { name: '', email: '' };
    const parsed = JSON.parse(raw);
    return {
      name:  String(parsed.name  || '').slice(0, 80),
      email: String(parsed.email || '').slice(0, 190),
    };
  } catch {
    return { name: '', email: '' };
  }
}

function setGuestIdentity(name, email) {
  const v = {
    name:  String(name  || '').slice(0, 80),
    email: String(email || '').slice(0, 190),
  };
  try { localStorage.setItem(GUEST_KEY, JSON.stringify(v)); } catch {}
  return v;
}

function clearGuestIdentity() {
  try { localStorage.removeItem(GUEST_KEY); } catch {}
}

/* ===== Mobile hamburger menu ===== */
(function setupHamburger() {
  const navRight     = document.getElementById('navRight');
  const hamburgerBtn = document.getElementById('hamburgerBtn');
  if (!navRight || !hamburgerBtn) return;

  const setOpen = (open) => {
    navRight.classList.toggle('open', open);
    hamburgerBtn.classList.toggle('open', open);
    hamburgerBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  };

  hamburgerBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    setOpen(!navRight.classList.contains('open'));
  });

  navRight.addEventListener('click', (e) => {
    if (e.target.closest('.nav-btn')) setOpen(false);
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.navbar') && navRight.classList.contains('open')) {
      setOpen(false);
    }
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 768) setOpen(false);
  });
})();

/* ===== View switcher ===== */
const views = document.querySelectorAll('.view');
function showView(name) {
  views.forEach(v => v.classList.remove('active'));
  const target = document.getElementById('view-' + name);
  if (target) target.classList.add('active');
  window.scrollTo({ top: 0, behavior: 'smooth' });
  if (name === 'home')           loadHome();
  if (name === 'shopProfile')    loadShopProfilePublic();
  if (name === 'rate')           loadRateView();
  if (name === 'myReviews')      loadMyReviewsView();
  if (name === 'shopDashboard' && !SHOPKEEPER) { showView('shopLogin'); return; }
  if (name === 'shopDashboard')  loadDashboard('products');
}

document.addEventListener('click', (e) => {
  const t = e.target.closest('[data-view]');
  if (!t) return;
  e.preventDefault();
  const view = t.dataset.view;
  if (view === 'home' || view === 'shopProfile' || view === 'shopLogin'
      || view === 'rate' || view === 'myReviews') {
    showView(view);
    return;
  }
  if (view === 'shopDashboard') {
    if (!SHOPKEEPER) { showView('shopLogin'); return; }
    showView('shopDashboard');
    return;
  }
});

/* ===== Toast ===== */
function showToast(message, isError = false) {
  const toast = document.getElementById('toast');
  if (!toast) return;
  toast.textContent = message;
  toast.style.background = isError ? '#d32f2f' : '#1a8917';
  toast.classList.add('show');
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => toast.classList.remove('show'), 2500);
}

/* ===== Form error helper ===== */
function setError(input, message) {
  const errorEl = input.parentElement.querySelector('.error-message');
  if (message) {
    input.classList.add('error');
    if (errorEl) { errorEl.textContent = message; errorEl.classList.add('show'); }
  } else {
    input.classList.remove('error');
    if (errorEl) { errorEl.textContent = ''; errorEl.classList.remove('show'); }
  }
}

function isValidEmail(email) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email); }

/* ===== Reveal-on-scroll ===== */
let _revealObserver = null;
function observeReveal(elements) {
  if (!elements || !elements.length) return;
  if (!_revealObserver) {
    _revealObserver = new IntersectionObserver((entries, obs) => {
      for (const e of entries) {
        if (e.isIntersecting) {
          e.target.classList.add('is-visible');
          obs.unobserve(e.target);
        }
      }
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
  }
  elements.forEach(el => _revealObserver.observe(el));
}

/* ===== API helpers ===== */
let CSRF = null;

async function ensureCsrf() {
  if (CSRF) return CSRF;
  try {
    const r = await fetch(`${API.auth}?action=csrf`, {
      method: 'GET',
      credentials: 'same-origin',
    });
    const data = await r.json().catch(() => ({}));
    if (r.ok && data && data.csrf) {
      CSRF = data.csrf;
      return CSRF;
    }
  } catch {}
  return null;
}

function readCsrfFromResponse(data) {
  if (data && typeof data.csrf === 'string' && data.csrf.length > 0) {
    CSRF = data.csrf;
  }
}

async function api(url, opts = {}) {
  opts.credentials = 'same-origin';
  const method = (opts.method || 'GET').toUpperCase();

  if (opts.body && !(opts.body instanceof FormData) && typeof opts.body !== 'string') {
    opts.headers = { 'Content-Type': 'application/json', ...(opts.headers || {}) };
    opts.body = JSON.stringify(opts.body);
  }

  if (method !== 'GET' && method !== 'HEAD') {
    if (!(opts.body instanceof FormData)) {
      await ensureCsrf();
      opts.headers = { ...(opts.headers || {}) };
      if (CSRF) opts.headers['X-CSRF-Token'] = CSRF;
    } else {
      await ensureCsrf();
      if (CSRF) opts.body.append('csrf', CSRF);
    }
  }

  const res = await fetch(url, opts);
  let data;
  try { data = await res.json(); } catch { data = {}; }
  if (!res.ok) {
    if (res.status === 403 && data.error && /csrf/i.test(data.error)) {
      CSRF = null;
      await ensureCsrf();
    }
    throw new Error(data.error || 'Request failed');
  }
  readCsrfFromResponse(data);
  return data;
}

/* ===== Session refresh ===== */
async function refreshSession() {
  try {
    const s = await api(`${API.auth}?action=shopMe`).catch(() => ({ shopkeeper: null, profile: null }));
    SHOPKEEPER   = s.shopkeeper || null;
    SHOP_PROFILE = s.profile || null;
  } catch {
    SHOPKEEPER = null; SHOP_PROFILE = null;
  }
  updateNavForAuth();
  updateBrand();
}

function updateBrand() {
  const name = (SHOP_PROFILE && SHOP_PROFILE.shop_name) || 'Depak Tiles & Granite';
  const tagline = (SHOP_PROFILE && SHOP_PROFILE.about)
    ? SHOP_PROFILE.about.slice(0, 80)
    : 'Premium tiles, granite and marble for your home';

  const logo     = document.getElementById('navLogo');
  const navName  = document.getElementById('navBrandName');
  const navTag   = document.getElementById('navBrandTag');
  const hero     = document.getElementById('heroShopName');
  const heroTag  = document.getElementById('heroShopTagline');
  const dash     = document.getElementById('dashShopName');
  const footName = document.getElementById('footerShopName');
  const footTag  = document.getElementById('footerShopTag');
  const footerYr = document.getElementById('footerYear');

  if (navName) navName.textContent = name;
  if (navTag)  navTag.textContent  = tagline;
  if (hero)    hero.textContent    = name;
  if (heroTag) heroTag.textContent = tagline;
  if (dash)    dash.textContent    = name;
  if (footName) footName.textContent = name;
  if (footTag)  footTag.textContent  = tagline;
  if (footerYr) footerYr.textContent = new Date().getFullYear();

  document.title = `${name} — Products, Stories & More`;
  const ogDesc = document.getElementById('pageDescription');
  if (ogDesc) ogDesc.setAttribute('content', tagline);
  const ogTitle = document.querySelector('meta[property="og:title"]');
  if (ogTitle) ogTitle.setAttribute('content', name);
}

function updateNavForAuth() {
  const sl = document.getElementById('navShopLoginBtn');
  const sd = document.getElementById('navShopDashboardBtn');
  const so = document.getElementById('navShopLogoutBtn');
  const mr = document.getElementById('navMyReviewsBtn');

  if (SHOPKEEPER) {
    if (sl) sl.style.display = 'none';
    if (sd) sd.style.display = '';
    if (so) so.style.display = '';
  } else {
    if (sl) sl.style.display = '';
    if (sd) sd.style.display = 'none';
    if (so) so.style.display = 'none';
  }

  // Show "My Reviews" only when a guest identity is on file.
  if (mr) {
    const gi = getGuestIdentity();
    mr.style.display = (gi.email ? '' : 'none');
  }
}

/* ===== Shopkeeper login / logout ===== */
document.getElementById('shopLoginForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const u = document.getElementById('shop-login-username');
  const p = document.getElementById('shop-login-password');
  let valid = true;
  if (!u.value.trim()) { setError(u, 'Username is required'); valid = false; } else setError(u, '');
  if (!p.value)        { setError(p, 'Password is required'); valid = false; } else setError(p, '');
  if (!valid) return;

  try {
    const r = await api(`${API.auth}?action=shopLogin`, {
      method: 'POST', body: { username: u.value.trim(), password: p.value }
    });
    SHOPKEEPER = r.shopkeeper || null;

    try {
      const profileRes = await api(`${API.auth}?action=shopProfile`);
      SHOP_PROFILE = profileRes.profile || null;
    } catch (profileErr) {
      console.warn('Shop profile load after login failed:', profileErr.message);
      SHOP_PROFILE = SHOP_PROFILE || null;
    }

    updateNavForAuth();
    updateBrand();
    document.getElementById('shopLoginForm').reset();
    showToast('Welcome, shopkeeper!');
    showView('shopDashboard');
  } catch (err) { showToast(err.message, true); }
});

document.getElementById('navShopLogoutBtn')?.addEventListener('click', async () => {
  try { await api(`${API.auth}?action=shopLogout`, { method: 'POST' }); }
  finally {
    SHOPKEEPER = null;
    updateNavForAuth();
    showToast('Shopkeeper signed out');
    showView('home');
  }
});

/* ===== Public shop profile ===== */
async function loadShopProfilePublic() {
  try {
    const [profile, ratings] = await Promise.all([
      api(`${API.auth}?action=shopProfile`),
      api(`${API.interactions}?action=ratings&product_id=0`).catch(() => ({ summary: { count: 0, average: 0 } })),
    ]);
    SHOP_PROFILE = profile.profile;
    renderShopProfile(SHOP_PROFILE);
    renderShopRatingSummary(ratings.summary);
  } catch (err) {
    showToast(err.message, true);
  }
}

function renderShopProfile(p) {
  if (!p) return;
  document.getElementById('shopProfileName').textContent    = p.shop_name || 'Depak Tiles & Granite';
  document.getElementById('shopProfileOwner').textContent   = p.owner_name ? `Owned by ${p.owner_name}` : 'Owned by Depak';
  document.getElementById('shopProfileAbout').textContent   = p.about || '';
  document.getElementById('shopProfileMobile').textContent  = p.mobile || 'Not provided';
  document.getElementById('shopProfileEmail').textContent   = p.email || 'Not provided';
  document.getElementById('shopProfileLocation').textContent = p.location || 'Not provided';
  document.getElementById('shopProfileNote').textContent    = p.contact_note || 'No additional notes';
  const logo = document.getElementById('shopProfileLogo');
  const fallback = document.getElementById('shopProfileLogoFallback');
  if (fallback) {
    fallback.textContent = (p.shop_name || 'Depak Tiles & Granite').charAt(0).toUpperCase();
  }
}

function renderShopRatingSummary(summary) {
  const starsEl = document.getElementById('shopRatingStars');
  const avgEl   = document.getElementById('shopRatingAverage');
  const cntEl   = document.getElementById('shopRatingCount');
  if (!starsEl || !avgEl || !cntEl) return;

  const avg = Number(summary && summary.average) || 0;
  const n   = Number(summary && summary.count)   || 0;
  avgEl.textContent = avg.toFixed(1);
  starsEl.textContent = starString(avg);
  starsEl.classList.toggle('is-empty', n === 0);
  if (n === 0) {
    cntEl.textContent = 'No reviews yet — be the first to rate us.';
  } else if (n === 1) {
    cntEl.textContent = '1 review';
  } else {
    cntEl.textContent = `${n} reviews`;
  }
}

/* Convert a 0–5 number to a unicode star string. */
function starString(n) {
  const v = Math.max(0, Math.min(5, Math.round(Number(n) || 0)));
  return '★★★★★'.slice(0, v) + '☆☆☆☆☆'.slice(0, 5 - v);
}

/* ===== Home: products + blogs ===== */
async function loadHome() {
  const grid = document.getElementById('productGrid');
  grid.innerHTML = '<p style="color:#757575;">Loading...</p>';
  const blogGrid = document.getElementById('blogFeed');
  if (blogGrid) blogGrid.innerHTML = '<p style="color:#757575;">Loading...</p>';
  try {
    const [pr, br] = await Promise.all([
      api(API.products).catch(() => ({ products: [] })),
      api(API.blogs).catch(() => ({ blogs: [] })),
    ]);
    ALL_PRODUCTS = pr.products || [];
    ALL_BLOGS    = br.blogs    || [];
    renderProductGrid(ALL_PRODUCTS);
    renderBlogFeed(ALL_BLOGS);
  } catch (err) {
    grid.innerHTML = `<p style="color:#d32f2f;">Failed to load: ${err.message}</p>`;
  }
}

function renderProductGrid(products) {
  const grid = document.getElementById('productGrid');
  if (!products.length) {
    grid.innerHTML = '<p style="color:#757575;">No products yet. The shopkeeper has not posted anything.</p>';
    return;
  }
  grid.innerHTML = products.map(p => `
    <article class="product-card" data-product-id="${p.id}">
      <div class="product-thumb" style="background-image:url('${escapeAttr(p.image_path || defaultCover(p.title))}');" onerror="this.style.background='linear-gradient(135deg,#a8e063,#56ab2f)'"></div>
      <div class="product-card-body">
        <h3 class="product-card-title">${escapeHtml(p.title)}</h3>
        <p class="product-card-excerpt">${escapeHtml(p.body).slice(0, 140)}${p.body.length > 140 ? '…' : ''}</p>
        <div class="product-card-foot">
          <span class="product-price">₹${formatPrice(p.price)}</span>
          <span class="product-date">${formatDate(p.created_at)}</span>
        </div>
      </div>
    </article>
  `).join('');
  grid.querySelectorAll('.product-card').forEach(card => {
    card.addEventListener('click', () => openProduct(parseInt(card.dataset.productId, 10)));
  });
  observeReveal(grid.querySelectorAll('.product-card'));
}

/* ===== Product detail ===== */
async function openProduct(id) {
  const wrap = document.getElementById('productDetail');
  showView('product');
  wrap.innerHTML = '<p style="color:#757575;">Loading...</p>';
  try {
    const gi = getGuestIdentity();
    const [r, c] = await Promise.all([
      api(`${API.products}?id=${id}`),
      api(`${API.interactions}?action=counts&product_id=${id}&email=${encodeURIComponent(gi.email)}`)
        .catch(() => ({ likes:0, comments:0, shares:0, liked:false })),
    ]);
    CURRENT_PRODUCT = r.product;
    renderProductDetail(r.product, c);
  } catch (err) {
    wrap.innerHTML = `<p style="color:#d32f2f;">${err.message}</p>`;
  }
}

function renderProductDetail(p, counts) {
  const wrap = document.getElementById('productDetail');
  const img = p.image_path || defaultCover(p.title);
  const canManage = !!SHOPKEEPER;
  wrap.innerHTML = `
    <button class="back-btn" onclick="document.querySelector('[data-view=home]').click()">← Back to products</button>
    <h1 class="article-title">${escapeHtml(p.title)}</h1>
    <div class="product-meta-row">
      <span class="read-time">${formatDate(p.created_at)}</span>
      <span class="product-price-lg">₹${formatPrice(p.price)}</span>
    </div>
    <img src="${escapeAttr(img)}" alt="" class="featured-image" onerror="this.src='${defaultCover(p.title)}'" />
    <div class="article-content">${formatBody(p.body)}</div>

    <div class="action-bar">
      <div class="action-left">
        <button class="action-item ${counts.liked ? 'liked' : ''}" id="likeBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
          </svg>
          <span id="likeCount">${counts.likes}</span>
        </button>
        <button class="action-item" id="commentBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          <span id="commentCount">${counts.comments}</span> Comments
        </button>
        <button class="action-item" id="shareBtn" title="Share">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
          </svg>
          Share
        </button>
        <button class="action-item" id="rateProductBtn" title="Rate this product">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
          </svg>
          Rate
        </button>
      </div>
      ${canManage ? `
      <div class="action-right">
        <button class="follow-btn" id="editProductBtn">Edit</button>
        <button class="follow-btn" id="deleteProductBtn" style="border-color:#d32f2f;color:#d32f2f;margin-left:8px;">Delete</button>
      </div>` : ''}
    </div>
  `;

  document.getElementById('likeBtn').addEventListener('click', () => onLike());
  document.getElementById('commentBtn').addEventListener('click', () => openComments(p.id));
  document.getElementById('shareBtn').addEventListener('click', () => onShare());
  document.getElementById('rateProductBtn').addEventListener('click', () => {
    showView('rate');
    // Pre-select this product in the rate form
    const radio = document.querySelector('input[name="rate-scope"][value="product"]');
    if (radio) {
      radio.checked = true;
      const sel = document.getElementById('rateProductSelect');
      if (sel) {
        sel.style.display = '';
        sel.value = String(p.id);
      }
    }
  });

  if (canManage) {
    document.getElementById('editProductBtn').addEventListener('click', () => startEditProduct(p.id));
    document.getElementById('deleteProductBtn').addEventListener('click', () => deleteProduct(p.id));
  }
}

/* ===== Like / share (guest-friendly) =====
 * We require a guest identity to like or share so the count doesn't
 * double when the same visitor hits Like twice. Email is hashed on the
 * server; we never store the raw email alongside the like.
 */
function requireGuestIdentity() {
  const gi = getGuestIdentity();
  if (!gi.name || !isValidEmail(gi.email)) {
    // Pop open the comments view which contains the name + email form.
    // Falling back to the same form keeps the UX consistent: fill the
    // name/email, then the action completes.
    showToast('Add your name and email to continue', true);
    openComments(CURRENT_PRODUCT ? CURRENT_PRODUCT.id : (CURRENT_BLOG ? CURRENT_BLOG.id : null));
    // Pre-fill the inputs so the visitor can confirm and retry.
    const nameEl  = document.getElementById('guestNameInput');
    const emailEl = document.getElementById('guestEmailInput');
    if (nameEl  && !nameEl.value)  nameEl.value  = gi.name;
    if (emailEl && !emailEl.value) emailEl.value = gi.email;
    return null;
  }
  return gi;
}

async function onLike() {
  if (!CURRENT_PRODUCT) return;
  const gi = requireGuestIdentity();
  if (!gi) return;
  try {
    const r = await api(`${API.interactions}?action=like`, {
      method: 'POST',
      body: { product_id: CURRENT_PRODUCT.id, name: gi.name, email: gi.email }
    });
    document.getElementById('likeCount').textContent = r.likes;
    document.getElementById('likeBtn').classList.toggle('liked', r.liked);
    showToast(r.liked ? 'Liked!' : 'Like removed');
  } catch (err) { showToast(err.message, true); }
}

async function onShare() {
  if (!CURRENT_PRODUCT) return;
  const url = window.location.origin + window.location.pathname + `#product=${CURRENT_PRODUCT.id}`;

  let shared = false;
  try {
    if (navigator.share) {
      await navigator.share({ title: CURRENT_PRODUCT.title, text: String(CURRENT_PRODUCT.body || '').slice(0, 100), url });
      shared = true;
    } else if (navigator.clipboard) {
      await navigator.clipboard.writeText(url);
      shared = true;
      showToast('Link copied to clipboard');
    } else {
      showToast(url);
    }
  } catch { return; /* user cancelled */ }

  if (shared) {
    const gi = getGuestIdentity();
    try {
      await api(`${API.interactions}?action=share`, {
        method: 'POST',
        body: { product_id: CURRENT_PRODUCT.id, name: gi.name, email: gi.email }
      });
    } catch { /* non-fatal */ }
  }
}

/* ===== Blog feed (home) + blog detail ===== */
function renderBlogFeed(blogs) {
  const grid = document.getElementById('blogFeed');
  if (!grid) return;
  if (!blogs.length) {
    grid.innerHTML = '<p style="color:#757575;">No blog posts yet. The shopkeeper can publish one from the Dashboard.</p>';
    return;
  }
  grid.innerHTML = blogs.map(b => `
    <article class="blog-feed-item" data-blog-id="${b.id}">
      <div class="blog-feed-thumb" style="background-image:url('${escapeAttr(b.image_path || defaultCover(b.title))}');" onerror="this.style.background='linear-gradient(135deg,#fbc2eb,#a6c1ee)'"></div>
      <div class="blog-feed-body">
        <h3 class="blog-feed-title">${escapeHtml(b.title)}</h3>
        <p class="blog-feed-excerpt">${escapeHtml(b.body)}</p>
        <span class="blog-feed-date">${formatDate(b.created_at)}</span>
      </div>
    </article>
  `).join('');
  grid.querySelectorAll('.blog-feed-item').forEach(card => {
    card.addEventListener('click', () => openBlog(parseInt(card.dataset.blogId, 10)));
  });
  observeReveal(grid.querySelectorAll('.blog-feed-item'));
}

async function openBlog(id) {
  const wrap = document.getElementById('blogDetail');
  showView('blog');
  wrap.innerHTML = '<p style="color:#757575;">Loading...</p>';
  try {
    const r = await api(`${API.blogs}&id=${id}`);
    renderBlogDetail(r.product);
  } catch (err) {
    wrap.innerHTML = `<p style="color:#d32f2f;">${err.message}</p>`;
  }
}

async function renderBlogDetail(b) {
  const wrap = document.getElementById('blogDetail');
  const img  = b.image_path || defaultCover(b.title);
  const canManage = !!SHOPKEEPER;
  CURRENT_BLOG = b;
  const gi = getGuestIdentity();
  let counts = { likes: 0, comments: 0, shares: 0, liked: false };
  try {
    counts = await api(`${API.interactions}?action=counts&product_id=${b.id}&email=${encodeURIComponent(gi.email)}`);
  } catch {}

  wrap.innerHTML = `
    <button class="back-btn" onclick="document.querySelector('[data-view=home]').click()">← Back to home</button>
    <h1 class="article-title">${escapeHtml(b.title)}</h1>
    <div class="product-meta-row">
      <span class="read-time">Published ${formatDate(b.created_at)}</span>
      ${counts.shares ? `<span class="read-time" style="margin-left:12px;">· ${counts.shares} share${counts.shares === 1 ? '' : 's'}</span>` : ''}
    </div>
    <img src="${escapeAttr(img)}" alt="" class="featured-image" onerror="this.src='${defaultCover(b.title)}'" />
    <div class="article-content">${formatBody(b.body)}</div>

    <div class="action-bar">
      <div class="action-left">
        <button class="action-item ${counts.liked ? 'liked' : ''}" id="blogLikeBtn" title="Like">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
          </svg>
          <span id="blogLikeCount">${counts.likes}</span>
        </button>
        <button class="action-item" id="blogCommentBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          <span id="blogCommentCount">${counts.comments}</span> Comments
        </button>
        <button class="action-item" id="blogShareBtn" title="Share">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
          </svg>
          Share
        </button>
      </div>
      ${canManage ? `
      <div class="action-right">
        <button class="follow-btn" id="editBlogBtn">Edit</button>
        <button class="follow-btn" id="deleteBlogBtn" style="border-color:#d32f2f;color:#d32f2f;margin-left:8px;">Delete</button>
      </div>` : ''}
    </div>
  `;

  document.getElementById('blogLikeBtn')?.addEventListener('click',    () => onBlogLike());
  document.getElementById('blogCommentBtn')?.addEventListener('click', () => openComments(b.id));
  document.getElementById('blogShareBtn')?.addEventListener('click',   () => onBlogShare());

  if (canManage) {
    document.getElementById('editBlogBtn')?.addEventListener('click', () => {
      showView('shopDashboard');
      loadDashboard('blog');
      startEditBlog(b.id);
    });
    document.getElementById('deleteBlogBtn')?.addEventListener('click', () => deleteBlog(b.id));
  }
}

async function onBlogLike() {
  if (!CURRENT_BLOG) return;
  const gi = requireGuestIdentity();
  if (!gi) return;
  try {
    const r = await api(`${API.interactions}?action=like`, {
      method: 'POST',
      body: { product_id: CURRENT_BLOG.id, name: gi.name, email: gi.email }
    });
    document.getElementById('blogLikeCount').textContent = r.likes;
    document.getElementById('blogLikeBtn').classList.toggle('liked', r.liked);
    showToast(r.liked ? 'Liked' : 'Like removed');
  } catch (err) { showToast(err.message, true); }
}

async function onBlogShare() {
  if (!CURRENT_BLOG) return;
  const url = window.location.origin + window.location.pathname + `#blog=${CURRENT_BLOG.id}`;
  let shared = false;
  try {
    if (navigator.share) {
      await navigator.share({ title: CURRENT_BLOG.title, text: String(CURRENT_BLOG.body || '').slice(0, 100), url });
      shared = true;
    } else if (navigator.clipboard) {
      await navigator.clipboard.writeText(url);
      shared = true;
      showToast('Link copied to clipboard');
    } else {
      showToast(url);
    }
  } catch { return; }

  if (shared) {
    const gi = getGuestIdentity();
    try {
      await api(`${API.interactions}?action=share`, {
        method: 'POST',
        body: { product_id: CURRENT_BLOG.id, name: gi.name, email: gi.email }
      });
    } catch {}
  }
}

/* ===== Comments view ===== */
let COMMENTS_PRODUCT_ID = null;

document.getElementById('commentsBackBtn')?.addEventListener('click', () => {
  showView(CURRENT_BLOG ? 'blog' : 'product');
});

async function openComments(productId) {
  COMMENTS_PRODUCT_ID = productId;
  showView('comments');
  await loadComments(productId);
}

async function loadComments(productId) {
  const list = document.getElementById('commentList');
  const summary = document.getElementById('commentsProductSummary');
  list.innerHTML = '<p style="color:#757575;">Loading...</p>';

  if (CURRENT_PRODUCT && CURRENT_PRODUCT.id === productId) {
    summary.innerHTML = `<h3 style="margin:12px 0 20px;">${escapeHtml(CURRENT_PRODUCT.title)}</h3>`;
  } else if (CURRENT_BLOG && CURRENT_BLOG.id === productId) {
    summary.innerHTML = `<h3 style="margin:12px 0 20px;">${escapeHtml(CURRENT_BLOG.title)}</h3>`;
  } else {
    summary.innerHTML = '';
  }

  // Pre-fill the guest identity inputs from localStorage so the visitor
  // doesn't have to retype their name + email every time.
  const gi = getGuestIdentity();
  const nameEl  = document.getElementById('guestNameInput');
  const emailEl = document.getElementById('guestEmailInput');
  if (nameEl  && !nameEl.value)  nameEl.value  = gi.name;
  if (emailEl && !emailEl.value) emailEl.value = gi.email;

  try {
    const r = await api(`${API.interactions}?action=comments&product_id=${productId}`);
    const comments = r.comments || [];
    if (!comments.length) {
      list.innerHTML = '<p style="color:#757575;">No comments yet. Be the first!</p>';
      return;
    }
    list.innerHTML = comments.map(c => renderComment(c, false)).join('');
  } catch (err) {
    list.innerHTML = `<p style="color:#d32f2f;">${err.message}</p>`;
  }
}

/** Render one comment block. Replies are only included when the viewer is the
 * shopkeeper (everyone sees the shopkeeper's reply so they know they've been
 * heard). */
function renderComment(c, canManage) {
  const replyHtml = (c.replies && c.replies.length)
    ? `<div class="comment-replies">
         ${c.replies.map(r => `
           <div class="comment-reply">
             <div class="comment-reply-head">
               <strong>${escapeHtml(r.reply_by)}</strong>
               <span class="comment-date">${formatDate(r.created_at)}</span>
             </div>
             <p>${escapeHtml(r.body)}</p>
           </div>
         `).join('')}
       </div>`
    : '';
  const manageBtn = canManage
    ? `<button class="btn-link danger" data-act="delete-comment" data-id="${c.id}">Delete</button>`
    : '';
  return `
    <div class="comment-item">
      <div class="comment-avatar">${initials(c.display_name)}</div>
      <div class="comment-body">
        <div class="comment-head">
          <strong>${escapeHtml(c.display_name)}</strong>
          <span class="comment-date">${formatDate(c.created_at)}</span>
          ${manageBtn}
        </div>
        <p>${escapeHtml(c.body)}</p>
        ${replyHtml}
      </div>
    </div>
  `;
}

document.getElementById('commentSubmitBtn')?.addEventListener('click', async () => {
  const nameEl  = document.getElementById('guestNameInput');
  const emailEl = document.getElementById('guestEmailInput');
  const input   = document.getElementById('commentInput');
  const name  = (nameEl  ? nameEl.value  : '').trim();
  const email = (emailEl ? emailEl.value : '').trim();
  const body  = input.value.trim();

  let valid = true;
  if (name.length < 2)         { setError(nameEl,  'Please enter your name');     valid = false; } else setError(nameEl, '');
  if (!isValidEmail(email))    { setError(emailEl, 'Enter a valid email');        valid = false; } else setError(emailEl, '');
  if (!body)                   { showToast('Comment cannot be empty', true); valid = false; }
  if (!valid) return;

  // Remember this identity for next time.
  setGuestIdentity(name, email);
  updateNavForAuth();

  try {
    await api(`${API.interactions}?action=comment`, {
      method: 'POST',
      body: {
        product_id: COMMENTS_PRODUCT_ID,
        name, email, body,
        website: document.getElementById('rateHoneypot') ? document.getElementById('rateHoneypot').value : '',
      }
    });
    input.value = '';
    showToast('Comment posted — thank you!');
    await loadComments(COMMENTS_PRODUCT_ID);
    // Refresh the comment count badge on the parent view.
    const pid = COMMENTS_PRODUCT_ID;
    const onProduct = CURRENT_PRODUCT && CURRENT_PRODUCT.id === pid;
    const onBlog    = CURRENT_BLOG    && CURRENT_BLOG.id    === pid;
    if (onProduct || onBlog) {
      const c = await api(`${API.interactions}?action=counts&product_id=${pid}&email=${encodeURIComponent(email)}`).catch(() => null);
      if (c) {
        const el = document.getElementById(onBlog ? 'blogCommentCount' : 'commentCount');
        if (el) el.textContent = c.comments;
      }
    }
  } catch (err) { showToast(err.message, true); }
});

/* ===========================================================
   Rate Us view — public star-rating + review form
   =========================================================== */

let RATE_STARS = 0;

function loadRateView() {
  RATE_STARS = 0;
  paintRateStars();

  const gi = getGuestIdentity();
  const nameEl  = document.getElementById('rateName');
  const emailEl = document.getElementById('rateEmail');
  if (nameEl  && !nameEl.value)  nameEl.value  = gi.name;
  if (emailEl && !emailEl.value) emailEl.value = gi.email;

  const selectEl = document.getElementById('rateProductSelect');
  if (selectEl && ALL_PRODUCTS.length && selectEl.options.length <= 1) {
    ALL_PRODUCTS.forEach(p => {
      const opt = document.createElement('option');
      opt.value = String(p.id);
      opt.textContent = p.title;
      selectEl.appendChild(opt);
    });
  }
  // Reset scope radios to "shop".
  const shopRadio = document.querySelector('input[name="rate-scope"][value="shop"]');
  if (shopRadio) shopRadio.checked = true;
  if (selectEl)  selectEl.style.display = 'none';

  setError(document.getElementById('rateName'), '');
  setError(document.getElementById('rateEmail'), '');
  setError(document.getElementById('rateBody'), '');
  const statusEl = document.getElementById('rateStatus');
  if (statusEl) { statusEl.textContent = ''; statusEl.style.color = '#1a8917'; }
}

function paintRateStars() {
  const stars = document.querySelectorAll('#rateStars .star');
  const label = document.getElementById('rateStarsLabel');
  stars.forEach(s => {
    const v = parseInt(s.dataset.star, 10);
    s.classList.toggle('active', v <= RATE_STARS);
    s.setAttribute('aria-checked', v === RATE_STARS ? 'true' : 'false');
  });
  const labels = ['Tap a star', 'Could be better', 'Okay', 'Good', 'Great', 'Excellent!'];
  if (label) label.textContent = labels[RATE_STARS] || 'Tap a star';
}

document.getElementById('rateStars')?.addEventListener('click', (e) => {
  const t = e.target.closest('.star');
  if (!t) return;
  RATE_STARS = parseInt(t.dataset.star, 10);
  paintRateStars();
});
document.getElementById('rateStars')?.addEventListener('mouseover', (e) => {
  const t = e.target.closest('.star');
  if (!t) return;
  const v = parseInt(t.dataset.star, 10);
  const label = document.getElementById('rateStarsLabel');
  const labels = ['Tap a star', 'Could be better', 'Okay', 'Good', 'Great', 'Excellent!'];
  if (label) label.textContent = labels[v];
});
document.getElementById('rateStars')?.addEventListener('mouseleave', () => paintRateStars());

document.querySelectorAll('input[name="rate-scope"]').forEach(r => {
  r.addEventListener('change', () => {
    const sel = document.getElementById('rateProductSelect');
    if (!sel) return;
    const wantProduct = document.querySelector('input[name="rate-scope"]:checked').value === 'product';
    sel.style.display = wantProduct ? '' : 'none';
  });
});

document.getElementById('rateSubmitBtn')?.addEventListener('click', async () => {
  const nameEl  = document.getElementById('rateName');
  const emailEl = document.getElementById('rateEmail');
  const bodyEl  = document.getElementById('rateBody');
  const scope   = document.querySelector('input[name="rate-scope"]:checked').value;
  const productId = scope === 'product'
    ? parseInt(document.getElementById('rateProductSelect').value, 10) || null
    : null;

  const name  = nameEl.value.trim();
  const email = emailEl.value.trim();
  const body  = bodyEl.value.trim();

  let valid = true;
  if (RATE_STARS < 1)            { showToast('Please pick at least one star', true); valid = false; }
  if (name.length < 2)           { setError(nameEl, 'Please enter your name');  valid = false; } else setError(nameEl, '');
  if (!isValidEmail(email))      { setError(emailEl, 'Enter a valid email');    valid = false; } else setError(emailEl, '');
  if (body.length > 2000)        { setError(bodyEl, 'Review is too long');       valid = false; } else setError(bodyEl, '');
  if (scope === 'product' && !productId) {
    showToast('Pick a product to rate', true);
    valid = false;
  }
  if (!valid) return;

  // Remember this identity for next time.
  setGuestIdentity(name, email);
  updateNavForAuth();

  const status = document.getElementById('rateStatus');
  status.textContent = 'Submitting…'; status.style.color = '#1a8917';

  try {
    const payload = {
      name,
      email,
      stars: RATE_STARS,
      review_body: body,
      website: document.getElementById('rateHoneypot').value || '',
    };
    if (productId) payload.product_id = productId;

    const r = await api(`${API.interactions}?action=rate`, { method: 'POST', body: payload });
    status.textContent = 'Thanks! Your rating was submitted ✓';
    status.style.color = '#1a8917';
    showToast('Thanks for your rating!');
    // Reflect the new aggregate on the shop profile page if the user goes there.
    if (r.summary) {
      // The rate view itself doesn't show one, but the next time the shop
      // profile loads, it'll re-read the summary.
    }
  } catch (err) {
    status.textContent = err.message;
    status.style.color = '#d32f2f';
    showToast(err.message, true);
  }
});

/* ===========================================================
   My Reviews view — guest-only, email-gated
   =========================================================== */

function loadMyReviewsView() {
  const statusEl = document.getElementById('myReviewsResults');
  if (statusEl) { statusEl.hidden = true; statusEl.innerHTML = ''; }
  const gi = getGuestIdentity();
  const emailEl = document.getElementById('myReviewsEmail');
  if (emailEl && !emailEl.value) emailEl.value = gi.email;
  setError(emailEl, '');
}

document.getElementById('myReviewsForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const emailEl = document.getElementById('myReviewsEmail');
  const email = (emailEl.value || '').trim().toLowerCase();
  if (!isValidEmail(email)) { setError(emailEl, 'Enter a valid email'); return; }
  setError(emailEl, '');

  const out = document.getElementById('myReviewsResults');
  out.hidden = false;
  out.innerHTML = '<p style="color:#757575;">Loading…</p>';

  try {
    const r = await api(`${API.interactions}?action=myReviews&email=${encodeURIComponent(email)}`);
    const reviews = r.reviews || [];
    if (!reviews.length) {
      out.innerHTML = '<p class="empty-state">No reviews yet under this email. <a href="#" data-view="rate">Rate us</a> to leave your first one.</p>';
      return;
    }
    out.innerHTML = reviews.map(rv => {
      const isShop = rv.product_id === null;
      const header = isShop
        ? `<strong>Overall shop</strong> <span class="muted">· ${formatDate(rv.created_at)}</span>`
        : `<strong>${escapeHtml(rv.product_title || 'Product')}</strong> <span class="muted">· ${formatDate(rv.created_at)}</span>`;
      const replyHtml = (rv.replies && rv.replies.length)
        ? `<div class="review-replies">
             <h4 class="muted">Shop owner's reply</h4>
             ${rv.replies.map(rep => `
               <div class="comment-reply">
                 <div class="comment-reply-head">
                   <strong>${escapeHtml(rep.reply_by)}</strong>
                   <span class="comment-date">${formatDate(rep.created_at)}</span>
                 </div>
                 <p>${escapeHtml(rep.body)}</p>
               </div>
             `).join('')}
           </div>`
        : `<p class="muted" style="margin: 8px 0 0; font-size: 13px;">No reply yet — the shop will reply here.</p>`;
      return `
        <div class="review-item">
          <div class="review-item-head">
            ${header}
            <span class="review-stars">${starString(rv.stars)}</span>
          </div>
          ${rv.review_body ? `<p class="review-body">${escapeHtml(rv.review_body)}</p>` : ''}
          ${replyHtml}
        </div>
      `;
    }).join('');
  } catch (err) {
    out.innerHTML = `<p style="color:#d32f2f;">${err.message}</p>`;
  }
});

/* ===========================================================
   Shopkeeper dashboard
   =========================================================== */

function loadDashboard(tab) {
  if (!SHOPKEEPER) { showView('shopLogin'); return; }
  document.querySelectorAll('[data-dash]').forEach(a => a.classList.remove('active'));
  const link = document.querySelector(`[data-dash="${tab}"]`);
  if (link) link.classList.add('active');
  document.querySelectorAll('.dash-tab').forEach(t => t.style.display = 'none');
  const target = document.getElementById('dashTab-' + tab);
  if (target) target.style.display = '';
  if (tab === 'products') loadDashboardProducts();
  if (tab === 'blog')     loadDashboardBlogs();
  if (tab === 'shop')     loadDashboardShop();
  if (tab === 'engagement') loadDashboardEngagement();
}

document.addEventListener('click', (e) => {
  const t = e.target.closest('[data-dash]');
  if (!t) return;
  e.preventDefault();
  loadDashboard(t.dataset.dash);
});

document.getElementById('dashLogoutBtn')?.addEventListener('click', async () => {
  try { await api(`${API.auth}?action=shopLogout`, { method: 'POST' }); } catch {}
  SHOPKEEPER = null;
  updateNavForAuth();
  showToast('Signed out');
  showView('home');
});

document.getElementById('newProductBtn')?.addEventListener('click', () => {
  resetProductForm();
  document.getElementById('productForm').style.display = '';
});

document.getElementById('prodCancelBtn')?.addEventListener('click', () => {
  document.getElementById('productForm').style.display = 'none';
  resetProductForm();
});

function resetProductForm() {
  EDIT_PRODUCT_ID = null;
  document.getElementById('editProductId').value = '';
  document.getElementById('prodTitle').value = '';
  document.getElementById('prodBody').value  = '';
  document.getElementById('prodPrice').value = '';
  document.getElementById('prodImage').value = '';
  document.getElementById('prodImagePreview').src = '';
  document.getElementById('prodImagePreview').style.display = 'none';
  document.getElementById('prodDropEmpty').style.display = '';
  setError(document.getElementById('prodTitle'), '');
  setError(document.getElementById('prodBody'), '');
  const status = document.getElementById('prodStatus');
  if (status) { status.textContent = ''; status.style.color = '#1a8917'; }
  document.getElementById('prodSaveBtn').textContent = 'Publish';
}

document.getElementById('prodImageDrop')?.addEventListener('click', () => {
  document.getElementById('prodImage').click();
});
document.getElementById('prodImage')?.addEventListener('change', (e) => {
  const f = e.target.files[0];
  if (!f) return;
  const reader = new FileReader();
  reader.onload = (ev) => {
    const prev = document.getElementById('prodImagePreview');
    prev.src = ev.target.result;
    prev.style.display = 'block';
    document.getElementById('prodDropEmpty').style.display = 'none';
  };
  reader.readAsDataURL(f);
});

document.getElementById('prodSaveBtn')?.addEventListener('click', async () => {
  if (!SHOPKEEPER) { showToast('Shopkeeper login required', true); return; }
  const title = document.getElementById('prodTitle');
  const body  = document.getElementById('prodBody');
  const price = document.getElementById('prodPrice');
  const file  = document.getElementById('prodImage').files[0];

  let valid = true;
  if (!title.value.trim()) { setError(title, 'Title is required'); valid = false; } else setError(title, '');
  if (!body.value.trim())  { setError(body, 'Description is required'); valid = false; } else setError(body, '');
  if (!valid) return;

  const status = document.getElementById('prodStatus');
  status.textContent = 'Saving...'; status.style.color = '#1a8917';

  try {
    let imagePath = null;
    if (file) {
      const fd = new FormData();
      fd.append('image', file);
      await ensureCsrf();
      if (CSRF) fd.append('csrf', CSRF);
      const r = await fetch(API.upload, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await r.json();
      if (!r.ok || !data.ok) throw new Error(data.error || 'Upload failed');
      imagePath = data.url;
    }

    const payload = { title: title.value.trim(), body: body.value.trim(), price: price.value || 0 };
    if (imagePath) payload.image_path = imagePath;

    if (EDIT_PRODUCT_ID) {
      await api(`${API.products}?id=${EDIT_PRODUCT_ID}`, { method: 'PUT', body: payload });
      showToast('Product updated');
    } else {
      await api(API.products, { method: 'POST', body: payload });
      showToast('Product published');
    }
    document.getElementById('productForm').style.display = 'none';
    resetProductForm();
    loadDashboardProducts();
  } catch (err) {
    status.textContent = err.message; status.style.color = '#d32f2f';
    showToast(err.message, true);
  }
});

async function loadDashboardProducts() {
  const grid = document.getElementById('dashProductGrid');
  grid.innerHTML = '<p style="color:#757575;">Loading…</p>';
  try {
    const r = await api(API.products);
    const items = r.products || [];
    if (!items.length) {
      grid.innerHTML = '<p style="color:#757575;">No products yet. Use "+ New product" to add one.</p>';
      return;
    }
    grid.innerHTML = items.map(p => `
      <div class="gig-card" data-product-id="${p.id}">
        <div class="gig-thumb" style="background-image:url('${escapeAttr(p.image_path || defaultCover(p.title))}');" onerror="this.style.background='linear-gradient(135deg,#a8e063,#56ab2f)'">
          <div class="gig-card-actions">
            <button class="gig-share" data-act="edit" data-id="${p.id}" title="Edit">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </button>
            <button class="gig-share" data-act="delete" data-id="${p.id}" title="Delete" style="margin-left:6px;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
          </div>
        </div>
        <div class="gig-body">
          <p class="gig-title">${escapeHtml(p.title)}</p>
          <div class="gig-footer">
            <div class="gig-price"><strong>₹${formatPrice(p.price)}</strong></div>
            <div class="gig-price">${formatDate(p.created_at)}</div>
          </div>
        </div>
      </div>
    `).join('');

    grid.querySelectorAll('.gig-card[data-product-id]').forEach(card => {
      card.addEventListener('click', (e) => {
        const act = e.target.closest('[data-act]');
        const id = parseInt(card.dataset.productId, 10);
        if (act) {
          e.stopPropagation();
          if (act.dataset.act === 'edit')   startEditProduct(id);
          if (act.dataset.act === 'delete') deleteProduct(id);
        } else {
          openProduct(id);
        }
      });
    });
  } catch (err) {
    grid.innerHTML = `<p style="color:#d32f2f;">${err.message}</p>`;
  }
}

async function startEditProduct(id) {
  try {
    const r = await api(`${API.products}?id=${id}`);
    const p = r.product;
    EDIT_PRODUCT_ID = p.id;
    document.getElementById('editProductId').value = p.id;
    document.getElementById('prodTitle').value = p.title;
    document.getElementById('prodBody').value  = p.body;
    document.getElementById('prodPrice').value = p.price || '';
    if (p.image_path) {
      const prev = document.getElementById('prodImagePreview');
      prev.src = p.image_path;
      prev.style.display = 'block';
      document.getElementById('prodDropEmpty').style.display = 'none';
    } else {
      document.getElementById('prodImagePreview').style.display = 'none';
      document.getElementById('prodDropEmpty').style.display = '';
    }
    document.getElementById('productForm').style.display = '';
    document.getElementById('prodSaveBtn').textContent = 'Save changes';
    document.getElementById('productForm').scrollIntoView({ behavior: 'smooth' });
  } catch (err) { showToast(err.message, true); }
}

async function deleteProduct(id) {
  if (!confirm('Delete this product? This cannot be undone.')) return;
  try {
    await api(`${API.products}?id=${id}`, { method: 'DELETE' });
    showToast('Product deleted');
    loadDashboardProducts();
  } catch (err) { showToast(err.message, true); }
}

function loadDashboardShop() {
  const p = SHOP_PROFILE || {};
  document.getElementById('shopName').value     = p.shop_name || '';
  document.getElementById('shopOwner').value    = p.owner_name || '';
  document.getElementById('shopMobile').value   = p.mobile || '';
  document.getElementById('shopEmail').value    = p.email || '';
  document.getElementById('shopLocation').value = p.location || '';
  document.getElementById('shopNote').value     = p.contact_note || '';
  document.getElementById('shopAbout').value    = p.about || '';
  const status = document.getElementById('shopStatus');
  if (status) { status.textContent = ''; status.style.color = '#1a8917'; }
}

document.getElementById('shopSaveBtn')?.addEventListener('click', async () => {
  if (!SHOPKEEPER) { showToast('Shopkeeper login required', true); return; }
  const status = document.getElementById('shopStatus');
  status.textContent = 'Saving...'; status.style.color = '#1a8917';
  const payload = {
    shop_name:    document.getElementById('shopName').value.trim(),
    owner_name:   document.getElementById('shopOwner').value.trim(),
    mobile:       document.getElementById('shopMobile').value.trim(),
    email:        document.getElementById('shopEmail').value.trim(),
    location:     document.getElementById('shopLocation').value.trim(),
    contact_note: document.getElementById('shopNote').value.trim(),
    about:        document.getElementById('shopAbout').value.trim(),
  };
  try {
    const r = await api(`${API.auth}?action=shopProfile`, { method: 'PUT', body: payload });
    SHOP_PROFILE = r.profile;
    updateBrand();
    renderShopProfile(r.profile);
    showToast('Shop details saved');
    status.textContent = 'Saved ✓';
  } catch (err) { status.textContent = err.message; status.style.color = '#d32f2f'; showToast(err.message, true); }
});

/* ===========================================================
   Engagement tab — review feed + reply UI
   =========================================================== */

let ENGAGEMENT_DATA = { ratings: [], comments: [], summary: { count: 0, average: 0 } };

async function loadDashboardEngagement() {
  const list = document.getElementById('engagementList');
  list.innerHTML = '<p style="color:#757575;">Loading…</p>';
  try {
    const [eng, counts] = await Promise.all([
      api(`${API.interactions}?action=shopReviews`),
      api(API.products).catch(() => ({ products: [] })),
    ]);
    ENGAGEMENT_DATA = eng;
    const totalComments = eng.comments.length;
    const totalLikes    = (counts.products || []).reduce(() => 0, 0);
    // Fetch per-product like counts in parallel.
    const likePromises = (counts.products || []).map(p =>
      api(`${API.interactions}?action=counts&product_id=${p.id}`).then(c => c.likes || 0).catch(() => 0)
    );
    const likeArr = await Promise.all(likePromises);
    const totalLikesSum = likeArr.reduce((a, b) => a + b, 0);

    document.getElementById('metricAvg').textContent       = (Number(eng.summary.average) || 0).toFixed(1);
    document.getElementById('metricCount').textContent     = `${eng.summary.count} review${eng.summary.count === 1 ? '' : 's'}`;
    document.getElementById('metricComments').textContent  = totalComments;
    document.getElementById('metricLikes').textContent     = totalLikesSum;

    renderEngagementList();
  } catch (err) {
    list.innerHTML = `<p style="color:#d32f2f;">${err.message}</p>`;
  }
}

document.getElementById('engagementFilter')?.addEventListener('change', renderEngagementList);

function renderEngagementList() {
  const list = document.getElementById('engagementList');
  const filter = document.getElementById('engagementFilter').value;
  const data = ENGAGEMENT_DATA;
  const sections = [];

  if (filter === 'all' || filter === 'ratings') {
    if (data.ratings && data.ratings.length) {
      sections.push(`<h3 class="engagement-section-title">⭐ Ratings &amp; reviews (${data.ratings.length})</h3>`);
      sections.push(data.ratings.map(rv => renderEngagementRating(rv)).join(''));
    } else if (filter === 'ratings') {
      sections.push('<p class="empty-state">No ratings yet.</p>');
    }
  }
  if (filter === 'all' || filter === 'comments') {
    if (data.comments && data.comments.length) {
      sections.push(`<h3 class="engagement-section-title">💬 Comments (${data.comments.length})</h3>`);
      sections.push(data.comments.map(c => renderEngagementComment(c)).join(''));
    } else if (filter === 'comments') {
      sections.push('<p class="empty-state">No comments yet.</p>');
    }
  }
  if (!sections.length) {
    list.innerHTML = '<p class="empty-state">No engagement yet. Visitors can like, comment, and rate without signing up.</p>';
    return;
  }
  list.innerHTML = sections.join('');
}

function renderEngagementRating(rv) {
  const target = rv.product_id
    ? `<span class="muted">on “${escapeHtml(rv.product_title || 'product #' + rv.product_id)}”</span>`
    : `<span class="muted">on the shop overall</span>`;
  const replies = (rv.replies || []).map(rep => `
    <div class="comment-reply">
      <div class="comment-reply-head">
        <strong>${escapeHtml(rep.reply_by)}</strong>
        <span class="comment-date">${formatDate(rep.created_at)}</span>
        <button class="btn-link danger" data-act="delete-reply" data-id="${rep.id}">Remove</button>
      </div>
      <p>${escapeHtml(rep.body)}</p>
    </div>
  `).join('');
  return `
    <div class="engagement-item" data-kind="rating" data-id="${rv.id}">
      <div class="engagement-item-head">
        <div>
          <strong>${escapeHtml(rv.guest_name)}</strong>
          <span class="muted">rated</span>
          <span class="review-stars">${starString(rv.stars)}</span>
          ${target}
          <span class="muted">· ${formatDate(rv.created_at)}</span>
        </div>
      </div>
      ${rv.review_body ? `<p class="engagement-body">${escapeHtml(rv.review_body)}</p>` : '<p class="muted">(no written review)</p>'}
      ${replies ? `<div class="engagement-replies">${replies}</div>` : ''}
      <form class="reply-form" data-act="reply" data-target="rating" data-target-id="${rv.id}">
        <textarea class="form-control" rows="2" placeholder="Reply to ${escapeHtml(rv.guest_name)}…" maxlength="2000" required></textarea>
        <div class="reply-form-actions">
          <button type="submit" class="btn-sm btn-submit">Send reply</button>
        </div>
      </form>
    </div>
  `;
}

function renderEngagementComment(c) {
  const target = c.product_id
    ? `<span class="muted">on “${escapeHtml(c.product_title || 'product #' + c.product_id)}”</span>`
    : '';
  const replies = (c.replies || []).map(rep => `
    <div class="comment-reply">
      <div class="comment-reply-head">
        <strong>${escapeHtml(rep.reply_by)}</strong>
        <span class="comment-date">${formatDate(rep.created_at)}</span>
        <button class="btn-link danger" data-act="delete-reply" data-id="${rep.id}">Remove</button>
      </div>
      <p>${escapeHtml(rep.body)}</p>
    </div>
  `).join('');
  return `
    <div class="engagement-item" data-kind="comment" data-id="${c.id}">
      <div class="engagement-item-head">
        <div>
          <strong>${escapeHtml(c.guest_name)}</strong>
          <span class="muted">commented</span>
          ${target}
          <span class="muted">· ${formatDate(c.created_at)}</span>
        </div>
        <button class="btn-link danger" data-act="delete-comment" data-id="${c.id}">Delete comment</button>
      </div>
      <p class="engagement-body">${escapeHtml(c.body)}</p>
      ${replies ? `<div class="engagement-replies">${replies}</div>` : ''}
      <form class="reply-form" data-act="reply" data-target="comment" data-target-id="${c.id}">
        <textarea class="form-control" rows="2" placeholder="Reply to ${escapeHtml(c.guest_name)}…" maxlength="2000" required></textarea>
        <div class="reply-form-actions">
          <button type="submit" class="btn-sm btn-submit">Send reply</button>
        </div>
      </form>
    </div>
  `;
}

/* Reply-form + delete handler delegated from the engagement list. */
document.getElementById('engagementList')?.addEventListener('click', async (e) => {
  const delBtn = e.target.closest('[data-act="delete-reply"]');
  if (delBtn) {
    e.preventDefault();
    if (!confirm('Remove this reply?')) return;
    try {
      await api(`${API.interactions}?action=deleteReply`, {
        method: 'POST',
        body: { reply_id: parseInt(delBtn.dataset.id, 10) }
      });
      showToast('Reply removed');
      loadDashboardEngagement();
    } catch (err) { showToast(err.message, true); }
    return;
  }
  const delCommentBtn = e.target.closest('[data-act="delete-comment"]');
  if (delCommentBtn) {
    e.preventDefault();
    if (!confirm('Delete this comment? This cannot be undone.')) return;
    try {
      await api(`${API.interactions}?action=deleteComment`, {
        method: 'POST',
        body: { comment_id: parseInt(delCommentBtn.dataset.id, 10) }
      });
      showToast('Comment deleted');
      loadDashboardEngagement();
    } catch (err) { showToast(err.message, true); }
    return;
  }
});

document.getElementById('engagementList')?.addEventListener('submit', async (e) => {
  const form = e.target.closest('.reply-form');
  if (!form) return;
  e.preventDefault();
  const ta = form.querySelector('textarea');
  const body = (ta.value || '').trim();
  if (!body) return;
  const targetKind = form.dataset.target;
  const targetId   = parseInt(form.dataset.targetId, 10);
  try {
    await api(`${API.interactions}?action=reply`, {
      method: 'POST',
      body: { target_kind: targetKind, target_id: targetId, body }
    });
    showToast('Reply sent');
    ta.value = '';
    loadDashboardEngagement();
  } catch (err) { showToast(err.message, true); }
});

/* ===========================================================
   Blog write/edit (shopkeeper)
   =========================================================== */

document.getElementById('newBlogBtn')?.addEventListener('click', () => {
  resetBlogForm();
  const dashForm = document.getElementById('blogForm');
  if (dashForm) {
    dashForm.style.display = '';
    dashForm.scrollIntoView({ behavior: 'smooth' });
  }
});

function resetBlogForm() {
  EDIT_BLOG_ID = null;
  document.getElementById('editBlogId').value = '';
  document.getElementById('blogTitle').value = '';
  document.getElementById('blogBody').value  = '';
  document.getElementById('blogImage').value = '';
  const prev = document.getElementById('blogImagePreview');
  if (prev) { prev.src = ''; prev.style.display = 'none'; }
  const drop = document.getElementById('blogDropEmpty');
  if (drop) drop.style.display = '';
  setError(document.getElementById('blogTitle'), '');
  setError(document.getElementById('blogBody'), '');
  const status = document.getElementById('blogStatus');
  if (status) { status.textContent = ''; status.style.color = '#1a8917'; }
  const btn = document.getElementById('blogPublishBtn');
  if (btn) btn.textContent = 'Publish';
}

document.getElementById('blogImageDrop')?.addEventListener('click', () => {
  document.getElementById('blogImage').click();
});
document.getElementById('blogImage')?.addEventListener('change', (e) => {
  const f = e.target.files[0];
  if (!f) return;
  const reader = new FileReader();
  reader.onload = (ev) => {
    const prev = document.getElementById('blogImagePreview');
    prev.src = ev.target.result;
    prev.style.display = 'block';
    document.getElementById('blogDropEmpty').style.display = 'none';
  };
  reader.readAsDataURL(f);
});

document.getElementById('blogCancelBtn')?.addEventListener('click', () => {
  resetBlogForm();
  const dashForm = document.getElementById('blogForm');
  if (dashForm) dashForm.style.display = 'none';
});

document.getElementById('blogPublishBtn')?.addEventListener('click', async () => {
  if (!SHOPKEEPER) { showToast('Shopkeeper login required', true); return; }
  const title = document.getElementById('blogTitle');
  const body  = document.getElementById('blogBody');
  const file  = document.getElementById('blogImage').files[0];

  let valid = true;
  if (!title.value.trim()) { setError(title, 'Title is required'); valid = false; } else setError(title, '');
  if (!body.value.trim())  { setError(body,  'Description is required'); valid = false; } else setError(body, '');
  if (!valid) return;

  const status = document.getElementById('blogStatus');
  status.textContent = 'Publishing…'; status.style.color = '#1a8917';

  try {
    let imagePath = null;
    if (file) {
      const fd = new FormData();
      fd.append('image', file);
      await ensureCsrf();
      if (CSRF) fd.append('csrf', CSRF);
      const r = await fetch(API.upload, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await r.json();
      if (!r.ok || !data.ok) throw new Error(data.error || 'Upload failed');
      imagePath = data.url;
    }

    const payload = { title: title.value.trim(), body: body.value.trim(), kind: 'blog' };
    if (imagePath) payload.image_path = imagePath;

    if (EDIT_BLOG_ID) {
      await api(`${API.blogs}&id=${EDIT_BLOG_ID}`, { method: 'PUT', body: payload });
      showToast('Blog updated');
    } else {
      await api(API.blogs, { method: 'POST', body: payload });
      showToast('Blog published');
    }
    resetBlogForm();
    const dashForm = document.getElementById('blogForm');
    if (dashForm) dashForm.style.display = 'none';
    await loadDashboardBlogs();
  } catch (err) {
    status.textContent = err.message; status.style.color = '#d32f2f';
    showToast(err.message, true);
  }
});

async function loadDashboardBlogs() {
  const grid = document.getElementById('dashBlogGrid');
  if (!grid) return;
  grid.innerHTML = '<p style="color:#757575;">Loading…</p>';
  try {
    const r = await api(API.blogs);
    const items = r.blogs || [];
    if (!items.length) {
      grid.innerHTML = '<p style="color:#757575;">No blogs yet. Publish your first one above.</p>';
      return;
    }
    grid.innerHTML = items.map(b => `
      <div class="gig-card" data-blog-id="${b.id}">
        <div class="gig-thumb" style="background-image:url('${escapeAttr(b.image_path || defaultCover(b.title))}');" onerror="this.style.background='linear-gradient(135deg,#fbc2eb,#a6c1ee)'">
          <div class="gig-card-actions">
            <button class="gig-share" data-act="edit-blog" data-id="${b.id}" title="Edit">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </button>
            <button class="gig-share" data-act="delete-blog" data-id="${b.id}" title="Delete" style="margin-left:6px;">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>
            </button>
          </div>
        </div>
        <div class="gig-body">
          <p class="gig-title">${escapeHtml(b.title)}</p>
          <div class="gig-footer">
            <div class="gig-price">${formatDate(b.created_at)}</div>
          </div>
        </div>
      </div>
    `).join('');

    grid.querySelectorAll('.gig-card[data-blog-id]').forEach(card => {
      card.addEventListener('click', (e) => {
        const act = e.target.closest('[data-act]');
        const id = parseInt(card.dataset.blogId, 10);
        if (act) {
          e.stopPropagation();
          if (act.dataset.act === 'edit-blog')   startEditBlog(id);
          if (act.dataset.act === 'delete-blog') deleteBlog(id);
        } else {
          openBlog(id);
        }
      });
    });
  } catch (err) {
    grid.innerHTML = `<p style="color:#d32f2f;">${err.message}</p>`;
  }
}

async function startEditBlog(id) {
  try {
    const r = await api(`${API.blogs}&id=${id}`);
    const b = r.product;
    EDIT_BLOG_ID = b.id;
    document.getElementById('editBlogId').value = b.id;
    document.getElementById('blogTitle').value = b.title;
    document.getElementById('blogBody').value  = b.body;
    document.getElementById('blogImage').value = '';
    if (b.image_path) {
      const prev = document.getElementById('blogImagePreview');
      prev.src = b.image_path;
      prev.style.display = 'block';
      document.getElementById('blogDropEmpty').style.display = 'none';
    } else {
      document.getElementById('blogImagePreview').style.display = 'none';
      document.getElementById('blogDropEmpty').style.display = '';
    }
    const dashForm = document.getElementById('blogForm');
    if (dashForm) {
      dashForm.style.display = '';
      dashForm.scrollIntoView({ behavior: 'smooth' });
    }
    document.getElementById('blogPublishBtn').textContent = 'Save changes';
  } catch (err) { showToast(err.message, true); }
}

async function deleteBlog(id) {
  if (!confirm('Delete this blog? This cannot be undone.')) return;
  try {
    await api(`${API.blogs}&id=${id}`, { method: 'DELETE' });
    showToast('Blog deleted');
    await loadDashboardBlogs();
  } catch (err) { showToast(err.message, true); }
}

/* ===== Helpers ===== */
function escapeHtml(s) { return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function escapeAttr(s) { return escapeHtml(s).replace(/'/g, '&#39;'); }
function initials(name) {
  return String(name || '?').split(' ').map(p => p[0]).join('').slice(0, 2).toUpperCase();
}
function formatDate(iso) {
  const d = new Date(iso);
  if (isNaN(d)) return '';
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
function formatPrice(p) {
  const n = Number(p);
  if (!isFinite(n)) return '0';
  return n.toLocaleString('en-IN', { maximumFractionDigits: 2 });
}
function formatBody(text) {
  return String(text || '').split(/\n\s*\n/).map(p => `<p>${escapeHtml(p).replace(/\n/g, '<br>')}</p>`).join('');
}
function defaultCover(seed) {
  const palettes = [
    'linear-gradient(135deg,#a8e063,#56ab2f)',
    'linear-gradient(135deg,#f6d365,#fda085)',
    'linear-gradient(135deg,#84fab0,#8fd3f4)',
    'linear-gradient(135deg,#a1c4fd,#c2e9fb)',
    'linear-gradient(135deg,#fbc2eb,#a6c1ee)',
  ];
  let h = 0; for (const c of String(seed || '')) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  return palettes[h % palettes.length];
}

/* ===== Boot ===== */
(async function init() {
  await ensureCsrf();
  await refreshSession();
  loadHome();
})();