import 'package:flutter/widgets.dart';

import '../../core/l10n/translations.dart';

/// Countries a business can start in: exactly the server's list (it refuses any other code), in the order the website
/// offers them. The code decides the currency and the time zone, which the server works out; the app never sends either.
const List<String> countryCodes = [
  'SA', 'AE', 'QA', 'KW', 'BH', 'OM', 'EG', 'JO', 'LB', 'IQ', 'MA', 'DZ', 'TN', 'LY', 'SD', 'YE', 'SY', 'MR', 'TR', 'PK', 'IN', 'BD', 'MY', 'ID',
  'FR', 'ES', 'DE', 'IT', 'GB', 'US', 'CA', 'AU', 'NG', 'KE', 'ZA',
];

/// A country's name in the app's language; a code the app has no name for is shown as it is.
String countryName(BuildContext context, String code) => switch (code.toUpperCase()) {
      'SA' => context.t('Saudi Arabia'),
      'AE' => context.t('United Arab Emirates'),
      'QA' => context.t('Qatar'),
      'KW' => context.t('Kuwait'),
      'BH' => context.t('Bahrain'),
      'OM' => context.t('Oman'),
      'EG' => context.t('Egypt'),
      'JO' => context.t('Jordan'),
      'LB' => context.t('Lebanon'),
      'IQ' => context.t('Iraq'),
      'MA' => context.t('Morocco'),
      'DZ' => context.t('Algeria'),
      'TN' => context.t('Tunisia'),
      'LY' => context.t('Libya'),
      'SD' => context.t('Sudan'),
      'YE' => context.t('Yemen'),
      'SY' => context.t('Syria'),
      'MR' => context.t('Mauritania'),
      'TR' => context.t('Türkiye'),
      'PK' => context.t('Pakistan'),
      'IN' => context.t('India'),
      'BD' => context.t('Bangladesh'),
      'MY' => context.t('Malaysia'),
      'ID' => context.t('Indonesia'),
      'FR' => context.t('France'),
      'ES' => context.t('Spain'),
      'DE' => context.t('Germany'),
      'IT' => context.t('Italy'),
      'GB' => context.t('United Kingdom'),
      'US' => context.t('United States'),
      'CA' => context.t('Canada'),
      'AU' => context.t('Australia'),
      'NG' => context.t('Nigeria'),
      'KE' => context.t('Kenya'),
      'ZA' => context.t('South Africa'),
      _ => code,
    };

/// A time zone as a person says it: the city part of its name, "Kuala Lumpur" for Asia/Kuala_Lumpur. The app has no
/// plugin for zone names in the reader's language, and the city reads the same in every one.
String timeZoneCity(String zone) {
  final city = zone.contains('/') ? zone.substring(zone.lastIndexOf('/') + 1) : zone;

  return city.replaceAll('_', ' ');
}
