/// Buscador de recetas con filtros.
library;

import 'package:flutter/material.dart';

import '../api.dart';
import '../models.dart';
import '../widgets.dart';
import 'recipe_detail.dart';

class RecipesScreen extends StatefulWidget {
  const RecipesScreen({super.key, required this.api});
  final ApiClient api;

  @override
  State<RecipesScreen> createState() => _RecipesScreenState();
}

class _RecipesScreenState extends State<RecipesScreen> {
  final _qCtrl = TextEditingController();
  String? _momento;
  String? _tiempo;
  String? _kcal;
  String? _tag;
  List<String> _tags = [];
  List<Recipe> _items = [];
  int _total = 0;
  bool _loading = false;
  String? _error;

  static const _momentos = ['desayuno', 'comida', 'cena', 'snack'];
  static const _tiempos = ['rapida', 'media', 'larga'];
  static const _kcales = ['ligera', 'media', 'alta'];

  @override
  void dispose() {
    _qCtrl.dispose();
    super.dispose();
  }

  Map<String, dynamic> get _query => {
        'q': _qCtrl.text.trim(),
        'momento': _momento,
        'tiempo': _tiempo,
        'kcal': _kcal,
        'tag': _tag,
        'n': 60,
      };

  Future<void> _search() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await widget.api.recipes(_query);
      if (!mounted) return;
      setState(() {
        _items = ((data['recipes'] as List?) ?? [])
            .map((e) => Recipe.fromMap((e as Map).cast<String, dynamic>()))
            .toList();
        _total = (data['total'] as num?)?.toInt() ?? _items.length;
        _tags = ((data['tags'] as List?) ?? []).map((e) => '$e').toList();
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Recetas')),
        body: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                children: [
                  TextField(
                    controller: _qCtrl,
                    textInputAction: TextInputAction.search,
                    onSubmitted: (_) => _search(),
                    decoration: InputDecoration(
                      hintText: 'Buscar por nombre o ingrediente…',
                      prefixIcon: const Icon(Icons.search),
                      border: const OutlineInputBorder(),
                      suffixIcon: IconButton(
                        icon: const Icon(Icons.send),
                        onPressed: _search,
                      ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        _filter('Momento', _momento, _momentos,
                            (v) => setState(() => _momento = v)),
                        const SizedBox(width: 8),
                        _filter('Tiempo', _tiempo, _tiempos,
                            (v) => setState(() => _tiempo = v)),
                        const SizedBox(width: 8),
                        _filter('Kcal', _kcal, _kcales,
                            (v) => setState(() => _kcal = v)),
                        if (_tags.isNotEmpty) ...[
                          const SizedBox(width: 8),
                          _filter('Etiqueta', _tag, _tags,
                              (v) => setState(() => _tag = v)),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: _loading
                  ? const Loading(label: 'Buscando recetas…')
                  : _error != null
                      ? ErrorRetry(message: _error!, onRetry: _search)
                      : _items.isEmpty
                          ? const EmptyState(
                              emoji: '🍳',
                              text: 'Busca entre más de 1000 recetas.')
                          : ListView.builder(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 12),
                              itemCount:
                                  _items.length + 1,
                              itemBuilder: (c, i) {
                                if (i >= _items.length) {
                                  return Padding(
                                    padding: const EdgeInsets.symmetric(
                                        vertical: 8),
                                    child: Center(
                                      child: Text(
                                          'Mostrando ${_items.length} de $_total'),
                                    ),
                                  );
                                }
                                final r = _items[i];
                                return RecipeCard(
                                  recipe: r,
                                  onTap: () => Navigator.push(
                                    context,
                                    MaterialPageRoute(
                                        builder: (_) =>
                                            RecipeDetailScreen(
                                                api: widget.api,
                                                recipeId: r.id)),
                                  ),
                                );
                              },
                            ),
            ),
          ],
        ),
      );

  Widget _filter(String label, String? value, List<String> options,
      ValueChanged<String?> onChanged) {
    return DropdownButton<String?>(
      value: value,
      hint: Text(label),
      items: [
        DropdownMenuItem<String?>(value: null, child: Text('Todos')),
        for (final o in options)
          DropdownMenuItem<String?>(value: o, child: Text(o)),
      ],
      onChanged: (v) {
        onChanged(v);
        _search();
      },
    );
  }
}
