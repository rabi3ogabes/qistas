import 'package:qistas/features/security/app_lock.dart';

/// The phone's fingerprint or face check, scripted: each prompt takes the next answer (the last one repeats).
class FakeDeviceAuth implements DeviceAuth {
  FakeDeviceAuth({this.isAvailable = true, List<bool> answers = const [true]}) : _answers = List.of(answers);

  /// A phone with no fingerprint, face or screen lock set up.
  FakeDeviceAuth.unavailable() : this(isAvailable: false, answers: const [false]);

  final bool isAvailable;
  final List<bool> _answers;
  final List<String> reasons = [];

  /// What the next prompts answer.
  set answers(List<bool> next) => _answers
    ..clear()
    ..addAll(next);

  int get prompts => reasons.length;

  @override
  Future<bool> available() async => isAvailable;

  @override
  Future<bool> authenticate(String reason) async {
    reasons.add(reason);
    return _answers.length > 1 ? _answers.removeAt(0) : _answers.first;
  }
}

/// A clock a test moves by hand.
class FakeClock {
  FakeClock([DateTime? start]) : now = start ?? DateTime(2026, 10, 11, 9);

  DateTime now;

  void advance(Duration by) => now = now.add(by);

  DateTime call() => now;
}
