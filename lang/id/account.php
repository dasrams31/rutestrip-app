<?php

// Account, sign-in and settings strings (source language). English: lang/en/account.php.
return [

    'altcha_failed' => 'Cek bukan robot belum lolos. Centang kotaknya lagi, lalu kirim ulang.',

    'login' => [
        'title'        => 'Masuk',
        'heading'      => 'Masuk dulu, lalu lanjut jalan.',
        'subtitle'     => 'Satu akun untuk RuteStrip dan AI Chat.',
        'email'        => 'Email',
        'password'     => 'Kata sandi',
        'remember'     => 'Biarkan saya tetap masuk di perangkat ini',
        'submit'       => 'Masuk',
        'no_account'   => 'Belum punya akun?',
        'register'     => 'Daftar gratis',
        'admin_link'   => 'Masuk sebagai admin',
        'back_home'    => 'Kembali ke beranda',
        'footer'       => 'Masuk supaya jalur favorit dan riwayat pencarianmu tersimpan.',
        'failed'       => 'Email atau kata sandi belum cocok. Coba periksa lagi.',
        'welcome'      => 'Selamat datang, :name!',
    ],

    'register' => [
        'title'                    => 'Buat akun',
        'heading'                  => 'Buat akun gratis, cukup semenit.',
        'subtitle'                 => 'Cukup nama, email, dan kata sandi. Akun yang sama juga dipakai di AI Chat.',
        'name'                     => 'Nama',
        'name_placeholder'         => 'Nama panggilan juga boleh',
        'email'                    => 'Email',
        'password'                 => 'Kata sandi',
        'password_placeholder'     => 'Minimal 8 karakter',
        'password_confirmation'    => 'Ulangi kata sandi',
        'confirmation_placeholder' => 'Ketik sekali lagi',
        'robot_label'              => 'Cek singkat bahwa kamu bukan robot',
        'robot_hint'               => 'Centang kotaknya, lalu tunggu sampai tertulis terverifikasi.',
        'submit'                   => 'Buat akun',
        'has_account'              => 'Sudah punya akun?',
        'login'                    => 'Masuk di sini',
        'success'                  => 'Akunmu sudah jadi. Selamat datang, :name.',
    ],

    'admin' => [
        'title'     => 'Masuk admin',
        'heading'   => 'Masuk ke panel admin.',
        'subtitle'  => 'Khusus pengelola RuteStrip. Pendaki cukup masuk lewat halaman biasa.',
        'email'     => 'Email admin',
        'submit'    => 'Buka panel admin',
        'user_link' => 'Bukan admin? Masuk di halaman biasa',
        'footer'    => 'Halaman ini hanya untuk pengelola situs.',
        'not_admin' => 'Akun ini bukan akun admin. Silakan masuk lewat halaman biasa.',
        'welcome'   => 'Selamat datang, Admin :name!',
    ],

    'perks' => [
        'title'     => 'Yang kamu dapat dengan akun',
        'favorites' => 'Simpan jalur yang kamu suka, buka lagi kapan saja.',
        'history'   => 'Lihat lagi apa saja yang pernah kamu cari.',
        'upload'    => 'Unggah file GPX atas namamu sendiri.',
        'chat'      => 'Pakai akun yang sama untuk AI Chat.',
    ],

    'logout' => [
        'success' => 'Kamu sudah keluar. Sampai jumpa di jalur.',
    ],

    'api' => [
        'invalid_credentials' => 'Email atau kata sandi salah.',
        'invalid_token'       => 'Sesi tidak valid atau sudah berakhir. Silakan masuk lagi.',
        'throttled'           => 'Terlalu banyak percobaan. Coba lagi dalam :seconds detik.',
    ],

    'settings' => [
        'title'                 => 'Pengaturan akun',
        'subtitle'              => 'Perubahan di sini berlaku juga untuk AI Chat.',
        'back'                  => 'Kembali ke dashboard',
        'profile'               => 'Profil',
        'profile_help'          => 'Nama dan email ini dipakai di RuteStrip dan AI Chat.',
        'name'                  => 'Nama',
        'email'                 => 'Email',
        'save_profile'          => 'Simpan profil',
        'profile_saved'         => 'Profil tersimpan.',
        'password'              => 'Kata sandi',
        'password_help'         => 'Setelah diganti, perangkat lain dan AI Chat keluar sendiri dalam beberapa menit. Browser ini tetap masuk.',
        'current_password'      => 'Kata sandi sekarang',
        'new_password'          => 'Kata sandi baru',
        'new_password_hint'     => 'Minimal 8 karakter.',
        'password_confirmation' => 'Ulangi kata sandi baru',
        'save_password'         => 'Ganti kata sandi',
        'password_saved'        => 'Kata sandi sudah diganti. Perangkat lain akan keluar sendiri.',
        'current_password_wrong' => 'Kata sandi sekarang belum cocok.',
        'language'              => 'Bahasa',
        'language_help'         => 'Bahasa tampilan untuk RuteStrip dan AI Chat.',
        'language_id'           => 'Bahasa Indonesia',
        'language_en'           => 'English',
        'save_language'         => 'Simpan bahasa',
        'nav'                   => 'Bagian pengaturan',
        'language_saved'        => 'Bahasa sudah diganti.',
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
        'current_password' => 'kata sandi sekarang',
        'locale'           => 'bahasa',
        'bio'              => 'bio',
    ],

    // Linimasa (social timeline): words that already mean something else in lang/*.json.
    'linimasa' => [
        'posts' => 'Kiriman',
    ],

];
