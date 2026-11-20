<?php

// Account, sign-in and settings strings (source language). English: lang/en/account.php.
return [

    'altcha_failed' => 'Verifikasi anti-robot gagal, silakan coba lagi.',

    'login' => [
        'title'        => 'Masuk',
        'heading'      => 'Masuk ke RuteStrip',
        'subtitle'     => 'Satu akun untuk RuteStrip dan AI Chat',
        'email'        => 'Email',
        'password'     => 'Kata sandi',
        'remember'     => 'Ingat saya',
        'submit'       => 'Masuk',
        'no_account'   => 'Belum punya akun?',
        'register'     => 'Daftar',
        'admin_link'   => 'Masuk sebagai admin',
        'back_home'    => 'Kembali ke beranda',
        'footer'       => 'Masuk untuk menyimpan rute favorit dan melihat riwayat pencarian.',
        'failed'       => 'Email atau kata sandi salah.',
        'welcome'      => 'Selamat datang, :name!',
    ],

    'register' => [
        'title'                    => 'Daftar Akun',
        'subtitle'                 => 'Buat akun untuk menyimpan rute favorit dan memakai AI Chat',
        'name'                     => 'Nama lengkap',
        'name_placeholder'         => 'Nama Anda',
        'email'                    => 'Email',
        'password'                 => 'Kata sandi',
        'password_placeholder'     => 'Minimal 8 karakter',
        'password_confirmation'    => 'Konfirmasi kata sandi',
        'confirmation_placeholder' => 'Ulangi kata sandi',
        'submit'                   => 'Daftar sekarang',
        'has_account'              => 'Sudah punya akun?',
        'login'                    => 'Masuk',
        'success'                  => 'Registrasi berhasil! Selamat datang, :name.',
    ],

    'admin' => [
        'title'     => 'Masuk Admin',
        'subtitle'  => 'Khusus administrator sistem',
        'email'     => 'Email admin',
        'submit'    => 'Masuk sebagai admin',
        'user_link' => 'Masuk sebagai pengguna biasa',
        'footer'    => 'Halaman ini hanya untuk administrator sistem.',
        'not_admin' => 'Akun ini bukan akun admin. Silakan masuk di halaman pengguna.',
        'welcome'   => 'Selamat datang, Admin :name!',
    ],

    'logout' => [
        'success' => 'Anda telah keluar.',
    ],

    'api' => [
        'invalid_credentials' => 'Email atau kata sandi salah.',
        'invalid_token'       => 'Sesi tidak valid atau sudah berakhir. Silakan masuk lagi.',
        'throttled'           => 'Terlalu banyak percobaan. Coba lagi dalam :seconds detik.',
    ],

    'settings' => [
        'title'                 => 'Pengaturan Akun',
        'subtitle'              => 'Berlaku untuk RuteStrip dan AI Chat.',
        'back'                  => 'Dashboard',
        'profile'               => 'Profil',
        'profile_help'          => 'Nama dan email yang dipakai di RuteStrip dan AI Chat.',
        'name'                  => 'Nama',
        'email'                 => 'Email',
        'save_profile'          => 'Simpan profil',
        'profile_saved'         => 'Profil berhasil diperbarui.',
        'password'              => 'Kata Sandi',
        'password_help'         => 'Setelah kata sandi diganti, perangkat lain dan AI Chat otomatis keluar dalam beberapa menit. Browser ini tetap masuk.',
        'current_password'      => 'Kata sandi saat ini',
        'new_password'          => 'Kata sandi baru',
        'new_password_hint'     => 'Minimal 8 karakter.',
        'password_confirmation' => 'Ulangi kata sandi baru',
        'save_password'         => 'Ganti kata sandi',
        'password_saved'        => 'Kata sandi berhasil diganti. Perangkat lain akan keluar otomatis.',
        'current_password_wrong' => 'Kata sandi saat ini salah.',
        'language'              => 'Bahasa',
        'language_help'         => 'Bahasa tampilan untuk RuteStrip dan AI Chat.',
        'language_id'           => 'Bahasa Indonesia',
        'language_en'           => 'English',
        'save_language'         => 'Simpan bahasa',
        'language_saved'        => 'Bahasa berhasil diperbarui.',
    ],

    // Validation messages for the account forms and the SSO API.
    'validation' => [
        'required'  => ':Attribute wajib diisi.',
        'string'    => ':Attribute harus berupa teks.',
        'email'     => ':Attribute harus berupa alamat email yang valid.',
        'max'       => ':Attribute maksimal :max karakter.',
        'min'       => ':Attribute minimal :min karakter.',
        'unique'    => ':Attribute sudah terdaftar.',
        'confirmed' => 'Konfirmasi :attribute tidak cocok.',
        'in'        => ':Attribute tidak valid.',
    ],

    'attributes' => [
        'name'             => 'nama',
        'email'            => 'email',
        'password'         => 'kata sandi',
        'current_password' => 'kata sandi saat ini',
        'locale'           => 'bahasa',
    ],

];
