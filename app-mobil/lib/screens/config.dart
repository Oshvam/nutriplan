/// Alta y edición de datos personales + cálculo nutricional en vivo.
library;

import 'dart:async';

import 'package:flutter/material.dart';

import '../api.dart';
import '../widgets.dart';

const _goals = {
  'perder_peso': 'Perder peso',
  'mantener_peso': 'Mantener peso',
  'ganar_peso': 'Ganar peso',
};
const _weekOpts = [4, 8, 12, 24];
const _genders = {'mujer': 'Mujer', 'hombre': 'Hombre'};
const _activities = {
  'sedentario': 'Sedentario',
  'ligero': 'Ligero',
  'moderado': 'Moderado',
  'intenso': 'Intenso',
  'atleta': 'Atleta',
};
const _mealsOpt = [4, 5, 6];
const _diets = {
  'mediterranea': 'Mediterránea',
  'omnivoro': 'Omnívora',
  'pescetariano': 'Pescetariana',
  'vegetariano': 'Vegetariana',
};
const _allergyList = [
  'Gluten',
  'Lácteos',
  'Huevos',
  'Pescado',
  'Crustáceos',
  'Moluscos',
  'Frutos secos',
  'Soja',
  'Sésamo',
  'Apio',
  'Mostaza',
  'Sulfitos',
];

class ConfigScreen extends StatefulWidget {
  const ConfigScreen(
      {super.key, required this.api, this.initial, this.isNew = false, this.onSaved});
  final ApiClient api;
  final Map<String, dynamic>? initial;
  final bool isNew;
  final VoidCallback? onSaved;

  @override
  State<ConfigScreen> createState() => _ConfigScreenState();
}

class _ConfigScreenState extends State<ConfigScreen> {
  late String _goal;
  late int _weeks;
  late String _gender;
  late String _activity;
  late int _meals;
  late String _diet;
  late Set<String> _allergies;
  late double _water;
  final _age = TextEditingController();
  final _height = TextEditingController();
  final _weight = TextEditingController();
  final _target = TextEditingController();
  Map<String, dynamic>? _calc;
  Timer? _debounce;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final p = widget.initial ?? {};
    _goal = '${p['goal'] ?? 'perder_peso'}';
    _weeks = (p['targetWeeks'] as num?)?.toInt() ?? 12;
    _gender = '${p['gender'] ?? 'mujer'}';
    _activity = '${p['activityLevel'] ?? 'moderado'}';
    _meals = (p['mealsPerDay'] as num?)?.toInt() ?? 5;
    _diet = '${p['dietaryPreference'] ?? 'mediterranea'}';
    _allergies = ((p['allergies'] as List?) ?? []).map((e) => '$e').toSet();
    _water =
        ((p['dailyWaterTargetLiters'] as num?)?.toDouble() ?? 2.5);
    _age.text = '${p['age'] ?? 30}';
    _height.text = '${p['height'] ?? 168}';
    _weight.text = '${p['currentWeight'] ?? 72}';
    _target.text = '${p['targetWeight'] ?? ''}';
    for (final c in [_age, _height, _weight, _target]) {
      c.addListener(_scheduleCalc);
    }
    _scheduleCalc();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _age.dispose();
    _height.dispose();
    _weight.dispose();
    _target.dispose();
    super.dispose();
  }

  void _scheduleCalc() {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), _calcNow);
  }

  double? _num(TextEditingController c) =>
      double.tryParse(c.text.replaceAll(',', '.'));

  Future<void> _calcNow() async {
    final w = _num(_weight), tw = _num(_target);
    if (w == null || tw == null) return;
    try {
      final r = await widget.api.calc({
        'goal': _goal,
        'targetWeeks': _weeks,
        'gender': _gender,
        'age': _num(_age) ?? 30,
        'height': _num(_height) ?? 168,
        'currentWeight': w,
        'targetWeight': tw,
        'activityLevel': _activity,
      });
      if (mounted) setState(() => _calc = (r['calc'] as Map).cast<String, dynamic>());
    } catch (_) {}
  }

  Map<String, dynamic> get _payload => {
        'goal': _goal,
        'targetWeeks': _weeks,
        'gender': _gender,
        'age': _num(_age) ?? 30,
        'height': _num(_height) ?? 168,
        'currentWeight': _num(_weight) ?? 0,
        'targetWeight': _num(_target) ?? 0,
        'activityLevel': _activity,
        'mealsPerDay': _meals,
        'dietaryPreference': _diet,
        'allergies': _allergies.toList(),
        'dailyWaterTargetLiters': _water,
      };

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      if (widget.isNew) {
        await widget.api.profilePost(_payload);
      } else {
        await widget.api.profilePut(_payload);
      }
      widget.onSaved?.call();
      if (mounted && !widget.isNew) Navigator.pop(context);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
            title: Text(widget.isNew ? 'Tus datos' : 'Editar datos')),
        body: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            if (widget.isNew)
              const Padding(
                padding: EdgeInsets.only(bottom: 12),
                child: Text(
                    'Cuéntanos sobre ti para crear tu plan personalizado.'),
              ),
            _chips('Objetivo', _goals, _goal,
                (v) => setState(() => _goal = v)),
            _numChips('Plazo (semanas)', _weekOpts, _weeks, (v) {
              setState(() => _weeks = v);
              _scheduleCalc();
            }),
            _chips('Sexo', _genders, _gender,
                (v) => setState(() => _gender = v)),
            _chips('Actividad', _activities, _activity,
                (v) => setState(() => _activity = v)),
            _numChips('Comidas al día', _mealsOpt, _meals,
                (v) => setState(() => _meals = v)),
            _chips('Dieta', _diets, _diet,
                (v) => setState(() => _diet = v)),
            const SizedBox(height: 8),
            Text('Edad', style: Theme.of(context).textTheme.titleSmall),
            _numField(_age, 6, 100),
            Text('Altura (cm)',
                style: Theme.of(context).textTheme.titleSmall),
            _numField(_height, 100, 230),
            Text('Peso actual (kg)',
                style: Theme.of(context).textTheme.titleSmall),
            _numField(_weight, 20, 250),
            Text('Peso objetivo (kg)',
                style: Theme.of(context).textTheme.titleSmall),
            _numField(_target, 20, 250),
            const SizedBox(height: 8),
            Text('Alergias',
                style: Theme.of(context).textTheme.titleSmall),
            Wrap(
              spacing: 8,
              children: [
                for (final a in _allergyList)
                  FilterChip(
                    label: Text(a),
                    selected: _allergies.contains(a),
                    onSelected: (v) => setState(() {
                      if (v) {
                        _allergies.add(a);
                      } else {
                        _allergies.remove(a);
                      }
                    }),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Text('Agua al día: ${_water.toStringAsFixed(1)} L',
                style: Theme.of(context).textTheme.titleSmall),
            Slider(
              value: _water,
              min: 1.5,
              max: 4.0,
              divisions: 25,
              label: '${_water.toStringAsFixed(1)} L',
              onChanged: (v) => setState(() => _water = v),
            ),
            if (_calc != null) _calcCard(),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(_error!,
                    style: TextStyle(
                        color: Theme.of(context).colorScheme.error)),
              ),
            const SizedBox(height: 16),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving
                    ? 'Guardando…'
                    : widget.isNew
                        ? 'Crear mi plan'
                        : 'Guardar cambios'),
              ),
            ),
          ],
        ),
      );

  Widget _chips(String label, Map<String, String> opts, String value,
      ValueChanged<String> on) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: Theme.of(context).textTheme.titleSmall),
        Wrap(
          spacing: 8,
          children: [
            for (final e in opts.entries)
              ChoiceChip(
                label: Text(e.value),
                selected: value == e.key,
                onSelected: (_) {
                  on(e.key);
                  _scheduleCalc();
                },
              ),
          ],
        ),
        const SizedBox(height: 8),
      ],
    );
  }

  Widget _numChips(
      String label, List<int> opts, int value, ValueChanged<int> on) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: Theme.of(context).textTheme.titleSmall),
        Wrap(
          spacing: 8,
          children: [
            for (final o in opts)
              ChoiceChip(
                label: Text('$o'),
                selected: value == o,
                onSelected: (_) => on(o),
              ),
          ],
        ),
        const SizedBox(height: 8),
      ],
    );
  }

  Widget _numField(TextEditingController c, double min, double max) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: TextField(
        controller: c,
        keyboardType:
            const TextInputType.numberWithOptions(decimal: true),
        decoration: const InputDecoration(border: OutlineInputBorder()),
      ),
    );
  }

  Widget _calcCard() {
    final c = _calc!;
    final macros = (c['macros'] as Map?)?.cast<String, dynamic>() ?? {};
    return SectionCard(
      title: 'Tu prescripción',
      emoji: '📊',
      child: Text(
        '${c['targetCalories'] ?? '—'} kcal/día · '
        'P ${macros['proteinGrams'] ?? '—'}g · '
        'C ${macros['carbsGrams'] ?? '—'}g · '
        'G ${macros['fatsGrams'] ?? '—'}g\n'
        'IMC ${c['bmi'] ?? '—'} · '
        '${c['weeklyExpectedChangeKg'] ?? '—'} kg/sem · '
        '~${c['estimatedWeeksToGoal'] ?? '—'} semanas',
      ),
    );
  }
}
