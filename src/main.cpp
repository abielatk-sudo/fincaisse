// FINCAISSE - API REST (Crow + SQLite + libsodium). Montants en FCFA : entiers uniquement.
#include "crow_all.h"
#include <sqlite3.h>
#include <sodium.h>
using namespace std;
using Row = map<string, string>;
using crow::json::wvalue;

static sqlite3* db;
static crow::SimpleApp app;  // mono-thread : pas de verrou nécessaire

static vector<Row> q(const string& sql, vector<string> p = {}) {
  sqlite3_stmt* s; vector<Row> out;
  if (sqlite3_prepare_v2(db, sql.c_str(), -1, &s, nullptr) != SQLITE_OK) throw runtime_error(sqlite3_errmsg(db));
  for (size_t i = 0; i < p.size(); ++i) sqlite3_bind_text(s, i + 1, p[i].c_str(), -1, SQLITE_TRANSIENT);
  int rc;
  while ((rc = sqlite3_step(s)) == SQLITE_ROW) {
    Row r;
    for (int c = 0; c < sqlite3_column_count(s); ++c) { auto t = sqlite3_column_text(s, c); r[sqlite3_column_name(s, c)] = t ? (const char*)t : ""; }
    out.push_back(r);
  }
  sqlite3_finalize(s);
  if (rc != SQLITE_DONE) throw runtime_error(sqlite3_errmsg(db));
  return out;
}
static wvalue jo(const Row& r) { wvalue o; for (auto& [k, v] : r) o[k] = v; return o; }
static wvalue js(const vector<Row>& v) { wvalue w = wvalue::list(); int i = 0; for (auto& r : v) w[i++] = jo(r); return w; }
static crow::response J(const wvalue& w, int c = 200) { crow::response r(c, w.dump()); r.set_header("Content-Type", "application/json"); return r; }
static crow::response E(int c, const string& m) { wvalue w; w["error"] = m; return J(w, c); }

struct Sess { int id; string user, role; };
static map<string, Sess> sessions;
static void audit(const Sess& s, const string& a) { q("INSERT INTO audit(ts,user,action) VALUES(datetime('now','localtime'),?,?)", {s.user, a}); }
static optional<Sess> auth(const crow::request& r) {
  auto h = r.get_header_value("Authorization");
  if (h.rfind("Bearer ", 0) != 0) return nullopt;
  auto it = sessions.find(h.substr(7));
  return it == sessions.end() ? nullopt : optional<Sess>(it->second);
}
static string hashPw(const string& p) {
  char o[crypto_pwhash_STRBYTES];
  if (crypto_pwhash_str(o, p.c_str(), p.size(), crypto_pwhash_OPSLIMIT_INTERACTIVE, crypto_pwhash_MEMLIMIT_INTERACTIVE) != 0) throw runtime_error("Hachage impossible");
  return o;
}
static string newToken() { unsigned char b[24]; randombytes_buf(b, 24); char h[49]; sodium_bin2hex(h, sizeof h, b, 24); return h; }
static void createUser(const string& u, const string& pw, const string& role) {
  if (u.empty()) throw runtime_error("Nom d'utilisateur requis");
  if (pw.size() < 8) throw runtime_error("Mot de passe : 8 caractères minimum");
  q("INSERT INTO users(username,hash,role,active) VALUES(?,?,?,1)", {u, hashPw(pw), role});
}

using H = function<crow::response(const crow::request&, const Sess&, const crow::json::rvalue&)>;
static void api(const string& path, crow::HTTPMethod m, vector<string> roles, H h) {
  app.route_dynamic(path).methods(m)([roles, h](const crow::request& req) {
    try {
      Sess s{};
      if (!roles.empty()) {
        auto o = auth(req);
        if (!o) return E(401, "Connexion requise");
        if (find(roles.begin(), roles.end(), o->role) == roles.end()) return E(403, "Accès refusé pour ce rôle");
        s = *o;
      }
      return h(req, s, crow::json::load(req.body));
    } catch (const exception& e) { return E(400, e.what()); }
  });
}
static const auto GET = crow::HTTPMethod::GET, POST = crow::HTTPMethod::POST, DEL = crow::HTTPMethod::DELETE;
static const vector<string> ALL{"ADMIN", "ACCUEIL", "CAISSE"};

static void tx(const function<void()>& f) { q("BEGIN"); try { f(); q("COMMIT"); } catch (...) { q("ROLLBACK"); throw; } }

int main() {
  if (sodium_init() < 0) return 1;
  const char* dbp = getenv("DB_PATH");
  sqlite3_open(dbp ? dbp : "fincaisse.db", &db);
  sqlite3_exec(db, R"(
    CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, hash TEXT NOT NULL, role TEXT NOT NULL CHECK(role IN('ADMIN','ACCUEIL','CAISSE')), active INTEGER DEFAULT 1);
    CREATE TABLE IF NOT EXISTS products(id INTEGER PRIMARY KEY, name TEXT NOT NULL, price INTEGER NOT NULL CHECK(price>=0), stock INTEGER NOT NULL CHECK(stock>=0), threshold INTEGER NOT NULL DEFAULT 5);
    CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, total INTEGER, created_at TEXT);
    CREATE TABLE IF NOT EXISTS order_items(order_id INTEGER, product_id INTEGER, name TEXT, price INTEGER, qty INTEGER);
    CREATE TABLE IF NOT EXISTS payments(order_id INTEGER UNIQUE, cashier_id INTEGER, given INTEGER, change_amt INTEGER, paid_at TEXT);
    CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY, ts TEXT, user TEXT, action TEXT);)", 0, 0, 0);

  app.route_dynamic("/")([](const crow::request&, crow::response& res) { res.set_static_file_info("public/index.html"); res.end(); });

  // --- Authentification ---
  api("/api/setup", POST, {}, [](auto&, auto&, auto& b) {
    if (stoi(q("SELECT COUNT(*) c FROM users")[0]["c"]) > 0) return E(403, "Un administrateur existe déjà");
    createUser(b["username"].s(), b["password"].s(), "ADMIN"); return J(wvalue({{"ok", true}}));
  });
  api("/api/login", POST, {}, [](auto&, auto&, auto& b) {
    auto u = q("SELECT * FROM users WHERE username=? AND active=1", {b["username"].s()});
    string pw = b["password"].s();
    if (u.empty() || crypto_pwhash_str_verify(u[0]["hash"].c_str(), pw.c_str(), pw.size()) != 0) return E(401, "Identifiants incorrects");
    Sess s{stoi(u[0]["id"]), u[0]["username"], u[0]["role"]}; string t = newToken(); sessions[t] = s; audit(s, "Connexion");
    wvalue w; w["token"] = t; w["user"] = s.user; w["role"] = s.role; return J(w);
  });
  api("/api/me", GET, ALL, [](auto&, auto& s, auto&) { wvalue w; w["user"] = s.user; w["role"] = s.role; return J(w); });

  // --- Produits & stock ---
  api("/api/products", GET, ALL, [](auto&, auto&, auto&) { return J(js(q("SELECT * FROM products ORDER BY name"))); });
  api("/api/products", POST, {"ADMIN"}, [](auto&, auto& s, auto& b) {
    vector<string> p{b["name"].s(), to_string(b["price"].i()), to_string(b["stock"].i()), to_string(b["threshold"].i())};
    if (p[0].empty()) throw runtime_error("Nom du produit requis");
    if (b["id"].i() > 0) { p.push_back(to_string(b["id"].i())); q("UPDATE products SET name=?,price=?,stock=?,threshold=? WHERE id=?", p); }
    else q("INSERT INTO products(name,price,stock,threshold) VALUES(?,?,?,?)", p);
    audit(s, "Produit enregistré : " + p[0]); return J(wvalue({{"ok", true}}));
  });
  api("/api/products", DEL, {"ADMIN"}, [](auto& r, auto& s, auto&) {
    string id = r.url_params.get("id") ? r.url_params.get("id") : "";
    q("DELETE FROM products WHERE id=?", {id}); audit(s, "Produit supprimé #" + id); return J(wvalue({{"ok", true}}));
  });

  // --- Commandes (accueil -> caisse) ---
  api("/api/orders", POST, {"ACCUEIL", "ADMIN"}, [](auto&, auto& s, auto& b) {
    wvalue w;
    tx([&] {
      q("INSERT INTO orders(user_id,status,total,created_at) VALUES(?,'PENDING',0,datetime('now','localtime'))", {to_string(s.id)});
      string oid = q("SELECT last_insert_rowid() i")[0]["i"]; long long total = 0; int n = 0;
      for (auto& it : b["items"]) {
        string pid = to_string(it["product_id"].i()); long long qty = it["qty"].i();
        if (qty <= 0) throw runtime_error("Quantité invalide");
        auto p = q("SELECT name,price,stock FROM products WHERE id=?", {pid});
        if (p.empty()) throw runtime_error("Produit introuvable");
        if (stoll(p[0]["stock"]) < qty) throw runtime_error("Stock insuffisant : " + p[0]["name"]);
        q("INSERT INTO order_items VALUES(?,?,?,?,?)", {oid, pid, p[0]["name"], p[0]["price"], to_string(qty)});
        total += stoll(p[0]["price"]) * qty; n++;
      }
      if (!n) throw runtime_error("Commande vide");
      q("UPDATE orders SET total=? WHERE id=?", {to_string(total), oid});
      audit(s, "Commande #" + oid + " envoyée à la caisse"); w["id"] = oid; w["total"] = total;
    });
    return J(w);
  });
  api("/api/orders", GET, ALL, [](auto& r, auto&, auto&) {
    string st = r.url_params.get("status") ? r.url_params.get("status") : "";
    return J(js(q("SELECT o.id,o.total,o.status,o.created_at,u.username seller FROM orders o JOIN users u ON u.id=o.user_id WHERE (?='' OR o.status=?) ORDER BY o.id DESC LIMIT 100", {st, st})));
  });
  api("/api/order", GET, ALL, [](auto& r, auto&, auto&) {
    string id = r.url_params.get("id") ? r.url_params.get("id") : "";
    auto o = q("SELECT o.*,u.username seller,p.given,p.change_amt,p.paid_at FROM orders o JOIN users u ON u.id=o.user_id LEFT JOIN payments p ON p.order_id=o.id WHERE o.id=?", {id});
    if (o.empty()) return E(404, "Commande introuvable");
    wvalue w; w["order"] = jo(o[0]); w["items"] = js(q("SELECT name,price,qty FROM order_items WHERE order_id=?", {id})); return J(w);
  });
  api("/api/pay", POST, {"CAISSE", "ADMIN"}, [](auto&, auto& s, auto& b) {
    string oid = to_string(b["order_id"].i()); long long given = b["given"].i(); wvalue w;
    tx([&] {
      auto o = q("SELECT total,status FROM orders WHERE id=?", {oid});
      if (o.empty() || o[0]["status"] != "PENDING") throw runtime_error("Cette commande n'est plus en attente");
      long long total = stoll(o[0]["total"]);
      if (given < total) throw runtime_error("Montant remis insuffisant");
      for (auto& it : q("SELECT product_id,name,qty FROM order_items WHERE order_id=?", {oid})) {
        auto st = q("SELECT stock FROM products WHERE id=?", {it["product_id"]});
        if (st.empty() || stoll(st[0]["stock"]) < stoll(it["qty"])) throw runtime_error("Stock insuffisant : " + it["name"]);
        q("UPDATE products SET stock=stock-? WHERE id=?", {it["qty"], it["product_id"]});
      }
      q("INSERT INTO payments VALUES(?,?,?,?,datetime('now','localtime'))", {oid, to_string(s.id), to_string(given), to_string(given - total)});
      q("UPDATE orders SET status='PAID' WHERE id=?", {oid});
      audit(s, "Commande #" + oid + " encaissée (" + to_string(total) + " FCFA)"); w["change"] = given - total;
    });
    return J(w);
  });

  // --- Administration ---
  api("/api/stats", GET, {"ADMIN"}, [](auto&, auto&, auto&) {
    auto f = [](const string& cond) { return q("SELECT COALESCE(SUM(o.total),0) t,COUNT(*) n FROM orders o JOIN payments p ON p.order_id=o.id WHERE " + cond)[0]; };
    auto d = f("date(p.paid_at)=date('now','localtime')"), m = f("strftime('%Y-%m',p.paid_at)=strftime('%Y-%m','now','localtime')");
    long long mt = stoll(m["t"]), mn = stoll(m["n"]); wvalue w;
    w["today"] = stoll(d["t"]); w["today_count"] = stoll(d["n"]); w["month"] = mt; w["avg_basket"] = mn ? mt / mn : 0;
    w["low_stock"] = js(q("SELECT name,stock,threshold FROM products WHERE stock<=threshold ORDER BY stock")); return J(w);
  });
  api("/api/users", GET, {"ADMIN"}, [](auto&, auto&, auto&) { return J(js(q("SELECT id,username,role,active FROM users"))); });
  api("/api/users", POST, {"ADMIN"}, [](auto&, auto& s, auto& b) {
    string role = b["role"].s();
    if (role != "ADMIN" && role != "ACCUEIL" && role != "CAISSE") throw runtime_error("Rôle invalide");
    createUser(b["username"].s(), b["password"].s(), role); audit(s, "Compte créé : " + string(b["username"].s())); return J(wvalue({{"ok", true}}));
  });
  api("/api/users/toggle", POST, {"ADMIN"}, [](auto&, auto& s, auto& b) {
    if (b["id"].i() == s.id) throw runtime_error("Vous ne pouvez pas désactiver votre propre compte");
    q("UPDATE users SET active=1-active WHERE id=?", {to_string(b["id"].i())}); return J(wvalue({{"ok", true}}));
  });
  api("/api/audit", GET, {"ADMIN"}, [](auto&, auto&, auto&) { return J(js(q("SELECT ts,user,action FROM audit ORDER BY id DESC LIMIT 200"))); });

  const char* port = getenv("PORT");
  app.bindaddr(port ? "0.0.0.0" : "127.0.0.1").port(port ? atoi(port) : 8080).run();
}
