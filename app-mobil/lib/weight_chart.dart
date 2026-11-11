/// Gráfica de evolución del peso con CustomPainter (sin dependencias).
library;

import 'package:flutter/material.dart';

import 'models.dart';

class WeightChart extends StatelessWidget {
  const WeightChart(
      {super.key, required this.history, required this.targetWeight});
  final List<WeightPoint> history;
  final double targetWeight;

  @override
  Widget build(BuildContext context) {
    final sorted = [...history]..sort((a, b) => a.date.compareTo(b.date));
    final points = sorted.length > 30 ? sorted.sublist(sorted.length - 30) : sorted;
    return SizedBox(
      height: 180,
      width: double.infinity,
      child: CustomPaint(
        painter: _WeightPainter(
          weights: points.map((e) => e.weight).toList(),
          target: targetWeight,
          line: Theme.of(context).colorScheme.primary,
          grid: Theme.of(context).colorScheme.outlineVariant,
        ),
      ),
    );
  }
}

class _WeightPainter extends CustomPainter {
  _WeightPainter(
      {required this.weights,
      required this.target,
      required this.line,
      required this.grid});
  final List<double> weights;
  final double target;
  final Color line;
  final Color grid;

  @override
  void paint(Canvas canvas, Size size) {
    if (weights.isEmpty) return;
    var lo = [...weights, target].reduce((a, b) => a < b ? a : b);
    var hi = [...weights, target].reduce((a, b) => a > b ? a : b);
    if ((hi - lo).abs() < .5) {
      lo -= .5;
      hi += .5;
    }
    final pad = (hi - lo) * .15;
    lo -= pad;
    hi += pad;

    double x(int i) => weights.length == 1
        ? size.width / 2
        : 16 + i * (size.width - 32) / (weights.length - 1);
    double y(double w) =>
        size.height - 16 - (w - lo) / (hi - lo) * (size.height - 32);

    final gridPaint = Paint()
      ..color = grid
      ..strokeWidth = 1;
    for (var i = 0; i <= 3; i++) {
      final yy = 8 + i * (size.height - 16) / 3;
      canvas.drawLine(Offset(0, yy), Offset(size.width, yy), gridPaint);
    }

    // Línea objetivo (discontinua).
    final targetPaint = Paint()
      ..color = Colors.green
      ..strokeWidth = 1.5;
    var tx = 0.0;
    const dash = 6.0, gap = 4.0;
    final ty = y(target);
    while (tx < size.width) {
      canvas.drawLine(Offset(tx, ty), Offset(tx + dash, ty), targetPaint);
      tx += dash + gap;
    }

    final linePaint = Paint()
      ..color = line
      ..strokeWidth = 2.5
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round;
    final path = Path();
    for (var i = 0; i < weights.length; i++) {
      final p = Offset(x(i), y(weights[i]));
      if (i == 0) {
        path.moveTo(p.dx, p.dy);
      } else {
        path.lineTo(p.dx, p.dy);
      }
    }
    canvas.drawPath(path, linePaint);

    final dotPaint = Paint()..color = line;
    for (var i = 0; i < weights.length; i++) {
      canvas.drawCircle(Offset(x(i), y(weights[i])), 3.5, dotPaint);
    }
  }

  @override
  bool shouldRepaint(_WeightPainter old) =>
      old.weights != weights || old.target != target;
}
