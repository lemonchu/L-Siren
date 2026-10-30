#!/usr/bin/env bash
# Manual update only. No arguments downloads APNIC; --source rebuilds offline.
set -euo pipefail
umask 022
script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
source_file=''
output_file="$script_dir/cn-ranges.json"
usage() { echo "Usage: $0 [--source LOCAL_FILE] [--output JSON_FILE]"; }
while (($#)); do
    case "$1" in
        --source|--output)
            if (($# < 2)) || [[ -z "$2" ]]; then usage >&2; exit 2; fi
            if [[ "$1" == --source ]]; then source_file="$2"; else output_file="$2"; fi
            shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done
command -v "${CXX:-c++}" >/dev/null || { echo 'A C++17 compiler is required (c++ or CXX).' >&2; exit 1; }
command -v flock >/dev/null || { echo 'flock is required.' >&2; exit 1; }
output_dir=$(CDPATH= cd -- "$(dirname -- "$output_file")" && pwd)
output_file="$output_dir/$(basename -- "$output_file")"
if [[ -n "$source_file" ]]; then
    [[ -r "$source_file" ]] || { echo 'Cannot read source file.' >&2; exit 1; }
    source_file="$(CDPATH= cd -- "$(dirname -- "$source_file")" && pwd)/$(basename -- "$source_file")"
    [[ "$source_file" != "$output_file" ]] || { echo 'Input and output must differ.' >&2; exit 2; }
fi
exec 9>"$output_file.lock"
flock -n 9 || { echo 'Another update is running.' >&2; exit 1; }
work_dir=$(mktemp -d "$output_dir/.apnic-update.XXXXXX")
trap 'rm -rf -- "$work_dir"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
"${CXX:-c++}" -std=c++17 -O2 -Wall -Wextra -pedantic "$script_dir/fetch.cpp" -o "$work_dir/fetch"
if [[ -z "$source_file" ]]; then
    command -v curl >/dev/null || { echo 'curl is required.' >&2; exit 1; }
    curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
        --connect-timeout 15 --max-time 180 --retry 2 --max-filesize 33554432 \
        'https://ftp.apnic.net/stats/apnic/delegated-apnic-latest' -o "$work_dir/delegated-apnic-latest"
    source_file="$work_dir/delegated-apnic-latest"
fi
"$work_dir/fetch" "$source_file" "$work_dir/cn-ranges.json"
chmod 664 "$work_dir/cn-ranges.json"
mv -f -- "$work_dir/cn-ranges.json" "$output_file"
echo "Published: $output_file"
