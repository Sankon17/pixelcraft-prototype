// Mobile nav toggle and category scroller
document.addEventListener('DOMContentLoaded', () => {
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
