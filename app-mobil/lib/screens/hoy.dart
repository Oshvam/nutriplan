/// Recomendaciones del momento + generador con IA.
library;

import 'package:flutter/material.dart';

import '../api.dart';
import '../models.dart';
import '../widgets.dart';
import 'recipe_detail.dart';

const _meals = ['desayuno', 'tentempie', 'comida', 'merienda', 'cena'];

class HoyScreen extends StatefulWidget {
  const HoyScreen({super.key, required this.api});
  final ApiClient api;

  @override
  State<HoyScreen> createState() => _HoyScreenState();
}

class _HoyScreenState extends State<HoyScreen> {
  String _meal = 'comida';
  final _tiempoCtrl = TextEditingController();
  final _ingCtrl = TextEditingController();
  final _antojoCtrl = TextEditingController();
  String? _estilo;
  List<Rec>? _recs;
  int _target = 0;
  String? _error;
  bool _loading = false;
  bool _aiBusy = false;

  @override
  void dispose() {
    _tiempoCtrl.dispose();
    _ingCtrl.dispose();
    _antojoCtrl.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await widget.api.hoy({
        'meal': _meal,
        'tiempo': _tiempoCtrl.text.trim(),
        'ing': _ingCtrl.text.trim(),
        'estilo': _estilo,
        'antojo': _antojoCtrl.text.trim(),
      });
      if (!mounted) return;
      setState(() {
        _recs = ((data['recs'] as List?) ?? [])
            .map((e) => Rec.fromMap((e as Map).cast<String, dynamic>()))
            .toList();
        _target = (data['target'] as num?)?.toInt() ?? 0;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _aiDialog() async {
    final cravingCtrl = TextEditingController();
    String? allergies;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('✨ Receta con IA'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: cravingCtrl,
              decoration: const InputDecoration(
                labelText: '¿Qué te apetece?',
                hintText: 'p. ej. algo fresco con pollo',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              decoration: const InputDecoration(
                labelText: 'Alergias (opcional)',
                hintText: 'gluten, frutos secos…',
                border: OutlineInputBorder(),
              ),
              onChanged: (v) => allergies = v,
            ),
          ],
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Cancelar')),
          FilledButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Generar')),
        ],
      ),
    );
    if (ok != true) return;
    setState(() => _aiBusy = true);
    try {
      final data = await widget.api.aiRecipe({
        'mealType': _meal,
        'targetCaloriesForMeal': _target > 0 ? _target : 500,
        'cravingText': cravingCtrl.text.trim(),
        'allergies': (allergies ?? '')
            .split(',')
            .map((e) => e.trim())
            .where((e) => e.isNotEmpty)
            .toList(),
      });
      if (!mounted) return;
      final recipes = (data['recipes'] as List?) ?? [];
      if (recipes.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('La IA no devolvió recetas')));
        return;
      }
      final r = Recipe.fromMap(
          (recipes.first as Map).cast<String, dynamic>());
      Navigator.push(
        context,
        MaterialPageRoute(
            builder: (_) => RecipeDetailScreen(api: widget.api, recipe: r)),
      );
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    } finally {
      if (mounted) setState(() => _aiBusy = false);
    }
  }

  Future<void> _addToPlan(Recipe r) async {
    int day = DateTime.now().weekday;
    String slot = _meal;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      builder: (c) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Añadir «${r.title}» al plan',
                  style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 12),
              DropdownButtonFormField<int>(
                initialValue: day,
                decoration:
                    const InputDecoration(labelText: 'Día', border: OutlineInputBorder()),
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
                  for (final m in _meals)
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
                  child: const Text('Añadir'),
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
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('¿Qué como hoy?')),
        body: ListView(
          padding: const EdgeInsets.all(12),
          children: [
            Wrap(
              spacing: 8,
              children: [
                for (final m in _meals)
                  ChoiceChip(
                    label: Text(mealLabels[m] ?? m),
                    selected: _meal == m,
                    onSelected: (_) => setState(() => _meal = m),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _tiempoCtrl,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Tiempo máx (min)',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    controller: _ingCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Ingrediente',
                      border: OutlineInputBorder(),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            TextField(
              controller: _antojoCtrl,
              decoration: const InputDecoration(
                labelText: 'Antojo o estilo (p. ej. ligero, italiano)',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: FilledButton.icon(
                    onPressed: _loading ? null : _search,
                    icon: const Icon(Icons.search),
                    label: const Text('Buscar'),
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  onPressed: _aiBusy ? null : _aiDialog,
                  icon: const Icon(Icons.auto_awesome),
                  label: const Text('IA'),
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (_loading)
              const Loading(label: 'Buscando ideas…')
            else if (_error != null)
              ErrorRetry(message: _error!, onRetry: _search)
            else if (_recs != null) ...[
              if (_target > 0)
                Text('Objetivo aprox.: $_target kcal',
                    style: Theme.of(context).textTheme.bodyMedium),
              const SizedBox(height: 8),
              if (_recs!.isEmpty)
                const EmptyState(
                    emoji: '🔍', text: 'Sin resultados con esos filtros.')
              else
                for (final rec in _recs!)
                  RecipeCard(
                    recipe: rec.recipe,
                    trailing: ScoreBadge(score: rec.score),
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                          builder: (_) => RecipeDetailScreen(
                              api: widget.api,
                              recipeId: rec.recipe.id)),
                    ),
                  ),
              if (_recs!.isNotEmpty) ...[
                const SizedBox(height: 4),
                for (final rec in _recs!)
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton.icon(
                      onPressed: () => _addToPlan(rec.recipe),
                      icon: const Icon(Icons.add),
                      label: Text('Añadir «${rec.recipe.title}»'),
                    ),
                  ),
              ],
            ],
          ],
        ),
      );
}
