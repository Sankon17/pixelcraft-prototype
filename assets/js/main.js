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
