<?php

// English translation of lang/id/account.php.
return [

    'altcha_failed' => 'The robot check did not pass. Tick the box again and send the form once more.',

    'login' => [
        'title'        => 'Log in',
        'heading'      => 'Log in and pick up where you left off.',
        'subtitle'     => 'One account for RuteStrip and AI Chat.',
        'email'        => 'Email',
        'password'     => 'Password',
        'remember'     => 'Keep me logged in on this device',
        'submit'       => 'Log in',
        'no_account'   => 'No account yet?',
        'register'     => 'Sign up for free',
        'admin_link'   => 'Log in as admin',
        'back_home'    => 'Back to home',
        'footer'       => 'Log in so your favourite routes and search history are kept.',
        'failed'       => 'That email and password do not match. Please check them again.',
        'welcome'      => 'Welcome, :name!',
    ],

    'register' => [
        'title'                    => 'Create an account',
        'heading'                  => 'Create a free account in a minute.',
        'subtitle'                 => 'Just a name, email and password. The same account works in AI Chat.',
        'name'                     => 'Name',
        'name_placeholder'         => 'A nickname is fine',
        'email'                    => 'Email',
        'password'                 => 'Password',
        'password_placeholder'     => 'At least 8 characters',
        'password_confirmation'    => 'Repeat the password',
        'confirmation_placeholder' => 'Type it once more',
        'robot_label'              => 'A quick check that you are not a robot',
        'robot_hint'               => 'Tick the box and wait until it says verified.',
        'submit'                   => 'Create account',
        'has_account'              => 'Already have an account?',
        'login'                    => 'Log in here',
        'success'                  => 'Your account is ready. Welcome, :name.',
    ],

    'admin' => [
        'title'     => 'Admin log in',
        'heading'   => 'Log in to the admin panel.',
        'subtitle'  => 'For RuteStrip admins only. Hikers log in on the regular page.',
        'email'     => 'Admin email',
        'submit'    => 'Open the admin panel',
        'user_link' => 'Not an admin? Log in on the regular page',
        'footer'    => 'This page is for site admins only.',
        'not_admin' => 'This is not an admin account. Please log in on the regular page.',
        'welcome'   => 'Welcome, Admin :name!',
    ],

    'perks' => [
        'title'     => 'What an account gives you',
        'favorites' => 'Save the routes you like and open them again any time.',
        'history'   => 'See everything you searched for before.',
        'upload'    => 'Upload GPX files under your own name.',
        'chat'      => 'Use the same account in AI Chat.',
    ],

    'logout' => [
        'success' => 'You are logged out. See you on the trail.',
    ],

    'api' => [
        'invalid_credentials' => 'Wrong email or password.',
        'invalid_token'       => 'Your session is invalid or has expired. Please log in again.',
        'throttled'           => 'Too many attempts. Try again in :seconds seconds.',
    ],

    'settings' => [
        'title'                 => 'Account settings',
        'subtitle'              => 'Changes here also apply to AI Chat.',
        'back'                  => 'Back to dashboard',
        'profile'               => 'Profile',
        'profile_help'          => 'This name and email are used on RuteStrip and AI Chat.',
        'name'                  => 'Name',
        'email'                 => 'Email',
        'save_profile'          => 'Save profile',
        'profile_saved'         => 'Profile saved.',
        'password'              => 'Password',
        'password_help'         => 'After you change it, other devices and AI Chat log out on their own within a few minutes. This browser stays logged in.',
        'current_password'      => 'Current password',
        'new_password'          => 'New password',
        'new_password_hint'     => 'At least 8 characters.',
        'password_confirmation' => 'Repeat the new password',
        'save_password'         => 'Change password',
        'password_saved'        => 'Password changed. Other devices will log out on their own.',
        'current_password_wrong' => 'The current password does not match.',
        'language'              => 'Language',
        'language_help'         => 'Display language for RuteStrip and AI Chat.',
        'language_id'           => 'Bahasa Indonesia',
        'language_en'           => 'English',
        'save_language'         => 'Save language',
        'nav'                   => 'Settings sections',
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
