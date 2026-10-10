<?php

// Arabic has six plural forms, in this order: zero, one, two, few (3-10), many (11-99), other (100+).
// These are the nouns that follow a number ("حتى 5 عملاء"), so they carry no article.
return [
    'customers' => 'عميل|عميل|عميلين|عملاء|عميلًا|عميل',
    'active_contracts' => 'عقد نشط|عقد نشط|عقدين نشطين|عقود نشطة|عقدًا نشطًا|عقد نشط',
    'pdf_statements' => 'كشف حساب PDF شهريًا|كشف حساب PDF شهريًا|كشفي حساب PDF شهريًا|كشوف حساب PDF شهريًا|كشف حساب PDF شهريًا|كشف حساب PDF شهريًا',
    'api_tokens' => 'رمز وصول API|رمز وصول API|رمزي وصول API|رموز وصول API|رمز وصول API|رمز وصول API',
    'members' => 'شخص|شخص|شخصين|أشخاص|شخصًا|شخص',
    'investors' => 'مستثمر|مستثمر|مستثمرَين|مستثمرين|مستثمرًا|مستثمر',
];
