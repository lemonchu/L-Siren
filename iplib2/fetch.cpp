// Build: c++ -std=c++17 -O2 -Wall -Wextra -pedantic fetch.cpp -o fetch
// Usage: ./fetch [d.txt [cn-ranges.json]]
#include <arpa/inet.h>
#include <algorithm>
#include <array>
#include <cstdint>
#include <fstream>
#include <iostream>
#include <limits>
#include <sstream>
#include <stdexcept>
#include <string>
#include <vector>

template <std::size_t N> using Address = std::array<unsigned char, N>;
template <std::size_t N> struct Range { Address<N> first, last; };

static std::uint64_t number(const std::string& text) {
    if (text.empty() || text.find_first_not_of("0123456789") != std::string::npos)
        throw std::runtime_error("invalid unsigned integer");
    return std::stoull(text);
}

static std::vector<std::string> fields(const std::string& line) {
    std::vector<std::string> result;
    std::istringstream input(line);
    std::string field;
    while (std::getline(input, field, '|')) result.push_back(field);
    return result;
}

template <std::size_t N>
static Address<N> address(const std::string& text, int family) {
    Address<N> result{};
    if (inet_pton(family, text.c_str(), result.data()) != 1)
        throw std::runtime_error("invalid IP address");
    return result;
}

static Range<4> ipv4(const std::string& start, const std::string& value) {
    const auto first = address<4>(start, AF_INET);
    const auto count = number(value);
    std::uint64_t base = 0;
    for (auto byte : first) base = (base << 8) | byte;
    if (!count || count > (std::uint64_t{1} << 32) ||
        base + count - 1 > std::numeric_limits<std::uint32_t>::max())
        throw std::runtime_error("IPv4 range exceeds address space");
    auto end = base + count - 1;
    Address<4> last{};
    for (int i = 3; i >= 0; --i) {
        last[static_cast<std::size_t>(i)] = static_cast<unsigned char>(end & 255);
        end >>= 8;
    }
    return {first, last};
}

static Range<16> ipv6(const std::string& start, const std::string& value) {
    auto first = address<16>(start, AF_INET6);
    auto last = first;
    const auto prefix = number(value);
    if (prefix > 128) throw std::runtime_error("IPv6 prefix must be 0..128");
    for (std::size_t i = 0; i < 16; ++i) {
        const auto bits = prefix > i * 8 ? std::min<std::uint64_t>(8, prefix - i * 8) : 0;
        const auto mask = static_cast<unsigned char>(bits ? (0xffu << (8 - bits)) & 0xffu : 0);
        first[i] &= mask;
        last[i] |= static_cast<unsigned char>(~mask);
    }
    return {first, last};
}

template <std::size_t N>
static bool adjacent(const Address<N>& last, const Address<N>& first) {
    auto next = last;
    for (std::size_t i = N; i > 0; --i) {
        if (++next[i - 1] != 0) return next == first;
    }
    return false;
}

template <std::size_t N>
static std::vector<Range<N>> merge(std::vector<Range<N>> ranges) {
    std::sort(ranges.begin(), ranges.end(), [](const Range<N>& a, const Range<N>& b) {
        return a.first < b.first || (a.first == b.first && a.last < b.last);
    });
    std::vector<Range<N>> result;
    for (const auto& range : ranges) {
        if (!result.empty() && (range.first <= result.back().last || adjacent(result.back().last, range.first))) {
            result.back().last = std::max(result.back().last, range.last);
        } else {
            result.push_back(range);
        }
    }
    return result;
}

template <std::size_t N>
static std::string hex(const Address<N>& value) {
    const char* digits = "0123456789abcdef";
    std::string result;
    result.reserve(N * 2);
    for (auto byte : value) {
        result += digits[byte >> 4];
        result += digits[byte & 15];
    }
    return result;
}

template <std::size_t N>
static void write_ranges(std::ostream& out, const std::vector<Range<N>>& ranges) {
    out << '[';
    bool first = true;
    for (const auto& range : ranges) {
        if (!first) out << ',';
        first = false;
        out << "[\"" << hex(range.first) << "\",\"" << hex(range.last) << "\"]";
    }
    out << ']';
}

int main(int argc, char* argv[]) {
    if (argc > 3) { std::cerr << "Usage: fetch [SOURCE [OUTPUT]]\n"; return 2; }
    const std::string source = argc > 1 ? argv[1] : "d.txt";
    const std::string output = argc > 2 ? argv[2] : "cn-ranges.json";
    std::size_t line_number = 0;
    try {
        std::ifstream input(source);
        if (!input) throw std::runtime_error("cannot open input file");
        std::vector<Range<4>> v4;
        std::vector<Range<16>> v6;
        std::string line, serial;
        std::uint64_t expected = 0, records = 0;
        bool has_header = false;
        while (std::getline(input, line)) {
            ++line_number;
            if (!line.empty() && line.back() == '\r') line.pop_back();
            if (line.empty() || line.front() == '#') continue;
            const auto parts = fields(line);
            if (!has_header) {
                if (parts.size() < 7 || parts[0] != "2" || parts[1] != "apnic" ||
                    parts[2].size() != 8 || !number(parts[2]))
                    throw std::runtime_error("missing APNIC version 2 header");
                serial = parts[2];
                expected = number(parts[3]);
                has_header = true;
                continue;
            }
            if (parts.size() == 6 && parts[0] == "apnic" && parts[1] == "*" && parts[5] == "summary") continue;
            if (parts.size() < 7 || parts[0] != "apnic") throw std::runtime_error("malformed APNIC record");
            ++records;
            if (parts[1] != "CN" || (parts[6] != "allocated" && parts[6] != "assigned")) continue;
            if (parts[2] == "ipv4") v4.push_back(ipv4(parts[3], parts[4]));
            else if (parts[2] == "ipv6") v6.push_back(ipv6(parts[3], parts[4]));
        }
        if (input.bad()) throw std::runtime_error("failed reading input");
        if (!has_header || !expected || records != expected)
            throw std::runtime_error("record count differs from APNIC header; file may be truncated");
        if (v4.empty() || v6.empty()) throw std::runtime_error("missing CN IPv4 or IPv6 data");
        const auto original_v4 = v4.size(), original_v6 = v6.size();
        v4 = merge(std::move(v4));
        v6 = merge(std::move(v6));
        std::ofstream out(output, std::ios::binary | std::ios::trunc);
        if (!out) throw std::runtime_error("cannot open output file");
        out << "{\"version\":1,\"source_serial\":\"" << serial << "\",\"ipv4\":";
        write_ranges(out, v4);
        out << ",\"ipv6\":";
        write_ranges(out, v6);
        out << "}\n";
        out.close();
        if (!out) throw std::runtime_error("failed writing output");
        std::cout << "APNIC " << serial << ": IPv4 " << original_v4 << " -> " << v4.size()
                  << " ranges; IPv6 " << original_v6 << " -> " << v6.size() << " ranges\n";
    } catch (const std::exception& error) {
        std::cerr << "APNIC conversion failed (line " << line_number << "): " << error.what() << '\n';
        return 1;
    }
    return 0;
}
