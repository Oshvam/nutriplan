/// Plan semanal: vista por día y vista matriz de 7 días.
library;

import 'package:flutter/material.dart';

import '../api.dart';
import '../models.dart';
import '../widgets.dart';
import 'recipe_detail.dart';

class PlanScreen extends StatefulWidget {
  const PlanScreen({super.key, required this.api});
  final ApiClient api;

  @override
  State<PlanScreen> createState() => _PlanScreenState();
}

class _PlanScreenState extends State<PlanScreen> {
  List<PlanDay>? _plan;
  Map<String, dynamic>? _needs;
  String? _error;
  bool _matrix = false;
  int _day = DateTime.now().weekday; // 1 = lunes, igual que dayNumber
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _error = null;
      _plan = null;
    });
    try {
      final data = await widget.api.planGet();
      if (!mounted) return;
      setState(() {
        _plan = (data['plan'] as List? ?? [])
            .map((e) => PlanDay.fromMap((e as Map).cast<String, dynamic>()))
            .toList();
        _needs = (data['needs'] as Map?)?.cast<String, dynamic>();
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _toggle(PlanMeal m, int day) async {
    try {
      await widget.api.planToggle(day, m.slotId);
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _swap(PlanMeal m, int day) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Cambiar receta'),
        content: Text('¿Sustituir «${m.recipe.title}» por otra sugerencia?'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Cancelar')),
          FilledButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Cambiar')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await widget.api.planSwap(day, m.slotId);
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _regenerate() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Regenerar plan'),
        content: const Text(
            'Se creará un plan nuevo de 7 días con tus datos actuales.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Cancelar')),
          FilledButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Regenerar')),
        ],
      ),
    );
    if (ok != true) return;
    setState(() => _busy = true);
    try {
      await widget.api.planRegenerate();
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final plan = _plan;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mi plan'),
        actions: [
          IconButton(
            icon: Icon(_matrix ? Icons.view_day : Icons.grid_view),
            tooltip: _matrix ? 'Vista por día' : 'Vista semana',
            onPressed: () => setState(() => _matrix = !_matrix),
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Regenerar',
            onPressed: _busy ? null : _regenerate,
          ),
        ],
      ),
      body: _error != null
          ? ErrorRetry(message: _error!, onRetry: _load)
          : plan == null
              ? const Loading(label: 'Cargando plan…')
              : plan.isEmpty
                  ? const EmptyState(
                      emoji: '📋', text: 'Aún no tienes plan generado.')
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: _matrix
                          ? _matrixView(plan)
                          : _dayView(plan),
                    ),
    );
  }

  Widget _dayView(List<PlanDay> plan) {
    final day = plan.firstWhere((d) => d.dayNumber == _day,
        orElse: () => plan.first);
    final totals =
        dayTotals(day.meals.map((m) => m.recipe).toList());
    final target = _needs == null ? 0 : _iNum(_needs!['targetCalories']);
    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        SizedBox(
          height: 44,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            itemCount: plan.length,
            separatorBuilder: (context, _) => const SizedBox(width: 8),
            itemBuilder: (c, i) {
              final d = plan[i];
              final sel = d.dayNumber == day.dayNumber;
              return ChoiceChip(
                label: Text(d.dayName),
                selected: sel,
                onSelected: (_) =>
                    setState(() => _day = d.dayNumber),
              );
            },
          ),
        ),
        const SizedBox(height: 8),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Text(
              target > 0
                  ? '${totals['cal']!.round()} / $target kcal · '
                      'P ${totals['p']!.round()}g · C ${totals['c']!.round()}g · G ${totals['f']!.round()}g'
                  : '${totals['cal']!.round()} kcal en el día',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ),
        const SizedBox(height: 4),
        for (final m in day.meals)
          MealCard(
            meal: m,
            onTap: () => Navigator.push(
              context,
              MaterialPageRoute(
                  builder: (_) => RecipeDetailScreen(
                      api: widget.api, recipeId: m.recipe.id)),
            ),
            onToggle: () => _toggle(m, day.dayNumber),
            onSwap: () => _swap(m, day.dayNumber),
          ),
      ],
    );
  }

  Widget _matrixView(List<PlanDay> plan) => ListView.separated(
        padding: const EdgeInsets.all(12),
        itemCount: plan.length,
        separatorBuilder: (context, _) => const SizedBox(height: 4),
        itemBuilder: (c, i) {
          final d = plan[i];
          return Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(d.dayName,
                      style:
                          Theme.of(context).textTheme.titleSmall),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    children: [
                      for (final m in d.meals)
                        ActionChip(
                          avatar: Text(m.recipe.imageEmoji,
                              style: const TextStyle(fontSize: 14)),
                          label: Text(
                              '${m.label}: ${m.recipe.title}',
                              overflow: TextOverflow.ellipsis),
                          onPressed: () {
                            setState(() {
                              _day = d.dayNumber;
                              _matrix = false;
                            });
                          },
                        ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      );

  int _iNum(dynamic v) =>
      v is num ? v.toInt() : int.tryParse('$v') ?? 0;
}
