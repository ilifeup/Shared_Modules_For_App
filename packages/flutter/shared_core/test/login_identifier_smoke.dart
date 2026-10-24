import 'package:eachcore_shared_core/eachcore_shared_core.dart';

void main() {
  if (classifyLoginIdentifier(' user@example.invalid ') !=
      LoginIdentifierKind.email) {
    throw StateError('Email classification failed');
  }
  if (classifyLoginIdentifier(' +886912345678 ') !=
      LoginIdentifierKind.phone) {
    throw StateError('Phone classification failed');
  }
  var rejected = false;
  try {
    classifyLoginIdentifier('  ');
  } on ArgumentError {
    rejected = true;
  }
  if (!rejected) throw StateError('Blank identifier accepted');
  print('S1 dual identifier smoke: PASS');
}
