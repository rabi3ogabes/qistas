import 'dart:convert';

import 'package:flutter/foundation.dart';

/// Someone who stands behind a contract (Win Plan K5): at most three on a contract. The ID number arrives masked, and
/// null for anyone who does not see ID numbers (collectors and viewers).
@immutable
class Guarantor {
  const Guarantor({required this.id, required this.name, required this.phone, this.relation, this.nationalId});

  factory Guarantor.fromJson(Map<String, dynamic> json) => Guarantor(
        id: json['id'] as String,
        name: (json['name'] ?? '').toString(),
        phone: (json['phone'] ?? '').toString(),
        relation: json['relation'] as String?,
        nationalId: json['national_id'] as String?,
      );

  /// A contract has at most this many.
  static const max = 3;

  final String id;
  final String name;
  final String phone;

  /// How they know the customer, in the owner's own words.
  final String? relation;

  /// Masked ("••••••432"), or null.
  final String? nationalId;
}

/// What a contract photo shows, as the server names it.
const contractPhotoKinds = ['id_front', 'id_back', 'contract_photo', 'invoice'];

/// A photo kept with a contract (Win Plan K5). An ordinary one comes with a link that lasts five minutes; an ID photo is
/// [isPrivate]: it has no link in the list and is opened one at a time (the server writes that to its audit log).
@immutable
class ContractPhoto {
  const ContractPhoto({required this.id, required this.kind, required this.label, this.url, this.isPrivate = false});

  factory ContractPhoto.fromJson(Map<String, dynamic> json) => ContractPhoto(
        id: json['id'] as String,
        kind: (json['kind'] ?? '').toString(),
        label: (json['label'] ?? '').toString(),
        url: json['url'] as String?,
        isPrivate: json['private'] == true,
      );

  /// A contract keeps at most this many.
  static const max = 12;

  final String id;
  final String kind;
  final String label;
  final String? url;
  final bool isPrivate;
}

/// The customer's signature on a contract (Win Plan K5): on the owner's screen ('screen') or through a link on their
/// own phone ('link'). The picture arrives as a PNG data URI.
@immutable
class ContractSignature {
  const ContractSignature({required this.method, required this.signedAt, required this.png});

  static ContractSignature? fromJson(Object? json) {
    if (json is! Map<String, dynamic>) return null;
    final image = (json['image'] ?? '').toString();
    final comma = image.indexOf(',');

    return ContractSignature(
      method: (json['method'] ?? 'screen').toString(),
      signedAt: DateTime.tryParse((json['signed_at'] ?? '').toString())?.toLocal() ?? DateTime.now(),
      png: comma < 0 ? Uint8List(0) : base64Decode(image.substring(comma + 1)),
    );
  }

  final String method;
  final DateTime signedAt;
  final Uint8List png;

  bool get throughLink => method == 'link';
}

/// A link for the customer to sign on their own phone, with its code: both shown once, to be sent apart.
@immutable
class SigningLink {
  const SigningLink({required this.url, required this.code, required this.expiresAt, this.phone});

  factory SigningLink.fromJson(Map<String, dynamic> json) => SigningLink(
        url: (json['url'] ?? '').toString(),
        code: (json['code'] ?? '').toString(),
        expiresAt: DateTime.tryParse((json['expires_at'] ?? '').toString())?.toLocal() ?? DateTime.now(),
        phone: json['phone'] as String?,
      );

  final String url;
  final String code;
  final DateTime expiresAt;
  final String? phone;
}
