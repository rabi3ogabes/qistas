import 'package:flutter/material.dart';

import '../../core/design/qistas_symbol.dart';
import '../../core/design/tokens.dart';

/// The first moment: the mark settles into place, the plumb bob dropping and steadying. With "reduce motion" on
/// (or when asked for a still frame) it simply appears.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(vsync: this, duration: const Duration(milliseconds: 1100));
  late final Animation<double> _drop = Tween<double>(begin: 1, end: 0).animate(CurvedAnimation(parent: _controller, curve: Curves.elasticOut));
  late final Animation<double> _fade = CurvedAnimation(parent: _controller, curve: const Interval(0, 0.4, curve: Curves.easeOut));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.disableAnimationsOf(context)) {
      _controller.value = 1;
    } else if (!_controller.isAnimating && _controller.value == 0) {
      _controller.forward();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return Scaffold(
      backgroundColor: c.bg,
      body: Center(
        child: AnimatedBuilder(
          animation: _controller,
          builder: (context, _) => Opacity(
            opacity: _fade.value,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                QistasSymbol(height: 96, drop: _drop.value),
                const SizedBox(height: 18),
                Text('qistas', style: Theme.of(context).textTheme.headlineLarge?.copyWith(letterSpacing: 1.5)),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
