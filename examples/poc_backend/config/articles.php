<?php

/*
 * S8 M05 articles. Every App sets its own values (nothing App-specific is hard-coded).
 */
return [
    // Base of shared links (the App's public web address). The page behind it checks
    // read permission like the API: protected articles never show their content.
    'share_base_url' => env('ARTICLE_SHARE_BASE_URL', env('APP_URL', 'http://localhost')),

    // Comment blacklist (PCRE). A match rejects the comment with a warning.
    // Extend per App, e.g. ARTICLE_COMMENT_BLACKLIST='["/foo/iu","/bar/iu"]'.
    'comment_blacklist' => json_decode((string) env('ARTICLE_COMMENT_BLACKLIST', ''), true) ?: [
        '/https?:\/\/\S+/iu',                       // links (spam)
        '/(加|請加)\s*(line|賴|微信|wechat)/iu',      // contact solicitation
        '/(代辦|代購|博弈|賭場|色情)/u',
    ],

    'report_reasons' => [
        'spam' => '垃圾訊息或廣告',
        'abuse' => '人身攻擊或騷擾',
        'hate' => '仇恨或歧視',
        'sexual' => '色情或不當內容',
        'misinformation' => '不實資訊',
        'other' => '其他',
    ],

    // AI review of reported comments (server side only; the key never reaches the App).
    // driver: null (not configured → comments are hidden for an admin) | openai_compatible
    'moderation' => [
        'driver' => env('AI_MODERATION_DRIVER', 'null'),
        'endpoint' => env('AI_MODERATION_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        'api_key' => env('AI_MODERATION_API_KEY'),
        'model' => env('AI_MODERATION_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('AI_MODERATION_TIMEOUT', 15),
    ],
];
