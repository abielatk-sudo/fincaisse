#include <algorithm>
#include <cctype>
#include <cmath>
#include <cstdint>
#include <iomanip>
#include <iostream>
#include <map>
#include <sstream>
#include <stdexcept>
#include <string>
#include <utility>
#include <vector>

namespace fincaisse {

// Minimal self-contained JSON AST, parser, and serializer (RFC 8259 subset)
struct Json {
  enum class Type { Null, Bool, Number, String, Array, Object };

  Type type = Type::Null;
  bool bool_val = false;
  double num_val = 0.0;
  std::string str_val;
  std::vector<Json> arr_val;
  std::map<std::string, Json> obj_val;

  static Json null() { return Json{}; }
  static Json boolean(bool b) {
    Json j;
    j.type = Type::Bool;
    j.bool_val = b;
    return j;
  }
  static Json number(double n) {
    Json j;
    j.type = Type::Number;
    j.num_val = n;
    return j;
  }
  static Json integer(int64_t n) {
    Json j;
    j.type = Type::Number;
    j.num_val = static_cast<double>(n);
    return j;
  }
  static Json string(std::string s) {
    Json j;
    j.type = Type::String;
    j.str_val = std::move(s);
    return j;
  }
  static Json array(std::vector<Json> a = {}) {
    Json j;
    j.type = Type::Array;
    j.arr_val = std::move(a);
    return j;
  }
  static Json object(std::map<std::string, Json> o = {}) {
    Json j;
    j.type = Type::Object;
    j.obj_val = std::move(o);
    return j;
  }

  const Json& get(const std::string& k) const {
    static const Json kNull;
    if (type != Type::Object) return kNull;
    auto it = obj_val.find(k);
    return it == obj_val.end() ? kNull : it->second;
  }

  int64_t as_int(int64_t def = 0) const {
    if (type == Type::Number) return static_cast<int64_t>(std::llround(num_val));
    if (type == Type::String && !str_val.empty()) {
      try {
        return std::stoll(str_val);
      } catch (...) {
        return def;
      }
    }
    return def;
  }

  double as_double(double def = 0.0) const {
    if (type == Type::Number) return num_val;
    if (type == Type::String && !str_val.empty()) {
      try {
        return std::stod(str_val);
      } catch (...) {
        return def;
      }
    }
    return def;
  }

  std::string as_string(const std::string& def = "") const {
    if (type == Type::String) return str_val;
    if (type == Type::Number) {
      std::ostringstream oss;
      if (std::floor(num_val) == num_val) {
        oss << static_cast<int64_t>(num_val);
      } else {
        oss << std::fixed << std::setprecision(2) << num_val;
      }
      return oss.str();
    }
    return def;
  }
};

class JsonParser {
 public:
  explicit JsonParser(const std::string& src) : s_(src) {}

  Json parse() {
    skip_ws();
    Json v = parse_value();
    skip_ws();
    if (pos_ != s_.size()) throw std::runtime_error("Trailing characters in JSON");
    return v;
  }

 private:
  const std::string& s_;
  size_t pos_ = 0;

  void skip_ws() {
    while (pos_ < s_.size() && std::isspace(static_cast<unsigned char>(s_[pos_]))) ++pos_;
  }

  char peek() const {
    if (pos_ >= s_.size()) throw std::runtime_error("Unexpected end of JSON");
    return s_[pos_];
  }

  char next() {
    char c = peek();
    ++pos_;
    return c;
  }

  Json parse_value() {
    skip_ws();
    char c = peek();
    if (c == '{') return parse_object();
    if (c == '[') return parse_array();
    if (c == '"') return Json::string(parse_string());
    if (c == 't' || c == 'f') return parse_bool();
    if (c == 'n') return parse_null();
    if (c == '-' || std::isdigit(static_cast<unsigned char>(c))) return parse_number();
    throw std::runtime_error("Invalid JSON token");
  }

  Json parse_null() {
    if (s_.compare(pos_, 4, "null") != 0) throw std::runtime_error("Invalid null");
    pos_ += 4;
    return Json::null();
  }

  Json parse_bool() {
    if (s_.compare(pos_, 4, "true") == 0) {
      pos_ += 4;
      return Json::boolean(true);
    }
    if (s_.compare(pos_, 5, "false") == 0) {
      pos_ += 5;
      return Json::boolean(false);
    }
    throw std::runtime_error("Invalid boolean");
  }

  Json parse_number() {
    size_t start = pos_;
    if (s_[pos_] == '-') ++pos_;
    while (pos_ < s_.size() && std::isdigit(static_cast<unsigned char>(s_[pos_]))) ++pos_;
    if (pos_ < s_.size() && s_[pos_] == '.') {
      ++pos_;
      while (pos_ < s_.size() && std::isdigit(static_cast<unsigned char>(s_[pos_]))) ++pos_;
    }
    if (pos_ < s_.size() && (s_[pos_] == 'e' || s_[pos_] == 'E')) {
      ++pos_;
      if (pos_ < s_.size() && (s_[pos_] == '+' || s_[pos_] == '-')) ++pos_;
      while (pos_ < s_.size() && std::isdigit(static_cast<unsigned char>(s_[pos_]))) ++pos_;
    }
    return Json::number(std::stod(s_.substr(start, pos_ - start)));
  }

  static void append_utf8(std::string& out, uint32_t cp) {
    if (cp <= 0x7F) {
      out.push_back(static_cast<char>(cp));
    } else if (cp <= 0x7FF) {
      out.push_back(static_cast<char>(0xC0 | ((cp >> 6) & 0x1F)));
      out.push_back(static_cast<char>(0x80 | (cp & 0x3F)));
    } else if (cp <= 0xFFFF) {
      out.push_back(static_cast<char>(0xE0 | ((cp >> 12) & 0x0F)));
      out.push_back(static_cast<char>(0x80 | ((cp >> 6) & 0x3F)));
      out.push_back(static_cast<char>(0x80 | (cp & 0x3F)));
    } else {
      out.push_back(static_cast<char>(0xF0 | ((cp >> 18) & 0x07)));
      out.push_back(static_cast<char>(0x80 | ((cp >> 12) & 0x3F)));
      out.push_back(static_cast<char>(0x80 | ((cp >> 6) & 0x3F)));
      out.push_back(static_cast<char>(0x80 | (cp & 0x3F)));
    }
  }

  std::string parse_string() {
    if (next() != '"') throw std::runtime_error("Expected quote");
    std::string out;
    while (pos_ < s_.size()) {
      char c = next();
      if (c == '"') return out;
      if (c == '\\') {
        char e = next();
        switch (e) {
          case '"': out.push_back('"'); break;
          case '\\': out.push_back('\\'); break;
          case '/': out.push_back('/'); break;
          case 'b': out.push_back('\b'); break;
          case 'f': out.push_back('\f'); break;
          case 'n': out.push_back('\n'); break;
          case 'r': out.push_back('\r'); break;
          case 't': out.push_back('\t'); break;
          case 'u': {
            if (pos_ + 4 > s_.size()) throw std::runtime_error("Bad unicode escape");
            uint32_t cp = std::stoul(s_.substr(pos_, 4), nullptr, 16);
            pos_ += 4;
            if (cp >= 0xD800 && cp <= 0xDBFF && pos_ + 6 <= s_.size() &&
                s_[pos_] == '\\' && s_[pos_ + 1] == 'u') {
              uint32_t low = std::stoul(s_.substr(pos_ + 2, 4), nullptr, 16);
              if (low >= 0xDC00 && low <= 0xDFFF) {
                pos_ += 6;
                cp = 0x10000 + (((cp - 0xD800) << 10) | (low - 0xDC00));
              }
            }
            append_utf8(out, cp);
            break;
          }
          default: out.push_back(e); break;
        }
      } else {
        out.push_back(c);
      }
    }
    throw std::runtime_error("Unterminated string");
  }

  Json parse_array() {
    next();
    std::vector<Json> arr;
    skip_ws();
    if (peek() == ']') {
      next();
      return Json::array(std::move(arr));
    }
    while (true) {
      arr.push_back(parse_value());
      skip_ws();
      char c = next();
      if (c == ']') break;
      if (c != ',') throw std::runtime_error("Expected comma in array");
    }
    return Json::array(std::move(arr));
  }

  Json parse_object() {
    next();
    std::map<std::string, Json> obj;
    skip_ws();
    if (peek() == '}') {
      next();
      return Json::object(std::move(obj));
    }
    while (true) {
      skip_ws();
      std::string key = parse_string();
      skip_ws();
      if (next() != ':') throw std::runtime_error("Expected colon in object");
      obj[std::move(key)] = parse_value();
      skip_ws();
      char c = next();
      if (c == '}') break;
      if (c != ',') throw std::runtime_error("Expected comma in object");
    }
    return Json::object(std::move(obj));
  }
};

static std::string escape_json(const std::string& s) {
  std::ostringstream oss;
  for (unsigned char c : s) {
    switch (c) {
      case '"': oss << "\\\""; break;
      case '\\': oss << "\\\\"; break;
      case '\b': oss << "\\b"; break;
      case '\f': oss << "\\f"; break;
      case '\n': oss << "\\n"; break;
      case '\r': oss << "\\r"; break;
      case '\t': oss << "\\t"; break;
      default:
        if (c < 0x20) {
          oss << "\\u00" << std::hex << std::setw(2) << std::setfill('0') << static_cast<int>(c) << std::dec;
        } else {
          oss << static_cast<char>(c);
        }
    }
  }
  return oss.str();
}

static std::string dump_json(const Json& j) {
  std::ostringstream oss;
  switch (j.type) {
    case Json::Type::Null: return "null";
    case Json::Type::Bool: return j.bool_val ? "true" : "false";
    case Json::Type::Number: {
      if (!std::isfinite(j.num_val)) return "0";
      if (std::floor(j.num_val) == j.num_val && std::abs(j.num_val) < 9e15) {
        oss << static_cast<int64_t>(j.num_val);
      } else {
        oss << std::fixed << std::setprecision(2) << j.num_val;
      }
      return oss.str();
    }
    case Json::Type::String: return "\"" + escape_json(j.str_val) + "\"";
    case Json::Type::Array: {
      oss << "[";
      for (size_t i = 0; i < j.arr_val.size(); ++i) {
        if (i) oss << ",";
        oss << dump_json(j.arr_val[i]);
      }
      oss << "]";
      return oss.str();
    }
    case Json::Type::Object: {
      oss << "{";
      size_t i = 0;
      for (const auto& [k, v] : j.obj_val) {
        if (i++) oss << ",";
        oss << "\"" << escape_json(k) << "\":" << dump_json(v);
      }
      oss << "}";
      return oss.str();
    }
  }
  return "null";
}

// 1. High-precision sale calculation & validation
static Json op_calculate_sale(const Json& req) {
  const auto& items = req.get("items");
  if (items.type != Json::Type::Array || items.arr_val.empty()) {
    throw std::runtime_error("Le panier est vide");
  }

  int64_t subtotal = 0;
  int64_t total_cost = 0;
  int64_t total_units = 0;
  std::vector<Json> computed_items;

  for (const auto& item : items.arr_val) {
    int64_t qty = item.get("quantity").as_int(0);
    int64_t unit_price = item.get("unit_price").as_int(-1);
    int64_t buy_price = std::max<int64_t>(0, item.get("buy_price").as_int(0));
    if (qty <= 0) throw std::runtime_error("Quantité invalide dans le panier");
    if (unit_price < 0) throw std::runtime_error("Prix unitaire invalide");

    int64_t line_total = qty * unit_price;
    int64_t line_cost = qty * buy_price;
    subtotal += line_total;
    total_cost += line_cost;
    total_units += qty;

    Json out_item = Json::object();
    out_item.obj_val["product_id"] = Json::integer(item.get("product_id").as_int(0));
    out_item.obj_val["quantity"] = Json::integer(qty);
    out_item.obj_val["unit_price"] = Json::integer(unit_price);
    out_item.obj_val["buy_price"] = Json::integer(buy_price);
    out_item.obj_val["line_total"] = Json::integer(line_total);
    out_item.obj_val["line_margin"] = Json::integer(line_total - line_cost);
    computed_items.push_back(std::move(out_item));
  }

  int64_t discount = std::max<int64_t>(0, req.get("discount_amount").as_int(0));
  if (discount > subtotal) discount = subtotal;
  int64_t total_amount = subtotal - discount;

  bool is_cash = req.get("is_cash").as_int(1) != 0;
  int64_t amount_given = req.get("amount_given").as_int(total_amount);
  if (!is_cash && amount_given <= 0) amount_given = total_amount;
  if (is_cash && amount_given < total_amount) {
    throw std::runtime_error("Montant remis inférieur au total à payer");
  }
  int64_t change_amount = std::max<int64_t>(0, amount_given - total_amount);
  int64_t gross_margin = total_amount - total_cost;
  double margin_pct = total_amount > 0 ? (static_cast<double>(gross_margin) * 100.0) / static_cast<double>(total_amount) : 0.0;

  Json res = Json::object();
  res.obj_val["ok"] = Json::boolean(true);
  res.obj_val["subtotal"] = Json::integer(subtotal);
  res.obj_val["discount_amount"] = Json::integer(discount);
  res.obj_val["total_amount"] = Json::integer(total_amount);
  res.obj_val["total_cost"] = Json::integer(total_cost);
  res.obj_val["gross_margin"] = Json::integer(gross_margin);
  res.obj_val["margin_percent"] = Json::number(std::round(margin_pct * 100.0) / 100.0);
  res.obj_val["total_units"] = Json::integer(total_units);
  res.obj_val["amount_given"] = Json::integer(amount_given);
  res.obj_val["change_amount"] = Json::integer(change_amount);
  res.obj_val["items"] = Json::array(std::move(computed_items));
  return res;
}

// 2. Cash drawer reconciliation
static Json op_reconcile_cash(const Json& req) {
  int64_t opening = req.get("opening_amount").as_int(0);
  int64_t cash_sales = req.get("cash_sales").as_int(0);
  int64_t manual_in = req.get("manual_in").as_int(0);
  int64_t cash_expenses = req.get("cash_expenses").as_int(0);
  int64_t manual_out = req.get("manual_out").as_int(0);
  int64_t cancelled_cash = req.get("cancelled_cash").as_int(0);

  int64_t total_in = cash_sales + manual_in;
  int64_t total_out = cash_expenses + manual_out + cancelled_cash;
  int64_t expected = opening + total_in - total_out;

  bool has_actual = req.get("has_actual").as_int(0) != 0;
  int64_t actual = has_actual ? req.get("actual_amount").as_int(expected) : expected;
  int64_t discrepancy = actual - expected;

  std::string status = "BALANCED";
  if (discrepancy > 0) status = "SURPLUS";
  else if (discrepancy < 0) status = "SHORTAGE";

  Json res = Json::object();
  res.obj_val["ok"] = Json::boolean(true);
  res.obj_val["opening_amount"] = Json::integer(opening);
  res.obj_val["total_in"] = Json::integer(total_in);
  res.obj_val["total_out"] = Json::integer(total_out);
  res.obj_val["expected_amount"] = Json::integer(expected);
  res.obj_val["actual_amount"] = Json::integer(actual);
  res.obj_val["discrepancy"] = Json::integer(discrepancy);
  res.obj_val["reconciliation_status"] = Json::string(status);
  return res;
}

// 3. Period financial analytics & profit computation
static Json op_compute_analytics(const Json& req) {
  const auto& sales = req.get("sales");
  const auto& expenses = req.get("expenses");
  const auto& items = req.get("sale_items");

  int64_t total_revenue = 0;
  int64_t total_discount = 0;
  int64_t sales_count = 0;
  int64_t cancelled_count = 0;
  std::map<std::string, int64_t> revenue_by_day;
  std::map<std::string, int64_t> expenses_by_day;
  std::map<std::string, int64_t> count_by_day;
  std::map<std::string, int64_t> by_payment;

  if (sales.type == Json::Type::Array) {
    for (const auto& s : sales.arr_val) {
      std::string st = s.get("status").as_string("COMPLETED");
      if (st == "CANCELLED") {
        ++cancelled_count;
        continue;
      }
      int64_t amt = s.get("total_amount").as_int(0);
      int64_t disc = s.get("discount_amount").as_int(0);
      std::string day = s.get("day").as_string("");
      std::string pm = s.get("payment_method").as_string("Espèces");
      total_revenue += amt;
      total_discount += disc;
      ++sales_count;
      if (!day.empty()) {
        revenue_by_day[day] += amt;
        count_by_day[day] += 1;
      }
      by_payment[pm] += amt;
    }
  }

  int64_t total_expenses = 0;
  std::map<std::string, int64_t> expenses_by_category;
  if (expenses.type == Json::Type::Array) {
    for (const auto& e : expenses.arr_val) {
      int64_t amt = e.get("amount").as_int(0);
      std::string day = e.get("day").as_string("");
      std::string cat = e.get("category").as_string("Autre");
      total_expenses += amt;
      if (!day.empty()) expenses_by_day[day] += amt;
      expenses_by_category[cat] += amt;
    }
  }

  int64_t total_cogs = 0;
  int64_t total_units_sold = 0;
  struct ProdStat {
    std::string name;
    int64_t qty = 0;
    int64_t revenue = 0;
    int64_t profit = 0;
  };
  std::map<std::string, ProdStat> prod_map;

  if (items.type == Json::Type::Array) {
    for (const auto& it : items.arr_val) {
      int64_t qty = it.get("quantity").as_int(0);
      int64_t line_total = it.get("line_total").as_int(0);
      int64_t buy_price = it.get("buy_price").as_int(0);
      int64_t cost = qty * buy_price;
      total_cogs += cost;
      total_units_sold += qty;

      std::string pname = it.get("product_name").as_string("Produit");
      auto& ps = prod_map[pname];
      ps.name = pname;
      ps.qty += qty;
      ps.revenue += line_total;
      ps.profit += (line_total - cost);
    }
  }

  int64_t gross_profit = total_revenue - total_cogs;
  int64_t net_profit = gross_profit - total_expenses;
  int64_t avg_basket = sales_count > 0 ? total_revenue / sales_count : 0;
  double profit_margin = total_revenue > 0 ? (static_cast<double>(net_profit) * 100.0) / static_cast<double>(total_revenue) : 0.0;

  // Collect unified sorted day series
  std::map<std::string, bool> all_days;
  for (const auto& [d, _] : revenue_by_day) all_days[d] = true;
  for (const auto& [d, _] : expenses_by_day) all_days[d] = true;

  std::vector<Json> series;
  for (const auto& [d, _] : all_days) {
    Json row = Json::object();
    int64_t rev = revenue_by_day[d];
    int64_t exp = expenses_by_day[d];
    row.obj_val["day"] = Json::string(d);
    row.obj_val["revenue"] = Json::integer(rev);
    row.obj_val["expenses"] = Json::integer(exp);
    row.obj_val["net"] = Json::integer(rev - exp);
    row.obj_val["count"] = Json::integer(count_by_day[d]);
    series.push_back(std::move(row));
  }

  std::vector<ProdStat> top_list;
  top_list.reserve(prod_map.size());
  for (const auto& [_, ps] : prod_map) top_list.push_back(ps);
  std::sort(top_list.begin(), top_list.end(), [](const ProdStat& a, const ProdStat& b) {
    if (a.qty != b.qty) return a.qty > b.qty;
    return a.revenue > b.revenue;
  });

  std::vector<Json> top_products;
  for (size_t i = 0; i < top_list.size() && i < 15; ++i) {
    Json p = Json::object();
    p.obj_val["name"] = Json::string(top_list[i].name);
    p.obj_val["quantity"] = Json::integer(top_list[i].qty);
    p.obj_val["revenue"] = Json::integer(top_list[i].revenue);
    p.obj_val["profit"] = Json::integer(top_list[i].profit);
    top_products.push_back(std::move(p));
  }

  Json pay_obj = Json::object();
  for (const auto& [k, v] : by_payment) pay_obj.obj_val[k] = Json::integer(v);

  Json exp_cat_obj = Json::object();
  for (const auto& [k, v] : expenses_by_category) exp_cat_obj.obj_val[k] = Json::integer(v);

  Json res = Json::object();
  res.obj_val["ok"] = Json::boolean(true);
  res.obj_val["total_revenue"] = Json::integer(total_revenue);
  res.obj_val["total_discount"] = Json::integer(total_discount);
  res.obj_val["total_cogs"] = Json::integer(total_cogs);
  res.obj_val["gross_profit"] = Json::integer(gross_profit);
  res.obj_val["total_expenses"] = Json::integer(total_expenses);
  res.obj_val["estimated_profit"] = Json::integer(net_profit);
  res.obj_val["profit_margin_percent"] = Json::number(std::round(profit_margin * 100.0) / 100.0);
  res.obj_val["sales_count"] = Json::integer(sales_count);
  res.obj_val["cancelled_count"] = Json::integer(cancelled_count);
  res.obj_val["total_units_sold"] = Json::integer(total_units_sold);
  res.obj_val["avg_basket"] = Json::integer(avg_basket);
  res.obj_val["daily_series"] = Json::array(std::move(series));
  res.obj_val["top_products"] = Json::array(std::move(top_products));
  res.obj_val["by_payment"] = std::move(pay_obj);
  res.obj_val["expenses_by_category"] = std::move(exp_cat_obj);
  return res;
}

}  // namespace fincaisse

int main(int argc, char** argv) {
  using namespace fincaisse;
  if (argc > 1 && std::string(argv[1]) == "--version") {
    std::cout << "{\"engine\":\"fincaisse_engine\",\"cpp_standard\":20,\"status\":\"ready\"}\n";
    return 0;
  }

  std::ostringstream buffer;
  buffer << std::cin.rdbuf();
  std::string input = buffer.str();

  try {
    JsonParser parser(input);
    Json req = parser.parse();
    std::string op = req.get("op").as_string("");
    Json out;
    if (op == "calculate_sale") {
      out = op_calculate_sale(req);
    } else if (op == "reconcile_cash") {
      out = op_reconcile_cash(req);
    } else if (op == "compute_analytics") {
      out = op_compute_analytics(req);
    } else if (op == "ping") {
      out = Json::object({{"ok", Json::boolean(true)}, {"engine", Json::string("fincaisse_engine-c++20")}});
    } else {
      throw std::runtime_error("Opération C++ inconnue: " + op);
    }
    std::cout << dump_json(out) << "\n";
    return 0;
  } catch (const std::exception& e) {
    Json err = Json::object({{"ok", Json::boolean(false)}, {"error", Json::string(e.what())}});
    std::cout << dump_json(err) << "\n";
    return 1;
  }
}
