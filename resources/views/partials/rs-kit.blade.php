{{--
    Shared visual kit for the app pages (account, upload, provenance, admin, docs).
    Same language as the landing (DESIGN.md): zamrud + kertas peta, kuning fajar only
    for the one main action, Barlow Condensed headings, contour lines as the motif.
    Include once per page: @include('partials.rs-kit'). Wrap the page in .rs-app.
--}}
@once
@push('head')
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
{{-- Barlow Condensed (SIL OFL 1.1): drawn from road and trail signage, the "papan penunjuk jalur" voice. --}}
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800&display=swap" rel="stylesheet">
@endpush

@push('styles')
<style>
    .rs-app { --rs-ink: #14231d; --rs-muted: #4b5d55; --rs-z9: #053b30; --rs-z7: #047857; --rs-paper: #f6f4ec; --rs-line: #dcd8c9; --rs-edge: #7c8c84; --rs-sun: #FCD34D; --rs-err: #b91c1c;
               color: var(--rs-ink); background: var(--rs-paper); min-height: calc(100vh - 4rem); }
    .rs-display, .rs-display * { font-family: 'Barlow Condensed', 'Roboto Condensed', 'Arial Narrow', sans-serif-condensed, 'Inter', system-ui, sans-serif; font-weight: 800; letter-spacing: -0.005em; }
    .rs-num { font-variant-numeric: tabular-nums; }
    .rs-app a, .rs-app button, .rs-app label { -webkit-tap-highlight-color: transparent; }

    /* Focus: dark ring on paper and white surfaces, white ring on zamrud. */
    .rs-app :focus-visible { outline: 3px solid var(--rs-z9); outline-offset: 2px; }
    .rs-app .rs-on-dark :focus-visible { outline-color: #fff; }

    /* Kuning fajar = the one next step on the page. Bordered like a trail sign. */
    .rs-sign { background: var(--rs-sun); color: var(--rs-z9); border: 2px solid var(--rs-z9); }
    .rs-sign:hover { background: #fde68a; }
    .rs-sign:disabled { opacity: .6; cursor: progress; }
    .rs-btn { background: var(--rs-z7); color: #fff; border: 2px solid var(--rs-z7); }
    .rs-btn:hover { background: #065f46; border-color: #065f46; }
    .rs-btn-ghost { background: #fff; color: var(--rs-z9); border: 2px solid var(--rs-z9); }
    .rs-btn-ghost:hover { background: #ecfdf5; }
    .rs-on-dark .rs-btn-ghost { background: transparent; color: #fff; border-color: #a7f3d0; }
    .rs-on-dark .rs-btn-ghost:hover { background: rgba(255,255,255,.08); }
    .rs-sign, .rs-btn, .rs-btn-ghost { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 48px; padding: 0 1.25rem; border-radius: 8px; font-weight: 700; text-align: center; line-height: 1.2; transition: background-color .15s; }
    .rs-btn-sm { min-height: 44px; padding: 0 1rem; font-size: .875rem; }

    /* Surfaces: white sheets on map paper, a thin line instead of big shadows. */
    .rs-card { background: #fff; border: 1px solid var(--rs-line); border-radius: 12px; }
    .rs-rule { border-color: var(--rs-line); }
    .rs-muted { color: var(--rs-muted); }

    /* Form fields: 48 px tall, border meets 3:1 against white, error text always written out. */
    .rs-label { display: block; font-size: .9375rem; font-weight: 600; color: var(--rs-ink); margin-bottom: .375rem; }
    .rs-hint { font-size: .8125rem; color: var(--rs-muted); margin-top: .375rem; }
    .rs-input { display: block; width: 100%; min-height: 48px; padding: .7rem .9rem; font-size: 1rem; color: var(--rs-ink); background: #fff; border: 1.5px solid var(--rs-edge); border-radius: 8px; }
    .rs-input::placeholder { color: #6b7a73; }
    .rs-input:focus { outline: 3px solid var(--rs-z7); outline-offset: 1px; border-color: var(--rs-z7); }
    .rs-input[aria-invalid="true"] { border-color: var(--rs-err); }
    textarea.rs-input { min-height: 0; resize: vertical; }
    .rs-error { display: flex; gap: .375rem; align-items: flex-start; margin-top: .375rem; font-size: .875rem; font-weight: 500; color: var(--rs-err); }
    .rs-check { width: 1.25rem; height: 1.25rem; accent-color: var(--rs-z7); flex-shrink: 0; }

    /* Drop zone: dashed edge like a planned route, solid zamrud while a file is dragged over it. */
    .rs-dropzone.is-drag { border-color: var(--rs-z7) !important; border-style: solid; background: #ecfdf5; }

    /* Contour motif: drawn in once when the page opens, static with reduced motion. */
    .rs-topo path { stroke-dasharray: 100; stroke-dashoffset: 0; }
    @media (prefers-reduced-motion: no-preference) {
        .rs-topo path { animation: rs-draw 1.6s cubic-bezier(.33, 0, .2, 1) both; animation-delay: calc(var(--i, 0) * 70ms); }
        @keyframes rs-draw { from { stroke-dashoffset: 100; } to { stroke-dashoffset: 0; } }
    }

    /* Difficulty as 1 to 4 zamrud bars (same as the landing cards). */
    .rs-diff { display: inline-flex; gap: 3px; align-items: flex-end; }
    .rs-diff i { display: block; width: 6px; border-radius: 1px; background: #cfd8d3; }
    .rs-diff i:nth-child(1) { height: 8px; } .rs-diff i:nth-child(2) { height: 11px; } .rs-diff i:nth-child(3) { height: 14px; } .rs-diff i:nth-child(4) { height: 17px; }
    .rs-diff i.on { background: var(--rs-z7); }

    /* Status chips that carry a word, never colour alone. */
    .rs-chip { display: inline-flex; align-items: center; gap: .375rem; padding: .2rem .55rem; border-radius: 6px; font-size: .8125rem; font-weight: 600; line-height: 1.3; border: 1px solid; }
    .rs-chip-ok { color: #065f46; background: #ecfdf5; border-color: #a7f3d0; }
    .rs-chip-bad { color: #991b1b; background: #fef2f2; border-color: #fecaca; }
    .rs-chip-warn { color: #92400e; background: #fffbeb; border-color: #fde68a; }
    .rs-chip-plain { color: var(--rs-muted); background: #fff; border-color: var(--rs-line); }

    /* Tables scroll inside their own box, never the page. */
    /* position: relative keeps sr-only labels inside wide tables from escaping to the page edge. */
    .rs-table-wrap { position: relative; overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .rs-table { width: 100%; font-size: .875rem; border-collapse: collapse; }
    .rs-table th { text-align: left; font-weight: 600; color: var(--rs-muted); background: #faf9f4; padding: .7rem .9rem; border-bottom: 1px solid var(--rs-line); white-space: nowrap; }
    .rs-table td { padding: .75rem .9rem; border-bottom: 1px solid #ece9de; vertical-align: top; }
    .rs-table tr:last-child td { border-bottom: 0; }

    /* The page runs straight into the footer (no strip of body background between them). */
    main:has(> .rs-app:last-child) + footer { margin-top: 0; }
</style>
@endpush

@push('scripts')
<script>
    // After a failed submit, take the hiker to the first field that needs fixing.
    (function () {
        var field = document.querySelector('.rs-app [aria-invalid="true"]');
        if (field) field.focus();
    })();
</script>
@endpush
@endonce
