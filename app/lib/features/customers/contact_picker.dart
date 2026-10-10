import 'package:flutter/services.dart';
import 'package:flutter_native_contact_picker/flutter_native_contact_picker.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../reminders/reminders.dart' show dialCodes;

/// A name and a number chosen from the phone's contacts.
typedef PickedContact = ({String name, String phone});

/// Opens the phone's own contact list and gives back the number chosen (Win Plan PP12). The system picker hands over
/// only that one contact, so the app never asks to read the whole address book. Null when the person backs out.
final contactPickerProvider = Provider<Future<PickedContact?> Function()>((ref) => _pickContact);

Future<PickedContact?> _pickContact() async {
  try {
    final contact = await FlutterNativeContactPicker().selectPhoneNumber();
    final phone = contact?.selectedPhoneNumber ?? contact?.phoneNumbers?.firstOrNull;
    if (contact == null || phone == null || phone.trim().isEmpty) return null;

    return (name: (contact.fullName ?? '').trim(), phone: phone.trim());
  } on PlatformException {
    return null;
  } on MissingPluginException {
    return null;
  }
}

/// [phone] with its country code in front (+966541110099), so reminders and WhatsApp reach it from anywhere. A local
/// number takes the business's country code; a number it cannot place is left exactly as it was.
String internationalPhone(String phone, String country) {
  final trimmed = phone.trim();
  final digits = trimmed.replaceAll(RegExp(r'[^\d]'), '');
  if (digits.length < 7) return trimmed;

  if (trimmed.startsWith('+')) return '+$digits';
  if (digits.startsWith('00')) return '+${digits.substring(2)}';

  final code = dialCodes[country];
  if (code != null && digits.startsWith('0')) return '+$code${digits.substring(1)}';

  return trimmed;
}
