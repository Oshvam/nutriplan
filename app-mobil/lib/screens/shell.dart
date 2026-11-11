/// Estructura con 5 destinos; si no hay perfil, muestra el alta.
library;

import 'package:flutter/material.dart';

import '../api.dart';
import 'config.dart';
import 'hoy.dart';
import 'plan.dart';
import 'profile.dart';
import 'recipes.dart';
import 'shopping.dart';

class ShellScreen extends StatefulWidget {
  const ShellScreen({super.key, required this.api, required this.onLoggedOut});
  final ApiClient api;
  final VoidCallback onLoggedOut;

  @override
  State<ShellScreen> createState() => _ShellScreenState();
}

class _ShellScreenState extends State<ShellScreen> {
  int _index = 0;
  bool _checking = true;
  bool _hasProfile = true;

  @override
  void initState() {
    super.initState();
    _checkProfile();
  }

  Future<void> _checkProfile() async {
    try {
      await widget.api.profileGet();
      _hasProfile = true;
    } on ApiException catch (e) {
      if (e.status == 404) _hasProfile = false;
    } catch (_) {}
    if (mounted) setState(() => _checking = false);
  }

  @override
  Widget build(BuildContext context) {
    if (_checking) {
      return const Scaffold(
          body: Center(child: CircularProgressIndicator()));
    }
    if (!_hasProfile) {
      return ConfigScreen(
        api: widget.api,
        isNew: true,
        onSaved: () => setState(() => _hasProfile = true),
      );
    }
    final pages = [
      PlanScreen(api: widget.api),
      const HoyScreenHost(),
      const RecipesScreenHost(),
      const ShoppingScreenHost(),
      ProfileScreen(api: widget.api, onLoggedOut: widget.onLoggedOut),
    ];
    return Scaffold(
      body: IndexedStack(index: _index, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: const [
          NavigationDestination(
              icon: Icon(Icons.calendar_view_week), label: 'Plan'),
          NavigationDestination(
              icon: Icon(Icons.restaurant), label: 'Hoy'),
          NavigationDestination(
              icon: Icon(Icons.search), label: 'Recetas'),
          NavigationDestination(
              icon: Icon(Icons.shopping_cart), label: 'Compra'),
          NavigationDestination(
              icon: Icon(Icons.person), label: 'Perfil'),
        ],
      ),
    );
  }
}

/// Hospedadores que recuperan el ApiClient del contexto (evita pasarlo en
/// cascada desde pantallas con estado propio). Se inyecta vía [ApiScope].
class ApiScope extends InheritedWidget {
  const ApiScope({super.key, required this.api, required super.child});
  final ApiClient api;

  static ApiClient of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<ApiScope>()!.api;

  @override
  bool updateShouldNotify(ApiScope old) => api != old.api;
}

class HoyScreenHost extends StatelessWidget {
  const HoyScreenHost({super.key});
  @override
  Widget build(BuildContext context) =>
      HoyScreen(api: ApiScope.of(context));
}

class RecipesScreenHost extends StatelessWidget {
  const RecipesScreenHost({super.key});
  @override
  Widget build(BuildContext context) =>
      RecipesScreen(api: ApiScope.of(context));
}

class ShoppingScreenHost extends StatelessWidget {
  const ShoppingScreenHost({super.key});
  @override
  Widget build(BuildContext context) =>
      ShoppingScreen(api: ApiScope.of(context));
}
