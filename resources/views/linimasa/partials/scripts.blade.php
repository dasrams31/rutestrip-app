{{--
    Linimasa behaviour, once per page. Everything works as plain links and forms first;
    this script only makes it smoother:
    - like, save and follow forms (data-social) are sent with fetch and flip in place;
      if the request fails, the form is submitted normally;
    - forms with data-confirm ask first;
    - "Muat lagi" (data-more) appends the next cards instead of opening a new page;
    - "Bagikan" (data-share) opens the phone's share sheet or copies the link;
    - one open "more" menu at a time, closed with Escape or a click elsewhere.
--}}
@once
@push('styles')
<style>
    /* Card actions: a 44 px row of icon + count (+ word from 640 px). */
    .rs-act { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; min-height: 44px; padding: 0 .5rem; border-radius: 8px; font-size: .875rem; font-weight: 600; color: var(--rs-muted); }
    a.rs-act:hover, button.rs-act:hover { background: #f6f4ec; color: var(--rs-z9); }
    button.rs-act[aria-pressed="true"] { color: var(--rs-z9); }
    [aria-pressed="true"] > .rs-off, [aria-pressed="false"] > .rs-on { display: none; }
    form[aria-busy="true"] button { cursor: progress; opacity: .7; }
    .rs-summary { list-style: none; }
    .rs-summary::-webkit-details-marker { display: none; }
    /* Story viewer: the native dialog (Escape closes it, focus stays inside). */
    #story-viewer::backdrop { background: rgba(5, 59, 48, .82); }
</style>
@endpush

@push('scripts')
<div data-social-toast role="status" aria-live="polite"
     class="fixed left-1/2 -translate-x-1/2 bottom-24 md:bottom-8 z-[60] hidden rounded-lg bg-[#053b30] text-white text-sm font-semibold px-4 py-3 shadow-lg"></div>
<script>
(function () {
    var toastEl = document.querySelector('[data-social-toast]');
    var toastTimer;
    function toast(text) {
        if (!toastEl) return;
        toastEl.textContent = text;
        toastEl.classList.remove('hidden');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.add('hidden'); }, 2600);
    }

    function send(form) {
        return fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); });
    }

    function flip(form, kind, data) {
        var on = kind === 'like' ? data.liked : (kind === 'save' ? data.saved : data.following);
        var btn = form.querySelector('button');
        form.querySelector('[name="_method"]').value = on ? 'DELETE' : 'POST';
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (kind === 'like') {
            var count = form.querySelector('[data-count]');
            if (count) count.textContent = data.likes_count;
        }
        var label = btn.querySelector('[data-label]');
        if (label && btn.dataset.labelOn) label.textContent = on ? btn.dataset.labelOn : btn.dataset.labelOff;
        if (label && label.dataset.labelOn) label.textContent = on ? label.dataset.labelOn : label.dataset.labelOff;
        if (kind === 'follow') {
            btn.classList.remove(on ? btn.dataset.offClass : btn.dataset.onClass);
            btn.classList.add(on ? btn.dataset.onClass : btn.dataset.offClass);
            document.querySelectorAll('[data-followers-count="' + form.dataset.user + '"]').forEach(function (el) {
                el.textContent = data.followers_count;
            });
        }
        if (kind === 'save') toast(on ? @json(__('Kiriman disimpan.')) : @json(__('Kiriman dihapus dari simpanan.')));
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { e.preventDefault(); return; }
        var kind = form.dataset.social;
        if (!kind || !window.fetch || !window.FormData) {
            form.setAttribute('aria-busy', 'true');
            var busy = form.querySelector('[data-busy-label]');
            if (busy) busy.textContent = busy.dataset.busyLabel;
            return;
        }
        e.preventDefault();
        if (form.getAttribute('aria-busy') === 'true') return;
        form.setAttribute('aria-busy', 'true');
        send(form).then(function (data) { flip(form, kind, data); })
            .catch(function () { form.submit(); })
            .then(function () { form.removeAttribute('aria-busy'); });
    });

    document.addEventListener('click', function (e) {
        var more = e.target.closest('a[data-more]');
        if (more && window.fetch) {
            e.preventDefault();
            if (more.getAttribute('aria-busy') === 'true') return;
            var idle = more.dataset.idle || more.textContent;
            more.dataset.idle = idle;
            more.setAttribute('aria-busy', 'true');
            more.textContent = more.dataset.loading;
            fetch(more.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.text(); })
                .then(function (html) {
                    var tpl = document.createElement('template');
                    tpl.innerHTML = html;
                    more.closest('[data-more-wrap]').replaceWith(tpl.content);
                })
                .catch(function () {
                    more.removeAttribute('aria-busy');
                    more.textContent = more.dataset.failed;
                });
            return;
        }

        var share = e.target.closest('a[data-share]');
        if (share) {
            if (navigator.share) {
                e.preventDefault();
                navigator.share({ title: share.dataset.title, url: share.href }).catch(function () {});
            } else if (navigator.clipboard && window.isSecureContext) {
                e.preventDefault();
                navigator.clipboard.writeText(share.href).then(function () {
                    toast(@json(__('Tautan kiriman disalin.')));
                }, function () { window.location.href = share.href; });
            }
            return;
        }

        document.querySelectorAll('details[data-menu][open]').forEach(function (d) {
            if (!d.contains(e.target)) d.removeAttribute('open');
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('details[data-menu][open]').forEach(function (d) {
            d.removeAttribute('open');
            var summary = d.querySelector('summary');
            if (summary) summary.focus();
        });
    });
})();
</script>
@endpush
@endonce
