import 'package:flutter/foundation.dart';

/// Who the documents come from (Win Plan PP8). The logo and the signature arrive as data URIs.
@immutable
class BusinessProfile {
  const BusinessProfile({
    this.nameAr,
    this.nameEn,
    this.phone,
    this.address,
    this.crNumber,
    this.vatNumber,
    this.footer,
    this.logo,
    this.signature,
  });

  factory BusinessProfile.fromJson(Map<String, dynamic> json) => BusinessProfile(
        nameAr: json['name_ar'] as String?,
        nameEn: json['name_en'] as String?,
        phone: json['phone'] as String?,
        address: json['address'] as String?,
        crNumber: json['cr_number'] as String?,
        vatNumber: json['vat_number'] as String?,
        footer: json['footer'] as String?,
        logo: json['logo'] as String?,
        signature: json['signature'] as String?,
      );

  final String? nameAr;
  final String? nameEn;
  final String? phone;
  final String? address;
  final String? crNumber;
  final String? vatNumber;
  final String? footer;
  final String? logo;
  final String? signature;
}

/// How the workspace's documents look unless someone chooses otherwise for one of them.
@immutable
class DocumentPreferences {
  const DocumentPreferences({this.paper = 'a4', this.text = 'normal', this.sections = defaultSections, this.wording = const {}});

  factory DocumentPreferences.fromJson(Map<String, dynamic> json) => DocumentPreferences(
        paper: json['paper'] as String? ?? 'a4',
        text: json['text'] as String? ?? 'normal',
        sections: {
          ...defaultSections,
          for (final entry in (json['sections'] as Map<String, dynamic>? ?? const {}).entries) entry.key: entry.value == true,
        },
        wording: {for (final entry in (json['wording'] as Map<String, dynamic>? ?? const {}).entries) entry.key: entry.value.toString()},
      );

  /// The cost is never on until someone turns it on: it is the shop's.
  static const defaultSections = {'cost': false, 'overdue': true, 'schedule': true, 'signature': true};

  final String paper;
  final String text;
  final Map<String, bool> sections;
  final Map<String, String> wording;

  Map<String, dynamic> toJson() => {'paper': paper, 'text': text, 'sections': sections, 'wording': wording};
}
