<?php
/**
 * Application Configuration
 */
return [
    'name'            => 'Smart Class Presence Log-in System',
    'short_name'      => 'SCPLS',
    'school_name'     => 'Monico Rarama National High School',
    'school_short'    => 'MRNHS',
    'academic_year'   => '2026-2027',
    'timezone'        => 'Asia/Manila',
    'debug'           => false,  // production — no error output to visitors
    'version'         => '2.3.0',

    // Attendance windows (minutes before/after start time)
    'attendance' => [
        'on_time_window'     => 15,  // minutes before start time to count as on-time
        'late_window'        => 30,  // minutes after start time before marked late
        'absent_threshold'   => 60,  // minutes after start time before marked absent
    ],

    // Parent notifications — email only. Each parent gets at most 2 mails per
    // school day: arrival (first scheduled class) + dismissal (last scheduled class).
    'mail' => [
        'enabled'           => true,
        'sender_name'       => 'MRNHS Attendance',   // display name parents see
        'school_name_short' => 'MRNHS',
        'reply_to'          => '',                   // optional: school inbox for parent replies
        // Shared secret for the cron/task endpoint (/tasks/notifications.php?key=...)
        'task_key'          => 'scpls-tasks-8f3a2c',
    ],

    // Email delivery — Gmail SMTP + App Password (free, 500 sends/day).
    // -------------------------------------------------------------------
    // SWITCH TO THE DEDICATED SCHOOL ACCOUNT (recommended — keeps personal mail out):
    //   1. Create a free Gmail, e.g. monicoraramaNHS@gmail.com
    //   2. Turn on 2-Step Verification for it
    //   3. Google Account > Security > App passwords > generate one for "Mail"
    //   4. Set username + password + from_email below to that account
    //   5. Optionally set mail.reply_to above to the same (or the school's real inbox)
    // Gmail rewrites the sender to the authenticated account, so from_email must
    // equal username (or a verified "Send mail as" alias).
    'smtp' => [
        'host'      => 'smtp.gmail.com',
        'port'      => 587,
        'username'  => 'matniqqa@gmail.com',
        'password'  => 'xghspnkqyzbywbhf',
        'from_email'=> 'matniqqa@gmail.com',
    ],

    // Session
    'session_lifetime' => 86400, // 24 hours
];
