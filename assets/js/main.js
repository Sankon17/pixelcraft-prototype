// Mobile nav toggle and category scroller
document.addEventListener('DOMContentLoaded', () => {
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Intro splash (the animation itself is CSS): click or press a key to skip it, and drop it once it's gone
    const intro = document.querySelector('.intro-screen');
    if (intro && !document.documentElement.classList.contains('has-intro')) {
        intro.remove();
    } else if (intro) {
        const skip = () => document.documentElement.classList.add('intro-skip');
        intro.addEventListener('click', skip);
        document.addEventListener('keydown', skip, { once: true });
        intro.addEventListener('animationend', e => {
            if (e.target !== intro) return;
            intro.remove();
            document.removeEventListener('keydown', skip);
        });
    }

    // Scroll reveal: cards and headings below the fold fade up as they come into view, a beat apart.
    // Anything already on screen is left alone (the page's own entrance covers it)
    const REVEAL = [
        '.section-head', '.spotlight', '.category-scroller', '.empty-state', '.cta-box', '.store-card', '.map-frame', '.jcl-intro',
        '.product-grid > *', '.pc-grid > *', '.svc-grid > *', '.svc-packages > *', '.svc-trust > *', '.cap-grid > *',
        '.jcl-grid > *', '.visit-grid > *', '.partner-banner-inner > :is(h2, p)', '.partner-feature > *',
        '.seller-block > :is(h3, p)', '.seller-grid > *', '.footer-inner > *',
    ].join(',');
    if (!still && 'IntersectionObserver' in window && document.body.classList.contains('site')) {
        const targets = [...document.querySelectorAll(REVEAL)].filter(el =>
            el.getBoundingClientRect().top > window.innerHeight && !el.parentElement.closest(REVEAL));

        const observer = new IntersectionObserver(entries => {
            entries.filter(en => en.isIntersecting).forEach((en, i) => {
                const el = en.target;
                const delay = Math.min(i * 80, 320); // things arriving together come in one after another
                observer.unobserve(el);
                el.style.transitionDelay = delay + 'ms';
                el.classList.add('is-visible');
                setTimeout(() => {
                    el.classList.remove('reveal', 'is-visible');
                    el.style.transitionDelay = '';
                }, delay + 750);
            });
        }, { rootMargin: '0px 0px -8% 0px' });

        targets.forEach(el => {
            el.classList.add('reveal');
            observer.observe(el);
        });
    }

    const header = document.querySelector('.site-header');
    const toggle = document.querySelector('.nav-toggle');
    if (header && toggle) {
        const setOpen = open => {
            header.classList.toggle('nav-open', open);
            toggle.setAttribute('aria-expanded', open);
        };

        toggle.addEventListener('click', () => setOpen(!header.classList.contains('nav-open')));
        header.querySelectorAll('.nav-links a').forEach(a => a.addEventListener('click', () => setOpen(false)));
        document.addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });
        window.addEventListener('resize', () => { if (window.innerWidth > 768) setOpen(false); });
    }

    // Product gallery: clicking a thumbnail swaps the main photo
    const mainImg = document.querySelector('.pd-main-img');
    document.querySelectorAll('.pd-thumb').forEach(thumb => {
        thumb.addEventListener('click', () => {
            if (!mainImg) return;
            mainImg.src = thumb.dataset.src;
            document.querySelectorAll('.pd-thumb.active').forEach(t => t.classList.remove('active'));
            thumb.classList.add('active');
        });
    });

    // Product configurator: chips pick a variant, price / JCL / stock follow the selection
    const buyForm = document.querySelector('.pd-buy');
    if (buyForm) {
        const variants = JSON.parse(buyForm.dataset.variants || '[]');
        const qty = buyForm.elements.quantity;
        const rm = n => 'RM ' + n.toLocaleString('en-MY', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });

        const updateVariant = () => {
            const picked = {};
            buyForm.querySelectorAll('.chip-row[data-group]').forEach(row => {
                picked[row.dataset.group] = row.querySelector('.chip-active').dataset.value;
            });
            const match = variants.find(v => Object.keys(picked).every(k => v[k] === picked[k]));
            const available = Boolean(match && match.stock > 0);

            buyForm.elements.selected_variant_id.value = match ? match.id : '';
            if (match) document.getElementById('displayPrice').textContent = rm(match.price);

            const teaser = document.getElementById('jclTeaser');
            if (teaser) {
                teaser.hidden = !(match && match.jcl);
                if (match && match.jcl) document.getElementById('jclMonthly').textContent = 'RM ' + match.jcl.toFixed(2);
            }

            const status = document.getElementById('stockStatus');
            status.textContent = !match ? 'Konfigurasi ini tidak tersedia' : available ? '✓ Dalam stok' : 'Stok habis';
            status.classList.toggle('out', !available);
            buyForm.querySelectorAll('button[type="submit"]').forEach(b => { b.disabled = !available; });
        };

        buyForm.addEventListener('click', e => {
            const chip = e.target.closest('.chip');
            if (!chip) return;
            const row = chip.parentElement;
            row.querySelectorAll('.chip').forEach(c => {
                c.classList.toggle('chip-active', c === chip);
                c.setAttribute('aria-pressed', c === chip);
            });

            if (row.dataset.input) buyForm.elements[row.dataset.input].value = chip.dataset.value;
            if (variants.length) {
                // A specific build is chosen now, so the price is no longer a "From" price
                const from = document.getElementById('priceFrom');
                if (from) from.hidden = true;
                updateVariant();
            }
        });

        buyForm.querySelectorAll('.qty-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const next = (parseInt(qty.value, 10) || 1) + Number(btn.dataset.step);
                qty.value = Math.min(Math.max(next, Number(qty.min)), Number(qty.max));
            });
        });

        // Tambah ke Troli: add without leaving the page, then say what was added (Beli Sekarang still goes to checkout)
        buyForm.addEventListener('submit', async e => {
            if (e.submitter?.value !== 'cart') return;
            e.preventDefault();
            const btn = e.submitter;
            btn.disabled = true;
            try {
                const body = new FormData(buyForm);
                body.set('action', 'cart');
                const res = await fetch(location.href, { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
                const data = await res.json();
                setCartCount(data.count);
                showToast(data.message, data.ok);
            } catch (err) {
                buyForm.submit(); // fall back to a normal page load
            } finally {
                btn.disabled = false;
            }
        });
    }

    // Notifications ("You just added … to the cart."): close button, and they go away by themselves
    const toastArea = document.querySelector('.toast-area');
    const dismiss = toast => {
        toast.classList.add('is-leaving');
        setTimeout(() => toast.remove(), 250);
    };
    const arm = toast => {
        toast.querySelector('.toast-close').addEventListener('click', () => dismiss(toast));
        setTimeout(() => toast.isConnected && dismiss(toast), 6000);
    };
    function showToast(message, ok) {
        if (!toastArea) return;
        toastArea.querySelectorAll('.toast').forEach(t => t.remove());
        const toast = document.createElement('div');
        toast.className = 'toast ' + (ok ? 'is-success' : 'is-error');
        toast.setAttribute('role', 'status');
        toast.innerHTML = '<span class="toast-icon" aria-hidden="true"></span><p></p>'
            + (ok ? '<a class="btn btn-sm">View Cart</a>' : '') + '<button type="button" class="toast-close" aria-label="Close">×</button>';
        toast.querySelector('.toast-icon').textContent = ok ? '✓' : '!';
        toast.querySelector('p').textContent = message;
        if (ok) toast.querySelector('a').href = toastArea.dataset.cartUrl;
        toastArea.appendChild(toast);
        arm(toast);
    }
    toastArea?.querySelectorAll('.toast').forEach(arm);

    // The cart count in the header
    function setCartCount(count) {
        const link = document.querySelector('.cart-link');
        if (!link || typeof count !== 'number') return;
        let badge = link.querySelector('.cart-count');
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'cart-count';
            badge.setAttribute('aria-hidden', 'true');
            link.appendChild(badge);
        }
        badge.textContent = count;
        badge.hidden = count < 1;
        link.setAttribute('aria-label', 'Cart, ' + count + ' item' + (count === 1 ? '' : 's'));
        badge.classList.remove('is-bump');
        void badge.offsetWidth;
        badge.classList.add('is-bump');
    }

    // Home "What's New" spotlight: loops through the featured builds.
    // Pauses while the pointer or keyboard focus is on it, and doesn't auto-play for people who turn off motion
    const spot = document.querySelector('[data-spotlight]');
    const slides = spot ? [...spot.querySelectorAll('.spotlight-slide')] : [];
    if (slides.length > 1) {
        const DELAY = 3000; // ms each build stays on screen
        const title = spot.querySelector('[data-spot-title]');
        let current = 0;
        let timer = null;

        const show = i => {
            current = (i + slides.length) % slides.length;
            slides.forEach((s, n) => {
                s.classList.toggle('is-active', n === current);
                s.inert = n !== current;
            });
            title.textContent = slides[current].dataset.title;
        };
        const stop = () => clearTimeout(timer);
        const play = () => {
            stop();
            if (still || document.hidden) return;
            timer = setTimeout(() => { show(current + 1); play(); }, DELAY);
        };

        spot.addEventListener('mouseenter', stop);
        spot.addEventListener('mouseleave', play);
        spot.addEventListener('focusin', stop);
        spot.addEventListener('focusout', e => { if (!spot.contains(e.relatedTarget)) play(); });
        document.addEventListener('visibilitychange', () => (document.hidden ? stop() : play()));

        // Swipe left/right on phones (then carry on looping from there)
        let startX = null;
        const frame = spot.querySelector('.spotlight');
        frame.addEventListener('touchstart', e => { startX = e.touches[0].clientX; }, { passive: true });
        frame.addEventListener('touchend', e => {
            if (startX === null) return;
            const dx = e.changedTouches[0].clientX - startX;
            startX = null;
            if (Math.abs(dx) > 50) { show(current + (dx < 0 ? 1 : -1)); play(); }
        });

        play();
    }

    // Category scroller arrows
    const scroller = document.querySelector('.category-scroller');
    const buttons = document.querySelectorAll('.scroll-btn');
    if (!scroller || !buttons.length) return;

    const updateButtons = () => {
        const max = scroller.scrollWidth - scroller.clientWidth - 2;
        buttons.forEach(btn => {
            btn.disabled = btn.dataset.scroll === '-1' ? scroller.scrollLeft <= 2 : scroller.scrollLeft >= max;
        });
    };

    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            scroller.scrollBy({ left: Number(btn.dataset.scroll) * scroller.clientWidth * 0.8 });
        });
    });

    scroller.addEventListener('scroll', updateButtons, { passive: true });
    window.addEventListener('resize', updateButtons);
    updateButtons();
});
