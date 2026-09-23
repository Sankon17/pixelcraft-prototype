// Mobile nav toggle and category scroller
document.addEventListener('DOMContentLoaded', () => {
    const header = document.querySelector('.site-header');
    const toggle = document.querySelector('.nav-toggle');
    if (header && toggle) {
        toggle.addEventListener('click', () => {
            const open = header.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', open);
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
