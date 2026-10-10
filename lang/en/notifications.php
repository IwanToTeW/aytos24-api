<?php

return [

    'verify_email' => [
        'subject' => 'Confirm your email address for Aytos24',
        'greeting' => 'Welcome to Aytos24!',
        'intro' => 'Thank you for creating an Aytos24 account. Please confirm that this email address belongs to you.',
        'reason' => 'We need a confirmed email address before you can place food orders, so that we can send you order confirmations and help you recover your account.',
        'action' => 'Confirm email address',
        'expires' => 'This link expires in :hours hour.|This link expires in :hours hours.',
        'ignore' => 'If you did not create an Aytos24 account, you can ignore this email; no account will be activated.',
        'salutation' => 'The Aytos24 team',
    ],

    'reset_password' => [
        'subject' => 'Reset your password — Aytos24',
        'greeting' => 'Hello!',
        'reason' => 'You are receiving this email because someone asked to reset the password of the Aytos24 account registered with this email address. Use the button below to choose a new password.',
        'action' => 'Reset password',
        'expires' => 'This link expires in :minutes minute and can be used only once.|This link expires in :minutes minutes and can be used only once.',
        'ignore' => 'If you did not ask to reset your password, you can ignore this email; your password will not change.',
        'salutation' => 'The Aytos24 team',
    ],

];
