/// Inicio de sesión: URL del servidor + botón de Google.
library;

import 'package:flutter/material.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api.dart';
import '../models.dart' show greeting;

class LoginScreen extends StatefulWidget {
  const LoginScreen(
      {super.key, required this.api, required this.onLoggedIn});
  final ApiClient api;
  final VoidCallback onLoggedIn;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _urlCtrl = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _urlCtrl.text = widget.api.baseUrl;
  }

  @override
  void dispose() {
    _urlCtrl.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final base = _urlCtrl.text.trim().replaceAll(RegExp(r'/+$'), '');
      if (base.isEmpty) throw const FormatException('Indica la URL');
      widget.api.baseUrl = base;
      final cfg = await widget.api.authConfig();
      final clientId = '${cfg['clientId'] ?? ''}';
      if (clientId.isEmpty || cfg['googleEnabled'] != true) {
        throw StateError('El servidor no tiene Google activado');
      }
      final gsi = GoogleSignIn(
        scopes: const ['email', 'profile'],
        serverClientId: clientId,
      );
      final account = await gsi.signIn();
      if (account == null) {
        setState(() => _busy = false); // cancelado por el usuario
        return;
      }
      final auth = await account.authentication;
      final idToken = auth.idToken;
      if (idToken == null || idToken.isEmpty) {
        throw StateError('Google no devolvió credencial');
      }
      await widget.api.login(idToken);
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('np_server', widget.api.baseUrl);
      await prefs.setString(
          'np_session', widget.api.sessionToken ?? '');
      widget.onLoggedIn();
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (e) {
      setState(() => _error = 'No se pudo conectar: $e');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Column(
                  children: [
                    const Text('🥗', style: TextStyle(fontSize: 64)),
                    const SizedBox(height: 8),
                    Text('NutriPlan',
                        style: Theme.of(context)
                            .textTheme
                            .headlineMedium
                            ?.copyWith(fontWeight: FontWeight.bold)),
                    const SizedBox(height: 4),
                    Text('${greeting(DateTime.now())}. Inicia sesión para ver tu plan.',
                        textAlign: TextAlign.center),
                    const SizedBox(height: 24),
                    TextField(
                      controller: _urlCtrl,
                      keyboardType: TextInputType.url,
                      decoration: const InputDecoration(
                        labelText: 'URL del servidor',
                        hintText: 'http://192.168.1.50:8000',
                        prefixIcon: Icon(Icons.dns),
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'En el emulador usa http://10.0.2.2:8000; en un móvil, la IP de tu PC en la misma Wi-Fi.',
                      style: TextStyle(fontSize: 12),
                    ),
                    const SizedBox(height: 16),
                    if (_error != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: Text(_error!,
                            style: TextStyle(
                                color: Theme.of(context).colorScheme.error),
                            textAlign: TextAlign.center),
                      ),
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton.icon(
                        onPressed: _busy ? null : _login,
                        icon: _busy
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2))
                            : const Icon(Icons.login),
                        label: const Text('Entrar con Google'),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      );
}
