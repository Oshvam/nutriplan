import 'package:flutter_test/flutter_test.dart';
import 'package:nutriplan/models.dart';

void main() {
  group('mealCalorieTarget (== Nutrition::mealCalorieTarget)', () {
    test('reparte 2000 kcal', () {
      expect(mealCalorieTarget('comida', 2000), 700);
      expect(mealCalorieTarget('cena', 2000), 560);
      expect(mealCalorieTarget('desayuno', 2000), 440);
      expect(mealCalorieTarget('merienda', 2000), 240);
      expect(mealCalorieTarget('tentempie', 2000), 160);
    });
  });

  group('dayTotals (== Nutrition::dayTotals)', () {
    test('suma macros de recetas', () {
      final rs = [
        Recipe.fromMap({
          'id': 'a',
          'calories': 500,
          'protein': 30,
          'carbs': 50,
          'fat': 10,
        }),
        Recipe.fromMap({
          'id': 'b',
          'calories': 300,
          'protein': 10,
          'carbs': 20,
          'fat': 5,
        }),
      ];
      final t = dayTotals(rs);
      expect(t['cal'], 800);
      expect(t['p'], 40);
      expect(t['c'], 70);
      expect(t['f'], 15);
    });
  });

  group('weightStats (== Weight::stats)', () {
    test('historial 72 → 70.6, objetivo 64', () {
      final h = [
        WeightPoint.fromMap({'date': '2026-11-01', 'weight': 72.0}),
        WeightPoint.fromMap({'date': '2026-11-05', 'weight': 71.2}),
        WeightPoint.fromMap({'date': '2026-11-10', 'weight': 70.6}),
      ];
      final s = weightStats(h, 168, 64);
      expect(s['current'], 70.6);
      expect(s['toGo'], 6.6);
      expect(s['count'], 3);
      expect(s['bmi'], isA<double>());
    });

    test('vacío devuelve mapa vacío', () {
      expect(weightStats([], 168, 64), isEmpty);
    });
  });

  group('parseo', () {
    test('receta ligera del índice', () {
      final r = Recipe.fromMap({
        'id': 'pollo-al-horno',
        'title': 'Pollo al horno',
        'subtitle': 'Jugoso',
        'mealTypes': ['comida', 'cena'],
        'prepTimeMinutes': 40,
        'prepCategory': 'media',
        'calorieCategory': 'media',
        'calories': 450,
        'protein': 35.0,
        'carbs': 20.0,
        'fat': 15.0,
        'fiber': 3.0,
        'imageEmoji': '🍗',
        'tags': ['alto-proteina'],
        'allergens': [],
      });
      expect(r.id, 'pollo-al-horno');
      expect(r.mealTypes, contains('cena'));
      expect(r.ingredients, isEmpty);
    });

    test('plan con 7 días', () {
      final day = PlanDay.fromMap({
        'dayNumber': 1,
        'dayName': 'Lunes',
        'meals': [
          {
            'mealType': 'comida',
            'label': 'Comida',
            'timeSlot': '13:30',
            'recipeId': 'x',
            'slotId': '1_comida',
            'completed': false,
            'recipe': {'id': 'x', 'title': 'X', 'calories': 500},
          },
        ],
      });
      expect(day.dayNumber, 1);
      expect(day.meals.single.slotId, '1_comida');
    });

    test('compra agrupa por categorías', () {
      final s = ShopData.fromMap({
        'groups': {
          'verduras': [
            {'name': 'Tomate', 'quantity': 500, 'unit': 'g'}
          ],
          'otros': [],
        },
        'names': {'verduras': 'Verduras'},
        'total': 1,
      });
      expect(s.groups['verduras']!.single.displayQty(), '500 g');
      expect(s.total, 1);
    });

    test('displayQty adapta unidades grandes', () {
      final i = ShopItem.fromMap(
          {'name': 'Leche', 'quantity': 1500, 'unit': 'ml'});
      expect(i.displayQty(), '1.5 l');
    });
  });
}
