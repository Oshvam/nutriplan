/// Lista de la compra del día, con checks persistidos en el móvil.
library;

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api.dart';
import '../models.dart';
import '../widgets.dart';

class ShoppingScreen extends StatefulWidget {
  const ShoppingScreen({super.key, required this.api});
  final ApiClient api;

  @override
  State<ShoppingScreen> createState() => _ShoppingScreenState();
}

class _ShoppingScreenState extends State<ShoppingScreen> {
  int _day = (DateTime.now().weekday - 1) % 7; // 0 = lunes
  ShopData? _shop;
  String? _error;
  Set<String> _checked = {};
  SharedPreferences? _prefs;

  @override
  void initState() {
    super.initState();
    _load();
  }

  String get _prefsKey => 'np_checked_$_day';

  Future<void> _load() async {
    setState(() {
      _error = null;
      _shop = null;
    });
    try {
      _prefs ??= await SharedPreferences.getInstance();
      final data = await widget.api.shopping(_day);
      if (!mounted) return;
      setState(() {
        _shop = ShopData.fromMap(
            (data['shop'] as Map).cast<String, dynamic>());
        _checked =
            (_prefs!.getStringList(_prefsKey) ?? []).toSet();
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  void _toggle(String key, bool? v) {
    setState(() {
      if (v == true) {
        _checked.add(key);
      } else {
        _checked.remove(key);
      }
    });
    _prefs?.setStringList(_prefsKey, _checked.toList());
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Lista de la compra')),
        body: Column(
          children: [
            SizedBox(
              height: 48,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: 12),
                itemCount: 7,
                separatorBuilder: (context, _) => const SizedBox(width: 8),
                itemBuilder: (c, i) => ChoiceChip(
                  label: Text(dayNames[i + 1]),
                  selected: _day == i,
                  onSelected: (_) {
                    setState(() => _day = i);
                    _load();
                  },
                ),
              ),
            ),
            Expanded(
              child: _error != null
                  ? ErrorRetry(message: _error!, onRetry: _load)
                  : _shop == null
                      ? const Loading(label: 'Calculando compra…')
                      : _shop!.total == 0
                          ? const EmptyState(
                              emoji: '🛒',
                              text: 'Este día no tiene comidas con ingredientes.')
                          : RefreshIndicator(
                              onRefresh: _load,
                              child: ListView(
                                padding:
                                    const EdgeInsets.all(12),
                                children: [
                                  Text(
                                      '${_shop!.total} ingredientes · ${dayNames[_day + 1]}'),
                                  const SizedBox(height: 8),
                                  for (final cat
                                      in _shop!.groups.keys)
                                    if (_shop!.groups[cat]!.isNotEmpty)
                                      SectionCard(
                                        title: _shop!.names[cat] ??
                                            cat,
                                        child: Column(
                                          children: [
                                            for (var i = 0;
                                                i <
                                                    _shop!.groups[cat]!
                                                        .length;
                                                i++)
                                              _item(
                                                  cat,
                                                  i,
                                                  _shop!.groups[
                                                      cat]![i]),
                                          ],
                                        ),
                                      ),
                                ],
                              ),
                            ),
            ),
          ],
        ),
      );

  Widget _item(String cat, int i, ShopItem item) {
    final key = '$cat:$i:${item.name}';
    final done = _checked.contains(key);
    return CheckboxListTile(
      dense: true,
      contentPadding: EdgeInsets.zero,
      title: Text(item.name,
          style: TextStyle(
              decoration:
                  done ? TextDecoration.lineThrough : null)),
      subtitle:
          item.note != null && item.note!.isNotEmpty
              ? Text(item.note!)
              : null,
      secondary: Text(item.displayQty(),
          style: Theme.of(context)
              .textTheme
              .bodyMedium
              ?.copyWith(fontWeight: FontWeight.bold)),
      value: done,
      onChanged: (v) => _toggle(key, v),
    );
  }
}
