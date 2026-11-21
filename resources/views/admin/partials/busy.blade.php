{{--
    Loading state for the admin forms: while the next page loads, the submit
    button says what is happening (data-busy-label) and the form is aria-busy.
    Forms with data-confirm ask first (native confirm, keyboard friendly).
--}}
@once
@push('scripts')
<script>
(function () {
    document.querySelectorAll('.rs-app form[data-busy], .rs-app form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { e.preventDefault(); return; }
            var btn = form.querySelector('[data-busy-label]');
            form.setAttribute('aria-busy', 'true');
            if (btn) {
                var label = btn.querySelector('span') || btn;
                label.textContent = btn.dataset.busyLabel;
                btn.setAttribute('aria-disabled', 'true');
                btn.style.cursor = 'progress';
            }
        });
    });
})();
</script>
@endpush
@endonce
