import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api.dart';
import 'screens/login.dart';
import 'screens/shell.dart';

void main() => runApp(const NutriPlanApp());

class NutriPlanApp extends StatefulWidget {
  const NutriPlanApp({super.key});

  @override
  State<NutriPlanApp> createState() => _NutriPlanAppState();
}

class _NutriPlanAppState extends State<NutriPlanApp> {
  ApiClient? _api;
  bool _loggedIn = false;

  @override
  void initState() {
    super.initState();
    _restore();
  }

  Future<void> _restore() async {
    final prefs = await SharedPreferences.getInstance();
    // En emulador: 10.0.2.2 llega al localhost del PC; en móvil físico: IP LAN.
    final server =
        prefs.getString('np_server') ?? 'http://10.0.2.2:8000';
    final token = prefs.getString('np_session');
    if (!mounted) return;
    setState(() {
      _api = ApiClient(server,
          sessionToken: token?.isEmpty == true ? null : token);
      _loggedIn = _api!.sessionToken != null;
    });
  }

  void _onLoggedIn() => setState(() => _loggedIn = true);

  Future<void> _onLoggedOut() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('np_session');
    if (mounted) setState(() => _loggedIn = false);
  }

  @override
  Widget build(BuildContext context) {
    final api = _api;
    return MaterialApp(
      title: 'NutriPlan',
      theme: ThemeData(
        colorScheme:
            ColorScheme.fromSeed(seedColor: const Color(0xFF059669)),
        useMaterial3: true,
      ),
      locale: const Locale('es'),
      localizationsDelegates:
          GlobalMaterialLocalizations.delegates,
      supportedLocales: const [Locale('es')],
      home: api == null
          ? const Scaffold(
              body: Center(child: CircularProgressIndicator()))
          : _loggedIn
              ? ApiScope(
                  api: api,
                  child: ShellScreen(
                      api: api, onLoggedOut: _onLoggedOut),
                )
              : LoginScreen(api: api, onLoggedIn: _onLoggedIn),
    );
  }
}
