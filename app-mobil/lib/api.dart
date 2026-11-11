import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

/// Error con código HTTP del backend PHP.
class ApiException implements Exception {
  ApiException(this.status, this.message);
  final int status;
  final String message;
  @override
  String toString() => message;
}

/// Cliente HTTP contra el backend PHP de NutriPlan.
///
/// Autentica con la cookie `nutriplan_session`: tras el login con Google se
/// captura el valor de `Set-Cookie` y se reenvía como header `Cookie`.
class ApiClient {
  ApiClient(this.baseUrl, {this.sessionToken});

  /// p. ej. `http://192.168.1.50:8000` (sin barra final).
  String baseUrl;
  String? sessionToken;

  static const _timeout = Duration(seconds: 30);

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        if (sessionToken != null) 'Cookie': 'nutriplan_session=$sessionToken',
      };

  Uri _uri(String path, [Map<String, dynamic>? query]) {
    final q = <String, String>{};
    query?.forEach((k, v) {
      if (v != null && '$v'.isNotEmpty) q[k] = '$v';
    });
    final base = baseUrl.replaceAll(RegExp(r'/+$'), '');
    final u = Uri.parse('$base$path');
    return q.isEmpty ? u : u.replace(queryParameters: q);
  }

  Future<dynamic> _send(String method, String path,
      {Map<String, dynamic>? query, Object? body}) async {
    final req = http.Request(method, _uri(path, query));
    req.headers.addAll(_headers);
    if (body != null) req.body = jsonEncode(body);
    final streamed = await req.send().timeout(_timeout);
    final res = await http.Response.fromStream(streamed);
    dynamic data;
    try {
      data = res.body.isEmpty ? {} : jsonDecode(res.body);
    } on FormatException {
      throw ApiException(res.statusCode, 'Respuesta no válida del servidor');
    }
    if (res.statusCode >= 400) {
      final msg = data is Map && data['error'] != null
          ? '${data['error']}'
          : 'Error ${res.statusCode}';
      throw ApiException(res.statusCode, msg);
    }
    return data;
  }

  Future<dynamic> get(String path, [Map<String, dynamic>? query]) =>
      _send('GET', path, query: query);

  Future<dynamic> post(String path, [Object? body]) =>
      _send('POST', path, body: body ?? {});

  Future<dynamic> put(String path, [Object? body]) =>
      _send('PUT', path, body: body ?? {});

  Future<dynamic> delete(String path, [Object? body]) =>
      _send('DELETE', path, body: body ?? {});

  /// Login con Google: envía el idToken y captura la cookie de sesión.
  Future<Map<String, dynamic>> login(String idToken) async {
    final req = http.Request('POST', _uri('/api/auth/google'));
    req.headers['Content-Type'] = 'application/json';
    req.body = jsonEncode({'idToken': idToken});
    final streamed = await req.send().timeout(_timeout);
    final res = await http.Response.fromStream(streamed);
    final data = jsonDecode(res.body);
    if (res.statusCode >= 400) {
      throw ApiException(res.statusCode, '${data['error'] ?? 'Error'}');
    }
    final setCookie = res.headers['set-cookie'] ?? '';
    final m = RegExp(r'nutriplan_session=([^;]+)').firstMatch(setCookie);
    if (m != null) sessionToken = m.group(1);
    return (data as Map).cast<String, dynamic>();
  }

  // --- Auth / cuenta ---
  Future<Map<String, dynamic>> authConfig() async =>
      (await get('/api/auth/config') as Map).cast<String, dynamic>();
  Future<void> logout() async {
    try {
      await post('/api/auth/logout');
    } catch (_) {}
    sessionToken = null;
  }

  Future<Map<String, dynamic>> meSyncGet() async =>
      (await get('/api/me/sync') as Map).cast<String, dynamic>();
  Future<void> meSyncPut(Map<String, dynamic> data) async {
    await put('/api/me/sync', data);
  }

  // --- Perfil ---
  Future<Map<String, dynamic>> profileGet() async =>
      (await get('/api/profile') as Map).cast<String, dynamic>();
  Future<Map<String, dynamic>> profilePost(Map<String, dynamic> p) async =>
      (await post('/api/profile', p) as Map).cast<String, dynamic>();
  Future<Map<String, dynamic>> profilePut(Map<String, dynamic> p) async =>
      (await put('/api/profile', p) as Map).cast<String, dynamic>();

  /// Cálculo nutricional en vivo (sin sesión).
  Future<Map<String, dynamic>> calc(Map<String, dynamic> q) async =>
      (await get('/api/calc', q) as Map).cast<String, dynamic>();

  // --- Plan ---
  Future<Map<String, dynamic>> planGet() async =>
      (await get('/api/plan') as Map).cast<String, dynamic>();
  Future<Map<String, dynamic>> planRegenerate() async =>
      (await post('/api/plan/regenerate') as Map).cast<String, dynamic>();
  Future<Map<String, dynamic>> planToggle(int day, String slot) async =>
      (await post('/api/plan/toggle', {'dayNumber': day, 'slotId': slot})
              as Map)
          .cast<String, dynamic>();
  Future<Map<String, dynamic>> planSwap(int day, String slot) async =>
      (await post('/api/plan/swap', {'dayNumber': day, 'slotId': slot}) as Map)
          .cast<String, dynamic>();
  Future<Map<String, dynamic>> planApply(
          int day, String slot, Map<String, dynamic> recipe) async =>
      (await post('/api/plan/apply',
              {'dayNumber': day, 'slotId': slot, 'recipe': recipe}) as Map)
          .cast<String, dynamic>();

  // --- Peso ---
  Future<Map<String, dynamic>> weightGet() async =>
      (await get('/api/weight') as Map).cast<String, dynamic>();
  Future<Map<String, dynamic>> weightPost(
          double weight, String date, String? note) async =>
      (await post('/api/weight',
              {'weight': weight, 'date': date, if (note case final n) 'note': n})
          as Map)
          .cast<String, dynamic>();
  Future<void> weightDelete(String date) async {
    await delete('/api/weight', {'date': date});
  }

  // --- Recetas / hoy / compra ---
  Future<Map<String, dynamic>> recipes(Map<String, dynamic> q) async =>
      (await get('/api/recipes', q) as Map).cast<String, dynamic>();
  Future<Map<String, dynamic>> recipe(String id) async =>
      (await get('/api/recipe', {'id': id}) as Map).cast<String, dynamic>();
  Future<Map<String, dynamic>> hoy(Map<String, dynamic> q) async =>
      (await get('/api/hoy', q) as Map).cast<String, dynamic>();
  Future<Map<String, dynamic>> shopping(int day) async =>
      (await get('/api/shopping', {'dia': day}) as Map).cast<String, dynamic>();

  /// Recomendación IA (1–2 recetas generadas).
  Future<Map<String, dynamic>> aiRecipe(Map<String, dynamic> body) async =>
      (await post('/api/ai-recipe-recommendation', body) as Map)
          .cast<String, dynamic>();

  // --- Mistral ---
  Future<Map<String, dynamic>> mistralGet() async =>
      (await get('/api/settings/mistral') as Map).cast<String, dynamic>();
  Future<void> mistralPost(String key) async {
    await post('/api/settings/mistral', {'apiKey': key});
  }

  Future<Map<String, dynamic>> mistralTest() async =>
      (await post('/api/settings/mistral/test') as Map).cast<String, dynamic>();
}
