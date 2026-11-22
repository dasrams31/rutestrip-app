@extends('layouts.app')

@section('title', __('Lokasi pendaki') . ' | RuteStrip')

@section('content')
@include('partials.rs-kit')
<div class="rs-app pb-16">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 pt-8">
        <p class="text-sm font-semibold text-rs-z7">{{ __('Lokasi langsung dari aplikasi RuteStrip') }}</p>
        @if(! $share)
            <h1 class="rs-display uppercase text-4xl text-rs-z9 leading-none mt-1">{{ __('Tautan tidak ditemukan') }}</h1>
            <p class="mt-3 text-rs-ink">{{ __('Tautan lokasi ini tidak ada atau salah ketik. Minta tautan baru ke pendakinya.') }}</p>
        @else
            <div x-data="liveShare(@js($payload), @js(route('live.data', $share->token)))" x-init="init()">
                <h1 class="rs-display uppercase text-4xl text-rs-z9 leading-none mt-1" x-text="data.name"></h1>
                <p class="mt-2 text-sm text-rs-ink" aria-live="polite">
                    <template x-if="data.active && data.position_at">
                        <span x-text="@js(__('Posisi terakhir')) + ' ' + ago(data.position_at)
                            + (data.elevation_m ? ' · ' + Math.round(data.elevation_m) + ' mdpl' : '')
                            + (data.battery !== null ? ' · ' + @js(__('baterai')) + ' ' + data.battery + '%' : '')"></span>
                    </template>
                    <template x-if="data.active && !data.position_at">
                        <span>{{ __('Belum ada posisi. Posisi masuk saat HP pendaki dapat sinyal.') }}</span>
                    </template>
                    <template x-if="!data.active">
                        <span class="font-semibold text-red-800">{{ __('Berbagi lokasi sudah berakhir.') }}</span>
                    </template>
                </p>
                <div id="live-map" class="mt-4 w-full h-[60vh] min-h-[320px] rounded-xl border border-rs-line bg-rs-mist" role="img" :aria-label="@js(__('Peta lokasi')) + ' ' + data.name"></div>
                <p class="mt-3 text-sm text-rs-muted">
                    {{ __('Posisi diperbarui saat HP pendaki dapat sinyal, jadi bisa tertinggal beberapa menit atau jam di gunung. Halaman ini memuat ulang sendiri tiap 30 detik.') }}
                </p>
                <p class="mt-1 text-sm text-rs-muted" x-text="@js(__('Tautan berlaku sampai')) + ' ' + new Date(data.expires_at).toLocaleString()"></p>
                <a x-show="data.active && data.lat" x-cloak class="inline-flex mt-3 min-h-[44px] items-center font-semibold text-rs-z7 underline"
                   :href="'https://www.google.com/maps/search/?api=1&query=' + data.lat + ',' + data.lng" target="_blank" rel="noopener noreferrer">{{ __('Buka di Google Maps') }}</a>
            </div>
        @endif
        <p class="mt-8 text-xs text-rs-muted">{{ __('Jangan bagikan tautan ini ke orang yang tidak kamu kenal. Dalam keadaan darurat hubungi 112 atau Basarnas 115.') }}</p>
    </div>
</div>
@if($share)
<script>
function liveShare(initial, url) {
    return {
        data: initial, map: null, marker: null, ring: null,
        init() {
            this.map = L.map('live-map', { zoomControl: true });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(this.map);
            this.draw(true);
            setInterval(() => this.refresh(), 30000);
        },
        async refresh() {
            try {
                const r = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (r.ok) { this.data = await r.json(); this.draw(false); }
            } catch (e) {}
        },
        draw(first) {
            const d = this.data;
            if (!d.active || d.lat === null) {
                if (first) this.map.setView([-7.5, 110.3], 7);
                if (this.marker) { this.marker.remove(); this.marker = null; }
                if (this.ring) { this.ring.remove(); this.ring = null; }
                return;
            }
            const at = [d.lat, d.lng];
            if (!this.marker) {
                this.marker = L.circleMarker(at, { radius: 9, color: '#ffffff', weight: 3, fillColor: '#047857', fillOpacity: 1 }).addTo(this.map);
                this.ring = L.circle(at, { radius: d.accuracy_m || 0, color: '#047857', weight: 1, fillOpacity: 0.12 }).addTo(this.map);
            } else {
                this.marker.setLatLng(at);
                this.ring.setLatLng(at).setRadius(d.accuracy_m || 0);
            }
            if (first) this.map.setView(at, 15);
        },
        ago(iso) {
            const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
            if (s < 90) return @js(__('baru saja'));
            if (s < 5400) return Math.round(s / 60) + ' ' + @js(__('menit lalu'));
            return Math.round(s / 3600) + ' ' + @js(__('jam lalu'));
        },
    };
}
</script>
@endif
@endsection
