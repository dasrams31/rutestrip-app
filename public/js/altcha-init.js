// Loads the self-hosted ALTCHA widget and registers its Indonesian strings.
// Use with <altcha-widget language="id" ...>.
import '/vendor/altcha/altcha.min.js?v=3.7.0';

globalThis.$altcha.i18n.set('id', {
    ...globalThis.$altcha.i18n.get('en'),
    ariaLinkLabel: 'ALTCHA (situs resmi)',
    cancel: 'Batal',
    enterCode: 'Masukkan kode',
    enterCodeAria: 'Masukkan kode yang Anda dengar. Tekan Spasi untuk memutar audio.',
    enterCodeFromImage: 'Untuk melanjutkan, masukkan kode pada gambar di bawah.',
    error: 'Verifikasi gagal. Silakan coba lagi.',
    expired: 'Verifikasi kedaluwarsa. Silakan ulangi.',
    footer: 'Dilindungi oleh <a href="https://altcha.org/" target="_blank" rel="noopener" aria-label="ALTCHA (situs resmi, buka di tab baru)">ALTCHA</a>',
    getAudioChallenge: 'Dengarkan kode audio',
    label: 'Saya bukan robot',
    loading: 'Memuat...',
    reload: 'Muat ulang',
    verify: 'Verifikasi',
    verificationRequired: 'Centang "Saya bukan robot" terlebih dahulu.',
    verified: 'Terverifikasi',
    verifying: 'Memverifikasi...',
    waitAlert: 'Memverifikasi... mohon tunggu.',
});
