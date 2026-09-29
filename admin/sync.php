<?php
session_start();
require_once '../includes/functions.php';
require_once '_layout.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

admin_open('Price Sync', 'sync');
?>
    <header class="admin-page-head">
        <div>
            <h1>Price Sync</h1>
            <p class="admin-page-sub">Upload the PixelCraft Price Sync Reference Excel file to update all variant prices.</p>
        </div>
    </header>

    <form id="syncForm" class="sync-upload" action="sync_handler.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <label class="sync-drop" for="syncFile">
            <span class="sync-drop-icon">📄</span>
            <span id="syncFileName" class="sync-drop-text">Choose <strong>PixelCraft_PriceSync_Reference.xlsx</strong></span>
            <span class="sync-drop-hint">.xlsx only · max 5 MB · reads the "SUMMARY (DB Import)" sheet</span>
        </label>
        <input type="file" id="syncFile" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
        <button type="submit" id="syncBtn" class="btn" disabled>Upload &amp; Sync</button>
    </form>

    <div id="syncError" class="sync-error" role="alert" hidden></div>

    <section id="syncResults" hidden>
        <div class="sync-summary">
            <div class="sync-stat is-updated"><strong id="nUpdated">0</strong><span>✅ Updated</span></div>
            <div class="sync-stat is-skipped"><strong id="nSkipped">0</strong><span>⚠️ Skipped</span></div>
            <div class="sync-stat is-not_found"><strong id="nNotFound">0</strong><span>❌ Not found</span></div>
        </div>
        <p id="syncNote" class="sync-note"></p>

        <div class="sync-log">
            <table>
                <thead>
                    <tr><th>Row</th><th>Variant Name</th><th>Status</th><th>Old Price</th><th>New Price</th></tr>
                </thead>
                <tbody id="syncLog"></tbody>
            </table>
        </div>
    </section>

<script>
(function () {
    const form = document.getElementById('syncForm');
    const input = document.getElementById('syncFile');
    const btn = document.getElementById('syncBtn');
    const errorBox = document.getElementById('syncError');
    const results = document.getElementById('syncResults');
    const labels = { updated: 'Updated', skipped: 'Skipped', not_found: 'Not found' };
    const rm = n => n === null || n === undefined ? '—' : 'RM ' + Number(n).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function cell(tr, text, cls) {
        const td = tr.insertCell();
        td.textContent = text;
        if (cls) td.className = cls;
        return td;
    }

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.hidden = false;
    }

    input.addEventListener('change', () => {
        const f = input.files[0];
        document.getElementById('syncFileName').textContent = f ? f.name : 'Choose PixelCraft_PriceSync_Reference.xlsx';
        btn.disabled = !f;
    });

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const f = input.files[0];
        if (!f) return;
        errorBox.hidden = true;
        if (!/\.xlsx$/i.test(f.name)) return showError('Only .xlsx files are accepted.');
        if (f.size > 5 * 1024 * 1024) return showError('The file is too large. Maximum size is 5 MB.');

        btn.disabled = true;
        btn.textContent = 'Syncing…';
        try {
            const res = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
            let data;
            try { data = await res.json(); } catch (e) { throw new Error('Unexpected response from the server (HTTP ' + res.status + ').'); }
            if (!res.ok || data.error) throw new Error(data.error || 'Sync failed.');

            document.getElementById('nUpdated').textContent = data.updated;
            document.getElementById('nSkipped').textContent = data.skipped;
            document.getElementById('nNotFound').textContent = data.not_found;
            document.getElementById('syncNote').textContent =
                data.changed + ' price' + (data.changed === 1 ? '' : 's') + ' changed · "From" price refreshed on ' +
                data.products + ' PC' + (data.products === 1 ? '' : 's') + ' · ' + new Date().toLocaleString('en-MY');

            const body = document.getElementById('syncLog');
            body.textContent = '';
            data.log.forEach(e => {
                const tr = body.insertRow();
                tr.className = 'is-' + e.status + (e.changed ? ' is-changed' : '');
                cell(tr, e.row);
                const name = cell(tr, e.name || '(blank)');
                if (e.reason) {
                    const small = document.createElement('small');
                    small.textContent = e.reason;
                    name.appendChild(small);
                }
                cell(tr, labels[e.status], 'sync-status');
                cell(tr, rm(e.old));
                cell(tr, rm(e.new));
            });
            results.hidden = false;
            results.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (err) {
            showError(err.message);
        } finally {
            btn.disabled = !input.files[0];
            btn.textContent = 'Upload & Sync';
        }
    });
})();
</script>
<?php admin_close(); ?>
