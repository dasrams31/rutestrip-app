// Client-only loader for the ALTCHA anti-robot widget (<altcha-widget>).
// Registers Indonesian strings under language "id".

const STRINGS_ID = {
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
};

let loading = null;

export function loadAltcha() {
  if (!loading) {
    loading = import('altcha').then(() => {
      const { i18n } = globalThis.$altcha;
      i18n.set('id', { ...i18n.get('en'), ...STRINGS_ID });
    });
  }
  return loading;
}
