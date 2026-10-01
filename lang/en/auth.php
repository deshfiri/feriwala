<?php

/*
 * Authentication messages and the sign-in, registration and recovery screens (§6).
 *
 * The first three lines are the ones Laravel's own guard and throttle read.
 * `failed` says the credentials do not match, and deliberately not which half of
 * them: naming the email would turn the form into a way of asking whether an
 * address has an account here.
 */

return [
    'failed' => 'These credentials do not match our records.',

    'password' => 'The provided password is incorrect.',

    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'login' => [
        'title' => 'Log in',
        'heading' => 'Welcome back',
        'intro' => 'Sign in to access your workspace and continue managing your business.',
        'email' => 'Email address',
        'email_placeholder' => 'email@example.com',
        'password' => 'Password',
        'password_placeholder' => 'Enter your password',
        'forgot' => 'Forgot password?',
        'remember' => 'Remember me',
        'submit' => 'Sign in',
        'submitting' => 'Signing in...',
        'new_here' => 'New to the platform?',
        'create_account' => 'Create an account',
        'supplier_prompt' => 'Want to become a supplier?',
        'supplier_hint' => 'Join our growing supplier network.',
        'supplier_cta' => 'Become a Supplier',
    ],

    'showcase' => [
        'eyebrow' => 'One connected workspace',
        'heading' => 'Run your business',
        'heading_muted' => 'with more control.',
        'description' => 'Track orders, monitor performance and manage day-to-day operations from one connected workspace.',
        'overview' => 'Overview',
        'dashboard' => 'Business Dashboard',
        'this_month' => 'This month',
        'orders' => 'Orders',
        'orders_change' => '+12.5% this month',
        'revenue' => 'Revenue',
        'total_revenue' => 'Total revenue',
        'customers' => 'Customers',
        'active_customers' => 'Active customers',
        'sales_overview' => 'Sales Overview',
        'performance' => 'Performance over time',
        'trust' => 'Built to keep your business moving.',
    ],

    'register' => [
        'title' => 'Register',
        'layout_title' => 'Create an account',
        'layout_description' => 'Enter your details below to create your account',
        'name' => 'Name',
        'name_placeholder' => 'Full name',
        'email' => 'Email address',
        'mobile' => 'Mobile number',
        'mobile_hint' => 'We send a verification code to this number.',
        'password' => 'Password',
        'password_placeholder' => 'Password',
        'password_confirmation' => 'Confirm password',
        'password_confirmation_placeholder' => 'Confirm password',
        'optional_legend' => 'Optional — you can add these later',
        'date_of_birth' => 'Date of birth',
        'gender' => 'Gender',
        'gender_unspecified' => 'Not specified',
        'country' => 'Country',
        'nationality' => 'Nationality',
        'nationality_placeholder' => 'Bangladeshi',
        'referral_code' => 'Referral code',
        'referral_placeholder' => 'Optional',
        'terms' => 'I accept the terms and conditions',
        'privacy' => 'I accept the privacy policy',
        'submit' => 'Create account',
        'have_account' => 'Already have an account?',
        'log_in' => 'Log in',
    ],

    'forgot' => [
        'title' => 'Forgot password',
        'description' => 'Enter your email to receive a password reset link',
        'email' => 'Email address',
        'submit' => 'Email password reset link',
        'return_to' => 'Or, return to',
        'log_in' => 'log in',
    ],

    'reset' => [
        'title' => 'Reset password',
        'description' => 'Please enter your new password below',
        'email' => 'Email',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'submit' => 'Reset password',
    ],

    'confirm' => [
        'title' => 'Confirm password',
        'description' => 'This is a secure area of the application. Please confirm your password before continuing.',
        'passkey' => 'Confirm with passkey',
        'passkey_loading' => 'Confirming...',
        'separator' => 'Or confirm with password',
        'password' => 'Password',
        'submit' => 'Confirm password',
    ],

    'two_factor' => [
        'title' => 'Two-factor authentication',
        'code_title' => 'Authentication code',
        'code_description' => 'Enter the authentication code provided by your authenticator application.',
        'code_toggle' => 'login using a recovery code',
        'recovery_title' => 'Recovery code',
        'recovery_description' => 'Please confirm access to your account by entering one of your emergency recovery codes.',
        'recovery_toggle' => 'login using an authentication code',
        'recovery_placeholder' => 'Enter recovery code',
        'or_you_can' => 'or you can',
        'submit' => 'Continue',
    ],

    'verify_email' => [
        'layout_description' => 'One step left before your account is ready',
    ],
];
