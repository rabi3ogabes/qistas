<?php

declare(strict_types=1);

namespace App\Theme;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Ready-made events: the national days of the Gulf, White Friday, Ramadan and the two Eids. Each brings its countries
 * (none = everyone), colours that pass every contrast check before any repair, and a greeting in the five languages.
 * National days fall on a fixed date, so the next one is suggested; Ramadan and the Eids follow the moon, so the admin
 * sets their dates each year.
 */
final class EventPresets
{
    /**
     * @var array<string, array{name: string, countries: list<string>, date: string|null, days: int, colours: array<string, string>, tone: string, cta_url: string|null, banner: array<string, array<string, string>>}>
     */
    public const LIST = [
        'saudi_national_day' => [
            'name' => 'Saudi National Day', 'countries' => ['SA'], 'date' => '09-23', 'days' => 2, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#006C35', 'accent' => '#C8A951', 'info' => '#0B6E4F', 'bg' => '#F4F8F4'],
            'banner' => [
                'en' => ['title' => 'Happy Saudi National Day', 'message' => 'Celebrating the Kingdom with you.'],
                'ar' => ['title' => 'كل عام والوطن بخير', 'message' => 'نحتفل معكم باليوم الوطني السعودي.'],
                'fr' => ['title' => 'Joyeuse fête nationale saoudienne', 'message' => 'Nous célébrons le Royaume avec vous.'],
                'es' => ['title' => 'Feliz Día Nacional de Arabia Saudí', 'message' => 'Celebramos el Reino contigo.'],
                'ur' => ['title' => 'سعودی قومی دن مبارک', 'message' => 'ہم آپ کے ساتھ مملکت کا جشن منا رہے ہیں۔'],
            ],
        ],
        'saudi_founding_day' => [
            'name' => 'Saudi Founding Day', 'countries' => ['SA'], 'date' => '02-22', 'days' => 1, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#5B3A1E', 'accent' => '#C9A25B', 'info' => '#1F6F8B', 'bg' => '#FAF6EF'],
            'banner' => [
                'en' => ['title' => 'Happy Founding Day', 'message' => 'A history since 1727, celebrated with you.'],
                'ar' => ['title' => 'يوم التأسيس السعودي', 'message' => 'نحتفي معكم بتاريخ يمتد منذ 1727.'],
                'fr' => ['title' => 'Bonne Journée de la Fondation', 'message' => 'Une histoire depuis 1727, célébrée avec vous.'],
                'es' => ['title' => 'Feliz Día de la Fundación', 'message' => 'Una historia desde 1727, celebrada contigo.'],
                'ur' => ['title' => 'یومِ تاسیس مبارک', 'message' => '1727 سے جاری تاریخ کا جشن آپ کے ساتھ۔'],
            ],
        ],
        'uae_national_day' => [
            'name' => 'UAE National Day', 'countries' => ['AE'], 'date' => '12-02', 'days' => 2, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#00632C', 'accent' => '#C8102E', 'info' => '#1C6BA4', 'bg' => '#F7F7F4'],
            'banner' => [
                'en' => ['title' => 'Happy UAE National Day', 'message' => 'Celebrating the Union with you.'],
                'ar' => ['title' => 'كل عام والإمارات بخير', 'message' => 'نحتفل معكم بعيد الاتحاد.'],
                'fr' => ['title' => 'Joyeuse fête nationale des Émirats', 'message' => 'Nous célébrons l’Union avec vous.'],
                'es' => ['title' => 'Feliz Día Nacional de los Emiratos', 'message' => 'Celebramos la Unión contigo.'],
                'ur' => ['title' => 'متحدہ عرب امارات کا قومی دن مبارک', 'message' => 'ہم آپ کے ساتھ اتحاد کا جشن منا رہے ہیں۔'],
            ],
        ],
        'kuwait_national_day' => [
            'name' => 'Kuwait National Day', 'countries' => ['KW'], 'date' => '02-25', 'days' => 2, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#00693A', 'accent' => '#CE1126', 'info' => '#1C6BA4', 'bg' => '#F7F7F4'],
            'banner' => [
                'en' => ['title' => 'Happy Kuwait National Day', 'message' => 'Celebrating National and Liberation Days with you.'],
                'ar' => ['title' => 'كل عام والكويت بخير', 'message' => 'نحتفل معكم بالعيد الوطني وعيد التحرير.'],
                'fr' => ['title' => 'Joyeuse fête nationale du Koweït', 'message' => 'Nous célébrons les fêtes nationale et de la Libération avec vous.'],
                'es' => ['title' => 'Feliz Día Nacional de Kuwait', 'message' => 'Celebramos el Día Nacional y el de la Liberación contigo.'],
                'ur' => ['title' => 'کویت کا قومی دن مبارک', 'message' => 'قومی دن اور یومِ آزادی کا جشن آپ کے ساتھ۔'],
            ],
        ],
        'qatar_national_day' => [
            'name' => 'Qatar National Day', 'countries' => ['QA'], 'date' => '12-18', 'days' => 1, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#8A1538', 'accent' => '#C9A25B', 'info' => '#1C6BA4', 'bg' => '#FAF6F7'],
            'banner' => [
                'en' => ['title' => 'Happy Qatar National Day', 'message' => 'Celebrating Qatar with you.'],
                'ar' => ['title' => 'كل عام وقطر بخير', 'message' => 'نحتفل معكم باليوم الوطني لدولة قطر.'],
                'fr' => ['title' => 'Joyeuse fête nationale du Qatar', 'message' => 'Nous célébrons le Qatar avec vous.'],
                'es' => ['title' => 'Feliz Día Nacional de Catar', 'message' => 'Celebramos Catar contigo.'],
                'ur' => ['title' => 'قطر کا قومی دن مبارک', 'message' => 'ہم آپ کے ساتھ قطر کا جشن منا رہے ہیں۔'],
            ],
        ],
        'bahrain_national_day' => [
            'name' => 'Bahrain National Day', 'countries' => ['BH'], 'date' => '12-16', 'days' => 2, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#A0111E', 'accent' => '#C9A25B', 'info' => '#1C6BA4', 'bg' => '#FBF6F6'],
            'banner' => [
                'en' => ['title' => 'Happy Bahrain National Day', 'message' => 'Celebrating Bahrain with you.'],
                'ar' => ['title' => 'كل عام والبحرين بخير', 'message' => 'نحتفل معكم بالعيد الوطني لمملكة البحرين.'],
                'fr' => ['title' => 'Joyeuse fête nationale de Bahreïn', 'message' => 'Nous célébrons Bahreïn avec vous.'],
                'es' => ['title' => 'Feliz Día Nacional de Baréin', 'message' => 'Celebramos Baréin contigo.'],
                'ur' => ['title' => 'بحرین کا قومی دن مبارک', 'message' => 'ہم آپ کے ساتھ بحرین کا جشن منا رہے ہیں۔'],
            ],
        ],
        'oman_national_day' => [
            'name' => 'Oman National Day', 'countries' => ['OM'], 'date' => '11-20', 'days' => 1, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#9E1B22', 'accent' => '#2E7D32', 'info' => '#1C6BA4', 'bg' => '#FAF7F5'],
            'banner' => [
                'en' => ['title' => 'Happy Oman National Day', 'message' => 'Celebrating Oman with you.'],
                'ar' => ['title' => 'كل عام وعُمان بخير', 'message' => 'نحتفل معكم بالعيد الوطني لسلطنة عُمان.'],
                'fr' => ['title' => 'Joyeuse fête nationale d’Oman', 'message' => 'Nous célébrons Oman avec vous.'],
                'es' => ['title' => 'Feliz Día Nacional de Omán', 'message' => 'Celebramos Omán contigo.'],
                'ur' => ['title' => 'عمان کا قومی دن مبارک', 'message' => 'ہم آپ کے ساتھ عمان کا جشن منا رہے ہیں۔'],
            ],
        ],
        'white_friday' => [
            'name' => 'White Friday', 'countries' => [], 'date' => 'white-friday', 'days' => 4, 'tone' => 'navy', 'cta_url' => '/pricing',
            'colours' => ['primary' => '#14171D', 'accent' => '#C9A25B', 'info' => '#2B65A8', 'bg' => '#FAFAF8'],
            'banner' => [
                'en' => ['title' => 'White Friday', 'message' => 'Our best offer of the year, for a few days only.', 'cta_label' => 'See plans'],
                'ar' => ['title' => 'الجمعة البيضاء', 'message' => 'أفضل عروض السنة، لأيام قليلة فقط.', 'cta_label' => 'الخطط'],
                'fr' => ['title' => 'Vendredi blanc', 'message' => 'Notre meilleure offre de l’année, quelques jours seulement.', 'cta_label' => 'Voir les offres'],
                'es' => ['title' => 'Viernes Blanco', 'message' => 'Nuestra mejor oferta del año, solo por unos días.', 'cta_label' => 'Ver planes'],
                'ur' => ['title' => 'وائٹ فرائیڈے', 'message' => 'سال کی بہترین پیشکش، صرف چند دنوں کے لیے۔', 'cta_label' => 'پلان دیکھیں'],
            ],
        ],
        'ramadan' => [
            'name' => 'Ramadan', 'countries' => [], 'date' => null, 'days' => 30, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#1B2A4A', 'accent' => '#D4AF37', 'info' => '#1C6BA4', 'bg' => '#F8F5EC'],
            'banner' => [
                'en' => ['title' => 'Ramadan Kareem', 'message' => 'Wishing you a blessed month.'],
                'ar' => ['title' => 'رمضان كريم', 'message' => 'نتمنى لكم شهرًا مباركًا.'],
                'fr' => ['title' => 'Ramadan Kareem', 'message' => 'Nous vous souhaitons un mois béni.'],
                'es' => ['title' => 'Ramadán Kareem', 'message' => 'Le deseamos un mes bendito.'],
                'ur' => ['title' => 'رمضان کریم', 'message' => 'آپ کو بابرکت مہینہ مبارک ہو۔'],
            ],
        ],
        'eid_al_fitr' => [
            'name' => 'Eid al-Fitr', 'countries' => [], 'date' => null, 'days' => 3, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#0F4C5C', 'accent' => '#E0B04A', 'info' => '#1A6E93', 'bg' => '#F6F8F7'],
            'banner' => [
                'en' => ['title' => 'Eid Mubarak', 'message' => 'Wishing you and your family a joyful Eid al-Fitr.'],
                'ar' => ['title' => 'عيد فطر مبارك', 'message' => 'كل عام وأنتم بخير.'],
                'fr' => ['title' => 'Aïd Moubarak', 'message' => 'Joyeux Aïd el-Fitr à vous et à vos proches.'],
                'es' => ['title' => 'Eid Mubarak', 'message' => 'Feliz Eid al-Fitr para usted y su familia.'],
                'ur' => ['title' => 'عید مبارک', 'message' => 'آپ اور آپ کے اہلِ خانہ کو عید الفطر مبارک۔'],
            ],
        ],
        'eid_al_adha' => [
            'name' => 'Eid al-Adha', 'countries' => [], 'date' => null, 'days' => 4, 'tone' => 'navy', 'cta_url' => null,
            'colours' => ['primary' => '#3E2A5C', 'accent' => '#D4AF37', 'info' => '#2D5C8A', 'bg' => '#F8F6FA'],
            'banner' => [
                'en' => ['title' => 'Eid al-Adha Mubarak', 'message' => 'Wishing you a blessed Eid.'],
                'ar' => ['title' => 'عيد أضحى مبارك', 'message' => 'تقبّل الله منا ومنكم.'],
                'fr' => ['title' => 'Aïd al-Adha Moubarak', 'message' => 'Nous vous souhaitons un Aïd béni.'],
                'es' => ['title' => 'Eid al-Adha Mubarak', 'message' => 'Le deseamos un Eid bendito.'],
                'ur' => ['title' => 'عید الاضحیٰ مبارک', 'message' => 'آپ کو بابرکت عید مبارک ہو۔'],
            ],
        ],
    ];

    /**
     * The first and last day of the next occurrence (this year's while it is still on or ahead), or null for a day that
     * follows the moon.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function nextDates(string $key, ?CarbonInterface $now = null): ?array
    {
        $preset = self::LIST[$key] ?? null;

        if ($preset === null || $preset['date'] === null) {
            return null;
        }

        $zone = (string) config('qistas.timezones.'.($preset['countries'][0] ?? ''), 'Asia/Riyadh');
        $today = CarbonImmutable::instance($now ?? now())->setTimezone($zone)->startOfDay();

        foreach ([$today->year, $today->year + 1] as $year) {
            $start = $preset['date'] === 'white-friday'
                ? CarbonImmutable::create($year, 11, 1, 0, 0, 0, $zone)->nthOfMonth(4, CarbonImmutable::THURSDAY)->addDay()
                : CarbonImmutable::parse("{$year}-{$preset['date']}", $zone);
            $end = $start->addDays($preset['days'] - 1);

            if ($end->greaterThanOrEqualTo($today)) {
                return [$start->toDateString(), $end->toDateString()];
            }
        }

        return null;
    }

    /**
     * What a preset puts in a new event: its name in the admin's language, countries, dates, time zone, places, colours
     * and banner for every place.
     *
     * @return array<string, mixed>
     */
    public static function draft(string $key): array
    {
        $preset = self::LIST[$key];
        [$start, $end] = self::nextDates($key) ?? [null, null];
        $banner = ['enabled' => true, 'tone' => $preset['tone'], 'dismissible' => true, 'image' => false, 'cta_url' => $preset['cta_url'], 'text' => $preset['banner']];

        return [
            'preset' => $key,
            'name' => __($preset['name']),
            'countries' => $preset['countries'],
            'starts_on' => $start,
            'ends_on' => $end,
            'timezone' => (string) config('qistas.timezones.'.($preset['countries'][0] ?? ''), 'Asia/Riyadh'),
            'surfaces' => Appearance::SURFACES,
            'colours' => $preset['colours'],
            'banners' => array_fill_keys(Appearance::SURFACES, $banner),
        ];
    }
}
