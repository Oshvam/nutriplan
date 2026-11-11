/// Widgets pequeños compartidos por todas las pantallas.
library;

import 'package:flutter/material.dart';

import 'models.dart';

class Loading extends StatelessWidget {
  const Loading({super.key, this.label = 'Cargando…'});
  final String label;
  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const CircularProgressIndicator(),
              const SizedBox(height: 12),
              Text(label, style: Theme.of(context).textTheme.bodyMedium),
            ],
          ),
        ),
      );
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.emoji, required this.text});
  final String emoji;
  final String text;
  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(emoji, style: const TextStyle(fontSize: 48)),
              const SizedBox(height: 12),
              Text(text, textAlign: TextAlign.center),
            ],
          ),
        ),
      );
}

class ErrorRetry extends StatelessWidget {
  const ErrorRetry(
      {super.key, required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text('⚠️', style: TextStyle(fontSize: 40)),
              const SizedBox(height: 8),
              Text(message, textAlign: TextAlign.center),
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: onRetry,
                icon: const Icon(Icons.refresh),
                label: const Text('Reintentar'),
              ),
            ],
          ),
        ),
      );
}

class SectionCard extends StatelessWidget {
  const SectionCard(
      {super.key, required this.title, this.emoji, required this.child});
  final String title;
  final String? emoji;
  final Widget child;
  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('${emoji != null ? '$emoji ' : ''}$title',
                  style: Theme.of(context).textTheme.titleMedium),
              const SizedBox(height: 12),
              child,
            ],
          ),
        ),
      );
}

/// Tarjeta de receta: emoji, título, subtítulo, chips de kcal/tiempo.
class RecipeCard extends StatelessWidget {
  const RecipeCard({super.key, required this.recipe, this.onTap, this.trailing});
  final Recipe recipe;
  final VoidCallback? onTap;
  final Widget? trailing;
  @override
  Widget build(BuildContext context) {
    final tr = trailing;
    return Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                Text(recipe.imageEmoji,
                    style: const TextStyle(fontSize: 36)),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(recipe.title,
                          style: Theme.of(context).textTheme.titleSmall),
                      if (recipe.subtitle.isNotEmpty)
                        Text(recipe.subtitle,
                            style: Theme.of(context).textTheme.bodySmall,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis),
                      const SizedBox(height: 6),
                      Wrap(
                        spacing: 6,
                        children: [
                          _chip(context,
                              '🔥 ${recipe.calories} kcal'),
                          _chip(context,
                              '⏱ ${recipe.prepTimeMinutes}′'),
                        ],
                      ),
                    ],
                  ),
                ),
                // ignore: use_null_aware_elements
                if (tr != null) tr,
              ],
            ),
          ),
        ),
      );
  }

  Widget _chip(BuildContext context, String label) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Text(label, style: Theme.of(context).textTheme.labelSmall),
      );
}

/// Fila de comida del plan: check + receta + botón cambiar.
class MealCard extends StatelessWidget {
  const MealCard({
    super.key,
    required this.meal,
    this.onTap,
    this.onToggle,
    this.onSwap,
  });
  final PlanMeal meal;
  final VoidCallback? onTap;
  final VoidCallback? onToggle;
  final VoidCallback? onSwap;

  @override
  Widget build(BuildContext context) => Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                IconButton(
                  icon: Icon(
                    meal.completed
                        ? Icons.check_circle
                        : Icons.radio_button_unchecked,
                    color: meal.completed
                        ? Theme.of(context).colorScheme.primary
                        : null,
                  ),
                  onPressed: onToggle,
                  tooltip: meal.completed ? 'Desmarcar' : 'Marcar hecha',
                ),
                Text(meal.recipe.imageEmoji,
                    style: const TextStyle(fontSize: 32)),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        meal.label,
                        style: Theme.of(context)
                            .textTheme
                            .labelSmall
                            ?.copyWith(
                                color: Theme.of(context)
                                    .colorScheme
                                    .onSurfaceVariant),
                      ),
                      Text(
                        meal.recipe.title,
                        style: Theme.of(context)
                            .textTheme
                            .titleSmall
                            ?.copyWith(
                              decoration: meal.completed
                                  ? TextDecoration.lineThrough
                                  : null,
                            ),
                      ),
                      Text(
                        '${meal.recipe.calories} kcal · ${meal.timeSlot}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.autorenew),
                  onPressed: onSwap,
                  tooltip: 'Cambiar receta',
                ),
              ],
            ),
          ),
        ),
      );
}

/// Insignia de puntuación de recomendación.
class ScoreBadge extends StatelessWidget {
  const ScoreBadge({super.key, required this.score});
  final int score;
  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: scheme.primaryContainer,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Text('★ $score',
          style: TextStyle(
              color: scheme.onPrimaryContainer,
              fontWeight: FontWeight.bold)),
    );
  }
}
