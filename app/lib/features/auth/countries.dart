import 'package:flutter/widgets.dart';

import '../../core/l10n/translations.dart';

/// Countries a business can start in (the same list the website knows). The code decides the starting currency.
const List<String> countryCodes = [
  'SA', 'AE', 'QA', 'KW', 'BH', 'OM', 'EG', 'JO', 'LB', 'IQ', 'MA', 'DZ', 'TN', 'LY', 'SD', 'YE', 'SY', 'MR', 'TR', 'PK', 'IN', 'BD', 'MY', 'ID',
  'FR', 'ES', 'DE', 'IT', 'GB', 'US', 'CA', 'AU', 'NG', 'KE', 'ZA',
];

/// A country's name in the app's language.
String countryName(BuildContext context, String code) => switch (code) {
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
