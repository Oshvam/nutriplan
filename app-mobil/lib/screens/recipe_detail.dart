/// Detalle de receta: ingredientes (× raciones), pasos y añadir al plan.
library;

import 'package:flutter/material.dart';

import '../api.dart';
import '../models.dart';
import '../widgets.dart';

class RecipeDetailScreen extends StatefulWidget {
  const RecipeDetailScreen(
      {super.key, required this.api, this.recipeId, this.recipe});
  final ApiClient api;
  final String? recipeId;
  final Recipe? recipe;

  @override
  State<RecipeDetailScreen> createState() => _RecipeDetailScreenState();
}

class _RecipeDetailScreenState extends State<RecipeDetailScreen> {
  Recipe? _recipe;
  String? _error;
  int _servings = 2;

  @override
  void initState() {
    super.initState();
    if (widget.recipe != null) {
      _recipe = widget.recipe;
    } else {
      _load();
    }
  }

  Future<void> _load() async {
    try {
      final data = await widget.api.recipe(widget.recipeId ?? '');
      if (!mounted) return;
      setState(() => _recipe =
          Recipe.fromMap((data['recipe'] as Map).cast<String, dynamic>()));
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _addToPlan() async {
    final r = _recipe;
    if (r == null) return;
    int day = DateTime.now().weekday;
    String slot = r.mealTypes.isNotEmpty ? r.mealTypes.first : 'comida';
    const meals = ['desayuno', 'tentempie', 'comida', 'merienda', 'cena'];
    if (!meals.contains(slot)) slot = 'comida';
    final ok = await showModalBottomSheet<bool>(
      context: context,
      builder: (c) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<int>(
                initialValue: day,
                decoration: const InputDecoration(
                    labelText: 'Día', border: OutlineInputBorder()),
                items: [
                  for (var i = 1; i <= 7; i++)
                    DropdownMenuItem(value: i, child: Text(dayNames[i])),
                ],
                onChanged: (v) => day = v ?? day,
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                initialValue: slot,
                decoration: const InputDecoration(
                    labelText: 'Comida', border: OutlineInputBorder()),
                items: [
                  for (final m in meals)
                    DropdownMenuItem(
                        value: m, child: Text(mealLabels[m] ?? m)),
                ],
                onChanged: (v) => slot = v ?? slot,
              ),
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.pop(c, true),
                  child: const Text('Añadir al plan'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    if (ok != true) return;
    try {
      await widget.api.planApply(day, '${day}_$slot', {
        'id': r.id,
        'title': r.title,
        'calories': r.calories,
        'protein': r.protein,
        'carbs': r.carbs,
        'fat': r.fat,
        'imageEmoji': r.imageEmoji,
        'prepTimeMinutes': r.prepTimeMinutes,
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Añadida al plan')));
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final r = _recipe;
    return Scaffold(
      appBar: AppBar(title: Text(r?.title ?? 'Receta')),
      body: _error != null
          ? ErrorRetry(message: _error!, onRetry: _load)
          : r == null
              ? const Loading(label: 'Cargando receta…')
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Center(
                        child: Text(r.imageEmoji,
                            style: const TextStyle(fontSize: 72))),
                    const SizedBox(height: 8),
                    Text(r.title,
                        textAlign: TextAlign.center,
                        style: Theme.of(context).textTheme.headlineSmall),
                    if (r.subtitle.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(r.subtitle,
                            textAlign: TextAlign.center,
                            style: Theme.of(context).textTheme.bodyMedium),
                      ),
                    const SizedBox(height: 12),
                    Wrap(
                      alignment: WrapAlignment.center,
                      spacing: 8,
                      children: [
                        Chip(label: Text('🔥 ${r.calories} kcal')),
                        Chip(
                            label: Text(
                                'P ${r.protein.round()} · C ${r.carbs.round()} · G ${r.fat.round()}')),
                        Chip(label: Text('⏱ ${r.prepTimeMinutes}′')),
                      ],
                    ),
                    if (r.allergens.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Text('⚠️ Alérgenos: ${r.allergens.join(', ')}',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                              color:
                                  Theme.of(context).colorScheme.error)),
                    ],
                    const SizedBox(height: 16),
                    SectionCard(
                      title: 'Ingredientes',
                      emoji: '🧂',
                      child: Column(
                        children: [
                          Row(
                            children: [
                              const Text('Raciones:'),
                              const SizedBox(width: 8),
                              for (final n in [1, 2, 4])
                                Padding(
                                  padding: const EdgeInsets.only(right: 8),
                                  child: ChoiceChip(
                                    label: Text('×$n'),
                                    selected: _servings == n,
                                    onSelected: (_) =>
                                        setState(() => _servings = n),
                                  ),
                                ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          for (final ing in r.ingredients)
                            ListTile(
                              dense: true,
                              contentPadding: EdgeInsets.zero,
                              title: Text(ing.name),
                              trailing: Text(
                                _qty(ing),
                                style: Theme.of(context)
                                    .textTheme
                                    .bodyMedium
                                    ?.copyWith(
                                        fontWeight: FontWeight.bold),
                              ),
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    SectionCard(
                      title: 'Preparación',
                      emoji: '👩‍🍳',
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          for (var i = 0; i < r.instructions.length; i++)
                            Padding(
                              padding:
                                  const EdgeInsets.only(bottom: 8),
                              child: Text(
                                  '${i + 1}. ${r.instructions[i]}'),
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton.icon(
                        onPressed: _addToPlan,
                        icon: const Icon(Icons.add),
                        label: const Text('Añadir al plan'),
                      ),
                    ),
                  ],
                ),
    );
  }

  String _qty(Ingredient ing) {
    if (ing.quantity <= 0) return ing.unit;
    final q = ing.quantity * _servings / 2;
    final s = q == q.roundToDouble() ? '${q.toInt()}' : '$q';
    return ing.unit.isEmpty ? s : '$s ${ing.unit}';
  }
}
