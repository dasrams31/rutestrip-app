<?php

// English translation of lang/id/account.php.
return [

    'altcha_failed' => 'The anti-robot check failed, please try again.',

    'login' => [
        'title'        => 'Log in',
        'heading'      => 'Log in to RuteStrip',
        'subtitle'     => 'One account for RuteStrip and AI Chat',
        'email'        => 'Email',
        'password'     => 'Password',
        'remember'     => 'Remember me',
        'submit'       => 'Log in',
        'no_account'   => 'No account yet?',
        'register'     => 'Sign up',
        'admin_link'   => 'Log in as admin',
        'back_home'    => 'Back to home',
        'footer'       => 'Log in to save favourite routes and see your search history.',
        'failed'       => 'Wrong email or password.',
        'welcome'      => 'Welcome, :name!',
    ],

    'register' => [
        'title'                    => 'Create an account',
        'subtitle'                 => 'Sign up to save favourite routes and use AI Chat',
        'name'                     => 'Full name',
        'name_placeholder'         => 'Your name',
        'email'                    => 'Email',
        'password'                 => 'Password',
        'password_placeholder'     => 'At least 8 characters',
        'password_confirmation'    => 'Confirm password',
        'confirmation_placeholder' => 'Repeat the password',
        'submit'                   => 'Sign up',
        'has_account'              => 'Already have an account?',
        'login'                    => 'Log in',
        'success'                  => 'Your account is ready. Welcome, :name.',
    ],

    'admin' => [
        'title'     => 'Admin log in',
        'subtitle'  => 'For system administrators only',
        'email'     => 'Admin email',
        'submit'    => 'Log in as admin',
        'user_link' => 'Log in as a regular user',
        'footer'    => 'This page is for system administrators only.',
        'not_admin' => 'This is not an admin account. Please log in on the user page.',
        'welcome'   => 'Welcome, Admin :name!',
    ],

    'logout' => [
        'success' => 'You have been logged out.',
    ],

    'api' => [
        'invalid_credentials' => 'Wrong email or password.',
        'invalid_token'       => 'Your session is invalid or has expired. Please log in again.',
        'throttled'           => 'Too many attempts. Try again in :seconds seconds.',
    ],

    'settings' => [
        'title'                 => 'Account settings',
        'subtitle'              => 'Applies to RuteStrip and AI Chat.',
        'back'                  => 'Dashboard',
        'profile'               => 'Profile',
        'profile_help'          => 'The name and email used on RuteStrip and AI Chat.',
        'name'                  => 'Name',
        'email'                 => 'Email',
        'save_profile'          => 'Save profile',
        'profile_saved'         => 'Profile updated.',
        'password'              => 'Password',
        'password_help'         => 'After a password change, other devices and AI Chat are logged out within a few minutes. This browser stays logged in.',
        'current_password'      => 'Current password',
        'new_password'          => 'New password',
        'new_password_hint'     => 'At least 8 characters.',
        'password_confirmation' => 'Repeat the new password',
        'save_password'         => 'Change password',
        'password_saved'        => 'Password changed. Other devices will be logged out shortly.',
        'current_password_wrong' => 'The current password is wrong.',
        'language'              => 'Language',
        'language_help'         => 'Display language for RuteStrip and AI Chat.',
        'language_id'           => 'Bahasa Indonesia',
        'language_en'           => 'English',
        'save_language'         => 'Save language',
        'language_saved'        => 'Language updated.',
    ],

    // Validation messages for the account forms and the SSO API.
    'validation' => [
        'required'  => 'The :attribute field is required.',
        'string'    => 'The :attribute must be text.',
        'email'     => 'The :attribute must be a valid email address.',
        'max'       => 'The :attribute may not be longer than :max characters.',
        'min'       => 'The :attribute must be at least :min characters.',
        'unique'    => 'This :attribute is already registered.',
        'confirmed' => 'The :attribute confirmation does not match.',
        'in'        => 'The selected :attribute is not valid.',
    ],

    'attributes' => [
        'name'             => 'name',
        'email'            => 'email',
        'password'         => 'password',
        'current_password' => 'current password',
        'locale'           => 'language',
    ],

];
