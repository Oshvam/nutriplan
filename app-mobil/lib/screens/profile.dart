/// Perfil: datos, necesidades, peso, Mistral y ajustes.
library;

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api.dart';
import '../models.dart';
import '../weight_chart.dart';
import '../widgets.dart';
import 'config.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen(
      {super.key, required this.api, required this.onLoggedOut});
  final ApiClient api;
  final VoidCallback onLoggedOut;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  Map<String, dynamic>? _profile;
  Map<String, dynamic>? _needs;
  Map<String, dynamic>? _user;
  List<WeightPoint> _history = [];
  String? _error;
  String? _mistralStatus;
  bool _mistralTest = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final p = await widget.api.profileGet();
      final w = await widget.api.weightGet();
      if (!mounted) return;
      setState(() {
        _profile = (p['profile'] as Map?)?.cast<String, dynamic>();
        _needs = (p['needs'] as Map?)?.cast<String, dynamic>();
        _user = (p['user'] as Map?)?.cast<String, dynamic>();
        _history = ((w['history'] as List?) ?? [])
            .map((e) =>
                WeightPoint.fromMap((e as Map).cast<String, dynamic>()))
            .toList();
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _logout() async {
    await widget.api.logout();
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('np_session');
    widget.onLoggedOut();
  }

  Future<void> _addWeight() async {
    final wCtrl = TextEditingController();
    final nCtrl = TextEditingController();
    DateTime date = DateTime.now();
    String fmt(DateTime d) =>
        '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => StatefulBuilder(
        builder: (c, setS) => AlertDialog(
          title: const Text('Registrar peso'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: wCtrl,
                keyboardType:
                    const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(
                  labelText: 'Peso (kg)',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              InkWell(
                onTap: () async {
                  final picked = await showDatePicker(
                    context: c,
                    initialDate: date,
                    firstDate: DateTime(2020),
                    lastDate: DateTime.now(),
                  );
                  if (picked != null) setS(() => date = picked);
                },
                child: InputDecorator(
                  decoration: const InputDecoration(
                    labelText: 'Fecha',
                    border: OutlineInputBorder(),
                    suffixIcon: Icon(Icons.calendar_today),
                  ),
                  child: Text(fmt(date)),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: nCtrl,
                decoration: const InputDecoration(
                  labelText: 'Nota (opcional)',
                  border: OutlineInputBorder(),
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(c, false),
                child: const Text('Cancelar')),
            FilledButton(
                onPressed: () => Navigator.pop(c, true),
                child: const Text('Guardar')),
          ],
        ),
      ),
    );
    if (ok != true) return;
    final w = double.tryParse(wCtrl.text.replaceAll(',', '.'));
    if (w == null) return;
    final iso =
        '${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
    try {
      await widget.api.weightPost(
          w, iso, nCtrl.text.trim().isEmpty ? null : nCtrl.text.trim());
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _deleteWeight(WeightPoint p) async {
    try {
      await widget.api.weightDelete(p.date);
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _mistralDialog() async {
    final c = TextEditingController();
    try {
      final cur = await widget.api.mistralGet();
      c.text = '${cur['apiKey'] ?? ''}';
    } catch (_) {}
    if (!mounted) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => AlertDialog(
        title: const Text('Clave de Mistral (IA)'),
        content: TextField(
          controller: c,
          obscureText: true,
          decoration: const InputDecoration(
            labelText: 'API key',
            border: OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(d, false),
              child: const Text('Cancelar')),
          FilledButton(
              onPressed: () => Navigator.pop(d, true),
              child: const Text('Guardar')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await widget.api.mistralPost(c.text.trim());
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Clave guardada')));
      }
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _mistralCheck() async {
    setState(() {
      _mistralTest = true;
      _mistralStatus = null;
    });
    try {
      final r = await widget.api.mistralTest();
      if (mounted) {
        setState(() => _mistralStatus =
            r['ok'] == true ? '✅ Conexión correcta' : '❌ ${r['error'] ?? 'Fallo'}');
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _mistralStatus = '❌ ${e.message}');
    } finally {
      if (mounted) setState(() => _mistralTest = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _profile;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Perfil'),
        actions: [
          if (p != null)
            IconButton(
              icon: const Icon(Icons.edit),
              tooltip: 'Editar datos',
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (_) => ConfigScreen(
                        api: widget.api,
                        initial: p,
                        onSaved: _load)),
              ),
            ),
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Cerrar sesión',
            onPressed: _logout,
          ),
        ],
      ),
      body: _error != null
          ? ErrorRetry(message: _error!, onRetry: _load)
          : p == null
              ? const Loading(label: 'Cargando perfil…')
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.all(12),
                    children: [
                      _userCard(),
                      const SizedBox(height: 12),
                      _needsCard(),
                      const SizedBox(height: 12),
                      _weightCard(),
                      const SizedBox(height: 12),
                      _mistralCard(),
                    ],
                  ),
                ),
    );
  }

  Widget _userCard() => Card(
        child: ListTile(
          leading: _user?['picture'] != null &&
                  '${_user!['picture']}'.isNotEmpty
              ? CircleAvatar(
                  backgroundImage: NetworkImage('${_user!['picture']}'))
              : const CircleAvatar(child: Icon(Icons.person)),
          title: Text('${_user?['name'] ?? ''}'),
          subtitle: Text('${_user?['email'] ?? ''}'),
        ),
      );

  Widget _needsCard() {
    final n = _needs;
    if (n == null) return const SizedBox.shrink();
    final macros = (n['macros'] as Map?)?.cast<String, dynamic>() ?? {};
    final bmi = (n['bmiClassification'] as Map?)?.cast<String, dynamic>();
    return SectionCard(
      title: 'Tus necesidades',
      emoji: '🎯',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
              '${n['targetCalories'] ?? '—'} kcal/día · IMC ${n['bmi'] ?? '—'}'),
          if (bmi != null) Text('${bmi['label'] ?? ''}'),
          const SizedBox(height: 8),
          Text(
              'Proteínas ${macros['proteinGrams'] ?? '—'}g · Carbohidratos ${macros['carbsGrams'] ?? '—'}g · Grasas ${macros['fatsGrams'] ?? '—'}g'),
          if (n['estimatedWeeksToGoal'] != null)
            Text(
                'Meta estimada: ${n['estimatedWeeksToGoal']} semanas (${n['weeklyExpectedChangeKg'] ?? '—'} kg/sem)'),
        ],
      ),
    );
  }

  Widget _weightCard() {
    final p = _profile!;
    final height = (p['height'] as num?)?.toDouble() ?? 168;
    final target = (p['targetWeight'] as num?)?.toDouble() ?? 0;
    final stats = weightStats(_history, height, target);
    return SectionCard(
      title: 'Peso',
      emoji: '⚖️',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (_history.isNotEmpty && stats.isNotEmpty) ...[
            WeightChart(history: _history, targetWeight: target),
            const SizedBox(height: 8),
            Text(
                '${stats['current']} kg · objetivo $target kg · faltan ${stats['toGo']} kg'),
          ] else
            const Text('Registra tu primer peso para ver la gráfica.'),
          const SizedBox(height: 8),
          Row(
            children: [
              FilledButton.icon(
                onPressed: _addWeight,
                icon: const Icon(Icons.add),
                label: const Text('Registrar peso'),
              ),
            ],
          ),
          for (final h in _history.reversed.take(5))
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              title: Text('${h.weight} kg · ${h.date}'),
              subtitle: h.note != null && h.note!.isNotEmpty
                  ? Text(h.note!)
                  : null,
              trailing: IconButton(
                icon: const Icon(Icons.delete_outline),
                onPressed: () => _deleteWeight(h),
              ),
            ),
        ],
      ),
    );
  }

  Widget _mistralCard() => SectionCard(
        title: 'Asistente IA (Mistral)',
        emoji: '✨',
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _mistralDialog,
                    icon: const Icon(Icons.key),
                    label: const Text('Configurar clave'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _mistralTest ? null : _mistralCheck,
                    icon: const Icon(Icons.wifi_tethering),
                    label: const Text('Probar'),
                  ),
                ),
              ],
            ),
            if (_mistralStatus != null) ...[
              const SizedBox(height: 8),
              Text(_mistralStatus!),
            ],
          ],
        ),
      );
}
